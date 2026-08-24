<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'sqlite:' . DB_PATH;
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');

            // Sem busy_timeout, um escritor concorrente recebe SQLITE_BUSY na hora em
            // vez de esperar — é a origem de quase todo "database is locked". Aqui
            // isso importa mais que nos sites institucionais: o worker de ingestão
            // grava ao mesmo tempo em que o chat responde. Ver ARQUITETURA.md §8.
            $pdo->exec('PRAGMA busy_timeout = ' . DB_BUSY_TIMEOUT_MS);

            // Em WAL, NORMAL dispensa o fsync a cada commit sem risco de corrupção
            // (só de perder a última transação numa queda de energia). É o que torna
            // a escrita de chat barata o bastante para não precisar de lote.
            $pdo->exec('PRAGMA synchronous = NORMAL');

            self::$instance = $pdo;
        }

        return self::$instance;
    }

    /**
     * Executa $fn dentro de uma transação, com rollback em caso de exceção.
     *
     * REGRA: nada de chamada HTTP aqui dentro. Uma transação aberta enquanto se
     * espera a API de embeddings responder trava o banco inteiro por segundos e
     * derruba o chat junto. Chame a API fora, grave dentro. É o bug nº 1 deste
     * tipo de aplicação — ver ARQUITETURA.md §6.
     */
    public static function transacao(callable $fn): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $pdo->beginTransaction();

        try {
            $resultado = $fn($pdo);
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cópia consistente do banco com ele em uso. `cp` de um arquivo em WAL
     * traz o .sqlite sem o conteúdo que ainda está no -wal, ou seja, corrompido.
     */
    public static function backup(string $destino): void
    {
        $pdo = self::connection();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($destino));
    }

    private function __construct()
    {
    }
}
