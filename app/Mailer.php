<?php

declare(strict_types=1);

/**
 * Envio de e-mail simples, sem dependências externas (nada de Composer).
 * Dois modos, escolhidos via MAIL_DRIVER no .env:
 *  - "mail": usa a função mail() nativa do PHP. Simples, mas depende do servidor
 *    estar configurado pra enviar e-mail (sendmail/postfix) — em hospedagem
 *    compartilhada geralmente funciona; em ambiente local, quase nunca.
 *  - "smtp": cliente SMTP mínimo (AUTH LOGIN + STARTTLS/SSL) que fala direto com
 *    um servidor SMTP autenticado (Gmail, SendGrid, Mailtrap, etc.) via socket.
 */
final class Mailer
{
    /**
     * $responderPara define o Reply-To: sem ele, responder ao aviso de um lead cai
     * no endereço "não responda" do remetente, e o e-mail de quem escreveu fica só
     * no corpo, exigindo copiar e colar.
     */
    public static function send(
        string $paraEmail,
        string $paraNome,
        string $assunto,
        string $corpoHtml,
        ?string $responderPara = null,
        ?string $responderParaNome = null
    ): bool {
        try {
            if (MAIL_DRIVER === 'smtp') {
                return self::enviarSmtp($paraEmail, $paraNome, $assunto, $corpoHtml, $responderPara, $responderParaNome);
            }

            return self::enviarPhpMail($paraEmail, $paraNome, $assunto, $corpoHtml, $responderPara, $responderParaNome);
        } catch (Throwable $e) {
            error_log('Falha ao enviar e-mail: ' . $e->getMessage());
            return false;
        }
    }

    private static function enviarPhpMail(
        string $paraEmail,
        string $paraNome,
        string $assunto,
        string $corpoHtml,
        ?string $responderPara = null,
        ?string $responderParaNome = null
    ): bool {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . self::formatarEndereco(MAIL_FROM_NOME, MAIL_FROM);

        if ($responderPara !== null && filter_var($responderPara, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . self::formatarEndereco((string) $responderParaNome, $responderPara);
        }

        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

        return mail(
            self::formatarEndereco($paraNome, $paraEmail),
            $assuntoCodificado,
            $corpoHtml,
            implode("\r\n", $headers)
        );
    }

    private static function enviarSmtp(
        string $paraEmail,
        string $paraNome,
        string $assunto,
        string $corpoHtml,
        ?string $responderPara = null,
        ?string $responderParaNome = null
    ): bool {
        if (MAIL_SMTP_HOST === '') {
            throw new RuntimeException('MAIL_SMTP_HOST não configurado.');
        }

        $seguranca = strtolower(MAIL_SMTP_SEGURANCA);
        $host = $seguranca === 'ssl' ? 'ssl://' . MAIL_SMTP_HOST : MAIL_SMTP_HOST;

        $socket = @stream_socket_client(
            "{$host}:" . MAIL_SMTP_PORT,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new RuntimeException("Não foi possível conectar ao SMTP: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        try {
            self::lerResposta($socket, 220);
            self::comando($socket, 'EHLO ' . self::hostnameLocal(), 250);

            if ($seguranca === 'tls') {
                self::comando($socket, 'STARTTLS', 220);

                $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($ok !== true) {
                    throw new RuntimeException('Falha ao negociar STARTTLS com o servidor SMTP.');
                }

                self::comando($socket, 'EHLO ' . self::hostnameLocal(), 250);
            }

            if (MAIL_SMTP_USUARIO !== '') {
                self::comando($socket, 'AUTH LOGIN', 334);
                self::comando($socket, base64_encode(MAIL_SMTP_USUARIO), 334);
                self::comando($socket, base64_encode(MAIL_SMTP_SENHA), 235);
            }

            self::comando($socket, 'MAIL FROM:<' . MAIL_FROM . '>', 250);
            self::comando($socket, 'RCPT TO:<' . $paraEmail . '>', 250);
            self::comando($socket, 'DATA', 354);

            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'From: ' . self::formatarEndereco(MAIL_FROM_NOME, MAIL_FROM);
            $headers[] = 'To: ' . self::formatarEndereco($paraNome, $paraEmail);

            if ($responderPara !== null && filter_var($responderPara, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: ' . self::formatarEndereco((string) $responderParaNome, $responderPara);
            }

            $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=';
            $headers[] = 'Date: ' . date('r');

            // pontos no início de linha precisam ser duplicados (SMTP "dot-stuffing")
            $corpoEscapado = preg_replace('/^\./m', '..', $corpoHtml);

            $mensagem = implode("\r\n", $headers) . "\r\n\r\n" . $corpoEscapado . "\r\n.";
            self::comando($socket, $mensagem, 250);

            self::comando($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }

        return true;
    }

    /**
     * @param resource $socket
     */
    private static function comando($socket, string $comando, int $codigoEsperado): string
    {
        fwrite($socket, $comando . "\r\n");
        return self::lerResposta($socket, $codigoEsperado);
    }

    /**
     * @param resource $socket
     */
    private static function lerResposta($socket, int $codigoEsperado): string
    {
        $resposta = '';

        while (($linha = fgets($socket, 515)) !== false) {
            $resposta .= $linha;
            // linha final do bloco tem "-" ausente na posição 4 (ex.: "250 " vs "250-")
            if (strlen($linha) < 4 || $linha[3] !== '-') {
                break;
            }
        }

        $codigo = (int) substr($resposta, 0, 3);

        if ($codigo !== $codigoEsperado) {
            throw new RuntimeException("Resposta SMTP inesperada (esperado {$codigoEsperado}): " . trim($resposta));
        }

        return $resposta;
    }

    private static function formatarEndereco(string $nome, string $email): string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return $email;
        }

        return '=?UTF-8?B?' . base64_encode($nome) . "?= <{$email}>";
    }

    private static function hostnameLocal(): string
    {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        return $host ?: 'localhost';
    }
}
