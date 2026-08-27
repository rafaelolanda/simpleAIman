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
            // Traduzido aqui, e não antes de gravar: o banco guarda o texto do
            // modelo como veio. Este é o único ponto por onde texto sai para o
            // WhatsApp, então é onde a tradução alcança tudo — resposta do
            // agente, aviso de falha e mensagem digitada pelo atendente.
            'text' => ['preview_url' => false, 'body' => mb_substr(markdown_para_whatsapp($texto), 0, 4000)],
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
     * Envia um arquivo.
     *
     * **Só o atendente humano chega aqui.** Não existe ferramenta que exponha
     * isto ao agente, e é decisão de desenho, não esquecimento: um modelo
     * escolhendo qual arquivo mandar para quem é risco sem ganho nenhum — o
     * agente não pede documento e não devolve documento.
     *
     * São dois passos, como no recebimento e pelo mesmo motivo (a Meta separa
     * o arquivo da mensagem): sobe o arquivo, recebe um id, manda a mensagem
     * referenciando esse id.
     *
     * @throws RuntimeException quando a Meta recusa o upload ou o envio
     */
    public function enviarMidia(
        string $para,
        string $caminho,
        string $mime,
        string $tipo,
        string $legenda = '',
        ?string $nomeArquivo = null,
    ): void {
        $token = $this->segredo('TOKEN');
        $numero = $this->segredo('PHONE_NUMBER_ID');

        if ($token === '' || $numero === '') {
            throw new RuntimeException(
                'Credenciais do WhatsApp ausentes no .env (' . self::prefixo($this->canal) . '_TOKEN / _PHONE_NUMBER_ID).'
            );
        }

        if (!is_file($caminho)) {
            throw new RuntimeException('Arquivo não encontrado para envio.');
        }

        $midiaId = $this->subirArquivo($numero, $token, $caminho, $mime);

        $conteudo = ['id' => $midiaId];

        // Áudio não aceita legenda na API; documento aceita e ainda leva o nome
        // que aparece para quem recebe. Mandar `caption` onde não cabe faz a
        // Meta recusar a mensagem inteira.
        if ($legenda !== '' && $tipo !== 'audio') {
            $conteudo['caption'] = mb_substr(markdown_para_whatsapp($legenda), 0, 1000);
        }

        if ($tipo === 'document' && $nomeArquivo !== null && $nomeArquivo !== '') {
            $conteudo['filename'] = mb_substr($nomeArquivo, 0, 200);
        }

        $corpo = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $para,
            'type' => $tipo,
            $tipo => $conteudo,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::API . rawurlencode($numero) . '/messages');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
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
            throw new RuntimeException(
                'Envio de mídia recusado (HTTP ' . $status . '): '
                . ($erroCurl ?: mb_substr((string) $resposta, 0, 300))
            );
        }
    }

    /** Sobe o arquivo e devolve o id da mídia na Meta. */
    private function subirArquivo(string $numero, string $token, string $caminho, string $mime): string
    {
        $ch = curl_init(self::API . rawurlencode($numero) . '/media');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
                'file' => new \CURLFile($caminho, $mime, basename($caminho)),
            ],
        ]);

        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $status >= 300) {
            throw new RuntimeException(
                'Upload recusado (HTTP ' . $status . '): '
                . ($erroCurl ?: mb_substr((string) $resposta, 0, 300))
            );
        }

        $dados = json_decode((string) $resposta, true);
        $id = is_array($dados) ? (string) ($dados['id'] ?? '') : '';

        if ($id === '') {
            throw new RuntimeException('A Meta aceitou o upload mas não devolveu id de mídia.');
        }

        return $id;
    }

    /**
     * Baixa uma mídia recebida.
     *
     * São dois passos, e não um: a Meta não entrega o arquivo pelo id. Primeiro
     * se pergunta o que é (`GET /{media-id}` devolve url, mime e tamanho), e só
     * então se baixa a url — **com o mesmo Bearer**, porque ela é autenticada
     * apesar de parecer pública.
     *
     * A url expira em minutos. Por isso o arquivo é guardado agora: guardar só
     * o id para buscar depois daria um link morto e uma conversa com um buraco
     * onde havia uma foto.
     *
     * @return array{bytes: string, mime: string, tamanho: int, sha256: ?string}
     *
     * @throws RuntimeException quando a Meta recusa, o tipo não é aceito ou o
     *                          arquivo passa do teto
     */
    public function baixarMidia(string $mediaId, int $tetoBytes, array $mimesAceitos): array
    {
        $token = $this->segredo('TOKEN');

        if ($token === '' || trim($mediaId) === '') {
            throw new RuntimeException('Credenciais ausentes ou id de mídia vazio.');
        }

        $meta = $this->pegarJson(self::API . rawurlencode($mediaId), $token);

        $url = (string) ($meta['url'] ?? '');
        $mime = strtolower(trim(explode(';', (string) ($meta['mime_type'] ?? ''))[0]));
        $tamanho = (int) ($meta['file_size'] ?? 0);

        if ($url === '') {
            throw new RuntimeException('A Meta não devolveu url para a mídia ' . $mediaId . '.');
        }

        // Os dois limites são checados ANTES de baixar, com o que a Meta
        // informou. Baixar para só então descobrir que não serve gastaria a
        // banda e o disco que o limite existe para proteger.
        if ($mimesAceitos !== [] && !in_array($mime, $mimesAceitos, true)) {
            throw new RuntimeException('Tipo não aceito: ' . ($mime ?: 'desconhecido'));
        }

        if ($tamanho > 0 && $tamanho > $tetoBytes) {
            throw new RuntimeException('Arquivo de ' . round($tamanho / 1048576, 1) . ' MB passa do teto.');
        }

        $bytes = $this->pegarBytes($url, $token, $tetoBytes);

        return [
            'bytes' => $bytes,
            'mime' => $mime ?: 'application/octet-stream',
            'tamanho' => strlen($bytes),
            'sha256' => isset($meta['sha256']) ? (string) $meta['sha256'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function pegarJson(string $url, string $token): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);

        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $status >= 300) {
            throw new RuntimeException('Consulta de mídia recusada (HTTP ' . $status . '): '
                . ($erroCurl ?: mb_substr((string) $resposta, 0, 200)));
        }

        $dados = json_decode((string) $resposta, true);

        return is_array($dados) ? $dados : [];
    }

    private function pegarBytes(string $url, string $token, int $tetoBytes): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            // Rede é rede: a Meta pode informar um tamanho e entregar outro.
            // Sem este corte, um arquivo maior que o anunciado passaria pelo
            // limite checado acima e cairia inteiro na memória.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $baixado): int => $baixado > $tetoBytes ? 1 : 0,
        ]);

        $bytes = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($bytes === false || $status >= 300) {
            throw new RuntimeException('Download da mídia recusado (HTTP ' . $status . '): '
                . ($erroCurl ?: 'sem corpo'));
        }

        if (strlen((string) $bytes) > $tetoBytes) {
            throw new RuntimeException('Arquivo passa do teto durante o download.');
        }

        return (string) $bytes;
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
