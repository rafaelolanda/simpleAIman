<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

use Database;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use PDO;

/**
 * Monta o provider do Neuron a partir de uma linha da tabela `provedores`.
 *
 * Todo o conhecimento sobre "como se fala com cada fornecedor" mora aqui. O
 * resto da aplicação recebe uma AIProviderInterface e não sabe de quem é.
 *
 * A CHAVE nunca vem do banco: `provedores.auth_ref` guarda o NOME da variável
 * do .env, e quem resolve é env_secret(). Chave no banco vazaria em backup,
 * em export e no log de consulta.
 */
final class ProviderFactory
{
    /** Tarefas de embedding. Usar a errada custa recall de graça. */
    public const TAREFA_INDEXAR = 'RETRIEVAL_DOCUMENT';
    public const TAREFA_CONSULTAR = 'RETRIEVAL_QUERY';

    /**
     * @param array<string, mixed> $provedor linha de `provedores`
     */
    public function __construct(private readonly array $provedor)
    {
    }

    public static function porId(int $id): self
    {
        $stmt = Database::connection()->prepare('SELECT * FROM provedores WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            throw new ErroAgente('configuracao', "Provedor id={$id} não encontrado.");
        }

        return new self($linha);
    }

    /**
     * O provedor de chat que um AGENTE escolheu — só se estiver ativo.
     *
     * `porId()` não olha `ativo` de propósito: o teste de provedor no painel e
     * o bin/testar-provedor.php precisam exercitar um provedor antes de ligá-lo.
     * Atender é outra coisa. Até 11/09/2026 o agente usava o provedor dele
     * pelo `porId()`, e inativar o provedor não desligava nada: o copiloto do
     * painel seguiu respondendo com todos os provedores inativos.
     */
    public static function doAgente(int $id): self
    {
        $stmt = Database::connection()->prepare('SELECT * FROM provedores WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            throw new ErroAgente('configuracao', "Provedor id={$id} não encontrado.");
        }

        if ((int) $linha['ativo'] !== 1) {
            throw new ErroAgente('configuracao', "O provedor \"{$linha['nome']}\" do agente está inativo.");
        }

        if (($linha['papel'] ?? 'ambos') === 'embedding') {
            throw new ErroAgente('configuracao', "O provedor \"{$linha['nome']}\" do agente é somente de embedding.");
        }

        return new self($linha);
    }

    /**
     * O provedor de embedding que uma BASE escolheu — só se estiver ativo.
     *
     * Mesmo raciocínio de `doAgente()`: até 11/09/2026 a indexação e a busca
     * pegavam o provedor da base por `porId()`, e inativá-lo não desligava
     * nada. Só a base que herdava o padrão respeitava o `ativo`.
     */
    public static function embeddingAtivo(int $id): self
    {
        $stmt = Database::connection()->prepare('SELECT * FROM provedores WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            throw new ErroAgente('configuracao', "Provedor id={$id} não encontrado.");
        }

        if ((int) $linha['ativo'] !== 1) {
            throw new ErroAgente('configuracao', "O provedor de embedding \"{$linha['nome']}\" da base está inativo.");
        }

        if (($linha['papel'] ?? 'ambos') === 'chat') {
            throw new ErroAgente('configuracao', "O provedor \"{$linha['nome']}\" da base é somente de chat e não gera vetores.");
        }

        return new self($linha);
    }

    /**
     * Provedor padrao de CHAT.
     *
     * Usado quando o agente nao define o dele (`agentes.provedor_id`).
     */
    public static function padraoChat(): self
    {
        return self::padrao('provedor_chat_padrao_id', ['chat', 'ambos'], 'de chat');
    }

    /**
     * Provedor padrao de EMBEDDING.
     *
     * Usado pelo indice da FAQ, pelo vetor da pergunta e por base que nao
     * define o proprio (`bases.provedor_embedding_id`).
     *
     * Este e o mais delicado dos dois. O vetor da pergunta e comparado tanto
     * com o indice da FAQ quanto com o das bases, e vetor so e comparavel com
     * vetor do MESMO modelo. Se cada ponta usar um provedor diferente, nada
     * falha: a similaridade vira ruido e o sistema recupera o trecho errado em
     * silencio. Por isso o padrao e explicito e unico, em vez de sair de um
     * `ORDER BY id` como saia antes.
     */
    public static function padraoEmbedding(): self
    {
        return self::padrao('provedor_embedding_padrao_id', ['embedding', 'ambos'], 'de embedding');
    }

    /**
     * O padrao gravado em `config`; se estiver vazio, o primeiro ativo com o
     * papel certo.
     *
     * O fallback existe para a instalacao que ainda nao passou pela tela, e
     * NAO e o caminho normal: a mensagem de erro manda para a tela justamente
     * porque escolher isso e decisao de quem administra, nao do banco.
     *
     * @param list<string> $papeis
     */
    private static function padrao(string $coluna, array $papeis, string $rotulo): self
    {
        $pdo = Database::connection();
        $lista = "'" . implode("','", $papeis) . "'";

        $id = (int) ($pdo->query("SELECT {$coluna} FROM config WHERE id = 1")->fetchColumn() ?: 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM provedores WHERE id = :id AND ativo = 1 AND papel IN ({$lista})");
            $stmt->execute(['id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($linha) {
                return new self($linha);
            }
        }

        $linha = $pdo
            ->query("SELECT * FROM provedores WHERE ativo = 1 AND papel IN ({$lista}) ORDER BY id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            throw new ErroAgente(
                'configuracao',
                "Nenhum provedor {$rotulo} ativo. Defina o padrao em Provedores, no painel."
            );
        }

        return new self($linha);
    }

    /**
     * Para onde a rede vai, em cada universo.
     *
     * Serve ao diagnóstico de infraestrutura, que mede a conexão até o
     * fornecedor separada do tempo da API. Espelha a resolução real de
     * `chat()` e `embeddings()`, inclusive onde elas ignoram o endereço
     * cadastrado: se as duas divergirem, o diagnóstico mede um lugar e o
     * sistema conversa com outro.
     *
     * @return array{host: string, porta: int, tls: bool}|null
     */
    public function destino(string $universo = 'chat'): ?array
    {
        if ($universo === 'embedding') {
            $driver = (string) (($this->provedor['driver_embedding'] ?? '') ?: ($this->provedor['driver'] ?? 'openai'));

            // Só o Ollama recebe o endereço de embedding em `embeddings()`;
            // Gemini e OpenAI usam o padrão do provider do Neuron.
            $base = $driver === 'ollama'
                ? (trim((string) ($this->provedor['base_url_embedding'] ?? '')) ?: 'http://localhost:11434')
                : match ($driver) {
                    'gemini', 'gemini_nativo' => 'https://generativelanguage.googleapis.com',
                    default => 'https://api.openai.com',
                };
        } else {
            $driver = (string) ($this->provedor['driver'] ?? 'openai');
            $cadastrado = trim((string) ($this->provedor['base_url'] ?? ''));

            // A Anthropic não recebe endereço em `chat()`; os demais recebem.
            $base = match (true) {
                $driver === 'anthropic' => 'https://api.anthropic.com',
                $cadastrado !== '' => $cadastrado,
                $driver === 'gemini' => 'https://generativelanguage.googleapis.com',
                $driver === 'ollama' => 'http://localhost:11434',
                default => 'https://api.openai.com',
            };
        }

        $partes = parse_url($base);

        if (!is_array($partes) || empty($partes['host'])) {
            return null;
        }

        $tls = ($partes['scheme'] ?? 'https') === 'https';

        return [
            'host' => (string) $partes['host'],
            'porta' => (int) ($partes['port'] ?? ($tls ? 443 : 80)),
            'tls' => $tls,
        ];
    }

    /** chat|embedding|ambos — a que universo esta linha serve. */
    public function id(): int
    {
        return (int) ($this->provedor['id'] ?? 0);
    }

    public function papel(): string
    {
        return (string) ($this->provedor['papel'] ?? 'ambos');
    }

    public function nome(): string
    {
        return (string) $this->provedor['nome'];
    }

    public function modeloChat(): string
    {
        return (string) $this->provedor['modelo_chat'];
    }

    public function modeloEmbedding(): string
    {
        return (string) $this->provedor['modelo_embedding'];
    }

    public function dimensoes(): int
    {
        return (int) $this->provedor['dimensoes'];
    }

    /**
     * Cliente HTTP com curl FORÇADO, inclusive em streaming.
     *
     * O Guzzle, por padrão, decide o handler assim: requisições normais vão
     * pelo curl, mas requisições com `stream => true` são desviadas para o
     * StreamHandler, que usa `fopen()` sobre HTTPS. Isso acontece sempre que
     * `allow_url_fopen` está ligado, que é o comum.
     *
     * Duas consequências ruins, e as duas apareceram em uso real:
     *
     *  1. Falha intermitente com "Error creating resource: fopen(...)" — o
     *     wrapper de stream é bem menos robusto que o curl, especialmente no
     *     Windows, e derrubava conversas de forma aparentemente aleatória.
     *  2. Ele **ignora `curl.cainfo`**, então todo o cuidado com o CA bundle
     *     valia só para metade das chamadas.
     *
     * O CurlMultiHandler faz streaming de verdade e usa a configuração de
     * certificado do curl. Como o chat do widget é todo em streaming, isso
     * vale para o caminho principal do produto, não para um caso de borda.
     */
    private function clienteHttp(): HttpClientInterface
    {
        return new GuzzleHttpClient(
            // 40s, não os 60s padrão. Uma falha de rede que trava a conexão
            // consumia o timeout inteiro antes de desistir — medido: 61s num
            // turno que deveria levar 2s. Ninguém espera isso num chat, e o
            // visitante prefere a mensagem de "tente de novo" em 40s do que
            // uma tela parada por um minuto.
            timeout: 40.0,
            connectTimeout: 8.0,
            handler: HandlerStack::create(new CurlMultiHandler()),
        );
    }

    /**
     * Chave resolvida do .env. Erro aqui é de CONFIGURAÇÃO, não de rede — a
     * distinção importa porque o admin precisa saber que o problema está no
     * .env e não no fornecedor.
     */
    private function chave(): string
    {
        $ref = (string) ($this->provedor['auth_ref'] ?? '');

        if ($ref === '') {
            throw new ErroAgente(
                'configuracao',
                "Provedor '{$this->nome()}' não define auth_ref (nome da variável no .env)."
            );
        }

        $chave = env_secret($ref);

        if ($chave === null || $chave === '') {
            throw new ErroAgente(
                'configuracao',
                "A variável {$ref} está vazia no .env (provedor '{$this->nome()}')."
            );
        }

        return $chave;
    }

    /**
     * Provider de CHAT.
     *
     * @param array<string, mixed> $opcoes max_tokens, temperatura, reasoning_effort
     */
    public function chat(array $opcoes = []): AIProviderInterface
    {
        $driver = (string) ($this->provedor['driver'] ?? 'openai');
        $baseUrl = trim((string) ($this->provedor['base_url'] ?? ''));

        // O AGENTE pode sobrescrever o modelo do provedor.
        //
        // Sem isto, `agentes.modelo` seria campo morto: a tela deixaria
        // escolher e o valor não teria efeito — e escolher o modelo por
        // agente é metade da razão de existir multi-agente. Um agente de FAQ
        // pode rodar num modelo barato enquanto outro, que encadeia
        // ferramentas, usa um mais capaz.
        $modelo = trim((string) ($opcoes['modelo'] ?? '')) ?: $this->modeloChat();

        return match ($driver) {
            // Caminho NATIVO, não a camada OpenAI-compatible. É o que trata
            // thoughtSignature no retorno da ferramenta — sem isso o Gemini 3.x
            // recusa a segunda volta com HTTP 400. Ver ARQUITETURA.md §1.
            'gemini' => new Gemini(
                key: $this->chave(),
                model: $modelo,
                parameters: $this->parametrosGemini($opcoes),
                httpClient: $this->clienteHttp(),
                baseUri: $baseUrl !== '' ? $baseUrl : 'https://generativelanguage.googleapis.com/v1beta/models',
            ),

            'anthropic' => new Anthropic(
                key: $this->chave(),
                model: $modelo,
                max_tokens: (int) ($opcoes['max_tokens'] ?? 1024),
                parameters: $this->parametrosOpenAI($opcoes, incluirMaxTokens: false),
                httpClient: $this->clienteHttp(),
            ),

            'ollama' => new Ollama(
                url: $baseUrl !== '' ? $baseUrl : 'http://localhost:11434/api',
                model: $modelo,
                parameters: $this->parametrosOpenAI($opcoes),
                httpClient: $this->clienteHttp(),
            ),

            // base_url preenchida cobre Groq, DeepSeek, OpenRouter e qualquer
            // outro OpenAI-compatible sem escrever driver novo.
            default => $baseUrl !== ''
                ? new OpenAILike(
                    baseUri: $baseUrl,
                    key: $this->chave(),
                    model: $modelo,
                    parameters: $this->parametrosOpenAI($opcoes),
                    httpClient: $this->clienteHttp(),
                )
                : new OpenAI(
                    key: $this->chave(),
                    model: $modelo,
                    parameters: $this->parametrosOpenAI($opcoes),
                    httpClient: $this->clienteHttp(),
                ),
        };
    }

    /**
     * O Gemini nativo aninha os parâmetros de geração em `generationConfig`;
     * mandar `maxOutputTokens` na raiz devolve 400 "Unknown name".
     *
     * @param array<string, mixed> $opcoes
     * @return array<string, mixed>
     */
    private function parametrosGemini(array $opcoes): array
    {
        $config = [];

        if (isset($opcoes['max_tokens'])) {
            $config['maxOutputTokens'] = (int) $opcoes['max_tokens'];
        }

        if (isset($opcoes['temperatura'])) {
            $config['temperature'] = (float) $opcoes['temperatura'];
        }

        // Orçamento de raciocínio. Os modelos 3.x gastam a saída pensando antes
        // de sobrar texto; com teto baixo a resposta volta VAZIA com HTTP 200.
        //
        // 'none' vale 1 e NÃO 0: medido em 2026-08-24, `thinkingBudget: 0` é
        // recusado com HTTP 400 tanto no 3.5-flash-lite quanto no 3.6-flash —
        // esses modelos não permitem desligar o raciocínio. Com 1 a chamada
        // passa e o gasto cai ao piso do modelo (0 tokens de pensamento no
        // lite, 65 no 3.6-flash). Ou seja, 'none' significa "o mínimo que este
        // modelo aceita", não "zero".
        $esforco = (string) ($opcoes['reasoning_effort'] ?? 'none');
        $config['thinkingConfig'] = ['thinkingBudget' => match ($esforco) {
            'none' => 1,
            'low' => 1024,
            'medium' => 4096,
            'high' => -1,   // -1 = o modelo decide
            default => 1,
        }];

        return $config === [] ? [] : ['generationConfig' => $config];
    }

    /**
     * @param array<string, mixed> $opcoes
     * @return array<string, mixed>
     */
    private function parametrosOpenAI(array $opcoes, bool $incluirMaxTokens = true): array
    {
        $p = [];

        if ($incluirMaxTokens && isset($opcoes['max_tokens'])) {
            $p['max_tokens'] = (int) $opcoes['max_tokens'];
        }

        if (isset($opcoes['temperatura'])) {
            $p['temperature'] = (float) $opcoes['temperatura'];
        }

        if (!empty($opcoes['reasoning_effort']) && $opcoes['reasoning_effort'] !== 'none') {
            $p['reasoning_effort'] = (string) $opcoes['reasoning_effort'];
        }

        return $p;
    }

    /**
     * Provider de EMBEDDING — endpoint e driver próprios, separados do chat.
     *
     * A camada OpenAI-compatible do Gemini recusa `task_type` com HTTP 400;
     * o endpoint nativo aceita e de fato aplica (medido: RETRIEVAL_DOCUMENT e
     * RETRIEVAL_QUERY produzem vetores diferentes). Por isso o embedder é
     * construído por `driver_embedding`/`base_url_embedding`, e não pelos
     * campos de chat.
     *
     * @param self::TAREFA_* $tarefa indexar chunk ou embeddar a pergunta
     */
    public function embeddings(string $tarefa = self::TAREFA_INDEXAR): EmbeddingsProviderInterface
    {
        // `?:` e não `??`. A tela de provedores grava STRING VAZIA quando se
        // escolhe "(mesmo do chat)", e `??` só cobre nulo: um provedor Gemini
        // nessa opção caía no `default` do `match` abaixo e montava o embedder
        // da OpenAI com a chave do Gemini — 401 do lado errado, na primeira
        // indexação. A listagem da própria tela já exibia `driver_embedding ?:
        // driver`, ou seja, mostrava uma coisa e o código fazia outra.
        $driver = (string) (($this->provedor['driver_embedding'] ?? '') ?: ($this->provedor['driver'] ?? 'openai'));
        $modelo = $this->modeloEmbedding();
        $dim = $this->dimensoes();

        // Recusa explícita em vez de erro do fornecedor.
        //
        // A Anthropic não tem API de embeddings — nenhuma. Sem esta guarda o
        // `match` abaixo caía no `default` e montava um provider da OpenAI
        // usando a CHAVE da Anthropic: o erro que chegava era um 401 do lado
        // da OpenAI, que não diz nada sobre a causa real. A tela já impede a
        // combinação; isto cobre o banco editado à mão e a instalação antiga.
        if ($this->papel() === 'chat' || $driver === 'anthropic') {
            throw new ErroAgente(
                'configuracao',
                "O provedor '{$this->nome()}' é de chat e não serve para embeddings"
                . ($driver === 'anthropic' ? ' (a Anthropic não tem API de embeddings).' : '.')
                . ' Cadastre um provedor de embedding e defina-o como padrão em Provedores.'
            );
        }

        if ($modelo === '') {
            throw new ErroAgente('configuracao', "Provedor '{$this->nome()}' não define modelo_embedding.");
        }

        return match ($driver) {
            'gemini_nativo', 'gemini' => new GeminiEmbeddingsProvider(
                key: $this->chave(),
                model: $modelo,
                config: array_filter([
                    'taskType' => $tarefa,
                    'outputDimensionality' => $dim > 0 ? $dim : null,
                ], static fn ($v): bool => $v !== null),
            ),

            'ollama' => new OllamaEmbeddingsProvider(
                url: (string) ($this->provedor['base_url_embedding'] ?: 'http://localhost:11434/api'),
                model: $modelo,
            ),

            default => new OpenAIEmbeddingsProvider(
                key: $this->chave(),
                model: $modelo,
                dimensions: $dim > 0 ? $dim : null,
            ),
        };
    }

    /**
     * Vetores de VÁRIOS textos, na menor quantidade de chamadas possível.
     *
     * A indexação chamava `embedText()` um trecho por vez: um grupo de 25
     * trechos virava 25 idas e voltas ao fornecedor, em sequência. Na primeira
     * instalação de produção (Hostinger, plano gratuito do Gemini, 11/09/2026)
     * cada ida levava cerca de 1 s — um grupo sozinho passava do orçamento de
     * 20 s do worker, e um arquivo de 300 KB levava mais de uma hora.
     *
     * O Neuron só faz lote para a OpenAI: o `embedDocuments` dela manda até 100
     * textos por requisição. O provider do Gemini implementa apenas o texto
     * único, então a chamada ao `batchEmbedContents` é nossa. Medido: dez
     * trechos numa chamada levaram 922 ms, contra 5.185 ms em dez chamadas, e
     * os vetores saem IDÊNTICOS aos individuais — dá para trocar sem reindexar.
     *
     * Ollama e demais compatíveis sem lote continuam um texto por vez.
     *
     * @param list<string> $textos
     *
     * @return list<list<float>> na mesma ordem dos textos
     */
    public function embeddarVarios(array $textos, string $tarefa = self::TAREFA_INDEXAR): array
    {
        if ($textos === []) {
            return [];
        }

        // Passa pelas mesmas recusas de embeddings() — provedor de chat,
        // Anthropic, modelo ausente. O caminho de lote não pode ser um atalho
        // em volta delas.
        $embedder = $this->embeddings($tarefa);
        $driver = (string) (($this->provedor['driver_embedding'] ?? '') ?: ($this->provedor['driver'] ?? 'openai'));

        $vetores = match (true) {
            $driver === 'gemini' || $driver === 'gemini_nativo' => $this->loteGemini($textos, $tarefa),
            $embedder instanceof OpenAIEmbeddingsProvider => array_map(
                static fn (\NeuronAI\RAG\Document $documento): array => $documento->embedding,
                $embedder->embedDocuments(array_map(
                    static fn (string $texto): \NeuronAI\RAG\Document => new \NeuronAI\RAG\Document($texto),
                    $textos
                ))
            ),
            default => array_map(static fn (string $texto): array => $embedder->embedText($texto), $textos),
        };

        // Vetor faltando desalinharia trecho e vetor: cada trecho seguinte
        // ficaria gravado com o vetor do vizinho, e a busca erraria calada.
        if (count($vetores) !== count($textos)) {
            throw new \RuntimeException(sprintf(
                'O lote de embeddings devolveu %d vetor(es) para %d texto(s).',
                count($vetores),
                count($textos)
            ));
        }

        return array_values($vetores);
    }

    /**
     * `batchEmbedContents` do Gemini, em grupos de até 100 pedidos.
     *
     * Cada pedido leva a mesma tarefa e as mesmas dimensões que `embeddings()`
     * passa ao provider do Neuron para um texto só. É isso que garante vetor
     * idêntico ao da chamada individual.
     *
     * @param list<string> $textos
     *
     * @return list<list<float>>
     */
    private function loteGemini(array $textos, string $tarefa): array
    {
        $modelo = $this->modeloEmbedding();
        $dim = $this->dimensoes();

        $cliente = $this->clienteHttp()
            ->withBaseUri('https://generativelanguage.googleapis.com/v1beta/models/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->chave(),
            ]);

        $vetores = [];

        foreach (array_chunk($textos, 100) as $grupo) {
            $resposta = $cliente->request(\NeuronAI\HttpClient\HttpRequest::post(
                uri: "{$modelo}:batchEmbedContents",
                body: [
                    'requests' => array_map(static fn (string $texto): array => array_filter([
                        'model' => 'models/' . $modelo,
                        'content' => ['parts' => [['text' => $texto]]],
                        'taskType' => $tarefa,
                        'outputDimensionality' => $dim > 0 ? $dim : null,
                    ], static fn ($valor): bool => $valor !== null), $grupo),
                ]
            ))->json();

            foreach ($resposta['embeddings'] ?? [] as $item) {
                $vetores[] = $item['values'];
            }
        }

        return $vetores;
    }
}
