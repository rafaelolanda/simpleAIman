<?php

declare(strict_types=1);

final class Metrics
{
    private const TIPOS = [
        'conversa_iniciada',
        'mensagem_enviada',
        'ferramenta_executada',
        'faq_direta',
        'lead_capturado',
        'chamado_aberto',
        'contato_setor',
        'handoff_solicitado',
        'widget_aberto',
    ];

    /**
     * Contador por tipo + referencia + dia.
     *
     * Diferente dos sites institucionais, aqui o padrão é NÃO deduplicar por cookie:
     * "quantas mensagens" e "quantas ferramentas rodaram" são contagens de evento, não
     * de visitante. Só a abertura do widget usa dedup diária, que é a pergunta
     * "quantas pessoas", e para isso passa-se $dedupDiaria = true.
     *
     * @return bool true se contou; false se já tinha sido contado hoje (só com dedup).
     */
    public static function log(string $tipo, int $referenciaId = 0, bool $dedupDiaria = false): bool
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de métrica inválido: {$tipo}");
        }

        if ($dedupDiaria) {
            $chaveCookie = 'mtx_' . $tipo . '_' . $referenciaId;

            if (($_COOKIE[$chaveCookie] ?? null) === today()) {
                return false;
            }

            if (!headers_sent()) {
                setcookie($chaveCookie, today(), [
                    'expires' => time() + 86400,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => APP_ENV !== 'local',
                ]);
            }
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO metricas (tipo, referencia_id, data, contador, criado_em)
             VALUES (:tipo, :referencia_id, :data, 1, :criado_em)
             ON CONFLICT (tipo, referencia_id, data)
             DO UPDATE SET contador = contador + 1'
        );
        $stmt->execute([
            'tipo' => $tipo,
            'referencia_id' => $referenciaId,
            'data' => today(),
            'criado_em' => now(),
        ]);

        return true;
    }

    /**
     * Série agregada por dia para os gráficos do dashboard.
     * @return array<string, array<string, int>> [data => [tipo => contador]]
     */
    public static function serieDiaria(string $dataInicio, string $dataFim): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT data, tipo, SUM(contador) AS total
             FROM metricas
             WHERE data BETWEEN :inicio AND :fim
             GROUP BY data, tipo
             ORDER BY data ASC'
        );
        $stmt->execute(['inicio' => $dataInicio, 'fim' => $dataFim]);

        $resultado = [];
        foreach ($stmt->fetchAll() as $linha) {
            $resultado[$linha['data']][$linha['tipo']] = (int) $linha['total'];
        }

        return $resultado;
    }

    public static function totalPorTipo(string $tipo, string $dataInicio, string $dataFim): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(contador), 0) FROM metricas WHERE tipo = :tipo AND data BETWEEN :inicio AND :fim'
        );
        $stmt->execute(['tipo' => $tipo, 'inicio' => $dataInicio, 'fim' => $dataFim]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, int> [referencia_id => total]
     */
    public static function totalPorReferencia(string $tipo): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT referencia_id, SUM(contador) AS total FROM metricas WHERE tipo = :tipo GROUP BY referencia_id'
        );
        $stmt->execute(['tipo' => $tipo]);

        $resultado = [];
        foreach ($stmt->fetchAll() as $linha) {
            $resultado[(int) $linha['referencia_id']] = (int) $linha['total'];
        }

        return $resultado;
    }
}
