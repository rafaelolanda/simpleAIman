<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Database;
use PDO;

/**
 * Fila de trabalho em SQLite.
 *
 * Todos os métodos aqui fazem transações CURTAS e nenhum deles chama a rede.
 * A regra vale para quem consome a fila também: chamar a API de embeddings
 * com uma transação aberta trava o banco pelos segundos da requisição e
 * derruba o chat junto. Chame fora, grave dentro. Ver ARQUITETURA.md §6.
 */
final class Queue
{
    /** Quanto tempo um job fica reservado antes de voltar para a fila. */
    private const LOCK_SEGUNDOS = 300;

    public static function enfileirar(string $tipo, array $payload, int $prioridade = 0): int
    {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO jobs (tipo, payload, status, prioridade, proxima_execucao_em, criado_em, editado_em)
             VALUES (:tipo, :payload, \'pendente\', :prioridade, :agora, :agora, :agora)'
        )->execute([
            'tipo' => $tipo,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'prioridade' => $prioridade,
            'agora' => now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Reserva o próximo job disponível, se houver.
     *
     * A reserva é feita num UPDATE condicionado ao status, dentro de uma
     * transação: dois workers rodando ao mesmo tempo (o cron e o "kick" do
     * upload, por exemplo) não pegam o mesmo job.
     *
     * @return array<string, mixed>|null
     */
    public static function proximo(?string $tipo = null): ?array
    {
        return Database::transacao(static function (PDO $pdo) use ($tipo): ?array {
            $agora = now();

            $filtro = $tipo !== null ? ' AND tipo = :tipo' : '';
            $sql = 'SELECT id FROM jobs
                    WHERE status = \'pendente\'
                      AND (proxima_execucao_em IS NULL OR proxima_execucao_em <= :agora)' . $filtro . '
                    ORDER BY prioridade DESC, id ASC LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $params = ['agora' => $agora];

            if ($tipo !== null) {
                $params['tipo'] = $tipo;
            }

            $stmt->execute($params);
            $id = $stmt->fetchColumn();

            if (!$id) {
                return null;
            }

            $lock = date('Y-m-d H:i:s', time() + self::LOCK_SEGUNDOS);

            $atualizou = $pdo->prepare(
                'UPDATE jobs SET status = \'processando\', lock_ate = :lock, tentativas = tentativas + 1, editado_em = :agora
                 WHERE id = :id AND status = \'pendente\''
            );
            $atualizou->execute(['lock' => $lock, 'agora' => $agora, 'id' => $id]);

            if ($atualizou->rowCount() === 0) {
                return null;   // outro worker chegou antes
            }

            $job = $pdo->prepare('SELECT * FROM jobs WHERE id = :id');
            $job->execute(['id' => $id]);

            $linha = $job->fetch(PDO::FETCH_ASSOC);
            $linha['payload'] = json_para_array($linha['payload'] ?? null);
            $linha['progresso'] = json_para_array($linha['progresso'] ?? null);

            return $linha;
        });
    }

    /**
     * Salva o ponto de retomada e renova o lock.
     *
     * É o que torna o worker retomável: ele processa um lote, grava aqui e
     * sai limpo antes do max_execution_time. Um PDF de 300 páginas atravessa
     * várias execuções do cron sem reprocessar o que já foi feito.
     */
    public static function progresso(int $id, array $progresso): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET progresso = :progresso, lock_ate = :lock, editado_em = :agora WHERE id = :id'
        )->execute([
            'progresso' => json_encode($progresso, JSON_UNESCAPED_UNICODE),
            'lock' => date('Y-m-d H:i:s', time() + self::LOCK_SEGUNDOS),
            'agora' => now(),
            'id' => $id,
        ]);
    }

    /** Devolve o job para a fila, para continuar na próxima execução. */
    public static function devolver(int $id, int $emSegundos = 0): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET status = \'pendente\', lock_ate = NULL, proxima_execucao_em = :quando, editado_em = :agora
             WHERE id = :id'
        )->execute([
            'quando' => date('Y-m-d H:i:s', time() + $emSegundos),
            'agora' => now(),
            'id' => $id,
        ]);
    }

    /**
     * Pausa por limite de requisições.
     *
     * Estado distinto de `erro` de propósito: bater na cota do free tier é
     * situação NORMAL numa ingestão grande, não falha do artefato. Marcar
     * como erro faria o admin ver um documento "quebrado" que na verdade só
     * precisa esperar a janela virar.
     */
    public static function pausar(int $id, int $segundos, string $motivo): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET status = \'pendente\', lock_ate = NULL, proxima_execucao_em = :quando,
                    erro = :motivo, editado_em = :agora
             WHERE id = :id'
        )->execute([
            'quando' => date('Y-m-d H:i:s', time() + max(1, $segundos)),
            'motivo' => $motivo,
            'agora' => now(),
            'id' => $id,
        ]);
    }

    public static function concluir(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET status = \'ok\', lock_ate = NULL, erro = NULL, editado_em = :agora WHERE id = :id'
        )->execute(['agora' => now(), 'id' => $id]);
    }

    public static function falhar(int $id, string $erro): void
    {
        Database::connection()->prepare(
            'UPDATE jobs SET status = \'erro\', lock_ate = NULL, erro = :erro, editado_em = :agora WHERE id = :id'
        )->execute(['erro' => mb_substr($erro, 0, 2000), 'agora' => now(), 'id' => $id]);
    }

    /**
     * Devolve à fila os jobs cujo lock expirou.
     *
     * Acontece quando o processo morre no meio — timeout do PHP, restart do
     * servidor, `kill`. Sem isto o job ficaria "processando" para sempre e a
     * ingestão travaria em silêncio.
     */
    public static function liberarPresos(): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE jobs SET status = \'pendente\', lock_ate = NULL, editado_em = :agora
             WHERE status = \'processando\' AND lock_ate IS NOT NULL AND lock_ate < :agora'
        );
        $stmt->execute(['agora' => now()]);

        return $stmt->rowCount();
    }

    /** @return array<string, int> */
    public static function resumo(): array
    {
        $linhas = Database::connection()
            ->query('SELECT status, COUNT(*) AS total FROM jobs GROUP BY status')
            ->fetchAll(PDO::FETCH_ASSOC);

        $resumo = ['pendente' => 0, 'processando' => 0, 'ok' => 0, 'erro' => 0];

        foreach ($linhas as $l) {
            $resumo[$l['status']] = (int) $l['total'];
        }

        return $resumo;
    }
}
