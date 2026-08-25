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

    public static function ativo(): self
    {
        $linha = Database::connection()
            ->query('SELECT * FROM provedores WHERE ativo = 1 ORDER BY id LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            throw new ErroAgente('configuracao', 'Nenhum provedor ativo cadastrado.');
        }

        return new self($linha);
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
        $modelo = $this->modeloChat();
        $baseUrl = trim((string) ($this->provedor['base_url'] ?? ''));

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
        $driver = (string) ($this->provedor['driver_embedding'] ?? $this->provedor['driver'] ?? 'openai');
        $modelo = $this->modeloEmbedding();
        $dim = $this->dimensoes();

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
}
