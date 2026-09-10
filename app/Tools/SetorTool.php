<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use Mailer;
use Metrics;
use PDO;
use RuntimeException;
use Throwable;

/**
 * As duas ferramentas embutidas de encaminhamento.
 *
 *   contato_setor  — leitura: devolve telefone, e-mail e horário do setor
 *   abrir_chamado  — escrita: registra e avisa o responsável por e-mail
 *
 * São embutidas, e não configuráveis, porque o dado já mora no nosso banco:
 * transformá-las em ferramenta `http` obrigaria a instância a chamar a si
 * mesma pela rede para ler a própria tabela.
 *
 * A regra que sustenta as duas: **contato só sai daqui**. O modelo é proibido
 * de inventar telefone ou e-mail, e inventa com muita naturalidade — um
 * número plausível é indistinguível de um número real para quem lê.
 */
final class SetorTool
{
    public function __construct(private readonly int $conversaId)
    {
    }

    /**
     * @param array<string, mixed> $parametros
     */
    public function contato(array $parametros): string
    {
        $setor = $this->resolverSetor($parametros['setor'] ?? null);

        $dados = array_filter([
            'setor' => $setor['nome'],
            'responsavel' => $setor['responsavel_nome'] ?: null,
            'email' => $setor['email'] ?: null,
            'telefone' => $setor['telefone'] ?: null,
            'ramal' => $setor['ramal'] ?: null,
            'whatsapp' => $setor['whatsapp'] ? whatsapp_url($setor['whatsapp'], 'Olá! Vim pelo assistente virtual.') : null,
            'horario' => $setor['horario_atendimento'] ?: null,
            'local' => $setor['local'] ?: null,
        ]);

        // Setor sem contato nenhum não pode virar resposta vazia: o modelo
        // preencheria a lacuna. Melhor dizer que não há.
        if (count($dados) === 1) {
            return json_encode([
                'setor' => $setor['nome'],
                'sem_contato' => true,
                'instrucao' => 'Este setor não tem contato cadastrado. Diga que não tem o contato e '
                    . 'sugira os canais oficiais da instituição. NÃO invente telefone nem e-mail.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Horário existe para ser dito junto: passar o telefone às 22h sem
        // avisar que atende 8h–17h só produz frustração.
        $dados['instrucao'] = 'Passe estes dados exatamente como estão. Se houver horário, mencione-o '
            . 'junto do telefone.';

        Metrics::log('contato_setor', (int) $setor['id']);

        return json_encode($dados, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $parametros
     */
    public function abrirChamado(array $parametros): string
    {
        $setor = $this->resolverSetor($parametros['setor'] ?? null);

        $assunto = trim((string) ($parametros['assunto'] ?? ''));
        $descricao = trim((string) ($parametros['descricao'] ?? ''));
        $contato = trim((string) ($parametros['contato'] ?? ''));

        if ($assunto === '') {
            throw new RuntimeException('Assunto é obrigatório para abrir um chamado.');
        }

        // Sem forma de retorno, o chamado é um bilhete sem endereço: alguém
        // leria e não teria como responder.
        if ($contato === '') {
            return json_encode([
                'erro' => true,
                'instrucao' => 'Antes de registrar, peça ao visitante um e-mail ou telefone para retorno. '
                    . 'Sem isso não há como responder a ele.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $pdo = Database::connection();
        $agora = now();

        $pdo->prepare(
            'INSERT INTO chamados (conversa_id, setor_id, assunto, descricao, contato, status, criado_em, editado_em)
             VALUES (:c, :s, :a, :d, :ct, \'aberto\', :agora, :agora)'
        )->execute([
            'c' => $this->conversaId,
            's' => $setor['id'],
            'a' => mb_substr(texto_utf8($assunto), 0, 200),
            'd' => texto_utf8($descricao),
            'ct' => mb_substr(texto_utf8($contato), 0, 200),
            'agora' => $agora,
        ]);

        $chamadoId = (int) $pdo->lastInsertId();

        $avisado = $this->avisarResponsavel($chamadoId, $setor, $assunto, $descricao, $contato);

        Metrics::log('chamado_aberto', (int) $setor['id']);

        return json_encode([
            'registrado' => true,
            'protocolo' => $chamadoId,
            'setor' => $setor['nome'],
            // O que o agente pode prometer depende de o e-mail ter saído. Se
            // não saiu, o chamado existe no painel mas ninguém foi avisado —
            // e prometer retorno seria a mesma promessa falsa de antes.
            'instrucao' => $avisado
                ? "Confirme que a dúvida foi registrada sob o protocolo {$chamadoId} e que o setor "
                    . '"' . $setor['nome'] . '" vai retornar pelo contato informado.'
                : "Confirme que a dúvida foi registrada sob o protocolo {$chamadoId}. NÃO prometa prazo "
                    . 'de retorno.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Encontra o setor pelo slug, caindo no padrão quando não casa.
     *
     * Nunca devolve "não encontrei": o setor padrão existe justamente para
     * que sempre haja destino válido. Sem ele, o modelo ficaria sem saída e a
     * tentação seria inventar um contato.
     *
     * @return array<string, mixed>
     */
    private function resolverSetor(mixed $slug): array
    {
        $pdo = Database::connection();
        $slug = trim((string) $slug);

        if ($slug !== '') {
            $stmt = $pdo->prepare('SELECT * FROM setores WHERE slug = :s AND ativo = 1');
            $stmt->execute(['s' => $slug]);
            $setor = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($setor) {
                return $setor;
            }
        }

        $setor = $pdo->query('SELECT * FROM setores WHERE padrao = 1 AND ativo = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        if (!$setor) {
            $setor = $pdo->query('SELECT * FROM setores WHERE ativo = 1 ORDER BY ordem LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        }

        if (!$setor) {
            throw new RuntimeException('Nenhum setor ativo cadastrado.');
        }

        return $setor;
    }

    /** @param array<string, mixed> $setor */
    private function avisarResponsavel(
        int $chamadoId,
        array $setor,
        string $assunto,
        string $descricao,
        string $contato,
    ): bool {
        $destino = trim((string) ($setor['email'] ?? ''));

        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $historico = $this->transcricao();

        $corpo = "Um chamado foi aberto pelo assistente virtual.\n\n"
            . "Protocolo: {$chamadoId}\n"
            . "Setor: {$setor['nome']}\n"
            . "Assunto: {$assunto}\n"
            . "Contato para retorno: {$contato}\n\n"
            . ($descricao !== '' ? "Descrição:\n{$descricao}\n\n" : '')
            . "Conversa:\n" . $historico;

        try {
            // Quatro argumentos, e o corpo em HTML.
            //
            // Estava chamando com três: o PHP recusava, a exceção era engolida
            // pelo catch abaixo, e o chamado ficava gravado como "não avisado"
            // — ninguém recebia nada e nada na tela indicava isso. O Mailer
            // manda `Content-Type: text/html`, então texto puro chegaria como
            // um parágrafo só, sem as quebras de linha.
            // O retorno IMPORTA: o Mailer engole a falha e devolve false. Marcar
            // como avisado sem conferir faria o agente dizer "o setor vai
            // retornar" sobre um e-mail que nunca saiu — a promessa que o
            // projeto inteiro tenta não fazer.
            if (!Mailer::send(
                $destino,
                (string) ($setor['responsavel_nome'] ?: $setor['nome']),
                "[Assistente] Chamado #{$chamadoId} — {$assunto}",
                nl2br(e($corpo))
            )) {
                \Log::erro('chamado_email_nao_saiu', ['chamado_id' => $chamadoId, 'causa' => 'mailer_false']);

                return false;
            }

            Database::connection()
                ->prepare('UPDATE chamados SET email_enviado_em = :agora WHERE id = :id')
                ->execute(['agora' => now(), 'id' => $chamadoId]);

            return true;
        } catch (Throwable $e) {
            // O chamado já está gravado: falhar o e-mail não pode desfazê-lo.
            // Ele aparece no painel como não avisado.
            \Log::erro('chamado_email_falhou', ['chamado_id' => $chamadoId, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Conversa em texto, para quem for atender entender o contexto.
     *
     * Vai junto do e-mail porque o atendente precisa saber o que já foi dito
     * — inclusive o que o bot respondeu — antes de retornar.
     */
    private function transcricao(int $limite = 20): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT autor_tipo, conteudo FROM mensagens
             WHERE conversa_id = :c AND autor_tipo IN (\'usuario\', \'bot\', \'atendente\')
             ORDER BY id DESC LIMIT :l'
        );
        $stmt->bindValue('c', $this->conversaId, PDO::PARAM_INT);
        $stmt->bindValue('l', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $linhas = [];

        foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)) as $m) {
            $quem = match ($m['autor_tipo']) {
                'usuario' => 'Visitante',
                'atendente' => 'Atendente',
                default => 'Assistente',
            };

            $linhas[] = $quem . ': ' . $m['conteudo'];
        }

        return $linhas === [] ? '(sem histórico)' : implode("\n\n", $linhas);
    }
}
