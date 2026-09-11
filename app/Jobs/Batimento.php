<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Throwable;

/**
 * Sinal de vida do worker.
 *
 * Cada execução registra quando começou, por qual caminho veio e como
 * terminou. Sem isso, "o worker está rodando?" só tinha resposta indireta —
 * job preso, artefato parado em pendente — e só depois que algo já tinha
 * dado errado.
 *
 * ## Por que arquivo, e não tabela
 *
 * O worker roda a cada cinco minutos mesmo com a fila vazia. Uma linha no
 * SQLite por execução seria escrita constante no mesmo arquivo que o chat
 * usa, para guardar algo que só interessa enquanto é recente. Um JSON com as
 * últimas `MAX` execuções em `storage/`, fora do alcance da web, basta.
 * Decisão de 11/09/2026.
 *
 * ## Como a morte do processo fica visível
 *
 * A execução é aberta no início e fechada no fim. Três desfechos possíveis:
 *
 *  - fechada pelo próprio worker        → terminou (ok ou com falhas)
 *  - fechada pela função de desligamento → erro fatal ou exceção que escapou
 *  - nunca fechada                        → o processo foi MORTO de fora
 *
 * O terceiro é o que interessa: `register_shutdown_function` roda em erro
 * fatal e em estouro de tempo, mas não quando o servidor mata o processo.
 * Execução que fica aberta além do razoável é assinatura de servidor web
 * encerrando o PHP no meio do trabalho — exatamente o que se suspeitou do
 * LiteSpeed da Hostinger em 11/09/2026.
 */
final class Batimento
{
    private const MAX = 50;

    public static function arquivo(): string
    {
        return caminho_storage() . '/worker-batimento.json';
    }

    /**
     * Abre o registro de uma execução e devolve o id dela.
     *
     * Nunca lança: observação que derruba o observado é pior que nenhuma.
     */
    public static function iniciar(): string
    {
        $id = bin2hex(random_bytes(6));

        self::alterar(static function (array $lista) use ($id): array {
            $lista[] = [
                'id' => $id,
                // Só há dois chamadores: o cron (e quem roda o worker na mão),
                // pela CLI, e o kick, pela web.
                'origem' => PHP_SAPI === 'cli' ? 'cli' : 'kick',
                'sapi' => PHP_SAPI,
                'liberacao' => $GLOBALS['__liberacao'] ?? null,
                // O diretório da trava. Cron e kick usam o mesmo nome de
                // arquivo em `sys_get_temp_dir()`, mas CLI e web podem ter
                // diretórios temporários diferentes — e aí as duas travas não
                // se enxergam e os dois processos pegam o mesmo job.
                'temp' => sys_get_temp_dir(),
                'inicio' => time(),
                'fim' => null,
                'situacao' => null,
                'jobs' => null,
                'falhas' => null,
            ];

            return array_slice($lista, -self::MAX);
        });

        register_shutdown_function(static function () use ($id): void {
            $erro = error_get_last();
            $fatal = is_array($erro)
                && in_array($erro['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

            // Só tem efeito se o worker não fechou a execução: `concluir()`
            // ignora registro já fechado.
            self::concluir($id, [], $fatal ? 'fatal: ' . mb_substr((string) $erro['message'], 0, 200) : 'abortada');
        });

        return $id;
    }

    /**
     * Fecha a execução. Idempotente: o primeiro a fechar vence.
     *
     * @param array<string, int> $placar
     */
    public static function concluir(string $id, array $placar, ?string $situacao = null): void
    {
        self::alterar(static function (array $lista) use ($id, $placar, $situacao): array {
            foreach ($lista as $i => $execucao) {
                if (($execucao['id'] ?? '') !== $id || $execucao['fim'] !== null) {
                    continue;
                }

                $falhas = (int) ($placar['falhas'] ?? 0);

                $lista[$i]['fim'] = time();
                $lista[$i]['jobs'] = (int) ($placar['processados'] ?? 0);
                $lista[$i]['falhas'] = $falhas;
                $lista[$i]['situacao'] = $situacao ?? ($falhas > 0 ? 'com falhas' : 'ok');
            }

            return $lista;
        });
    }

    /**
     * Execuções registradas, a mais recente primeiro, com a situação das que
     * ainda estão abertas resolvida pela idade.
     *
     * @return list<array<string, mixed>>
     */
    public static function ler(): array
    {
        $bruto = @file_get_contents(self::arquivo());
        $lista = is_string($bruto) ? json_decode($bruto, true) : null;

        if (!is_array($lista)) {
            return [];
        }

        $agora = time();
        $limite = self::limiteInterrupcao();

        foreach ($lista as $i => $execucao) {
            if (($execucao['fim'] ?? null) === null) {
                $lista[$i]['situacao'] = ($agora - (int) $execucao['inicio']) > $limite ? 'interrompida' : 'em curso';
            }
        }

        return array_reverse(array_values($lista));
    }

    /**
     * O que o painel e a CLI precisam saber de relance.
     *
     * @return array<string, mixed>
     */
    public static function resumo(): array
    {
        $lista = self::ler();
        $agora = time();

        $ultima = static function (?string $origem) use ($lista): ?int {
            foreach ($lista as $execucao) {
                if ($origem === null || $execucao['origem'] === $origem) {
                    return (int) $execucao['inicio'];
                }
            }

            return null;
        };

        $contar = static function (callable $criterio) use ($lista): int {
            return count(array_filter($lista, $criterio));
        };

        $temps = [];

        foreach ($lista as $execucao) {
            $temps[$execucao['origem']][(string) ($execucao['temp'] ?? '')] = true;
        }

        $liberacoes = array_values(array_unique(array_filter(array_map(
            static fn (array $e): ?string => $e['origem'] === 'kick' ? ($e['liberacao'] ?? 'nenhum') : null,
            $lista
        ))));

        return [
            'total' => count($lista),
            'ultima' => $ultima(null),
            'ultima_cli' => $ultima('cli'),
            'ultima_kick' => $ultima('kick'),
            'cli_ultima_hora' => $contar(static fn (array $e): bool => $e['origem'] === 'cli' && $e['inicio'] > $agora - 3600),
            'interrompidas' => $contar(static fn (array $e): bool => $e['situacao'] === 'interrompida'),
            'interrompidas_kick' => $contar(static fn (array $e): bool => $e['situacao'] === 'interrompida' && $e['origem'] === 'kick'),
            'abortadas' => $contar(static fn (array $e): bool => $e['situacao'] === 'abortada' || str_starts_with((string) $e['situacao'], 'fatal')),
            'temp_cli' => array_keys($temps['cli'] ?? []),
            'temp_kick' => array_keys($temps['kick'] ?? []),
            'liberacoes_kick' => $liberacoes,
        ];
    }

    /**
     * Quanto tempo uma execução pode ficar aberta antes de ser dada como morta.
     *
     * O orçamento do worker é conferido ENTRE jobs, então uma execução pode
     * passar dele pelo tempo de um job — uma chamada ao modelo pode levar até
     * o timeout de 40 s do cliente HTTP. O triplo do orçamento, com piso de
     * dois minutos, cobre isso com folga.
     */
    public static function limiteInterrupcao(): int
    {
        return max(WORKER_TEMPO_MAX_S * 3, 120);
    }

    /** Leitura, alteração e escrita sob trava, para cron e kick não se atropelarem. */
    private static function alterar(callable $alteracao): void
    {
        try {
            $fp = @fopen(self::arquivo(), 'c+');

            if ($fp === false) {
                return;
            }

            if (!flock($fp, LOCK_EX)) {
                fclose($fp);

                return;
            }

            $bruto = stream_get_contents($fp);
            $lista = is_string($bruto) && $bruto !== '' ? json_decode($bruto, true) : [];
            $lista = $alteracao(is_array($lista) ? $lista : []);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode(array_values($lista), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } catch (Throwable) {
            // Silêncio proposital: ver o comentário de iniciar().
        }
    }
}
