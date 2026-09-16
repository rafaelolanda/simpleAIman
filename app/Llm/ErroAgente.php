<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

use RuntimeException;
use Throwable;

/**
 * Falha do agente com DUAS faces: uma pública e uma técnica.
 *
 * O visitante do widget nunca pode ver erro de API, nome de modelo, nome de
 * provedor, status HTTP ou mensagem de cota. Três motivos:
 *
 *   1. Não significa nada para quem só quer saber o preço de um curso.
 *   2. Entrega informação sobre a nossa infraestrutura a qualquer pessoa que
 *      converse com o bot — inclusive qual fornecedor e modelo usamos.
 *   3. Passa a impressão de sistema quebrado quando, do ponto de vista do
 *      visitante, o que precisa acontecer é apenas o atendimento continuar.
 *
 * Por isso toda falha carrega `mensagemPublica` (o que aparece no chat) e
 * `detalhe` (o que vai para o log e para a tela de diagnóstico do admin).
 * A regra de ouro: se um texto veio de uma exceção, de uma resposta HTTP ou
 * de uma ferramenta, ele NUNCA é a mensagemPublica.
 */
final class ErroAgente extends RuntimeException
{
    /**
     * Mensagens públicas por código. Deliberadamente vagas quanto à causa e
     * específicas quanto ao próximo passo — a saída útil para o visitante é o
     * caminho humano (handoff), não uma explicação técnica.
     */
    private const MENSAGENS = [
        'provedor_cota' => 'Estou com um volume alto de atendimentos agora e não consegui responder. '
            . 'Quer que eu registre sua dúvida para alguém retornar?',
        'provedor_autenticacao' => 'Não consegui responder agora. '
            . 'Quer que eu registre sua dúvida para alguém retornar?',
        'provedor_indisponivel' => 'Estou com dificuldade para responder neste momento. '
            . 'Quer tentar de novo em instantes ou prefere que eu registre sua dúvida?',
        'provedor_timeout' => 'A resposta está demorando mais que o normal. '
            . 'Quer tentar de novo ou prefere que eu registre sua dúvida?',
        'resposta_vazia' => 'Não consegui formular uma resposta para isso. '
            . 'Pode reformular a pergunta?',
        'ferramenta_falhou' => 'Não consegui consultar essa informação agora. '
            . 'Quer que eu registre sua dúvida para alguém confirmar?',
        'ferramenta_recusada' => 'Não consigo fazer essa consulta por aqui. '
            . 'Posso te encaminhar para o setor responsável.',
        'limite_iteracoes' => 'Não consegui concluir essa consulta. '
            . 'Quer que eu encaminhe para alguém verificar?',
        'pedido_grande' => 'Esta conversa ficou longa demais para eu processar de uma vez. '
            . 'Pode reformular a pergunta, mais curta?',
        'configuracao' => 'O atendimento automático está indisponível no momento.',
        'desconhecido' => 'Não consegui responder agora. '
            . 'Quer que eu registre sua dúvida para alguém retornar?',
    ];

    public function __construct(
        public readonly string $codigo,
        public readonly string $detalhe,
        ?string $mensagemPublica = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $anterior = null,
    ) {
        // A mensagem da exceção é a TÉCNICA: é ela que vai para o log.
        parent::__construct($detalhe, 0, $anterior);

        $this->publica = $mensagemPublica
            ?? self::MENSAGENS[$codigo]
            ?? self::MENSAGENS['desconhecido'];
        $this->personalizada = $mensagemPublica !== null;
    }

    private string $publica;

    /** Mensagem escolhida por quem lançou: `ajustarOferta()` não mexe nela. */
    private bool $personalizada = false;

    /**
     * As mesmas mensagens, sem a oferta de registrar a dúvida.
     *
     * Para agente sem a ferramenta de chamado. Em uso real (11/09/2026), o
     * convite saiu, a pessoa disse "sim", e o modelo — que não tinha como
     * registrar nada — pediu transferência de novo.
     */
    private const SEM_REGISTRO = [
        'provedor_cota' => 'Estou com um volume alto de atendimentos agora e não consegui responder. '
            . 'Tente de novo em alguns instantes.',
        'provedor_autenticacao' => 'Não consegui responder agora. Tente de novo mais tarde.',
        'provedor_indisponivel' => 'Estou com dificuldade para responder neste momento. Tente de novo em instantes.',
        'provedor_timeout' => 'A resposta está demorando mais que o normal. Tente de novo em instantes.',
        'ferramenta_falhou' => 'Não consegui consultar essa informação agora. Tente de novo em instantes.',
        'desconhecido' => 'Não consegui responder agora. Tente de novo em instantes.',
    ];

    /**
     * Tira a oferta de registro quando o agente não tem como cumpri-la.
     *
     * No lugar dela, aponta o menu de setores, se houver: contato de setor é
     * dado nosso e não depende de ferramenta nem de provedor.
     */
    public function ajustarOferta(bool $podeRegistrar, bool $temMenu): self
    {
        if ($podeRegistrar || $this->personalizada || !isset(self::SEM_REGISTRO[$this->codigo])) {
            return $this;
        }

        $this->publica = self::SEM_REGISTRO[$this->codigo]
            . ($temMenu ? ' Se preferir, digite *MENU* para ver os contatos dos setores.' : '');

        return $this;
    }

    /** O que pode aparecer no chat. */
    public function mensagemPublica(): string
    {
        return $this->publica;
    }

    /**
     * Traduz a falha de um provedor para um código nosso.
     *
     * O texto original do fornecedor entra apenas em `detalhe`. Ele costuma
     * conter nome de modelo, id de projeto e link de documentação — nada disso
     * pode escapar para o chat.
     */
    public static function deProvedor(Throwable $e, string $contexto = ''): self
    {
        $texto = $e->getMessage();
        $status = self::extrairStatus($texto);

        $codigo = match (true) {
            $status === 429 || str_contains($texto, 'exceeded your current quota') => 'provedor_cota',
            $status === 401 || $status === 403 => 'provedor_autenticacao',
            $status === 404 => 'configuracao',
            // 413 da Groq: "Request too large ... Limit 8000, Requested 18155".
            // Caia em "desconhecido" ate 16/09/2026, e o admin lia "erro
            // desconhecido" para um problema com causa e correcao claras.
            $status === 413 || str_contains($texto, 'Request too large') => 'pedido_grande',
            $status !== null && $status >= 500 => 'provedor_indisponivel',
            // "timed out" é como o cURL escreve ("cURL error 28: Operation timed
            // out after 40003 milliseconds"). Sem esta variante, o timeout caía
            // adiante em "Network error" e virava `provedor_indisponivel`: o
            // visitante lia "estou com dificuldade" quando o certo era "está
            // demorando mais que o normal". Os jobs com erro de 27 e 29/08
            // mostram o código errado gravado.
            str_contains(strtolower($texto), 'timeout'),
            str_contains(strtolower($texto), 'timed out') => 'provedor_timeout',
            str_contains(strtolower($texto), 'could not resolve host') => 'provedor_indisponivel',
            // "Error creating resource" e "Network error" vêm do cliente HTTP,
            // não do fornecedor: é falha de transporte, e o visitante deve
            // receber a mensagem de indisponibilidade temporária.
            str_contains($texto, 'Error creating resource') => 'provedor_indisponivel',
            str_contains($texto, 'Network error') => 'provedor_indisponivel',
            str_contains(strtolower($texto), 'ssl') => 'provedor_indisponivel',
            default => 'desconhecido',
        };

        $detalhe = ($contexto !== '' ? $contexto . ': ' : '') . $texto;

        return new self($codigo, $detalhe, null, $status, $e);
    }

    private static function extrairStatus(string $texto): ?int
    {
        // Cobre "HTTP 429", "(429)", "code: 429" — formatos que os provedores usam.
        if (preg_match('/\b(?:HTTP\s+|\(|"code":\s*)(\d{3})\b/', $texto, $m)) {
            $status = (int) $m[1];
            return $status >= 100 && $status < 600 ? $status : null;
        }

        return null;
    }

    /**
     * Linha pronta para o log/admin: código, status e o detalhe técnico.
     */
    public function paraLog(): string
    {
        return '[' . $this->codigo . ($this->httpStatus !== null ? ' ' . $this->httpStatus : '') . '] ' . $this->detalhe;
    }

    /**
     * Dica acionável para a tela de diagnóstico do admin — o que fazer a
     * respeito. Nunca vai para o chat.
     */
    public function sugestaoAdmin(): string
    {
        return match ($this->codigo) {
            // Duas cotas diferentes, e a saída de uma não serve à outra.
            'provedor_cota' => str_contains($this->detalhe, 'free-models-per-day')
                || str_contains($this->detalhe, 'openrouter_free_tier_daily')
                ? 'Teto diário do plano gratuito, contado por CONTA e não por modelo — trocar o modelo do '
                    . 'agente não resolve. Espere a virada do dia ou compre créditos no fornecedor.'
                : 'Cota do provedor esgotada. No free tier do Gemini o limite é por modelo e por dia: '
                    . 'trocar o modelo do agente costuma destravar na hora.',
            'provedor_autenticacao' => 'Chave inválida ou sem permissão. Confira a variável indicada em '
                . '`auth_ref` no .env e se o projeto tem acesso ao modelo.',
            'configuracao' => 'Modelo ou endpoint não encontrado. O modelo pode ter sido descontinuado — '
                . 'confira o nome em `provedores.modelo_chat`.',
            'provedor_indisponivel', 'provedor_timeout' => 'Falha ou lentidão do provedor. Modelos do free '
                . 'tier degradam sem aviso: rode este teste com outro modelo em `provedores.modelo_chat` e '
                . 'compare os tokens/s — a diferença entre dois modelos do mesmo fornecedor chega a 50x. '
                . 'Se todos estiverem lentos, verifique conectividade e o CA bundle (curl.cainfo).',
            'resposta_vazia' => 'Modelo pensante gastou o orçamento de saída antes do texto. Aumente '
                . '`max_tokens` do agente ou reduza `reasoning_effort`.',
            'pedido_grande' => 'O pedido passou do limite de tokens do provedor. O que mais pesa: `top_k` e o '
                . 'tamanho do chunk das bases, a descrição das ferramentas (ela vai inteira em TODA chamada) '
                . 'e o histórico. Reduza o top_k do agente, encurte as descrições das ferramentas ou use um '
                . 'modelo com limite maior.',
            default => 'Veja o detalhe técnico no log.',
        };
    }
}
