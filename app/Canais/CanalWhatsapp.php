<?php

declare(strict_types=1);

namespace SimpleAIman\Canais;

use Database;
use PDO;
use RuntimeException;

/**
 * Canal WhatsApp, pela Cloud API da Meta.
 *
 * Duas diferenças em relação ao widget mandam no desenho inteiro:
 *
 * 1. **A Meta exige resposta em segundos.** O webhook não pode esperar a LLM:
 *    ele valida, enfileira e devolve 200. Quem responde é o worker, depois,
 *    pela API de envio. Demorar faz a Meta reenviar — e reenvio vira resposta
 *    duplicada para a pessoa.
 *
 * 2. **Sem streaming.** Não existe "digitando" incremental: a mensagem sai
 *    inteira. Por isso o `ChatService::responder()` existe desde o começo, ao
 *    lado do `stream()` — os dois passam pelo mesmo pipeline.
 *
 * As credenciais vêm do `.env`, nunca do banco: `canais.credenciais_ref`
 * guarda o PREFIXO (ex.: `WHATSAPP`) e daqui se resolvem
 * `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET` e
 * `WHATSAPP_VERIFY_TOKEN`. O prefixo permite mais de um número na mesma
 * instalação sem inventar tabela de segredo.
 */
final class CanalWhatsapp
{
    private const API = 'https://graph.facebook.com/v21.0/';

    /** @var array<string, mixed> */
    public readonly array $canal;

    /** @param array<string, mixed> $canal */
    private function __construct(array $canal)
    {
        $this->canal = $canal;
    }

    /**
     * Encontra o canal pelo número que RECEBEU a mensagem.
     *
     * A Meta manda o `phone_number_id` de destino em cada evento, e é ele que
     * identifica o canal — não o remetente. Assim uma instalação com dois
     * números atende os dois, cada um com seu agente.
     */
    public static function porNumero(string $phoneNumberId): ?self
    {
        $phoneNumberId = trim($phoneNumberId);

        if ($phoneNumberId === '') {
            return null;
        }

        $canais = Database::connection()->query(
            "SELECT * FROM canais WHERE tipo = 'whatsapp' AND ativo = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($canais as $canal) {
            $esperado = (string) env_secret(self::prefixo($canal) . '_PHONE_NUMBER_ID');

            if ($esperado !== '' && $esperado === $phoneNumberId) {
                return new self($canal);
            }
        }

        return null;
    }

    /** Canal por id — é assim que a fila o recupera, já sabendo qual número recebeu. */
    public static function porId(int $id): ?self
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM canais WHERE id = :id AND tipo = 'whatsapp' AND ativo = 1"
        );
        $stmt->execute(['id' => $id]);

        $canal = $stmt->fetch(PDO::FETCH_ASSOC);

        return $canal ? new self($canal) : null;
    }

    /** Primeiro canal WhatsApp ativo — usado na verificação do webhook, que não traz número. */
    public static function primeiro(): ?self
    {
        $canal = Database::connection()->query(
            "SELECT * FROM canais WHERE tipo = 'whatsapp' AND ativo = 1 ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        return $canal ? new self($canal) : null;
    }

    /** @param array<string, mixed> $canal */
    private static function prefixo(array $canal): string
    {
        $ref = trim((string) ($canal['credenciais_ref'] ?? ''));

        return $ref !== '' ? $ref : 'WHATSAPP';
    }

    public function segredo(string $sufixo): string
    {
        return (string) env_secret(self::prefixo($this->canal) . '_' . $sufixo);
    }

    public function agenteId(): int
    {
        return (int) ($this->canal['agente_id'] ?? 0);
    }

    /**
     * A assinatura do corpo confere?
     *
     * É a única prova de que o webhook veio da Meta. Sem ela, o endpoint é
     * público e qualquer um pode injetar mensagem falsa — fazendo o agente
     * responder a quem quiser, na conta do cliente.
     *
     * `hash_equals` e não `===`: comparação de segredo em tempo constante.
     */
    public function assinaturaConfere(string $corpo, ?string $cabecalho): bool
    {
        $segredo = $this->segredo('APP_SECRET');

        // Sem o segredo, TUDO é recusado — e o sintoma seria a Meta mostrando
        // falha de entrega sem explicação nenhuma. Distinguir no log o "não
        // configurado" do "assinatura errada" economiza a hora que se perde
        // procurando no lugar errado.
        if ($segredo === '') {
            error_log(
                '[simpleAIman] whatsapp: ' . self::prefixo($this->canal) . '_APP_SECRET vazio no .env — '
                . 'todo webhook será recusado. O valor fica em Configurações do app > Básico, '
                . 'não na tela do WhatsApp.'
            );

            return false;
        }

        if ($cabecalho === null || !str_starts_with($cabecalho, 'sha256=')) {
            error_log('[simpleAIman] whatsapp: requisição sem X-Hub-Signature-256; recusada.');

            return false;
        }

        if (!hash_equals('sha256=' . hash_hmac('sha256', $corpo, $segredo), $cabecalho)) {
            error_log(
                '[simpleAIman] whatsapp: assinatura não confere. O APP_SECRET do .env '
                . 'provavelmente não é o do app que está enviando.'
            );

            return false;
        }

        return true;
    }

    /**
     * Entrega uma mensagem de texto à Meta para envio.
     *
     * **Voltar sem exceção NÃO significa que a mensagem chegou.** A Meta
     * responde 200 assim que aceita a requisição e entrega depois; se a
     * entrega falhar, isso aparece só no webhook de status
     * (`entry[].changes[].value.statuses[]`), com o código do erro.
     *
     * Confundir as duas coisas fez o sistema afirmar ter respondido quatro
     * vezes enquanto todos os envios morriam em 130497.
     *
     * @throws RuntimeException quando a Meta recusa a REQUISIÇÃO (token
     *                          inválido, número errado, fora da janela)
     */
    public function enviar(string $para, string $texto): void
    {
        $token = $this->segredo('TOKEN');
        $numero = $this->segredo('PHONE_NUMBER_ID');

        if ($token === '' || $numero === '') {
            throw new RuntimeException(
                'Credenciais do WhatsApp ausentes no .env (' . self::prefixo($this->canal) . '_TOKEN / _PHONE_NUMBER_ID).'
            );
        }

        $corpo = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $para,
            'type' => 'text',
            // `preview_url` desligado: link em resposta automática virando card
            // com imagem de terceiro é ruído, e a prévia é buscada pela Meta a
            // partir do link — o que vaza para fora o que estamos respondendo.
            'text' => ['preview_url' => false, 'body' => mb_substr($texto, 0, 4000)],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::API . rawurlencode($numero) . '/messages');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $status >= 300) {
            // A mensagem da Meta entra no log, nunca na conversa: ela cita
            // token, número e id interno.
            throw new RuntimeException(
                'Envio recusado (HTTP ' . $status . '): ' . ($erroCurl ?: mb_substr((string) $resposta, 0, 300))
            );
        }
    }

    /**
     * A conversa está dentro da janela de 24 horas?
     *
     * Fora dela, a Meta só aceita template pré-aprovado — texto livre é
     * recusado. Não é detalhe de API: é o que impede o sistema de responder
     * horas depois como se nada tivesse mudado. Por isso
     * `conversas.ultima_msg_usuario_em` existe desde o primeiro schema.
     */
    public static function dentroDaJanela(int $conversaId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT ultima_msg_usuario_em FROM conversas WHERE id = :id'
        );
        $stmt->execute(['id' => $conversaId]);

        $ultima = $stmt->fetchColumn();

        return $ultima !== false && $ultima !== null
            && strtotime((string) $ultima) > time() - 86400;
    }
}
