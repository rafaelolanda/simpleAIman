<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use Metrics;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Executa uma ferramenta configurada e registra o que aconteceu.
 *
 * É o ponto único por onde toda ferramenta passa. Concentrar aqui é o que
 * permite que validação, trava de ordem e auditoria existam de verdade em vez
 * de ficarem espalhadas — e é exatamente por isso que o loop automático de
 * uma biblioteca não servia: ele chamaria a função sem passar por aqui.
 *
 * A ordem das checagens não é arbitrária. As baratas e mais restritivas vêm
 * antes, para que uma chamada indevida custe o mínimo:
 *
 *   ativa? → depende_de satisfeito? → parâmetros válidos? → executa → registra
 */
final class Executor
{
    /** @var array<int, true> ferramentas já executadas nesta conversa */
    private array $jaExecutadas = [];

    /**
     * Execuções neste TURNO, para o teto de iterações.
     *
     * Diferente de `$jaExecutadas`, que é histórico da conversa inteira: o
     * teto protege contra o laço de um único turno, em que o modelo chama,
     * recebe o resultado, chama de novo, e assim por diante. Cada volta dessas
     * é uma chamada nova ao provedor, com o histórico inteiro — é onde o
     * crédito some sem ninguém perceber.
     */
    private int $nesteTurno = 0;

    /**
     * Avisos das ferramentas que rodaram, para anexar à resposta.
     *
     * Existem para dizer ao visitante que o dado NÃO veio da base curada — de
     * busca na web, de sistema de terceiro — e precisa ser conferido. Vai
     * anexado pelo código, e não pedido ao modelo: aviso que depende de o
     * modelo lembrar é aviso que some justamente na resposta em que importava.
     *
     * @var list<string>
     */
    private array $avisos = [];

    public function __construct(
        private readonly int $conversaId,
        private readonly ?int $agenteId = null,
    ) {
        $this->carregarHistorico();
    }

    /**
     * Ferramentas que já rodaram nesta conversa.
     *
     * Carregado do banco e não da memória: o turno anterior aconteceu em
     * outro request, e `depende_de` precisa valer na conversa inteira, não
     * só na mensagem atual.
     */
    private function carregarHistorico(): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT ferramenta_id FROM ferramenta_execucoes
             WHERE conversa_id = :c AND status = \'ok\' AND ferramenta_id IS NOT NULL'
        );
        $stmt->execute(['c' => $this->conversaId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->jaExecutadas[(int) $id] = true;
        }
    }

    /**
     * @param array<string, mixed> $ferramenta linha de `ferramentas`
     * @param array<string, mixed> $argumentos vindos do modelo
     * @return string resultado serializado, pronto para voltar ao modelo
     */
    public function executar(array $ferramenta, array $argumentos, ?int $mensagemId = null): string
    {
        $inicio = microtime(true);
        $id = (int) $ferramenta['id'];

        // Fora do try de propósito: o `catch` devolve uma frase neutra e
        // genérica ao modelo, que engoliria justamente a instrução de parar —
        // e ele tentaria de novo, que é o oposto do que o teto quer.
        if (($estouro = $this->tetoEstourado()) !== null) {
            $this->registrar($ferramenta, $argumentos, 'recusado', $estouro, $inicio, $mensagemId);

            return json_encode([
                'erro' => true,
                'mensagem' => $estouro,
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            if (empty($ferramenta['ativo'])) {
                throw new RuntimeException('Ferramenta desativada.');
            }

            $this->verificarDependencia($ferramenta);

            $parametros = $this->validar($id, $argumentos);
            $resultado = $this->despachar($ferramenta, $parametros);

            $this->jaExecutadas[$id] = true;
            $this->nesteTurno++;

            $aviso = trim((string) ($ferramenta['aviso_resposta'] ?? ''));

            if ($aviso !== '' && !in_array($aviso, $this->avisos, true)) {
                $this->avisos[] = $aviso;
            }

            $this->registrar($ferramenta, $parametros, 'ok', $resultado, $inicio, $mensagemId);
            Metrics::log('ferramenta_executada', $this->agenteId ?? 0);

            return $resultado;
        } catch (Throwable $e) {
            $this->registrar($ferramenta, $argumentos, 'erro', $e->getMessage(), $inicio, $mensagemId);

            // O modelo recebe uma frase curta e NEUTRA. O texto do erro pode
            // conter URL interna, nome de host e resposta de terceiro — nada
            // disso pode entrar no contexto e acabar repetido ao visitante.
            return json_encode([
                'erro' => true,
                'mensagem' => 'Não foi possível consultar essa informação agora.',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Teto de ferramentas por turno.
     *
     * O campo `agentes.max_iteracoes_tool` existia na tabela e no formulário,
     * e ninguém o lia: quem fazia o laço era a biblioteca, com o limite dela.
     * Era um botão que mentia, e justamente o botão que impede um agente mal
     * configurado de chamar ferramenta em círculo queimando crédito.
     *
     * A recusa devolve texto ao modelo em vez de estourar: ele precisa poder
     * concluir o turno com o que já tem, e não receber um erro que o faria
     * tentar de novo.
     */
    private function tetoEstourado(): ?string
    {
        $teto = 5;

        if ($this->agenteId !== null) {
            $stmt = Database::connection()->prepare('SELECT max_iteracoes_tool FROM agentes WHERE id = :id');
            $stmt->execute(['id' => $this->agenteId]);
            $teto = max(1, (int) ($stmt->fetchColumn() ?: 5));
        }

        if ($this->nesteTurno < $teto) {
            return null;
        }

        // Texto acionável, não erro: o modelo precisa concluir o turno com o
        // que já tem. "Falhou" o faria tentar outra ferramenta.
        return 'Limite de ' . $teto . ' ferramentas por resposta atingido. '
            . 'Responda agora com o que já foi obtido, sem chamar mais ferramentas.';
    }

    /**
     * Trava de ordem: a ferramenta pré-requisito precisa ter rodado.
     *
     * É a camada mais forte de controle de sequência, e a única que não
     * depende de o modelo obedecer — as outras três (descrição, parâmetro
     * obrigatório, prompt) são pedidos; esta é uma recusa.
     */
    private function verificarDependencia(array $ferramenta): void
    {
        $dependeDe = $ferramenta['depende_de'] ?? null;

        if ($dependeDe === null) {
            return;
        }

        if (!isset($this->jaExecutadas[(int) $dependeDe])) {
            $stmt = Database::connection()->prepare('SELECT nome FROM ferramentas WHERE id = :id');
            $stmt->execute(['id' => (int) $dependeDe]);
            $nome = (string) ($stmt->fetchColumn() ?: 'anterior');

            throw new RuntimeException("Requer que \"{$nome}\" seja executada antes nesta conversa.");
        }
    }

    /**
     * Valida os argumentos contra os parâmetros declarados.
     *
     * O modelo preenche SLOTS; ele nunca monta a chamada. Tudo que não foi
     * declarado é descartado aqui — sem isso, um argumento inventado poderia
     * atravessar até o corpo da requisição HTTP.
     *
     * @param array<string, mixed> $argumentos
     * @return array<string, mixed>
     */
    private function validar(int $ferramentaId, array $argumentos): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ferramenta_parametros WHERE ferramenta_id = :id ORDER BY ordem, id'
        );
        $stmt->execute(['id' => $ferramentaId]);

        $limpos = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $nome = (string) $p['nome'];
            $valor = $argumentos[$nome] ?? null;

            if ($valor === null || $valor === '') {
                if (!empty($p['obrigatorio']) && ($p['padrao'] ?? '') === '') {
                    throw new RuntimeException("Parâmetro obrigatório ausente: {$nome}.");
                }

                if (($p['padrao'] ?? '') === '') {
                    continue;
                }

                $valor = $p['padrao'];
            }

            $limpos[$nome] = $this->converter($p, $valor);
        }

        return $limpos;
    }

    /** @param array<string, mixed> $p */
    private function converter(array $p, mixed $valor): mixed
    {
        $opcoes = $this->opcoesDoParametro($p);

        if ($opcoes !== [] && !in_array((string) $valor, $opcoes, true)) {
            throw new RuntimeException(
                'Valor inválido para ' . $p['nome'] . ': "' . $valor . '". Aceitos: ' . implode(', ', $opcoes) . '.'
            );
        }

        return match ((string) $p['tipo']) {
            'number' => is_numeric($valor)
                ? $valor + 0
                : throw new RuntimeException("O parâmetro {$p['nome']} precisa ser um número."),
            'boolean' => filter_var($valor, FILTER_VALIDATE_BOOLEAN),
            default => texto_utf8((string) $valor),
        };
    }

    /**
     * Opções de um parâmetro enum, estáticas ou vindas do banco.
     *
     * O enum dinâmico é o que faz criar um setor no admin torná-lo roteável
     * na mesma hora, sem tocar em código nem no prompt.
     *
     * @param array<string, mixed> $p
     * @return list<string>
     */
    public function opcoesDoParametro(array $p): array
    {
        $fonte = trim((string) ($p['enum_fonte'] ?? ''));

        if ($fonte !== '') {
            return self::opcoesDinamicas($fonte);
        }

        $estaticas = json_para_array($p['enum_valores'] ?? null);

        return array_values(array_map('strval', $estaticas));
    }

    /** @return list<string> */
    public function avisos(): array
    {
        return $this->avisos;
    }

    /** @return list<string> */
    public static function opcoesDinamicas(string $fonte): array
    {
        // Lista fechada de fontes: aceitar nome de tabela livre aqui seria
        // deixar o admin montar consulta arbitrária.
        $consulta = match ($fonte) {
            'setores' => 'SELECT slug FROM setores WHERE ativo = 1 ORDER BY ordem, nome',
            'bases' => 'SELECT slug FROM bases WHERE ativo = 1 ORDER BY nome',
            'agentes' => 'SELECT slug FROM agentes WHERE ativo = 1 ORDER BY nome',
            default => null,
        };

        if ($consulta === null) {
            return [];
        }

        return array_map('strval', Database::connection()->query($consulta)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $parametros */
    private function despachar(array $ferramenta, array $parametros): string
    {
        return match ((string) $ferramenta['tipo']) {
            'contato_setor' => (new SetorTool($this->conversaId))->contato($parametros),
            'handoff', 'abrir_chamado' => (new SetorTool($this->conversaId))->abrirChamado($parametros),
            'transferir_atendimento' => (new AtendimentoTool($this->conversaId))->transferir($parametros),
            'lead' => (new LeadTool($this->conversaId, $this->agenteId))->registrar($parametros),
            'http' => (new HttpTool())->executar($ferramenta, $parametros),
            default => throw new RuntimeException("Tipo de ferramenta não implementado: {$ferramenta['tipo']}."),
        };
    }

    /** @param array<string, mixed> $parametros */
    private function registrar(
        array $ferramenta,
        array $parametros,
        string $status,
        string $resposta,
        float $inicio,
        ?int $mensagemId,
    ): void {
        // O tempo real da ferramenta sai daqui, nao do observer do Neuron: o
        // Neuron sabe quando o modelo PEDIU a ferramenta, nos sabemos quanto o
        // trabalho levou de fato — HTTP, banco, e-mail.
        \Turno::somar('ferramentas', (microtime(true) - $inicio) * 1000);

        try {
            Database::connection()->prepare(
                'INSERT INTO ferramenta_execucoes
                    (conversa_id, mensagem_id, ferramenta_id, params, status, resposta, duracao_ms, erro, trace_id, criado_em)
                 VALUES (:c, :m, :f, :p, :s, :r, :d, :e, :trace, :agora)'
            )->execute([
                'c' => $this->conversaId,
                'trace' => \Turno::id(),
                'm' => $mensagemId,
                'f' => (int) $ferramenta['id'],
                'p' => json_encode($parametros, JSON_UNESCAPED_UNICODE),
                's' => $status,
                'r' => mb_substr($resposta, 0, 4000),
                'd' => (int) ((microtime(true) - $inicio) * 1000),
                'e' => $status === 'erro' ? mb_substr($resposta, 0, 1000) : null,
                'agora' => now(),
            ]);
        } catch (Throwable $e) {
            \Log::erro('execucao_nao_registrada', ['erro' => $e->getMessage()]);
        }
    }
}
