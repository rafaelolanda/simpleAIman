<?php

declare(strict_types=1);

namespace SimpleAIman\Canais;

use Database;
use PDO;
use Throwable;

/**
 * Entrega uma mensagem ao visitante, pelo canal em que ele está.
 *
 * Existe porque os canais **puxam e empurram de formas opostas**:
 *
 * - **Widget web**: o navegador consulta a cada 4 segundos. Gravar no banco JÁ
 *   é entregar — não há nada a fazer aqui.
 * - **WhatsApp**: ninguém consulta. Se a mensagem só for gravada, ela nunca
 *   sai; a pessoa fica esperando uma resposta que existe no banco e não no
 *   telefone dela.
 *
 * Sem este despachante, o painel do atendente funcionaria no web e falharia em
 * silêncio no WhatsApp — o pior tipo de falha, porque o atendente vê a própria
 * mensagem na tela e acha que respondeu.
 */
final class Saida
{
    /**
     * @return bool true se algo foi enviado de fato (false = canal que só puxa)
     */
    public static function entregar(int $conversaId, string $texto): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.externo_id, ca.tipo FROM conversas c
             LEFT JOIN canais ca ON ca.id = c.canal_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $conversaId]);
        $conversa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversa || ($conversa['tipo'] ?? '') !== 'whatsapp') {
            return false;
        }

        $canal = self::canalDaConversa($conversaId);

        if ($canal === null) {
            return false;
        }

        // Fora da janela de 24h a Meta recusa texto livre — só template
        // aprovado. Melhor registrar o motivo do que tentar e receber um erro
        // que ninguém liga à causa.
        if (!CanalWhatsapp::dentroDaJanela($conversaId)) {
            error_log('[simpleAIman] whatsapp: fora da janela de 24h, conversa ' . $conversaId);

            return false;
        }

        try {
            $canal->enviar((string) $conversa['externo_id'], $texto);

            return true;
        } catch (Throwable $e) {
            error_log('[simpleAIman] whatsapp: envio falhou na conversa ' . $conversaId . ': ' . $e->getMessage());

            return false;
        }
    }

    private static function canalDaConversa(int $conversaId): ?CanalWhatsapp
    {
        $stmt = Database::connection()->prepare(
            'SELECT canal_id FROM conversas WHERE id = :id'
        );
        $stmt->execute(['id' => $conversaId]);

        return CanalWhatsapp::porId((int) ($stmt->fetchColumn() ?: 0));
    }
}
