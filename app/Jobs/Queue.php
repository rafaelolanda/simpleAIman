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

    /**
     * @param int $emSegundos adia a primeira execução. Útil quando quem
     *        enfileira já sabe que o trabalho só faz sentido depois — um
     *        retry com backoff, por exemplo. Sem isso o worker acordaria o
     *        job antes da hora e ele sairia sem fazer nada.
     */
    public static function enfileirar(string $tipo, array $payload, int $prioridade = 0, int $emSegundos = 0): int
    {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO jobs (tipo, payload, status, prioridade, proxima_execucao_em, criado_em, editado_em)
             VALUES (:tipo, :payload, \'pendente\', :prioridade, :quando, :agora, :agora)'
        )->execute([
            'tipo' => $tipo,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'prioridade' => $prioridade,
            'quando' => date('Y-m-d H:i:s', time() + max(0, $emSegundos)),
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

    /**
     * Cutuca o worker para começar agora, sem esperar o cron.
     *
     * Fire-and-forget de propósito: abre a conexão, manda a requisição e
     * fecha sem ler a resposta. Quem acabou de subir um arquivo não pode
     * ficar esperando a ingestão terminar para o formulário responder.
     *
     * Falhar aqui é aceitável e silencioso — o cron pega no próximo ciclo.
     * É por isso que o cron continua existindo mesmo com o kick funcionando.
     */
    public static function cutucarWorker(): void
    {
        if (WORKER_TOKEN === '' || APP_URL === '') {
            return;
        }

        $url = APP_URL . '/api/worker-kick.php?token=' . rawurlencode(WORKER_TOKEN);
        $partes = parse_url($url);

        if (!is_array($partes) || !isset($partes['host'])) {
            return;
        }

        $seguro = ($partes['scheme'] ?? 'http') === 'https';
        $porta = $partes['port'] ?? ($seguro ? 443 : 80);
        $destino = ($seguro ? 'ssl://' : '') . $partes['host'];

        $contexto = stream_context_create([
            // Ambiente local costuma usar certificado autoassinado; o alvo
            // aqui é a própria máquina, então não há o que interceptar.
            'ssl' => ['verify_peer' => APP_ENV !== 'local', 'verify_peer_name' => APP_ENV !== 'local'],
        ]);

        $socket = @stream_socket_client(
            "{$destino}:{$porta}",
            $erro,
            $mensagem,
            2,
            STREAM_CLIENT_CONNECT,
            $contexto
        );

        if ($socket === false) {
            return;
        }

        $caminho = ($partes['path'] ?? '/') . (isset($partes['query']) ? '?' . $partes['query'] : '');

        fwrite($socket, "GET {$caminho} HTTP/1.1\r\nHost: {$partes['host']}\r\nConnection: Close\r\n\r\n");

        // Lê APENAS a linha de status antes de fechar.
        //
        // Fechar logo após o fwrite() parece mais rápido, mas descarta o que
        // ainda está no buffer — sobretudo em TLS, onde o handshake e a
        // escrita podem não ter sido drenados. O pedido simplesmente não
        // chega, e como a falha é silenciosa ninguém percebe.
        //
        // Ler uma linha custa milissegundos: o endpoint responde 202 e só
        // depois começa a trabalhar. Não é esperar a ingestão — é confirmar
        // que o pedido foi entregue.
        stream_set_timeout($socket, 3);
        fgets($socket, 128);
        fclose($socket);
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
