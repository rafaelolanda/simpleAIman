<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Database;
use PDO;
use RuntimeException;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Llm\ProviderFactory;
use SimpleAIman\Rag\Reader\DocxLeitor;
use SimpleAIman\Rag\Reader\HtmlLeitor;
use SimpleAIman\Rag\Reader\Leitor;
use SimpleAIman\Rag\Reader\PdfLeitor;
use SimpleAIman\Rag\Reader\TextoLeitor;
use Throwable;

/**
 * Transforma um artefato em chunks embeddados.
 *
 * Trabalha em DUAS fases, e a separação é o que torna o processo retomável:
 *
 *   extrair()  → lê o arquivo, corta e grava os chunks (rápido, sem rede)
 *   embeddar() → percorre os chunks sem vetor, em lotes (lento, com rede)
 *
 * Se o processo morrer no meio da segunda fase, a primeira não se repete e a
 * segunda continua exatamente de onde parou — os chunks já vetorizados ficam
 * de fora da consulta. Um PDF de 300 páginas atravessa várias execuções do
 * cron sem reprocessar nada.
 */
final class Ingestor
{
    /** @var list<Leitor> */
    private array $leitores;

    public function __construct()
    {
        $this->leitores = [
            new PdfLeitor(),
            new DocxLeitor(),
            new TextoLeitor(),
            new HtmlLeitor(),
        ];
    }

    private function leitorPara(string $arquivo): Leitor
    {
        $extensao = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));

        foreach ($this->leitores as $leitor) {
            if (in_array($extensao, $leitor->extensoes(), true)) {
                return $leitor;
            }
        }

        throw new RuntimeException("Não sei ler arquivos .{$extensao}.");
    }

    public function extensoesAceitas(): array
    {
        $todas = [];

        foreach ($this->leitores as $leitor) {
            $todas = array_merge($todas, $leitor->extensoes());
        }

        sort($todas);

        return $todas;
    }

    /**
     * Fase 1 — arquivo em chunks. Sem rede, então roda inteira de uma vez.
     *
     * @return int quantidade de chunks gravados
     */
    public function extrair(int $artefatoId): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT a.*, b.chunk_tamanho, b.chunk_sobreposicao
             FROM artefatos a JOIN bases b ON b.id = a.base_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $artefatoId]);
        $artefato = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$artefato) {
            throw new RuntimeException("Artefato id={$artefatoId} não encontrado.");
        }

        $caminho = caminho_uploads('artefatos') . '/' . $artefato['arquivo'];

        if (!is_file($caminho)) {
            throw new RuntimeException("Arquivo não encontrado: {$artefato['arquivo']}");
        }

        // Reindexação: limpa os chunks antigos primeiro. Os embeddings e o
        // índice FTS saem junto, por cascata e por trigger.
        $pdo->prepare('DELETE FROM chunks WHERE artefato_id = :id')->execute(['id' => $artefatoId]);

        $chunker = new Chunker(
            (int) ($artefato['chunk_tamanho'] ?: 800),
            (int) ($artefato['chunk_sobreposicao'] ?: 120),
        );

        $leitor = $this->leitorPara($artefato['arquivo']);
        $insere = $pdo->prepare(
            'INSERT INTO chunks (artefato_id, base_id, ordem, conteudo, tokens, metadados, criado_em)
             VALUES (:artefato, :base, :ordem, :conteudo, :tokens, :metadados, :agora)'
        );

        $ordem = 0;
        $agora = now();

        foreach ($leitor->ler($caminho) as $parte) {
            foreach ($chunker->dividir($parte['texto'], $parte['metadados']) as $chunk) {
                $insere->execute([
                    'artefato' => $artefatoId,
                    'base' => $artefato['base_id'],
                    'ordem' => $ordem++,
                    // Cobre TODOS os leitores de uma vez: extrator de PDF e de
                    // DOCX devolve CP1252 com frequencia, e o texto so causaria
                    // problema muito depois, ao virar prompt.
                    'conteudo' => texto_utf8($chunk['texto']),
                    'tokens' => Chunker::tokensAproximados($chunk['texto']),
                    'metadados' => json_ou_nulo($chunk['metadados']),
                    'agora' => $agora,
                ]);
            }
        }

        if ($ordem === 0) {
            throw new RuntimeException('O arquivo foi lido, mas não gerou nenhum trecho de texto.');
        }

        $pdo->prepare('UPDATE artefatos SET chunks_total = :total, editado_em = :agora WHERE id = :id')
            ->execute(['total' => $ordem, 'agora' => $agora, 'id' => $artefatoId]);

        return $ordem;
    }

    /**
     * Fase 2 — embeddar um LOTE de chunks ainda sem vetor.
     *
     * Devolve quantos processou. Zero significa que acabou. O chamador
     * (Worker) decide quando parar por tempo.
     *
     * @throws ErroAgente com código `provedor_cota` quando bate no limite —
     *                    o Worker traduz isso em pausa, não em falha.
     */
    public function embeddar(int $artefatoId, int $tamanhoLote = 25): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT b.id, b.provedor_embedding_id, b.modelo_embedding, b.dimensoes
             FROM artefatos a JOIN bases b ON b.id = a.base_id WHERE a.id = :id'
        );
        $stmt->execute(['id' => $artefatoId]);
        $base = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$base) {
            throw new RuntimeException("Artefato id={$artefatoId} sem base.");
        }

        $pendentes = $pdo->prepare(
            'SELECT c.id, c.conteudo FROM chunks c
             LEFT JOIN embeddings e ON e.chunk_id = c.id
             WHERE c.artefato_id = :id AND e.chunk_id IS NULL
             ORDER BY c.ordem LIMIT :limite'
        );
        $pendentes->bindValue('id', $artefatoId, PDO::PARAM_INT);
        $pendentes->bindValue('limite', $tamanhoLote, PDO::PARAM_INT);
        $pendentes->execute();

        $chunks = $pendentes->fetchAll(PDO::FETCH_ASSOC);

        if ($chunks === []) {
            return 0;
        }

        $fabrica = $base['provedor_embedding_id']
            // Inativo recusa aqui, com a causa, e a política de falhas desiste:
            // configuração não volta sozinha. Reativado o provedor, reindexe.
            ? ProviderFactory::embeddingAtivo((int) $base['provedor_embedding_id'])
            : ProviderFactory::padraoEmbedding();

        // RETRIEVAL_DOCUMENT ao indexar; a pergunta usa RETRIEVAL_QUERY. São
        // espaços otimizados diferentes, e usar o mesmo dos dois lados custa
        // recall de graça.
        //
        // O grupo inteiro vai numa chamada só — ver ProviderFactory::embeddarVarios().
        // Antes eram 25 idas e voltas em sequência por grupo; na Hostinger, com
        // o plano gratuito do Gemini, cada uma levava ~1 s, e um grupo sozinho
        // estourava o orçamento de 20 s do worker.
        //
        // A rede acontece AQUI, fora de qualquer transação. Se o lote falhar,
        // nada deste grupo é gravado: os grupos anteriores já estão salvos, e o
        // job retoma exatamente deste ponto, porque só entra no próximo grupo o
        // trecho que ainda não tem vetor.
        try {
            $lista = $fabrica->embeddarVarios(
                array_map(static fn (array $chunk): string => (string) $chunk['conteudo'], $chunks),
                ProviderFactory::TAREFA_INDEXAR,
            );
        } catch (ErroAgente $e) {
            // Recusa de configuração (provedor de chat, modelo ausente) já vem
            // traduzida; embrulhar de novo esconderia a causa.
            throw $e;
        } catch (Throwable $e) {
            throw ErroAgente::deProvedor($e, 'embeddings');
        }

        $vetores = [];

        foreach ($chunks as $i => $chunk) {
            $vetores[(int) $chunk['id']] = $lista[$i];
        }

        $this->gravarVetores($vetores, (int) $base['id'], (string) $base['modelo_embedding']);

        return count($vetores);
    }

    /**
     * @param array<int, list<float>> $vetores
     */
    private function gravarVetores(array $vetores, int $baseId, string $modelo): void
    {
        if ($vetores === []) {
            return;
        }

        $agora = now();

        // Transação curta e sem rede dentro: é o padrão que mantém o chat
        // respondendo enquanto a ingestão roda.
        Database::transacao(static function (PDO $pdo) use ($vetores, $baseId, $modelo, $agora): void {
            $insere = $pdo->prepare(
                'INSERT OR REPLACE INTO embeddings (chunk_id, base_id, modelo, dimensoes, vetor, norma, criado_em)
                 VALUES (:chunk, :base, :modelo, :dim, :vetor, :norma, :agora)'
            );

            foreach ($vetores as $chunkId => $vetor) {
                // float32 empacotado: metade do espaço de float64 e o formato
                // que o unpack() da busca espera.
                $binario = pack('f*', ...$vetor);

                $insere->bindValue('chunk', $chunkId, PDO::PARAM_INT);
                $insere->bindValue('base', $baseId, PDO::PARAM_INT);
                $insere->bindValue('modelo', $modelo);
                $insere->bindValue('dim', count($vetor), PDO::PARAM_INT);

                // PARAM_LOB e não string: passado como string, o SQLite guarda
                // com typeof() = 'text' mesmo na coluna declarada BLOB. Os
                // bytes sobrevivem, mas um `.dump` (SQL textual) corrompe o
                // vetor, e qualquer ferramenta que assuma UTF-8 no conteúdo
                // pode reescrevê-lo. Como BLOB, o tipo é honesto e o dado é
                // opaco para todo mundo.
                $insere->bindValue('vetor', $binario, PDO::PARAM_LOB);

                // Norma pré-calculada para o cosseno não recalcular a
                // magnitude a cada comparação, em toda busca.
                $insere->bindValue('norma', self::norma($vetor));
                $insere->bindValue('agora', $agora);

                $insere->execute();
            }
        });
    }

    /** @param list<float> $vetor */
    private static function norma(array $vetor): float
    {
        $soma = 0.0;

        foreach ($vetor as $v) {
            $soma += $v * $v;
        }

        return sqrt($soma) ?: 1.0;
    }

    /** Quantos chunks deste artefato ainda não têm vetor. */
    public function pendentes(int $artefatoId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM chunks c LEFT JOIN embeddings e ON e.chunk_id = c.id
             WHERE c.artefato_id = :id AND e.chunk_id IS NULL'
        );
        $stmt->execute(['id' => $artefatoId]);

        return (int) $stmt->fetchColumn();
    }
}
