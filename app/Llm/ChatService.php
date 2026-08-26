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
use SimpleAIman\Atendimento\Roteador;
use SimpleAIman\Rag\FaqBusca;
use SimpleAIman\Tools\ToolRegistry;
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
 * A ordem do turno é: FAQ curada (curto-circuito) → RAG → geração. As
 * ferramentas configuráveis entram na etapa 7, neste mesmo pipeline.
 */
final class ChatService
{
    private const PAPEIS_HISTORICO = 8;

    /** @param array<string, mixed> $agente linha de `agentes` */
    private function __construct(
        private readonly array $agente,
        private ?ProviderFactory $fabrica = null,
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

        // O provedor é resolvido SOB DEMANDA, e não aqui.
        //
        // Um agente em modo roteador não usa provedor nenhum — mas montar a
        // fábrica neste ponto explodia com "atendimento indisponível" antes de
        // o roteador ter chance de responder, sempre que não houvesse provedor
        // ativo. Que é exatamente o caso de quem escolheu o roteador para não
        // precisar de chave de API.
        return new self($agente);
    }

    /**
     * @throws ErroAgente se não houver provedor utilizável
     */
    private function fabrica(): ProviderFactory
    {
        if ($this->fabrica === null) {
            $this->fabrica = $this->agente['provedor_id']
                ? ProviderFactory::porId((int) $this->agente['provedor_id'])
                : ProviderFactory::ativo();
        }

        return $this->fabrica;
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

        // O id é lido AQUI, colado no INSERT.
        //
        // `lastInsertId()` devolve a última linha inserida na CONEXÃO, não na
        // tabela. Com o `Metrics::log()` no meio, na primeira conversa do dia
        // — quando a métrica ainda não tem linha e precisa inserir — o retorno
        // virava o id da `metricas`. Toda mensagem seguinte apontava para uma
        // conversa que não existe, e o sintoma era "FOREIGN KEY constraint
        // failed" uma vez por dia, sem padrão aparente.
        $id = (int) $pdo->lastInsertId();

        Metrics::log('conversa_iniciada', (int) $this->agente['id']);

        return $id;
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

        // Mesma armadilha do `conversa()`: ler o id antes de qualquer outra
        // escrita. Aqui o estrago seria diferente e mais silencioso — este id
        // vai para `gravarFontes()`, então as citações grudariam na mensagem
        // errada em vez de estourar.
        $id = (int) $pdo->lastInsertId();

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

        return $id;
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

        foreach ($this->normalizarSequencia($linhas) as $l) {
            $mensagens[] = $l['papel'] === 'usuario'
                ? new UserMessage($l['conteudo'])
                : new AssistantMessage($l['conteudo']);
        }

        return $mensagens;
    }

    /**
     * Garante alternância estrita usuário → assistente, começando por usuário.
     *
     * O Gemini recusa qualquer outra sequência com
     * "Invalid message sequence at position N". Duas situações quebram isso
     * naturalmente, e as duas aconteceram em uso real:
     *
     *  1. **Turno que falhou.** A pergunta do usuário é gravada, mas nenhuma
     *     resposta de bot — só uma mensagem de `sistema`, que fica fora do
     *     histórico. Sobram duas mensagens de usuário seguidas. Sem esta
     *     normalização, a falha ENVENENA a conversa: todo turno seguinte
     *     também falha, e o visitante vê a mensagem genérica para sempre.
     *
     *  2. **Janela deslizante.** Pegar as N últimas mensagens pode começar
     *     numa resposta do bot, e position 0 já é inválida.
     *
     * Mensagens consecutivas do mesmo papel são unidas em vez de descartadas:
     * quando o usuário pergunta algo, a resposta falha e ele escreve "sim", o
     * "sim" só faz sentido junto da pergunta anterior.
     *
     * @param list<array<string, mixed>> $linhas em ordem cronológica
     * @return list<array{papel: string, conteudo: string}>
     */
    private function normalizarSequencia(array $linhas): array
    {
        $normalizadas = [];

        foreach ($linhas as $l) {
            // Atendente fala no lugar do assistente: do ponto de vista do
            // modelo, foi "o atendimento" que respondeu.
            $papel = $l['autor_tipo'] === 'usuario' ? 'usuario' : 'assistente';
            $conteudo = trim((string) $l['conteudo']);

            if ($conteudo === '') {
                continue;
            }

            // Nada antes da primeira fala do usuário.
            if ($normalizadas === [] && $papel !== 'usuario') {
                continue;
            }

            $ultima = count($normalizadas) - 1;

            if ($ultima >= 0 && $normalizadas[$ultima]['papel'] === $papel) {
                $normalizadas[$ultima]['conteudo'] .= "\n\n" . $conteudo;
                continue;
            }

            $normalizadas[] = ['papel' => $papel, 'conteudo' => $conteudo];
        }

        return $normalizadas;
    }

    /** @param list<array<string, mixed>> $trechos */
    private function montarAgente(array $trechos, int $conversaId, bool $comFerramentas = true): Agent
    {
        $provider = $this->fabrica()->chat([
            'modelo' => (string) ($this->agente['modelo'] ?? ''),
            'max_tokens' => (int) $this->agente['max_tokens'],
            'temperatura' => (float) $this->agente['temperatura'],
            'reasoning_effort' => (string) $this->agente['reasoning_effort'],
        ]);

        $agenteId = (int) $this->agente['id'];

        // O agente só pode oferecer encaminhamento se tiver a ferramenta
        // ligada. É o que impede a promessa falsa: enquanto a capacidade nao
        // existir de fato, o prompt proibe menciona-la.
        $config = $this->agente;
        $config['handoff_disponivel'] = ToolRegistry::temHandoff($agenteId);

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions((new PromptBuilder())->montar($config, $trechos));

        // Sem ferramentas é o modo do copiloto: ele responde ao ATENDENTE, e
        // não pode transferir conversa, abrir chamado nem capturar lead em
        // nome dele. Uma pergunta de consulta não deve ter efeito colateral —
        // quem age é a pessoa, depois de ler.
        if ($comFerramentas) {
            $ferramentas = (new ToolRegistry($agenteId, $conversaId))->paraAgente();

            if ($ferramentas !== []) {
                $agent->addTool($ferramentas);
            }
        }

        return $agent;
    }

    /**
     * Tenta responder direto pela FAQ curada.
     *
     * Devolve o texto quando o casamento é confiável o bastante; `null`
     * quando não é, e aí o turno segue pelo caminho normal — a FAQ não some,
     * ela apenas deixa de ser resposta e vira contexto.
     *
     * Vale a chamada de embedding extra? Vale, e por dois motivos: ela
     * substitui a geração inteira (que é mais cara e mais lenta), e o
     * resultado é texto escrito por um humano, que não escorrega.
     */
    private function faqDireta(int $conversaId, string $pergunta, float $inicio): ?string
    {
        if (empty($this->agente['usa_faq'])) {
            return null;
        }

        $vetor = $this->vetorDaPergunta($pergunta);

        if ($vetor === null) {
            return null;
        }

        try {
            $achada = (new FaqBusca())->melhor(
                $pergunta,
                $vetor,
                (float) ($this->agente['limiar_faq_direto'] ?? 0.78),
            );
        } catch (Throwable $e) {
            // Falha aqui não pode custar o turno: segue pelo RAG.
            error_log('[simpleAIman] busca na FAQ falhou: ' . $e->getMessage());

            return null;
        }

        if ($achada === null || !$achada['direto']) {
            $this->faqCandidata = $achada;

            return null;
        }

        $id = $this->gravarMensagem(
            $conversaId,
            'bot',
            $achada['resposta'],
            null,
            (int) ((microtime(true) - $inicio) * 1000),
        );

        Database::connection()->prepare(
            'INSERT INTO mensagem_fontes (mensagem_id, tipo, referencia_id, score)
             VALUES (:msg, \'faq\', :ref, :score)'
        )->execute(['msg' => $id, 'ref' => $achada['faq_id'], 'score' => $achada['score']]);

        $this->ultimasFontes = [[
            'numero' => 1,
            'rotulo' => 'Pergunta frequente: ' . $achada['pergunta'],
            'chunk_id' => $achada['faq_id'],
        ]];

        Metrics::log('faq_direta', (int) $this->agente['id']);

        return $achada['resposta'];
    }

    /**
     * FAQ que casou, mas abaixo do limiar de resposta direta.
     *
     * @var array<string, mixed>|null
     */
    private ?array $faqCandidata = null;

    /**
     * Vetor da pergunta do turno, calculado UMA vez.
     *
     * A FAQ e o RAG precisam do mesmo vetor, da mesma frase, com o mesmo
     * task_type. Embeddar duas vezes custava 589 ms por turno — medido, e
     * mais que o dobro do tempo somado de toda a busca. A chamada de rede é
     * a parte cara de tudo aqui; repeti-la é o erro mais fácil de cometer.
     *
     * @var list<float>|null
     */
    private ?array $vetorPergunta = null;

    /**
     * @return list<float>|null null quando o embedding falha — o turno segue
     *                          sem busca, e os guardrails cuidam do resto.
     */
    private function vetorDaPergunta(string $pergunta): ?array
    {
        if ($this->vetorPergunta !== null) {
            return $this->vetorPergunta;
        }

        try {
            $this->vetorPergunta = $this->fabrica()
                ->embeddings(ProviderFactory::TAREFA_CONSULTAR)
                ->embedText($pergunta);
        } catch (Throwable $e) {
            error_log('[simpleAIman] embedding da pergunta falhou: ' . $e->getMessage());

            return null;
        }

        return $this->vetorPergunta;
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
                true,
                true,
                // Reaproveita o vetor já calculado para a FAQ.
                $this->vetorDaPergunta($pergunta),
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

        // FAQ com casamento de alta confiança encerra o turno aqui: o texto
        // curado sai palavra por palavra, sem geração nenhuma.
        $curada = $this->faqDireta($conversaId, $pergunta, $inicio);

        if ($curada !== null) {
            return $curada;
        }

        $trechos = $this->recuperar($pergunta);

        try {
            $mensagens = [...$this->historico($conversaId)];

            $resposta = $this->montarAgente($trechos, $conversaId)->chat($mensagens)->getMessage();
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
     * Consulta do ATENDENTE ao assistente, em privado.
     *
     * Parece um turno de conversa, mas é outra coisa, e as diferenças são
     * todas deliberadas:
     *
     * - **Não grava a pergunta como `usuario`, nem a resposta como `bot`.**
     *   Fosse assim, os dois apareceriam para o visitante e entrariam no
     *   histórico do modelo, que passaria a achar que ele mesmo disse aquilo.
     * - **Sem ferramentas.** O copiloto não transfere conversa, não abre
     *   chamado e não captura lead em nome do atendente. Consulta não pode ter
     *   efeito colateral: quem age é a pessoa, depois de ler.
     * - **Usa o histórico da conversa** como contexto, que é justamente o que
     *   torna a resposta útil — o atendente pergunta "e sobre isso?" sem
     *   precisar recontar o caso.
     *
     * O par pergunta/resposta fica registrado com `autor_tipo = 'copiloto'`:
     * invisível ao visitante por construção (o filtro dele é lista de
     * permissão), fora do histórico do modelo (que só lê usuario/bot/atendente)
     * e disponível para quem assumir a conversa depois.
     *
     * @return array{texto: string, fontes: list<array<string, mixed>>}
     * @throws ErroAgente
     */
    public function consultar(int $conversaId, string $pergunta, int $atendenteId): array
    {
        $inicio = microtime(true);
        $trechos = $this->recuperar($pergunta);

        // O tom do agente é feito para o visitante — inclusive o sotaque, se
        // alguém configurou um. Quem lê aqui é colega de trabalho com um
        // atendimento aberto na tela: quer o fato, não a conversa.
        $config = $this->agente;
        $config['system_prompt'] = trim(
            "Você está ajudando um ATENDENTE HUMANO que está no meio de um atendimento. "
            . "Responda de forma direta e factual, em poucas linhas, sem saudação e sem "
            . "floreio. Se os documentos não trouxerem a resposta, diga isso claramente em "
            . "vez de preencher a lacuna. Nunca invente valor, prazo, telefone ou e-mail.

"
            . (string) ($this->agente['system_prompt'] ?? '')
        );

        try {
            // A conversa vai como CONTEXTO nas instruções, e a pergunta do
            // atendente é o único turno.
            //
            // Emendar a pergunta no fim do histórico parecia natural e quebrava:
            // o histórico termina numa fala do visitante (que o atendente
            // assumiu sem responder), e duas mensagens de usuário seguidas
            // fazem o Gemini recusar com "invalid message sequence". Além de
            // errado no sentido — quem pergunta aqui não é o visitante.
            $config['system_prompt'] .= "\n\nCONVERSA EM ANDAMENTO com o visitante, para contexto:\n"
                . $this->transcricao($conversaId);

            $mensagens = [new UserMessage($pergunta)];

            $provider = $this->fabrica()->chat([
                'modelo' => (string) ($this->agente['modelo'] ?? ''),
                'max_tokens' => (int) $this->agente['max_tokens'],
                'temperatura' => (float) $this->agente['temperatura'],
                'reasoning_effort' => (string) $this->agente['reasoning_effort'],
            ]);

            $agent = Agent::make()
                ->setAiProvider($provider)
                ->setInstructions((new PromptBuilder())->montar($config, $trechos));

            $texto = trim((string) $agent->chat($mensagens)->getMessage()->getContent());

            if ($texto === '') {
                throw new ErroAgente('resposta_vazia', 'Provedor respondeu 200 com conteúdo vazio.');
            }
        } catch (ErroAgente $e) {
            $this->registrarFalha($conversaId, $e);
            throw $e;
        } catch (Throwable $e) {
            $erro = ErroAgente::deProvedor($e, 'copiloto');
            $this->registrarFalha($conversaId, $erro);
            throw $erro;
        }

        $this->gravarMensagem($conversaId, 'copiloto', '❓ ' . $pergunta, $atendenteId);
        $id = $this->gravarMensagem(
            $conversaId,
            'copiloto',
            $texto,
            $atendenteId,
            (int) ((microtime(true) - $inicio) * 1000)
        );

        $this->gravarFontes($id, $trechos);

        return [
            'texto' => $texto,
            'fontes' => (new PromptBuilder())->fontesCitadas($texto, $trechos),
        ];
    }

    /**
     * Conversa recente em texto corrido, para servir de contexto.
     *
     * Só o que foi dito de fato — avisos, notas e consultas anteriores ficam de
     * fora: são ruído para quem precisa entender o caso, e nota interna de um
     * colega não é o assunto do visitante.
     */
    private function transcricao(int $conversaId, int $limite = 12): string
    {
        $stmt = Database::connection()->prepare(
            "SELECT autor_tipo, conteudo FROM mensagens
              WHERE conversa_id = :c AND autor_tipo IN ('usuario', 'bot', 'atendente')
              ORDER BY id DESC LIMIT :l"
        );
        $stmt->bindValue('c', $conversaId, PDO::PARAM_INT);
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

        return $linhas === [] ? '(a conversa ainda não começou)' : implode("\n", $linhas);
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

        // Agente em modo roteador não toca no provedor: menu de setores,
        // contato e fila. Sai antes da FAQ e do RAG, que também não fazem
        // sentido aqui — sem chave de API, nada disso existe.
        if (($this->agente['modo'] ?? 'ia') === 'roteador') {
            $texto = Roteador::responder($conversaId, $pergunta);
            $this->gravarMensagem($conversaId, 'bot', $texto, null, (int) ((microtime(true) - $inicio) * 1000));
            yield $texto;

            return;
        }

        // Curto-circuito da FAQ também no streaming: o texto curado sai de
        // uma vez, sem geração. Não é streaming de verdade, mas responder em
        // 600 ms inteiro é melhor que streamar uma resposta pior em 2 s.
        $curada = $this->faqDireta($conversaId, $pergunta, $inicio);

        if ($curada !== null) {
            yield $curada;
            return;
        }

        $trechos = $this->recuperar($pergunta);
        $texto = '';

        try {
            $mensagens = [...$this->historico($conversaId)];
            $handler = $this->montarAgente($trechos, $conversaId)->stream($mensagens);

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

            if (($alternativa = $this->degradarParaMenu($conversaId, $pergunta, $texto)) !== null) {
                yield $alternativa;

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            $erro = ErroAgente::deProvedor($e, 'streaming');
            $this->registrarFalha($conversaId, $erro);

            if (($alternativa = $this->degradarParaMenu($conversaId, $pergunta, $texto)) !== null) {
                yield $alternativa;

                return;
            }

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
     * Provedor fora do ar: em vez de morrer, cai no menu de setores.
     *
     * Sem isto, uma cota estourada (429) ou instabilidade encerra a conversa
     * com "não consegui responder agora" — e a pessoa fica sem nada,
     * justamente quando mais precisava de um caminho. Com o menu, ela ainda
     * chega a quem resolve, mesmo que a instalação não tenha atendente nenhum:
     * telefone, e-mail e horário são dados nossos, não dependem de IA.
     *
     * Devolve `null` quando não há menu possível (nenhum setor cadastrado) ou
     * quando parte da resposta já saiu — nesse caso o visitante já está lendo
     * um texto, e emendar um menu por cima seria mais confuso que o erro.
     */
    private function degradarParaMenu(int $conversaId, string $pergunta, string $jaEnviado): ?string
    {
        if (trim($jaEnviado) !== '' || !Roteador::temMenu()) {
            return null;
        }

        $texto = Roteador::responder(
            $conversaId,
            $pergunta,
            'Estou com uma instabilidade e não consigo consultar os documentos agora. '
                . 'Mas posso te encaminhar:'
        );

        $this->gravarMensagem($conversaId, 'bot', $texto);

        return $texto;
    }

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
