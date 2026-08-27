<?php

declare(strict_types=1);

namespace SimpleAIman\Canais;

use Database;
use PDO;
use RuntimeException;

/**
 * Arquivos recebidos numa conversa: onde ficam, como voltam e quando somem.
 *
 * Três decisões mandam aqui:
 *
 * 1. **Fora da raiz web.** O arquivo é conteúdo de conversa — a foto que
 *    alguém mandou, o áudio que gravou. Servido por URL direta, bastaria um id
 *    vazar num print para ficar aberto a qualquer um, sem login, para sempre.
 *
 * 2. **Nome sorteado, não o que a pessoa deu.** O nome original vai para o
 *    banco, onde o expurgo alcança; no disco ele viraria `contrato-joao.pdf`
 *    numa listagem de diretório. Sorteado também evita colisão e a travessia
 *    de caminho que um `../` no nome tentaria.
 *
 * 3. **Lista branca de tipos.** Quem envia escolhe o arquivo, então o tipo é
 *    entrada de fora. Sem lista, um `.php` gravado numa pasta que um dia seja
 *    servida vira execução remota.
 */
final class Anexos
{
    /**
     * Tipos aceitos e a extensão com que são gravados.
     *
     * A extensão sai DAQUI, nunca do nome que veio junto: é o que impede
     * `foto.jpg.php` de chegar ao disco com a extensão errada.
     */
    private const TIPOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    /** @return list<string> */
    public static function mimesAceitos(): array
    {
        return array_keys(self::TIPOS);
    }

    public static function tetoBytes(): int
    {
        return MIDIA_MAX_MB * 1048576;
    }

    /**
     * Grava o arquivo e registra a linha. Devolve o id do anexo.
     *
     * A ordem importa: arquivo primeiro, linha depois. Se a escrita falhar,
     * não sobra linha apontando para um arquivo que não existe — e uma linha
     * assim quebraria a tela do atendente sem dizer por quê.
     */
    public static function guardar(
        int $mensagemId,
        string $tipo,
        string $mime,
        string $bytes,
        ?string $nomeOriginal = null,
        ?string $externoId = null,
    ): int {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (!isset(self::TIPOS[$mime])) {
            throw new RuntimeException('Tipo não aceito: ' . ($mime ?: 'desconhecido'));
        }

        // Uma pasta por mês: diretório com dezenas de milhares de arquivos fica
        // lento para listar e impossível de inspecionar à mão.
        $relativo = 'whatsapp/' . date('Y/m');
        $destino = caminho_storage($relativo);

        if (!is_dir($destino)) {
            throw new RuntimeException('Não foi possível criar a pasta de anexos.');
        }

        $arquivo = bin2hex(random_bytes(16)) . '.' . self::TIPOS[$mime];

        if (@file_put_contents($destino . '/' . $arquivo, $bytes) === false) {
            throw new RuntimeException('Não foi possível gravar o anexo em disco.');
        }

        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO mensagem_anexos (mensagem_id, tipo, mime, tamanho, nome_original, caminho, externo_id, criado_em)
             VALUES (:m, :t, :mime, :tam, :nome, :cam, :ext, :agora)'
        )->execute([
            'm' => $mensagemId,
            't' => $tipo,
            'mime' => $mime,
            'tam' => strlen($bytes),
            'nome' => $nomeOriginal !== null && $nomeOriginal !== '' ? mb_substr($nomeOriginal, 0, 200) : null,
            'cam' => $relativo . '/' . $arquivo,
            'ext' => $externoId,
            'agora' => now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function porId(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM mensagem_anexos WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $anexo = $stmt->fetch(PDO::FETCH_ASSOC);

        return $anexo ?: null;
    }

    /**
     * @param array<string, mixed> $anexo
     */
    public static function caminhoAbsoluto(array $anexo): string
    {
        return caminho_storage() . '/' . ltrim((string) $anexo['caminho'], '/');
    }

    /**
     * Anexos de várias mensagens de uma vez, agrupados por mensagem.
     *
     * Existe para a tela não fazer uma consulta por mensagem: o painel abre
     * conversas inteiras, e o N+1 apareceria como lentidão sem causa visível.
     *
     * @param list<int> $mensagemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function porMensagens(array $mensagemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mensagemIds)));

        if ($ids === []) {
            return [];
        }

        // Os ids são inteiros por construção (o intval acima), então interpolar
        // é seguro — e um IN com placeholders variáveis não cabe em prepare
        // reutilizável.
        $lista = implode(',', $ids);

        $linhas = Database::connection()->query(
            "SELECT * FROM mensagem_anexos WHERE mensagem_id IN ({$lista}) ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $agrupado = [];

        foreach ($linhas as $anexo) {
            $agrupado[(int) $anexo['mensagem_id']][] = $anexo;
        }

        return $agrupado;
    }

    /** @return list<array<string, mixed>> */
    public static function daMensagem(int $mensagemId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM mensagem_anexos WHERE mensagem_id = :m ORDER BY id'
        );
        $stmt->execute(['m' => $mensagemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Apaga os ARQUIVOS de uma conversa e marca as linhas.
     *
     * Chamado pela retenção. A linha fica, com `removido_em` preenchido: é o
     * que distingue "não tinha anexo" de "tinha e foi apagado" — e sem essa
     * distinção não há como responder a um titular o que aconteceu com o
     * arquivo dele.
     *
     * @return int quantos arquivos sumiram do disco
     */
    public static function apagarDaConversa(int $conversaId): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT a.id, a.caminho FROM mensagem_anexos a
             JOIN mensagens m ON m.id = a.mensagem_id
             WHERE m.conversa_id = :c AND a.removido_em IS NULL'
        );
        $stmt->execute(['c' => $conversaId]);

        $apagados = 0;
        $marcar = $pdo->prepare(
            'UPDATE mensagem_anexos SET removido_em = :agora, nome_original = NULL WHERE id = :id'
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $anexo) {
            $caminho = caminho_storage() . '/' . ltrim((string) $anexo['caminho'], '/');

            // Arquivo já ausente não impede a marcação: o objetivo é o disco
            // limpo, e um arquivo que já sumiu está no estado desejado.
            if (is_file($caminho) && @unlink($caminho)) {
                $apagados++;
            }

            $marcar->execute(['agora' => now(), 'id' => (int) $anexo['id']]);
        }

        return $apagados;
    }
}
