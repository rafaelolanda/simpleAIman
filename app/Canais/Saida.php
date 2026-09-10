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
    public static function entregar(int $conversaId, string $texto, ?string $autor = null): bool
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
            \Log::evento('whatsapp_fora_da_janela', ['conversa_id' => $conversaId, 'tipo' => 'texto']);

            return false;
        }

        try {
            $canal->enviar((string) $conversa['externo_id'], self::comAutor($texto, $autor));

            return true;
        } catch (Throwable $e) {
            \Log::erro('whatsapp_envio_falhou', ['conversa_id' => $conversaId, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Este canal precisa que alguém empurre a mensagem?
     *
     * Existe para separar duas coisas que `entregar()` devolve iguais: o
     * widget web responde `false` porque não há nada a enviar — gravar já é
     * entregar —, e o WhatsApp responde `false` quando o envio FALHOU. Tratar
     * as duas como a mesma coisa faria a tela avisar "não foi entregue" sobre
     * um arquivo que o visitante está vendo.
     */
    public static function precisaEnviar(int $conversaId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT ca.tipo FROM conversas c
             LEFT JOIN canais ca ON ca.id = c.canal_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $conversaId]);

        return (string) $stmt->fetchColumn() === 'whatsapp';
    }

    /**
     * Entrega um ARQUIVO ao visitante.
     *
     * Chamado só a partir do painel do atendente. O agente não tem caminho até
     * aqui, e é de propósito: modelo escolhendo arquivo para mandar é risco
     * sem ganho.
     *
     * No widget web devolve false como o texto — lá o arquivo já está gravado,
     * e quem exibe é a tela que consulta.
     *
     * @param array<string, mixed> $anexo linha de `mensagem_anexos`
     */
    public static function entregarAnexo(
        int $conversaId,
        array $anexo,
        string $legenda = '',
        ?string $autor = null,
    ): bool {
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

        if (!CanalWhatsapp::dentroDaJanela($conversaId)) {
            \Log::evento('whatsapp_fora_da_janela', ['conversa_id' => $conversaId, 'tipo' => 'anexo']);

            return false;
        }

        $para = (string) $conversa['externo_id'];
        $tipo = (string) $anexo['tipo'];

        try {
            // Audio nao aceita legenda na API da Meta. Sem esta linha, tanto o
            // nome de quem mandou quanto a legenda digitada pelo atendente
            // sumiriam sem aviso — a pessoa receberia um audio solto, de
            // origem desconhecida.
            if ($tipo === 'audio') {
                $aviso = trim(self::comAutor($legenda, $autor));

                if ($aviso !== '') {
                    $canal->enviar($para, $aviso);
                }
            }

            $canal->enviarMidia(
                $para,
                Anexos::caminhoAbsoluto($anexo),
                (string) $anexo['mime'],
                $tipo,
                $tipo === 'audio' ? '' : self::comAutor($legenda, $autor),
                $anexo['nome_original'] !== null ? (string) $anexo['nome_original'] : null
            );

            return true;
        } catch (Throwable $e) {
            \Log::erro('whatsapp_anexo_falhou', ['conversa_id' => $conversaId, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Prefixa o nome de quem esta falando.
     *
     * So o WhatsApp precisa disto, e por falta de estrutura: la toda mensagem
     * chega igual, do mesmo numero, sem nenhum campo dizendo quem escreveu. O
     * widget e o painel exibem o autor ao lado do balao, entao repetir no
     * texto duplicaria.
     *
     * Importa mais do que parece: atendente muda no meio da conversa —
     * transferencia, resgate de conversa orfa —, e sem o nome a pessoa nao tem
     * como saber que agora fala com outra gente.
     *
     * `*Nome:*` com um asterisco porque e a marcacao do WhatsApp, e a quebra
     * de linha separa o rotulo da fala.
     */
    private static function comAutor(string $texto, ?string $autor): string
    {
        $autor = trim((string) $autor);

        if ($autor === '') {
            return $texto;
        }

        // Asterisco dentro do nome quebraria o negrito e deixaria a marcacao
        // vazando na tela de quem recebe.
        $autor = str_replace(['*', '_', '~'], '', $autor);

        return '*' . $autor . ':*' . ($texto === '' ? '' : "\n" . $texto);
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
