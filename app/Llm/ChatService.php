<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

use Database;
use Generator;
use Metrics;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use SimpleAIman\Rag\Retriever;
use Throwable;

/**
 * Orquestra um turno de conversa.
 *
 * Desacoplado do transporte de propósito: `responder()` devolve o texto
 * inteiro e `stream()` devolve um gerador, mas ambos passam pelo MESMO
 * caminho de montagem, persistência e tratamento de erro. É isso que permite
 * o widget web usar SSE e o WhatsApp usar resposta completa sem reescrever a
 * orquestração — ver ARQUITETURA.md §5.
 *
 * Nesta etapa ainda não há RAG nem ferramentas configuráveis: entram nas
 * etapas 5 e 7, dentro deste mesmo pipeline.
 */
final class ChatService
{
    private const PAPEIS_HISTORICO = 8;

    /** @param array<string, mixed> $agente linha de `agentes` */
    private function __construct(
        private readonly array $agente,
        private readonly ProviderFactory $fabrica,
    ) {
    }

    public static function paraAgente(int $agenteId): self
    {
        $stmt = Database::connection()->prepare('SELECT * FROM agentes WHERE id = :id AND ativo = 1');
        $stmt->execute(['id' => $agenteId]);
        $agente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$agente) {
            throw new ErroAgente('configuracao', "Agente id={$agenteId} não encontrado ou inativo.");
        }

        $fabrica = $agente['provedor_id']
            ? ProviderFactory::porId((int) $agente['provedor_id'])
            : ProviderFactory::ativo();

        return new self($agente, $fabrica);
    }

    /** @return array<string, mixed> */
    public function agente(): array
    {
        return $this->agente;
    }

    /**
     * Abre ou recupera a conversa de uma sessão de canal.
     */
    public function conversa(?int $canalId, string $externoId, ?string $ip = null): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT id FROM conversas WHERE agente_id = :agente AND externo_id = :externo ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['agente' => $this->agente['id'], 'externo' => $externoId]);
        $id = $stmt->fetchColumn();

        if ($id) {
            return (int) $id;
        }

        $agora = now();
        $pdo->prepare(
            'INSERT INTO conversas (canal_id, agente_id, externo_id, modo, ip, criado_em, editado_em)
             VALUES (:canal, :agente, :externo, :modo, :ip, :agora, :agora)'
        )->execute([
            'canal' => $canalId,
            'agente' => $this->agente['id'],
            'externo' => $externoId,
            'modo' => 'bot',
            'ip' => $ip,
            'agora' => $agora,
        ]);

        Metrics::log('conversa_iniciada', (int) $this->agente['id']);

        return (int) $pdo->lastInsertId();
    }

    /**
     * O bot deve responder nesta conversa?
     *
     * Com a conversa em modo `humano`, a mensagem do visitante é gravada mas
     * NÃO vai para a LLM. É um `if`, mas é a feature inteira do handoff Nível
     * 2 — sem ele o bot fala por cima do atendente.
     */
    public function botDeveResponder(int $conversaId): bool
    {
        $stmt = Database::connection()->prepare('SELECT modo FROM conversas WHERE id = :id');
        $stmt->execute(['id' => $conversaId]);

        return in_array((string) $stmt->fetchColumn(), ['bot', ''], true);
    }

    public function gravarMensagem(
        int $conversaId,
        string $autorTipo,
        string $conteudo,
        ?int $autorId = null,
        ?int $latenciaMs = null,
    ): int {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO mensagens (conversa_id, autor_tipo, autor_id, conteudo, latencia_ms, criado_em)
             VALUES (:conversa, :autor_tipo, :autor_id, :conteudo, :latencia, :agora)'
        )->execute([
            'conversa' => $conversaId,
            'autor_tipo' => $autorTipo,
            'autor_id' => $autorId,
            'conteudo' => $conteudo,
            'latencia' => $latenciaMs,
            'agora' => now(),
        ]);

        $campos = ['editado_em' => now(), 'id' => $conversaId];
        $sql = 'UPDATE conversas SET editado_em = :editado_em';

        // Janela de 24h da Meta: fora dela só template aprovado. Registrar
        // agora evita descobrir isso quando o WhatsApp entrar.
        if ($autorTipo === 'usuario') {
            $sql .= ', ultima_msg_usuario_em = :ultima';
            $campos['ultima'] = now();
        }

        $pdo->prepare($sql . ' WHERE id = :id')->execute($campos);

        Metrics::log('mensagem_enviada', (int) $this->agente['id']);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Histórico recente convertido para as mensagens do Neuron.
     *
     * Mensagens do ATENDENTE entram como assistente — do ponto de vista do
     * modelo, foi "o atendimento" que falou. Sem `autor_tipo` no schema não
     * daria para fazer essa distinção, e o bot passaria a se contradizer ao
     * retomar uma conversa que passou por humano.
     *
     * @return list<UserMessage|AssistantMessage>
     */
    private function historico(int $conversaId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT autor_tipo, conteudo FROM mensagens
             WHERE conversa_id = :id AND autor_tipo IN (\'usuario\', \'bot\', \'atendente\')
             ORDER BY id DESC LIMIT :limite'
        );
        $stmt->bindValue('id', $conversaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', self::PAPEIS_HISTORICO, PDO::PARAM_INT);
        $stmt->execute();

        $linhas = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        $mensagens = [];

        foreach ($linhas as $l) {
            $mensagens[] = $l['autor_tipo'] === 'usuario'
                ? new UserMessage((string) $l['conteudo'])
                : new AssistantMessage((string) $l['conteudo']);
        }

        return $mensagens;
    }

    /** @param list<array<string, mixed>> $trechos */
    private function montarAgente(array $trechos = []): Agent
    {
        $provider = $this->fabrica->chat([
            'max_tokens' => (int) $this->agente['max_tokens'],
            'temperatura' => (float) $this->agente['temperatura'],
            'reasoning_effort' => (string) $this->agente['reasoning_effort'],
        ]);

        return Agent::make()
            ->setAiProvider($provider)
            ->setInstructions((new PromptBuilder())->montar($this->agente, $trechos));
    }

    /**
     * Recupera os trechos da pergunta, se o agente usar RAG.
     *
     * Falha de recuperação NÃO derruba o turno: o agente responde sem
     * contexto, e os guardrails o obrigam a admitir que não encontrou a
     * informação. É melhor que devolver erro a quem só queria uma resposta —
     * e a falha fica no log para o admin.
     *
     * @return list<array<string, mixed>>
     */
    private function recuperar(string $pergunta): array
    {
        if (empty($this->agente['usa_rag'])) {
            return [];
        }

        $bases = array_map('intval', Database::connection()->query(
            'SELECT b.id FROM bases b
             JOIN agente_bases ab ON ab.base_id = b.id
             WHERE ab.agente_id = ' . (int) $this->agente['id'] . ' AND b.ativo = 1'
        )->fetchAll(PDO::FETCH_COLUMN));

        if ($bases === []) {
            return [];
        }

        try {
            $retriever = new Retriever();

            $trechos = $retriever->buscar(
                $pergunta,
                $bases,
                (int) $this->agente['top_k'],
                (float) $this->agente['limiar_similaridade'],
            );

            $this->tempoBusca = $retriever->tempos;

            return $trechos;
        } catch (Throwable $e) {
            error_log('[simpleAIman] recuperação falhou: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Liga a resposta aos trechos que a embasaram.
     *
     * Grava TODOS os trechos recuperados, não apenas os citados: saber o que
     * o agente tinha em mãos e ignorou é o que permite diagnosticar uma
     * resposta ruim. Só o citado contaria metade da história.
     *
     * @param list<array<string, mixed>> $trechos
     */
    private function gravarFontes(int $mensagemId, array $trechos): void
    {
        if ($trechos === []) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO mensagem_fontes (mensagem_id, tipo, referencia_id, score)
             VALUES (:msg, :tipo, :ref, :score)'
        );

        foreach ($trechos as $t) {
            $stmt->execute([
                'msg' => $mensagemId,
                'tipo' => 'chunk',
                'ref' => (int) $t['chunk_id'],
                'score' => $t['score_vetorial'] ?? $t['score'],
            ]);
        }
    }

    /**
     * Consumo do turno.
     *
     * Os tokens de raciocínio somam em `tokens_out` porque é assim que se
     * paga: o Gemini os reporta em `thoughtsTokenCount`, separado de
     * `candidatesTokenCount`, e o total do provedor NÃO os inclui. Somar é o
     * que faz o número bater com a fatura.
     */
    private function gravarUso(int $mensagemId, ?object $resposta): void
    {
        $uso = $resposta !== null && method_exists($resposta, 'getUsage') ? $resposta->getUsage() : null;

        if ($uso === null) {
            return;
        }

        Database::connection()->prepare(
            'UPDATE mensagens SET tokens_in = :entrada, tokens_out = :saida WHERE id = :id'
        )->execute([
            'entrada' => $uso->inputTokens,
            'saida' => $uso->outputTokens + $uso->reasoningTokens,
            'id' => $mensagemId,
        ]);
    }

    /**
     * Turno completo (WhatsApp, API). Devolve o texto da resposta.
     *
     * @throws ErroAgente sempre — nunca uma exceção crua do fornecedor, que
     *                    traria nome de modelo e status HTTP para cima.
     */
    public function responder(int $conversaId, string $pergunta): string
    {
        $inicio = microtime(true);
        $this->gravarMensagem($conversaId, 'usuario', $pergunta);

        $trechos = $this->recuperar($pergunta);

        try {
            $mensagens = [...$this->historico($conversaId)];

            $resposta = $this->montarAgente($trechos)->chat($mensagens)->getMessage();
            $texto = trim((string) $resposta->getContent());

            if ($texto === '') {
                throw new ErroAgente('resposta_vazia', 'Provedor respondeu 200 com conteúdo vazio.');
            }
        } catch (ErroAgente $e) {
            $this->registrarFalha($conversaId, $e);
            throw $e;
        } catch (Throwable $e) {
            $erro = ErroAgente::deProvedor($e, 'chat');
            $this->registrarFalha($conversaId, $erro);
            throw $erro;
        }

        $id = $this->gravarMensagem($conversaId, 'bot', $texto, null, (int) ((microtime(true) - $inicio) * 1000));

        $this->gravarFontes($id, $trechos);
        $this->gravarUso($id, $resposta);

        $this->ultimasFontes = (new PromptBuilder())->fontesCitadas($texto, $trechos);

        return $texto;
    }

    /**
     * Turno em streaming (widget web). Emite pedaços de texto.
     *
     * A resposta só é gravada no fim, quando o texto está completo — gravar
     * pedaço a pedaço encheria a tabela e ainda deixaria mensagem truncada
     * caso o visitante fechasse a aba no meio.
     *
     * @return Generator<int, string>
     * @throws ErroAgente
     */
    public function stream(int $conversaId, string $pergunta): Generator
    {
        $inicio = microtime(true);
        $this->gravarMensagem($conversaId, 'usuario', $pergunta);

        $trechos = $this->recuperar($pergunta);
        $texto = '';

        try {
            $mensagens = [...$this->historico($conversaId)];
            $handler = $this->montarAgente($trechos)->stream($mensagens);

            foreach ($handler->events() as $evento) {
                $pedaco = match (true) {
                    is_string($evento) => $evento,
                    is_object($evento) && property_exists($evento, 'content') && is_string($evento->content) => $evento->content,
                    default => null,
                };

                if ($pedaco === null || $pedaco === '') {
                    continue;
                }

                $texto .= $pedaco;
                yield $pedaco;
            }

            if (trim($texto) === '') {
                throw new ErroAgente('resposta_vazia', 'Stream terminou sem conteúdo.');
            }
        } catch (ErroAgente $e) {
            $this->registrarFalha($conversaId, $e);
            throw $e;
        } catch (Throwable $e) {
            $erro = ErroAgente::deProvedor($e, 'streaming');
            $this->registrarFalha($conversaId, $erro);
            throw $erro;
        }

        $id = $this->gravarMensagem($conversaId, 'bot', trim($texto), null, (int) ((microtime(true) - $inicio) * 1000));

        $this->gravarFontes($id, $trechos);

        // No streaming o consumo vem no evento final do handler, não numa
        // mensagem de retorno — por isso não há gravarUso() aqui. Os tokens
        // do turno em streaming ficam nulos até haver um gancho confiável.
        $this->ultimasFontes = (new PromptBuilder())->fontesCitadas($texto, $trechos);
    }

    /**
     * Fontes citadas na última resposta, para o canal exibir sob a mensagem.
     *
     * @var list<array{numero: int, rotulo: string, chunk_id: int}>
     */
    public array $ultimasFontes = [];

    /** @var array<string, float> tempos da última recuperação, em ms */
    public array $tempoBusca = [];

    /**
     * A falha vira mensagem de SISTEMA na conversa, não de bot.
     *
     * Assim o histórico mostra que houve uma falha (e o atendente que assumir
     * depois entende o buraco), sem que o texto técnico entre no contexto do
     * modelo na próxima rodada nem apareça como fala do assistente.
     */
    private function registrarFalha(int $conversaId, ErroAgente $e): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO mensagens (conversa_id, autor_tipo, conteudo, criado_em)
                 VALUES (:conversa, \'sistema\', :conteudo, :agora)'
            )->execute([
                'conversa' => $conversaId,
                'conteudo' => $e->paraLog(),
                'agora' => now(),
            ]);
        } catch (Throwable) {
            // Falhar ao registrar a falha não pode derrubar o atendimento.
        }
    }
}
