<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use Metrics;
use PDO;
use RuntimeException;
use SimpleAIman\Jobs\Queue;

/**
 * Captura de contato — a ferramenta embutida `registrar_lead`.
 *
 * Grava SEMPRE no banco primeiro, e só depois enfileira a entrega ao destino
 * externo. A ordem não é detalhe: fire-and-forget contra API de terceiro
 * significa perder lead sem ninguém saber. Aqui, se o CRM estiver fora do ar,
 * o lead existe, aparece no painel e a entrega é retentada.
 *
 * `leads` é a fonte da verdade; `lead_destinos` registra cada entrega com
 * tentativas, backoff e dead letter próprios — é o que permite responder
 * "esse lead chegou no CRM?" sem adivinhar.
 */
final class LeadTool
{
    public function __construct(
        private readonly int $conversaId,
        private readonly ?int $agenteId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parametros
     */
    public function registrar(array $parametros): string
    {
        $nome = trim(texto_utf8($parametros['nome'] ?? ''));
        $email = trim(texto_utf8($parametros['email'] ?? ''));
        $telefone = trim((string) ($parametros['telefone'] ?? ''));

        $agente = $this->agente();

        // O que é OBRIGATÓRIO vem do destino, não daqui.
        //
        // A versão anterior exigia e-mail ou telefone, chumbado — o que é uma
        // suposição sobre o CRM alheio. Um CRM pode identificar contato por
        // e-mail (RD Station), outro por nome e CPF, outro por telefone. Quem
        // sabe é a configuração da ferramenta de destino, e é de lá que a
        // regra tem de sair.
        $faltando = $this->camposFaltando($agente, $parametros);

        if ($faltando !== []) {
            return json_encode([
                'erro' => true,
                'instrucao' => 'Antes de registrar, peça à pessoa: ' . implode(', ', $faltando)
                    . '. Peça de forma natural, explicando para que serve.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Sem NENHUMA forma de retorno o lead é inútil, mesmo que o destino
        // externo não exija: alguém leria um nome no painel e não teria como
        // falar com a pessoa.
        if ($email === '' && $telefone === '') {
            return json_encode([
                'erro' => true,
                'instrucao' => 'Peça um e-mail ou telefone antes de registrar. Sem contato não há como retornar.',
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_encode([
                'erro' => true,
                'instrucao' => 'O e-mail informado parece inválido. Peça para a pessoa confirmar.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // CPF entra normalizado e conferido pelos dígitos verificadores. Um
        // CPF malformado não serve ao CRM e seria rejeitado lá na frente,
        // quando ninguém mais estiver na conversa para corrigir.
        if (isset($parametros['cpf'])) {
            $cpf = cpf_normalizar((string) $parametros['cpf']);

            if ($cpf === null) {
                return json_encode([
                    'erro' => true,
                    'instrucao' => 'O CPF informado não confere. Peça para a pessoa repetir, com os 11 dígitos.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $parametros['cpf'] = $cpf;
        }

        // Consentimento: registrar contato de alguém é tratamento de dado
        // pessoal, e o momento de perguntar é ANTES de gravar, não depois.
        // O agente confirma na conversa; aqui carimbamos quando isso ocorreu.
        $extra = array_diff_key($parametros, array_flip(['nome', 'email', 'telefone']));

        $pdo = Database::connection();
        $agora = now();

        $pdo->prepare(
            'INSERT INTO leads (conversa_id, agente_id, nome, email, telefone, campos_extra,
                    origem, consentimento_em, criado_em)
             VALUES (:c, :a, :n, :e, :t, :x, :o, :ce, :agora)'
        )->execute([
            'c' => $this->conversaId,
            'a' => $this->agenteId,
            'n' => $nome !== '' ? $nome : null,
            'e' => $email !== '' ? $email : null,
            't' => $telefone !== '' ? preg_replace('/\D+/', '', $telefone) : null,
            'x' => json_ou_nulo($extra),
            'o' => 'assistente',
            'ce' => $agora,
            'agora' => $agora,
        ]);

        $leadId = (int) $pdo->lastInsertId();

        $destino = $this->prepararEntrega($leadId, $agente, $parametros);

        Metrics::log('lead_capturado', (int) ($this->agenteId ?? 0));

        return json_encode([
            'registrado' => true,
            'protocolo' => $leadId,
            'instrucao' => 'Confirme que os dados foram registrados e agradeça. '
                . ($destino === 'descartado'
                    ? 'Diga apenas que o registro foi feito, SEM prometer por qual canal virá o retorno.'
                    : 'Diga que a equipe vai entrar em contato pelo canal informado.'),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Cria a linha de entrega e enfileira, quando há destino configurado.
     *
     * @return string estado da entrega, para o agente saber o que prometer
     */
    private function prepararEntrega(int $leadId, array $agente, array $campos): string
    {
        $destinoId = (int) ($agente['lead_destino_id'] ?? 0);

        // Sem destino externo, o lead vive só no painel — e isso é uma
        // configuração legítima, não uma falha.
        if ($destinoId <= 0) {
            return 'local';
        }

        $stmt = Database::connection()->prepare('SELECT * FROM ferramentas WHERE id = :id AND ativo = 1');
        $stmt->execute(['id' => $destinoId]);
        $ferramenta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ferramenta) {
            return 'local';
        }

        // Destino que exige um campo ausente: estado `descartado`, não
        // `erro`. O RD Station identifica contato por e-mail obrigatório, um
        // CRM próprio pode exigir nome e CPF, e um lead vindo do WhatsApp
        // costuma ter só telefone. Não é falha da integração — é dado que
        // aquele destino não aceita, e marcar como erro faria alguém
        // investigar um problema que não existe.
        $estado = $this->destinoAceita($ferramenta, $campos) ? 'pendente' : 'descartado';

        $agora = now();

        Database::connection()->prepare(
            'INSERT INTO lead_destinos (lead_id, ferramenta_id, status, tentativas,
                    proxima_tentativa_em, erro, idempotencia, criado_em, editado_em)
             VALUES (:l, :f, :s, 0, :quando, :erro, :idem, :agora, :agora)'
        )->execute([
            'l' => $leadId,
            'f' => (int) $ferramenta['id'],
            's' => $estado,
            'quando' => $estado === 'pendente' ? $agora : null,
            'erro' => $estado === 'descartado'
                ? 'O destino exige um campo que este lead não tem.'
                : null,
            // Chave de idempotência: o retry não pode criar dois contatos no
            // CRM. Vai como cabeçalho na entrega.
            'idem' => 'lead-' . $leadId . '-' . bin2hex(random_bytes(4)),
            'agora' => $agora,
        ]);

        if ($estado === 'pendente') {
            Queue::enfileirar('entrega_lead', ['lead_id' => $leadId], 1);
            Queue::cutucarWorker();
        }

        return $estado;
    }

    /**
     * Campos que o destino exige e o lead ainda não tem.
     *
     * Descobre pela configuração da ferramenta de destino em vez de manter
     * uma lista de integrações conhecidas — assim vale para o CRM próprio
     * (que pode exigir nome e CPF), para o RD Station (que exige e-mail) e
     * para qualquer outro que alguém cadastre depois.
     *
     * Devolve a DESCRIÇÃO do campo, não o nome técnico: é texto que o agente
     * vai usar para pedir à pessoa, e "cpf" pedido cru soa mal.
     *
     * @param array<string, mixed> $agente
     * @param array<string, mixed> $parametros
     * @return list<string>
     */
    private function camposFaltando(array $agente, array $parametros): array
    {
        $destinoId = (int) ($agente['lead_destino_id'] ?? 0);

        if ($destinoId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT nome, descricao_llm FROM ferramenta_parametros
             WHERE ferramenta_id = :id AND obrigatorio = 1 ORDER BY ordem, id'
        );
        $stmt->execute(['id' => $destinoId]);

        $faltando = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $nome = (string) $p['nome'];
            $valor = trim((string) ($parametros[$nome] ?? ''));

            if ($valor === '') {
                $faltando[] = trim((string) $p['descricao_llm']) ?: $nome;
            }
        }

        return $faltando;
    }

    /**
     * O destino exige algum campo que este lead não tem?
     *
     * Diferente de `camposFaltando()`: aqui já é tarde para pedir — o lead
     * está sendo gravado. Serve para decidir entre enfileirar a entrega e
     * marcá-la como `descartado`.
     *
     * @param array<string, mixed> $lead campos disponíveis
     */
    private function destinoAceita(array $ferramenta, array $lead): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT nome FROM ferramenta_parametros
             WHERE ferramenta_id = :id AND obrigatorio = 1'
        );
        $stmt->execute(['id' => (int) $ferramenta['id']]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nome) {
            if (trim((string) ($lead[(string) $nome] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function agente(): array
    {
        if ($this->agenteId === null) {
            return [];
        }

        $stmt = Database::connection()->prepare('SELECT * FROM agentes WHERE id = :id');
        $stmt->execute(['id' => $this->agenteId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Entrega um lead ao destino externo. Chamado pelo worker.
     *
     * @throws RuntimeException com o motivo, para o worker decidir retry
     */
    public static function entregar(int $leadId): void
    {
        $pdo = Database::connection();

        // `proxima_tentativa_em` precisa ser respeitado AQUI.
        //
        // O job reenfileirado fica disponível na hora, mas a entrega tem
        // relógio próprio: sem este filtro, as 5 tentativas eram consumidas em
        // segundos e o lead virava dead letter antes de o destino ter chance
        // de voltar. O backoff existe justamente para dar esse tempo — e uma
        // falha transitória de rede se resolve em minutos, não em segundos.
        $stmt = $pdo->prepare(
            'SELECT d.*, f.* , d.id AS destino_id, d.tentativas AS tentativas
             FROM lead_destinos d JOIN ferramentas f ON f.id = d.ferramenta_id
             WHERE d.lead_id = :l AND d.status = \'pendente\'
               AND (d.proxima_tentativa_em IS NULL OR d.proxima_tentativa_em <= :agora)'
        );
        $stmt->execute(['l' => $leadId, 'agora' => now()]);
        $destinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($destinos === []) {
            return;
        }

        $lead = $pdo->prepare('SELECT * FROM leads WHERE id = :id');
        $lead->execute(['id' => $leadId]);
        $dados = $lead->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            throw new RuntimeException("Lead {$leadId} não encontrado.");
        }

        foreach ($destinos as $d) {
            self::entregarUm($d, $dados);
        }
    }

    /**
     * @param array<string, mixed> $destino
     * @param array<string, mixed> $lead
     */
    private static function entregarUm(array $destino, array $lead): void
    {
        $pdo = Database::connection();
        $destinoId = (int) $destino['destino_id'];
        $tentativas = (int) $destino['tentativas'] + 1;

        $parametros = array_filter([
            'nome' => $lead['nome'],
            'email' => $lead['email'],
            'telefone' => $lead['telefone'],
        ] + json_para_array($lead['campos_extra'] ?? null), static fn ($v): bool => $v !== null && $v !== '');

        try {
            // A chave de idempotência vai junto: se a resposta se perder na
            // rede e o retry disparar, o destino reconhece o mesmo contato.
            $ferramenta = $destino;
            $cabecalhos = json_para_array($ferramenta['headers'] ?? null);
            $cabecalhos['Idempotency-Key'] = (string) $destino['idempotencia'];
            $ferramenta['headers'] = json_encode($cabecalhos, JSON_UNESCAPED_UNICODE);

            $resposta = (new HttpTool())->executar($ferramenta, $parametros);

            $pdo->prepare(
                'UPDATE lead_destinos SET status = \'ok\', tentativas = :t, resposta = :r,
                        erro = NULL, editado_em = :agora
                 WHERE id = :id'
            )->execute([
                't' => $tentativas,
                'r' => mb_substr($resposta, 0, 2000),
                'agora' => now(),
                'id' => $destinoId,
            ]);
        } catch (\Throwable $e) {
            // Backoff crescente, e depois de 5 tentativas vira dead letter
            // visível no painel — com botão de reenviar. Falha definitiva não
            // pode sumir em silêncio: é um lead que alguém esperava receber.
            $definitivo = $tentativas >= 5;
            $espera = min(3600, 60 * (2 ** ($tentativas - 1)));

            $pdo->prepare(
                'UPDATE lead_destinos SET status = :s, tentativas = :t, erro = :e,
                        proxima_tentativa_em = :quando, editado_em = :agora
                 WHERE id = :id'
            )->execute([
                's' => $definitivo ? 'erro' : 'pendente',
                't' => $tentativas,
                'e' => mb_substr($e->getMessage(), 0, 1000),
                'quando' => $definitivo ? null : date('Y-m-d H:i:s', time() + $espera),
                'agora' => now(),
                'id' => $destinoId,
            ]);

            if (!$definitivo) {
                // O job também espera: reenfileirar para "agora" faria o
                // worker acordar a entrega antes da hora e ela sair sem fazer
                // nada, gastando um ciclo por vez.
                Queue::enfileirar('entrega_lead', ['lead_id' => (int) $lead['id']], 1, $espera);
            }

            throw new RuntimeException(
                "Entrega do lead {$lead['id']} falhou (tentativa {$tentativas}): " . $e->getMessage()
            );
        }
    }
}
