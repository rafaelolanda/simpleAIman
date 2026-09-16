<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\ObserverInterface;
use Throwable;
use Turno;

/**
 * Ouve o que acontece DENTRO da chamada ao modelo e alimenta o `Turno`.
 *
 * É o único ponto do projeto em que adotar um módulo do Neuron faz mais sentido
 * que reescrever — ao contrário do RAG, ver ARQUITETURA.md §1. O motivo é
 * simples: o tempo entre mandar o prompt e receber a resposta acontece do lado
 * de dentro da biblioteca, e nosso código não tem como cronometrar o que não
 * enxerga. O mesmo vale para a fronteira exata de cada tool call dentro do
 * laço de ferramentas, que é do Neuron.
 *
 * ## Por que `setDefaultObserver` e não `observe`
 *
 * Os nós do agente emitem com um `workflowId` próprio, tirado do estado da
 * execução (`Workflow\Node::emit()`). Um observer registrado com escopo nulo
 * nunca receberia esses eventos. Mas o `EventBus` tem um atalho: ao emitir num
 * escopo ainda não inicializado, ele registra ali o observer padrão. Definir o
 * padrão UMA vez, então, alcança todo escopo novo sem saber o id de nenhum.
 *
 * ## A armadilha do estado global
 *
 * `EventBus` guarda os observers por escopo em propriedade estática, e cada
 * execução do agente cria um escopo. Numa requisição web isso morre com o
 * processo; no worker CLI, que atende vários jobs em sequência, o mapa cresce
 * a cada turno e nada o esvazia. Daí `desligar()` chamar `EventBus::clear()` —
 * não é higiene, é o que impede o processo longo de inchar até morrer.
 */
final class ObservadorNeuron implements ObserverInterface
{
    private static ?self $instancia = null;

    /** @var array<string, float> marco de início por evento aberto */
    private array $marcos = [];

    /**
     * Liga o observer. Idempotente: chamar duas vezes não duplica evento,
     * porque o padrão é um só.
     */
    public static function ligar(): void
    {
        self::$instancia ??= new self();
        EventBus::setDefaultObserver(self::$instancia);
    }

    /**
     * Desliga e esvazia os observers acumulados por escopo.
     *
     * Chamar entre jobs do worker. Ver o comentário do cabeçalho.
     */
    public static function desligar(): void
    {
        try {
            EventBus::clear();
        } catch (Throwable) {
            // Observabilidade nunca derruba o que observa.
        }
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        try {
            match ($event) {
                'inference-start' => $this->abrir('inferencia'),
                'inference-stop' => $this->fecharInferencia($data),

                // As ferramentas são cronometradas pelo `Executor`, que mede o
                // trabalho de verdade. Aqui só se conta quantas o modelo pediu:
                // é o número que revela agente chamando ferramenta em círculo.
                'tool-called' => Turno::contar('ferramentas'),

                default => null,
            };
        } catch (Throwable) {
            // Idem.
        }
    }

    private function abrir(string $etapa): void
    {
        $this->marcos[$etapa] = microtime(true);
    }

    /**
     * Fecha a inferência e colhe o consumo dela.
     *
     * É o ÚNICO lugar em que o consumo do streaming aparece. O turno completo
     * lê `getUsage()` da resposta e grava; o streaming não tem resposta
     * nenhuma — tem pedaços —, e por isso `tokens_in` e `tokens_out` ficavam
     * nulos em todo turno do widget. Sem eles não há custo por atendimento
     * justamente no canal de maior volume.
     *
     * O evento traz a mensagem montada (`StreamingNode` emite depois de
     * juntar os pedaços), então o consumo chega aqui quando o fornecedor o
     * envia. Quando não envia, fica nulo como antes — nada quebra.
     */
    private function fecharInferencia(mixed $dados): void
    {
        $this->fechar('inferencia');

        $resposta = is_object($dados) && property_exists($dados, 'response') ? $dados->response : null;
        $uso = is_object($resposta) && method_exists($resposta, 'getUsage') ? $resposta->getUsage() : null;

        if ($uso === null) {
            return;
        }

        Turno::somarTokens(
            (int) ($uso->inputTokens ?? 0),
            (int) ($uso->outputTokens ?? 0) + (int) ($uso->reasoningTokens ?? 0),
        );
    }

    /**
     * Fecha e acumula.
     *
     * Acumula porque um turno com ferramenta tem MAIS DE UMA inferência: o
     * modelo é chamado, pede a ferramenta, e é chamado de novo com o resultado.
     * Somar as duas é o que responde "quanto do turno foi o modelo".
     */
    private function fechar(string $etapa): void
    {
        if (!isset($this->marcos[$etapa])) {
            return;
        }

        Turno::somar($etapa, (microtime(true) - $this->marcos[$etapa]) * 1000);
        unset($this->marcos[$etapa]);
    }
}
