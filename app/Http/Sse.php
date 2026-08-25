<?php

declare(strict_types=1);

namespace SimpleAIman\Http;

/**
 * Encanamento do Server-Sent Events.
 *
 * Extraído de `api/chat.php` quando o endpoint público apareceu: são umas
 * quarenta linhas de cabeçalho anti-buffering que precisam estar idênticas nos
 * dois lugares. Duplicar isso significaria consertar um bug de streaming duas
 * vezes — e a segunda seria esquecida.
 */
final class Sse
{
    /**
     * Prepara a saída para streaming.
     *
     * Apache com mod_deflate/proxy segura o stream e o chat parece travado.
     * Sem estes cabeçalhos o SSE "funciona" no servidor embutido e falha no
     * servidor real — o pior tipo de bug, porque só aparece em produção.
     */
    public static function abrir(): void
    {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');       // nginx
        header('Content-Encoding: none');      // impede o mod_deflate de bufferizar

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_implicit_flush(true);

        // Alguns proxies só liberam o primeiro byte depois de encher um buffer
        // mínimo. Um comentário SSE de padding destrava sem sujar o stream.
        echo ':' . str_repeat(' ', 4096) . "\n\n";

        // O visitante fechar a aba não pode deixar a resposta pela metade sem
        // gravar o que já foi produzido.
        ignore_user_abort(true);
        set_time_limit(120);
    }

    /** @param array<string, mixed>|list<mixed> $dados */
    public static function evento(string $evento, array $dados): void
    {
        echo 'event: ' . $evento . "\n";
        echo 'data: ' . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }

    /**
     * Encerra com uma mensagem de erro e sai.
     *
     * Recebe SEMPRE o texto público. Nome de modelo, provedor e status HTTP
     * não podem chegar ao visitante — no endpoint público isso deixaria de ser
     * uma questão de experiência e viraria vazamento de infraestrutura.
     */
    public static function falhar(string $mensagemPublica): never
    {
        self::evento('erro', ['mensagem' => $mensagemPublica]);
        exit;
    }
}
