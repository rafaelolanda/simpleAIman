<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Database;
use PDO;
use SimpleAIman\Llm\ProviderFactory;
use Throwable;

/**
 * Mantém os vetores da FAQ em dia.
 *
 * A FAQ é embeddada pela PERGUNTA, não pela resposta. O que precisa casar com
 * o que o visitante digitou é a pergunta curada; a resposta pode ser longa,
 * cheia de detalhes que só diluiriam o vetor.
 *
 * Diferente dos artefatos, aqui não há fase de extração nem de chunking: a
 * pergunta já é a unidade. Por isso o indexador é simples e roda em lote
 * pequeno — mas continua fora do request, pela mesma razão de sempre: são
 * chamadas de rede, e a rede é lenta.
 */
final class FaqIndexador
{
    /**
     * Embedda as FAQ ativas que ainda não têm vetor, ou cujo texto mudou.
     *
     * @return int quantas foram indexadas
     */
    public function indexarPendentes(int $limite = 20): int
    {
        $pdo = Database::connection();

        // `criado_em` do vetor anterior à edição da FAQ significa pergunta
        // alterada depois de indexada — o vetor antigo aponta para um texto
        // que não existe mais.
        $stmt = $pdo->prepare(
            'SELECT f.id, f.pergunta FROM faq f
             LEFT JOIN faq_embeddings e ON e.faq_id = f.id
             WHERE f.ativo = 1 AND (e.faq_id IS NULL OR e.criado_em < f.editado_em)
             ORDER BY f.id LIMIT :limite'
        );
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pendentes === []) {
            return 0;
        }

        // Padrão de EMBEDDING, nunca o do chat: o índice da FAQ é comparado
        // com o vetor da pergunta, e vetor só é comparável com vetor do mesmo
        // modelo. As duas pontas precisam sair da mesma origem.
        $fabrica = ProviderFactory::padraoEmbedding();

        // RETRIEVAL_DOCUMENT: a FAQ é o lado "documento" da busca, mesmo
        // sendo uma pergunta. Quem usa RETRIEVAL_QUERY é a fala do visitante.
        $embedder = $fabrica->embeddings(ProviderFactory::TAREFA_INDEXAR);
        $modelo = $fabrica->modeloEmbedding();

        $vetores = [];

        // Rede fora de transação, como sempre.
        foreach ($pendentes as $faq) {
            $vetores[(int) $faq['id']] = $embedder->embedText((string) $faq['pergunta']);
        }

        $agora = now();

        Database::transacao(static function (PDO $pdo) use ($vetores, $modelo, $agora): void {
            $insere = $pdo->prepare(
                'INSERT OR REPLACE INTO faq_embeddings (faq_id, modelo, dimensoes, vetor, norma, criado_em)
                 VALUES (:faq, :modelo, :dim, :vetor, :norma, :agora)'
            );

            foreach ($vetores as $faqId => $vetor) {
                $soma = 0.0;

                foreach ($vetor as $x) {
                    $soma += $x * $x;
                }

                $insere->bindValue('faq', $faqId, PDO::PARAM_INT);
                $insere->bindValue('modelo', $modelo);
                $insere->bindValue('dim', count($vetor), PDO::PARAM_INT);
                // PARAM_LOB: como string, o SQLite gravaria com typeof 'text'
                // mesmo na coluna BLOB, e um .dump textual corromperia.
                $insere->bindValue('vetor', pack('f*', ...$vetor), PDO::PARAM_LOB);
                $insere->bindValue('norma', sqrt($soma) ?: 1.0);
                $insere->bindValue('agora', $agora);
                $insere->execute();
            }
        });

        return count($vetores);
    }

    /** Quantas FAQ ativas ainda estão sem vetor atualizado. */
    public static function pendentes(): int
    {
        return (int) Database::connection()->query(
            'SELECT COUNT(*) FROM faq f
             LEFT JOIN faq_embeddings e ON e.faq_id = f.id
             WHERE f.ativo = 1 AND (e.faq_id IS NULL OR e.criado_em < f.editado_em)'
        )->fetchColumn();
    }

    /**
     * Remove vetores de FAQ que deixaram de existir ou foram desativadas.
     *
     * FAQ desativada com vetor vivo continuaria casando na busca e o agente
     * responderia por ela — o pior tipo de erro, porque alguém desativou
     * justamente para que parasse de ser dita.
     */
    public static function limpar(): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM faq_embeddings WHERE faq_id NOT IN (SELECT id FROM faq WHERE ativo = 1)'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** Indexa em segundo plano, sem derrubar o salvamento se a rede falhar. */
    public static function tentarIndexar(): void
    {
        try {
            self::limpar();
            (new self())->indexarPendentes();
        } catch (Throwable $e) {
            // A FAQ fica salva e aparece como pendente no painel; o worker
            // pega depois. Perder o vetor não pode perder o texto curado.
            error_log('[simpleAIman] indexação da FAQ falhou: ' . $e->getMessage());
        }
    }
}
