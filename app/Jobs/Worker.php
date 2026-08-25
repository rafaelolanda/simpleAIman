<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Database;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Rag\Ingestor;
use Throwable;

/**
 * Processa a fila até o tempo acabar, e sai limpo.
 *
 * Desenhado para hospedagem compartilhada, onde não há processo persistente:
 * o cron chama a cada N minutos e o upload dispara um "kick" logo depois.
 * Por isso o worker nunca trabalha "até terminar" — trabalha até o orçamento
 * de tempo, grava onde parou e devolve o job para a fila.
 *
 * O `max_execution_time` não é uma ameaça a evitar: é o relógio do desenho.
 */
final class Worker
{
    /** Margem antes do limite real, para dar tempo de gravar o progresso. */
    private const MARGEM_SEGUNDOS = 5;

    private float $inicio;

    public function __construct(
        private readonly int $tempoMaximo = 20,
        private readonly int $tamanhoLote = 25,
    ) {
        $this->inicio = microtime(true);
    }

    private function tempoAcabou(): bool
    {
        return (microtime(true) - $this->inicio) >= max(1, $this->tempoMaximo - self::MARGEM_SEGUNDOS);
    }

    /**
     * @param callable(string): void|null $log
     * @return array{processados: int, concluidos: int, falhas: int, pausas: int}
     */
    public function executar(?callable $log = null): array
    {
        $log ??= static fn (string $m): null => null;

        // Rotinas de manutencao entram aqui, no maximo uma vez por dia. Nao ha
        // agendador proprio nesta arquitetura: o cron so chama o worker, entao
        // e o worker que decide o que ja passou da hora.
        if (Queue::agendarPeriodico('retencao')) {
            $log('retencao do dia enfileirada.');
        }

        $liberados = Queue::liberarPresos();

        if ($liberados > 0) {
            $log("{$liberados} job(s) com lock expirado devolvidos à fila.");
        }

        $placar = ['processados' => 0, 'concluidos' => 0, 'falhas' => 0, 'pausas' => 0];

        while (!$this->tempoAcabou()) {
            $job = Queue::proximo();

            if ($job === null) {
                break;
            }

            $placar['processados']++;
            $resultado = $this->processar($job, $log);
            $placar[$resultado] = ($placar[$resultado] ?? 0) + 1;
        }

        return $placar;
    }

    /**
     * Anonimizacao e expurgo do conteudo das conversas.
     *
     * Nasce desligado (dias = 0 nos dois estagios), entao esta rodada custa
     * duas leituras e termina. So faz alguma coisa depois que alguem escolhe
     * um prazo em Configuracoes.
     *
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'
     */
    private function aplicarRetencao(array $job, callable $log): string
    {
        $placar = (new \SimpleAIman\Jobs\Retencao())->executar($log);

        Queue::concluir((int) $job['id']);

        if ($placar['anonimizadas'] === 0 && $placar['expurgadas'] === 0) {
            $log('retencao: nada vencido.');
        }

        return 'concluidos';
    }

    /**
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'falhas'|'pausas'
     */
    private function processar(array $job, callable $log): string
    {
        $id = (int) $job['id'];

        try {
            return match ($job['tipo']) {
                'ingestao' => $this->ingerir($job, $log),
                'entrega_lead' => $this->entregarLead($job, $log),
                'retencao' => $this->aplicarRetencao($job, $log),
                default => throw new \RuntimeException("Tipo de job desconhecido: {$job['tipo']}"),
            };
        } catch (ErroAgente $e) {
            // Cota estourada NÃO é falha do artefato: é situação normal numa
            // ingestão grande no free tier. Vira pausa, e o job volta sozinho
            // quando a janela virar. Marcar como erro faria o admin ver um
            // documento "quebrado" que só precisava esperar.
            if ($e->codigo === 'provedor_cota') {
                Queue::pausar($id, 90, 'Limite de requisições do provedor. Retomando automaticamente.');
                $log("job #{$id}: cota do provedor — pausado por 90s.");

                return 'pausas';
            }

            Queue::falhar($id, $e->paraLog());
            $this->marcarArtefatoComErro($job, $e->paraLog());
            $log("job #{$id}: FALHOU — {$e->paraLog()}");

            return 'falhas';
        } catch (Throwable $e) {
            Queue::falhar($id, $e->getMessage());
            $this->marcarArtefatoComErro($job, $e->getMessage());
            $log("job #{$id}: FALHOU — {$e->getMessage()}");

            return 'falhas';
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'pausas'
     */
    private function ingerir(array $job, callable $log): string
    {
        $id = (int) $job['id'];
        $artefatoId = (int) ($job['payload']['artefato_id'] ?? 0);

        if ($artefatoId <= 0) {
            throw new \RuntimeException('Job de ingestão sem artefato_id.');
        }

        $pdo = Database::connection();
        $ingestor = new Ingestor();

        $pdo->prepare('UPDATE artefatos SET status = \'processando\', erro = NULL, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $artefatoId]);

        // Fase 1 só na primeira passada. Nas retomadas os chunks já existem —
        // refazer apagaria e recriaria tudo, jogando fora os vetores prontos.
        if (empty($job['progresso']['extraido'])) {
            $total = $ingestor->extrair($artefatoId);
            Queue::progresso($id, ['extraido' => true, 'total' => $total, 'feitos' => 0]);
            $log("job #{$id}: artefato {$artefatoId} extraído em {$total} chunks.");
        }

        $feitos = (int) ($job['progresso']['feitos'] ?? 0);
        $total = (int) ($job['progresso']['total'] ?? $ingestor->pendentes($artefatoId) + $feitos);

        // Fase 2, em lotes, respeitando o orçamento de tempo.
        while (!$this->tempoAcabou()) {
            $n = $ingestor->embeddar($artefatoId, $this->tamanhoLote);

            if ($n === 0) {
                break;
            }

            $feitos += $n;
            Queue::progresso($id, ['extraido' => true, 'total' => $total, 'feitos' => $feitos]);
            $log("job #{$id}: {$feitos}/{$total} chunks embeddados.");
        }

        $restantes = $ingestor->pendentes($artefatoId);

        if ($restantes > 0) {
            // Ainda falta: devolve à fila e continua na próxima execução.
            Queue::devolver($id);
            $pdo->prepare('UPDATE artefatos SET status = \'processando\', editado_em = :agora WHERE id = :id')
                ->execute(['agora' => now(), 'id' => $artefatoId]);
            $log("job #{$id}: tempo esgotado com {$restantes} chunk(s) restantes — continua na próxima execução.");

            return 'pausas';
        }

        Queue::concluir($id);
        $pdo->prepare('UPDATE artefatos SET status = \'ok\', erro = NULL, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $artefatoId]);

        $log("job #{$id}: artefato {$artefatoId} concluído ({$feitos} chunks).");

        return 'concluidos';
    }

    /**
     * Entrega de lead ao destino externo.
     *
     * A falha aqui NÃO marca o job como erro: o `lead_destinos` já registra
     * tentativa, backoff e dead letter por conta própria, e é ele quem o
     * painel mostra. Duplicar o estado no job faria o admin ver dois lugares
     * dizendo coisas diferentes sobre a mesma entrega.
     *
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'pausas'
     */
    private function entregarLead(array $job, callable $log): string
    {
        $leadId = (int) ($job['payload']['lead_id'] ?? 0);

        if ($leadId <= 0) {
            throw new \RuntimeException('Job de entrega sem lead_id.');
        }

        try {
            \SimpleAIman\Tools\LeadTool::entregar($leadId);
            Queue::concluir((int) $job['id']);
            $log("job #{$job['id']}: lead {$leadId} entregue.");

            return 'concluidos';
        } catch (Throwable $e) {
            // O reagendamento já foi feito pelo LeadTool, com backoff.
            Queue::concluir((int) $job['id']);
            $log("job #{$job['id']}: {$e->getMessage()}");

            return 'pausas';
        }
    }

    /** @param array<string, mixed> $job */
    private function marcarArtefatoComErro(array $job, string $erro): void
    {
        $artefatoId = (int) ($job['payload']['artefato_id'] ?? 0);

        if ($artefatoId <= 0) {
            return;
        }

        try {
            Database::connection()
                ->prepare('UPDATE artefatos SET status = \'erro\', erro = :erro, editado_em = :agora WHERE id = :id')
                ->execute(['erro' => mb_substr($erro, 0, 1000), 'agora' => now(), 'id' => $artefatoId]);
        } catch (Throwable) {
            // Falhar ao registrar a falha não pode derrubar o worker.
        }
    }
}
