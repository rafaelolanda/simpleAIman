<?php

declare(strict_types=1);

namespace SimpleAIman\Atendimento;

use Database;
use Mailer;
use Metrics;
use PDO;
use Throwable;

/**
 * A fila do handoff Nível 2 — transferência de uma conversa para gente.
 *
 * Toda a transição de `conversas.modo` passa por aqui. É de propósito: o modo
 * decide se o bot fala ou cala (`ChatService::botDeveResponder()`), e espalhar
 * `UPDATE conversas SET modo` pelo código seria criar a chance de alguém
 * transferir sem calar o bot, ou calar o bot sem avisar ninguém.
 *
 *   bot ──solicitar()──> aguardando ──assumir()──> humano ──encerrar()──> encerrada
 *                             │                      │
 *                             └──expirar()───────────┴──devolverAoBot()──> bot
 *
 * A regra que sustenta tudo: **não se promete o que não se pode cumprir**.
 * Se ninguém está disponível, `solicitar()` recusa a transferência em vez de
 * colocar a pessoa numa sala vazia — e quem chamou cai no Nível 1 (chamado por
 * e-mail), que funciona às 3h da manhã.
 */
final class Fila
{
    /**
     * Quanto tempo alguém espera antes de desistirmos por ela.
     *
     * Não existe "esperar indefinidamente": a aba fica aberta, a pessoa vai
     * embora e a conversa morre em `aguardando` sem ninguém saber. Passado o
     * prazo, a conversa volta para o bot, que oferece registrar um chamado.
     */
    public const ESPERA_MAX_MIN = 5;

    // -----------------------------------------------------------------
    // Disponibilidade
    // -----------------------------------------------------------------

    /**
     * Atendentes prontos agora, preferindo os do setor.
     *
     * A preferência é por setor, mas o desempate é "qualquer um disponível":
     * um setor sem atendente de plantão nunca transferiria, e alguém que pode
     * redirecionar internamente é melhor que ninguém.
     *
     * @return list<array<string, mixed>>
     */
    public static function disponiveis(?int $setorId = null): array
    {
        $pdo = Database::connection();

        if ($setorId !== null) {
            $stmt = $pdo->prepare(
                'SELECT id, usuario, nome, email FROM admin_users
                 WHERE atende = 1 AND disponivel = 1 AND setor_id = :s ORDER BY id'
            );
            $stmt->execute(['s' => $setorId]);
            $doSetor = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($doSetor !== []) {
                return $doSetor;
            }
        }

        return $pdo->query(
            'SELECT id, usuario, nome, email FROM admin_users
             WHERE atende = 1 AND disponivel = 1 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function haDisponivel(?int $setorId = null): bool
    {
        return self::disponiveis($setorId) !== [];
    }

    // -----------------------------------------------------------------
    // Transições
    // -----------------------------------------------------------------

    /**
     * Coloca a conversa na fila, se houver quem atenda.
     *
     * @return array{transferido: bool, atendentes?: int}
     */
    public static function solicitar(int $conversaId, ?int $setorId = null, string $motivo = ''): array
    {
        $atendentes = self::disponiveis($setorId);

        if ($atendentes === []) {
            return ['transferido' => false];
        }

        $pdo = Database::connection();
        $agora = now();

        $pdo->prepare(
            "UPDATE conversas SET modo = 'aguardando', aguardando_desde = :agora, editado_em = :agora
             WHERE id = :id AND modo IN ('bot', 'aguardando')"
        )->execute(['agora' => $agora, 'id' => $conversaId]);

        self::registrarAviso($conversaId, 'Transferência para atendimento humano solicitada.');

        Metrics::log('handoff_solicitado', $setorId ?? 0);

        self::avisarAtendentes($conversaId, $atendentes, $motivo);

        return ['transferido' => true, 'atendentes' => count($atendentes)];
    }

    /**
     * Um atendente assume a conversa. Só a primeira tentativa vence.
     *
     * A condição `modo = 'aguardando'` na cláusula WHERE é o que evita dois
     * atendentes abrindo a mesma conversa ao mesmo tempo e digitando por cima
     * um do outro — quem chegar depois recebe `false` e vê a fila atualizada.
     */
    public static function assumir(int $conversaId, int $atendenteId): bool
    {
        $pdo = Database::connection();
        $agora = now();

        $stmt = $pdo->prepare(
            "UPDATE conversas SET modo = 'humano', atendente_id = :a, editado_em = :agora
             WHERE id = :id AND modo = 'aguardando'"
        );
        $stmt->execute(['a' => $atendenteId, 'id' => $conversaId, 'agora' => $agora]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $nome = self::nomeDoAtendente($atendenteId);
        self::registrarAviso($conversaId, $nome . ' entrou na conversa.');

        return true;
    }

    /**
     * Devolve a conversa ao bot, preservando o contexto.
     *
     * O histórico do atendente continua na conversa, marcado com
     * `autor_tipo = 'atendente'`. É por isso que aquela coluna existe: sem
     * ela, o bot ao retomar leria a fala do atendente como se fosse dele
     * mesmo e passaria a se contradizer.
     */
    public static function devolverAoBot(int $conversaId, string $motivo = ''): void
    {
        Database::connection()->prepare(
            "UPDATE conversas SET modo = 'bot', atendente_id = NULL, aguardando_desde = NULL, editado_em = :agora
             WHERE id = :id"
        )->execute(['id' => $conversaId, 'agora' => now()]);

        self::registrarAviso(
            $conversaId,
            $motivo !== '' ? $motivo : 'Atendimento humano encerrado. O assistente voltou a responder.'
        );
    }

    /**
     * Um atendente devolve a conversa à fila, para outra pessoa assumir.
     *
     * Volta para `aguardando`, e NÃO reserva a conversa para o destinatário.
     * Reservar seria o desenho intuitivo e o errado: se a pessoa escolhida
     * saísse para o almoço, a conversa ficaria trancada esperando alguém que
     * não vai voltar, enquanto três colegas disponíveis olham a fila vazia.
     * Quem foi escolhido recebe o e-mail; qualquer um pode assumir.
     *
     * O relógio de abandono recomeça, então a conversa também não fica presa
     * caso ninguém pegue.
     */
    public static function repassar(int $conversaId, int $deAtendenteId, ?int $paraAtendenteId, string $motivo = ''): bool
    {
        $pdo = Database::connection();
        $agora = now();

        $stmt = $pdo->prepare(
            "UPDATE conversas SET modo = 'aguardando', atendente_id = NULL, aguardando_desde = :agora, editado_em = :agora
             WHERE id = :id AND modo = 'humano' AND atendente_id = :de"
        );
        $stmt->execute(['agora' => $agora, 'id' => $conversaId, 'de' => $deAtendenteId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $de = self::nomeDoAtendente($deAtendenteId);
        $para = $paraAtendenteId !== null ? self::nomeDoAtendente($paraAtendenteId) : null;

        // O que o VISITANTE vê é neutro e curto. Quem passou para quem, e por
        // quê, é processo interno: dizer "Fulano devolveu para a fila, pedindo
        // para Ciclano" expõe a organização por dentro e ainda soa como se
        // estivessem empurrando a pessoa de mão em mão.
        self::registrarAviso($conversaId, 'Estamos transferindo você para outro atendente. Um instante.');

        // O detalhe inteiro vira nota interna: quem assumir entra sabendo de
        // quem veio e por quê, sem que nada disso atravesse.
        self::registrarNota(
            $conversaId,
            $deAtendenteId,
            'Repassada por ' . $de
                . ($para !== null ? ', pedindo para ' . $para : '')
                . ($motivo !== '' ? ' — ' . $motivo : '.')
        );

        $destinos = $paraAtendenteId !== null
            ? array_values(array_filter(self::disponiveis(), static fn (array $a): bool => (int) $a['id'] === $paraAtendenteId))
            : self::disponiveis();

        if ($destinos !== []) {
            self::avisarAtendentes($conversaId, $destinos, 'Repassada por ' . $de . ($motivo !== '' ? ': ' . $motivo : ''));
        }

        return true;
    }

    public static function encerrar(int $conversaId): void
    {
        Database::connection()->prepare(
            "UPDATE conversas SET modo = 'encerrada', editado_em = :agora WHERE id = :id"
        )->execute(['id' => $conversaId, 'agora' => now()]);

        self::registrarAviso($conversaId, 'Atendimento encerrado.');
    }

    /**
     * Fecha o laço das conversas que ficaram esperando e ninguém assumiu.
     *
     * Chamada de forma oportunista (a cada consulta do painel e do widget) em
     * vez de depender só do cron: em hospedagem compartilhada o cron pode ser
     * de 5 em 5 minutos ou simplesmente não existir, e este é justamente o
     * caso em que há alguém do outro lado olhando a tela.
     *
     * @return int quantas expiraram
     */
    public static function expirarAbandonadas(): int
    {
        $pdo = Database::connection();
        $limite = date('Y-m-d H:i:s', time() - (self::ESPERA_MAX_MIN * 60));

        $stmt = $pdo->prepare(
            "SELECT id FROM conversas WHERE modo = 'aguardando' AND aguardando_desde IS NOT NULL AND aguardando_desde < :limite"
        );
        $stmt->execute(['limite' => $limite]);

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            // Volta para o bot com uma instrução, não com um pedido de
            // desculpas genérico: o próximo turno precisa oferecer a saída do
            // Nível 1, que é o que de fato resolve.
            self::devolverAoBot(
                (int) $id,
                'Ninguém do atendimento estava disponível. Peça desculpas pela espera e ofereça '
                    . 'registrar a dúvida para retorno posterior.'
            );
        }

        return count($ids);
    }

    // -----------------------------------------------------------------
    // Leitura para as telas
    // -----------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public static function aguardando(): array
    {
        return Database::connection()->query(
            "SELECT c.id, c.aguardando_desde, c.criado_em, a.nome AS agente,
                    (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS msgs,
                    (SELECT m.conteudo FROM mensagens m
                      WHERE m.conversa_id = c.id AND m.autor_tipo = 'usuario'
                      ORDER BY m.id DESC LIMIT 1) AS ultima
             FROM conversas c
             LEFT JOIN agentes a ON a.id = c.agente_id
             WHERE c.modo = 'aguardando'
             ORDER BY c.aguardando_desde ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function emAtendimento(?int $atendenteId = null): array
    {
        $pdo = Database::connection();

        $sql = "SELECT c.id, c.atendente_id, c.editado_em, a.nome AS agente, u.usuario AS atendente,
                       (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS msgs
                FROM conversas c
                LEFT JOIN agentes a ON a.id = c.agente_id
                LEFT JOIN admin_users u ON u.id = c.atendente_id
                WHERE c.modo = 'humano'";

        if ($atendenteId !== null) {
            $stmt = $pdo->prepare($sql . ' AND c.atendente_id = :a ORDER BY c.editado_em DESC');
            $stmt->execute(['a' => $atendenteId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $pdo->query($sql . ' ORDER BY c.editado_em DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function conversa(int $conversaId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, a.nome AS agente, u.usuario AS atendente
             FROM conversas c
             LEFT JOIN agentes a ON a.id = c.agente_id
             LEFT JOIN admin_users u ON u.id = c.atendente_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $conversaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Mensagens da conversa a partir de um id — a base do polling.
     *
     * `$apenasParaVisitante` filtra o que o widget deve receber: bot e
     * visitante ele já tem na tela (chegaram pelo SSE e pela própria
     * digitação); o que falta é a fala do atendente e os avisos do sistema.
     *
     * @return list<array<string, mixed>>
     */
    public static function mensagensDesde(int $conversaId, int $desde, bool $apenasParaVisitante = false): array
    {
        $filtro = $apenasParaVisitante ? " AND autor_tipo IN ('atendente', 'aviso')" : '';

        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.autor_tipo, m.conteudo, m.criado_em,
                    u.nome AS autor_nome, u.usuario AS autor_usuario
             FROM mensagens m
             LEFT JOIN admin_users u ON u.id = m.autor_id
             WHERE m.conversa_id = :c AND m.id > :desde' . $filtro . '
             ORDER BY m.id ASC LIMIT 50'
        );
        $stmt->execute(['c' => $conversaId, 'desde' => $desde]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aviso visível para os DOIS lados ("Fulano entrou na conversa").
     *
     * Usa `autor_tipo = 'aviso'`, e não `'sistema'`, porque `'sistema'` já
     * carrega os diagnósticos internos do provedor (`[provedor_cota 429] …`),
     * que o próprio ChatService documenta como coisa que nunca vai para o
     * chat. Eram dois significados na mesma etiqueta, e o filtro do widget
     * não tinha como distinguir — entregaria erro técnico ao visitante.
     */
    public static function registrarAviso(int $conversaId, string $texto): void
    {
        self::gravar($conversaId, 'aviso', $texto, null);
    }

    /**
     * Recado visível só para quem trabalha aqui dentro.
     *
     * Não vaza por construção, e isso é sorte de projeto que vale manter: o
     * filtro do visitante é uma LISTA DE PERMISSÃO (`atendente`, `aviso`).
     * Tipo novo nasce invisível para fora — ninguém precisa lembrar de
     * excluí-lo. Fosse lista de bloqueio, esquecer uma linha vazaria nota
     * interna para o visitante.
     */
    public static function registrarNota(int $conversaId, int $atendenteId, string $texto): int
    {
        return self::gravar($conversaId, 'nota', $texto, $atendenteId);
    }

    public static function registrarAtendente(int $conversaId, int $atendenteId, string $texto): int
    {
        return self::gravar($conversaId, 'atendente', $texto, $atendenteId);
    }

    // -----------------------------------------------------------------

    private static function gravar(int $conversaId, string $autorTipo, string $texto, ?int $autorId): int
    {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO mensagens (conversa_id, autor_tipo, autor_id, conteudo, criado_em)
             VALUES (:c, :t, :a, :conteudo, :agora)'
        )->execute([
            'c' => $conversaId,
            't' => $autorTipo,
            'a' => $autorId,
            'conteudo' => texto_utf8($texto),
            'agora' => now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function nomeDoAtendente(int $id): string
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(NULLIF(nome, \'\'), usuario) FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string) ($stmt->fetchColumn() ?: 'Atendente');
    }

    /**
     * Avisa por e-mail quem está disponível.
     *
     * Em hospedagem compartilhada não há push nem WebSocket: o e-mail é o
     * único aviso que alcança alguém que não esteja com o painel aberto. O
     * painel tem o próprio alerta sonoro para quem já está na tela.
     *
     * @param list<array<string, mixed>> $atendentes
     */
    private static function avisarAtendentes(int $conversaId, array $atendentes, string $motivo): void
    {
        $destinos = array_values(array_filter(
            array_map(static fn (array $a): string => trim((string) ($a['email'] ?? '')), $atendentes),
            static fn (string $e): bool => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));

        if ($destinos === []) {
            return;
        }

        $url = APP_URL !== '' ? APP_URL . '/admin/atendimento.php?c=' . $conversaId : 'o painel';

        $corpo = "Um visitante pediu atendimento humano.\n\n"
            . ($motivo !== '' ? "Motivo: {$motivo}\n\n" : '')
            . "Conversa: #{$conversaId}\n"
            . "Atenda em: {$url}\n\n"
            . 'A conversa volta para o assistente automaticamente após ' . self::ESPERA_MAX_MIN . " minutos sem ninguém assumir.";

        foreach ($destinos as $destino) {
            try {
                Mailer::send($destino, "[Assistente] Atendimento pedido — conversa #{$conversaId}", $corpo);
            } catch (Throwable $e) {
                // Aviso que falha não pode derrubar a transferência: a conversa
                // já está na fila e aparece no painel de qualquer forma.
                error_log('[simpleAIman] aviso de handoff falhou: ' . $e->getMessage());
            }
        }
    }
}
