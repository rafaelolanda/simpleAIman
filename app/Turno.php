<?php

declare(strict_types=1);

/**
 * O contexto de UM turno: um id, e o tempo gasto em cada etapa dele.
 *
 * Turno = uma pergunta e o que ela provocou. Nasce quando a fala do visitante
 * chega, morre quando a resposta sai. Tudo que acontecer no meio — busca,
 * inferência, ferramenta, falha — carrega o mesmo `trace_id`.
 *
 * Antes disto cada peça gravava a sua parte num lugar diferente: a mensagem em
 * `mensagens`, a ferramenta em `ferramenta_execucoes`, a falha num `error_log`
 * sem id de conversa. Reconstruir um turno lento era garimpo manual, e
 * responder "por que ESSA conversa demorou 9 segundos" não tinha caminho.
 *
 * ## Por que estado estático
 *
 * Porque o log está espalhado por 16 arquivos, e passar o contexto por
 * parâmetro até `Mailer` ou `Saida` significaria mudar a assinatura de meia
 * aplicação para carregar um dado que não é do domínio dela.
 *
 * O preço é conhecido e tem uma armadilha: no worker CLI o processo é longo e
 * atende vários jobs em sequência, então quem abre turno TEM de fechar —
 * `finalizar()` limpa, e o `finally` de quem chama garante o resto. Turno
 * vazado contamina o job seguinte com o id do anterior, que é pior que não ter
 * id nenhum: leva a investigação para a conversa errada.
 *
 * ## Nomes dos campos
 *
 * Seguem, onde dá, as convenções `gen_ai.*` do OpenTelemetry. Não adotamos o
 * OTel — seria peso desproporcional para hospedagem compartilhada — mas nomear
 * igual não custa nada agora e é o que permite plugar Langfuse ou Grafana
 * depois sem reescrever a instrumentação.
 */
final class Turno
{
    private static ?string $id = null;
    private static float $inicio = 0.0;

    /** @var array<string, float> etapa => milissegundos acumulados */
    private static array $ms = [];

    /** @var array<string, int> */
    private static array $contagem = [];

    /** @var array<string, mixed> */
    private static array $contexto = [];

    /**
     * Abre o turno e devolve o `trace_id`.
     *
     * `$canal` é de onde a fala veio: widget, whatsapp, api, copiloto, worker.
     */
    public static function iniciar(string $canal, ?int $agenteId = null, ?int $conversaId = null): string
    {
        self::$id = bin2hex(random_bytes(8));
        self::$inicio = microtime(true);
        self::$ms = [];
        self::$contagem = [];
        self::$contexto = array_filter(
            ['canal' => $canal, 'agente_id' => $agenteId, 'conversa_id' => $conversaId],
            static fn ($v): bool => $v !== null,
        );

        return self::$id;
    }

    public static function ativo(): bool
    {
        return self::$id !== null;
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    /** @return array<string, mixed> */
    public static function contexto(): array
    {
        return self::$contexto;
    }

    /**
     * Preenche o que só se sabe no meio do caminho: a conversa depois de
     * resolvida, o modelo depois de escolhido, os tokens depois da resposta.
     *
     * @param array<string, mixed> $campos
     */
    public static function definir(array $campos): void
    {
        if (self::$id === null) {
            return;
        }

        self::$contexto = array_merge(self::$contexto, array_filter(
            $campos,
            static fn ($v): bool => $v !== null,
        ));
    }

    /**
     * Soma o consumo de UMA inferência ao turno.
     *
     * Acumula porque o turno com ferramenta chama o modelo mais de uma vez: a
     * primeira decide chamar, a segunda recebe o resultado. Só a soma responde
     * "quanto custou este atendimento".
     */
    public static function somarTokens(int $entrada, int $saida): void
    {
        if (self::$id === null) {
            return;
        }

        self::$contexto['tokens_in'] = (int) (self::$contexto['tokens_in'] ?? 0) + $entrada;
        self::$contexto['tokens_out'] = (int) (self::$contexto['tokens_out'] ?? 0) + $saida;
    }

    /** Acumula, não substitui: um turno pode chamar três ferramentas. */
    public static function somar(string $etapa, float $ms): void
    {
        if (self::$id === null) {
            return;
        }

        self::$ms[$etapa] = (self::$ms[$etapa] ?? 0.0) + $ms;
    }

    public static function contar(string $chave, int $quantos = 1): void
    {
        if (self::$id === null) {
            return;
        }

        self::$contagem[$chave] = (self::$contagem[$chave] ?? 0) + $quantos;
    }

    /**
     * Marca o instante e devolve quem fecha a medição.
     *
     * `$fim = Turno::medir('inferencia'); ... ; $fim();`
     *
     * Devolver a closure em vez de receber um callable evita embrulhar blocos
     * que já têm try/catch e yield — o streaming não caberia num callable sem
     * virar generator dentro de generator.
     */
    public static function medir(string $etapa): callable
    {
        $marco = microtime(true);

        return static function () use ($etapa, $marco): void {
            self::somar($etapa, (microtime(true) - $marco) * 1000);
        };
    }

    /**
     * Fecha o turno: grava a linha em `turnos`, loga o resumo e limpa.
     *
     * Nunca lança. Ver o comentário de `Log::escrever()`.
     */
    public static function finalizar(string $status = 'ok', ?int $mensagemId = null): void
    {
        if (self::$id === null) {
            return;
        }

        $total = (microtime(true) - self::$inicio) * 1000;
        $ctx = self::$contexto;

        $linha = [
            'trace_id' => self::$id,
            'conversa_id' => $ctx['conversa_id'] ?? null,
            'mensagem_id' => $mensagemId ?? ($ctx['mensagem_id'] ?? null),
            'agente_id' => $ctx['agente_id'] ?? null,
            'canal' => $ctx['canal'] ?? null,
            'caminho' => $ctx['caminho'] ?? null,
            'status' => $status,
            'erro_codigo' => $ctx['erro_codigo'] ?? null,
            'provedor_id' => $ctx['provedor_id'] ?? null,
            'modelo' => $ctx['modelo'] ?? null,
            'tokens_in' => $ctx['tokens_in'] ?? null,
            'tokens_out' => $ctx['tokens_out'] ?? null,
            'ms_total' => (int) round($total),
            'ms_embedding' => self::inteiro('embedding'),
            'ms_busca' => self::inteiro('busca'),
            'ms_inferencia' => self::inteiro('inferencia'),
            'ms_ferramentas' => self::inteiro('ferramentas'),
            'n_ferramentas' => self::$contagem['ferramentas'] ?? 0,
            'n_trechos' => self::$contagem['trechos'] ?? 0,
            'criado_em' => now(),
        ];

        try {
            $colunas = implode(', ', array_keys($linha));
            $valores = ':' . implode(', :', array_keys($linha));

            Database::connection()
                ->prepare("INSERT INTO turnos ({$colunas}) VALUES ({$valores})")
                ->execute($linha);
        } catch (Throwable $e) {
            error_log('[simpleAIman] {"ev":"turno_nao_gravado","erro":' . json_encode($e->getMessage()) . '}');
        }

        // O mesmo resumo vai para o log em JSON. A tabela serve à tela do
        // painel; o log serve a quem está com o terminal aberto agora.
        //
        // `trace_id` e `criado_em` saem: o primeiro o Log já carimba como
        // `trace`, e o segundo o próprio error_log já prefixa com a data.
        Log::evento('turno', array_diff_key($linha, ['trace_id' => null, 'criado_em' => null]));

        self::limpar();
    }

    /**
     * Descarta o turno sem gravar.
     *
     * Existe para o `finally` de quem abriu: se a requisição morrer no meio, é
     * melhor não deixar o id vazar para o job seguinte no worker.
     */
    public static function limpar(): void
    {
        self::$id = null;
        self::$inicio = 0.0;
        self::$ms = [];
        self::$contagem = [];
        self::$contexto = [];
    }

    private static function inteiro(string $etapa): ?int
    {
        return isset(self::$ms[$etapa]) ? (int) round(self::$ms[$etapa]) : null;
    }
}
