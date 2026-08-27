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
    // Os prazos moram no .env (ver app/config.php): ESPERA_MAX_MIN,
    // INATIVIDADE_AVISO_MIN, INATIVIDADE_HUMANO_MIN e INATIVIDADE_BOT_MIN.
    // Estavam fixos aqui, e "quanto tempo esperar" é justamente o número que
    // muda de cliente para cliente sem que ninguém queira mexer em código.

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

        // Três condições, e as três importam:
        //
        //   atende      quem administra decidiu que essa pessoa recebe fila
        //   disponivel  INTENÇÃO dela agora (o botão; serve para almoçar)
        //   visto_em    PRESENÇA de fato — a tela está aberta neste instante
        //
        // Sem a terceira, quem fechasse o navegador sem clicar em "ausente"
        // continuaria recebendo transferência, e o agente prometeria uma
        // pessoa que não está lá. Intenção esquecida ligada é o padrão, não a
        // exceção: ninguém lembra de se desligar ao ir embora.
        $vivo = "AND visto_em IS NOT NULL AND visto_em >= :desde";
        $desde = date('Y-m-d H:i:s', time() - PRESENCA_JANELA_SEG);

        if ($setorId !== null) {
            $stmt = $pdo->prepare(
                "SELECT id, usuario, nome, email FROM admin_users
                 WHERE atende = 1 AND disponivel = 1 AND setor_id = :s {$vivo} ORDER BY id"
            );
            $stmt->execute(['s' => $setorId, 'desde' => $desde]);
            $doSetor = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($doSetor !== []) {
                return $doSetor;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT id, usuario, nome, email FROM admin_users
             WHERE atende = 1 AND disponivel = 1 {$vivo} ORDER BY id"
        );
        $stmt->execute(['desde' => $desde]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Batimento: o painel passou por aqui agora.
     *
     * Chamado pela consulta periódica da tela de atendimento, que já roda a
     * cada 4 segundos — a presença sai de graça de um mecanismo que já existia,
     * sem timer novo nem requisição extra.
     */
    public static function baterPonto(int $atendenteId): void
    {
        Database::connection()
            ->prepare('UPDATE admin_users SET visto_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $atendenteId]);
    }

    /**
     * Encerra a presença na saída explícita (logout, ou "ausente").
     *
     * Não é obrigatório — a janela expira sozinha — mas evita os dois minutos
     * em que a pessoa já foi embora e a fila ainda conta com ela.
     */
    public static function encerrarPresenca(int $atendenteId): void
    {
        Database::connection()
            ->prepare('UPDATE admin_users SET visto_em = NULL WHERE id = :id')
            ->execute(['id' => $atendenteId]);
    }

    /**
     * Devolve à fila as conversas presas com quem sumiu.
     *
     * O atendente fechou o navegador no meio de um atendimento: sem isto a
     * conversa fica dele para sempre, e o visitante espera uma resposta que
     * não vem de ninguém. Volta para `aguardando`, e quem estiver online
     * assume.
     *
     * O visitante vê o mesmo aviso neutro do repasse comum — ele não precisa
     * saber que alguém sumiu.
     *
     * @return int quantas voltaram
     */
    public static function resgatarOrfas(): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT c.id, c.atendente_id FROM conversas c
             JOIN admin_users u ON u.id = c.atendente_id
             WHERE c.modo = 'humano'
               AND (u.visto_em IS NULL OR u.visto_em < :limite)"
        );
        $stmt->execute(['limite' => date('Y-m-d H:i:s', time() - (PRESENCA_ORFA_MIN * 60))]);

        $orfas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orfas as $c) {
            $pdo->prepare(
                "UPDATE conversas SET modo = 'aguardando', atendente_id = NULL,
                        aguardando_desde = :agora, editado_em = :agora
                 WHERE id = :id AND modo = 'humano'"
            )->execute(['id' => (int) $c['id'], 'agora' => now()]);

            self::registrarAviso((int) $c['id'], 'Estamos transferindo você para outro atendente. Um instante.');

            self::registrarNota(
                (int) $c['id'],
                (int) $c['atendente_id'],
                'Devolvida à fila automaticamente: ' . self::nomeDoAtendente((int) $c['atendente_id'])
                    . ' saiu do painel sem encerrar o atendimento.'
            );
        }

        return count($orfas);
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
     * Motivos pelos quais uma conversa volta ao assistente, e o que o VISITANTE
     * lê em cada caso.
     *
     * O texto mora aqui, e quem chama escolhe um motivo — não escreve a frase.
     * Isso não é preciosismo: a versão anterior aceitava texto livre, e o
     * `expirarAbandonadas()` passava por ali uma INSTRUÇÃO destinada ao modelo
     * ("Peça desculpas pela espera e ofereça registrar a dúvida"). Ela foi
     * gravada como aviso e apareceu na tela do visitante.
     *
     * Falhou nas duas pontas, aliás: `ChatService::historico()` só alimenta o
     * modelo com `usuario`, `bot` e `atendente`, então a instrução também nunca
     * chegou a quem era destinada.
     *
     * Com o texto fechado num mapa, essa classe de erro deixa de existir: não
     * há como um chamador injetar nada na conversa.
     */
    private const MOTIVOS_DEVOLUCAO = [
        'encerrado' => 'Atendimento humano encerrado. O assistente voltou a responder.',
        'expirado' => null, // sem aviso: quem fala é o próprio assistente, abaixo
    ];

    /**
     * Devolve a conversa ao bot, preservando o contexto.
     *
     * O histórico do atendente continua na conversa, marcado com
     * `autor_tipo = 'atendente'`. É por isso que aquela coluna existe: sem ela,
     * o bot ao retomar leria a fala do atendente como se fosse dele mesmo e
     * passaria a se contradizer.
     */
    public static function devolverAoBot(int $conversaId, string $motivo = 'encerrado'): void
    {
        Database::connection()->prepare(
            "UPDATE conversas SET modo = 'bot', atendente_id = NULL, aguardando_desde = NULL, editado_em = :agora
             WHERE id = :id"
        )->execute(['id' => $conversaId, 'agora' => now()]);

        // array_key_exists, e não `??`: aqui `null` é um valor com significado
        // ("não avise nada, quem fala é o assistente"), e o `??` o trataria
        // como ausente, caindo no texto de 'encerrado'.
        $aviso = array_key_exists($motivo, self::MOTIVOS_DEVOLUCAO)
            ? self::MOTIVOS_DEVOLUCAO[$motivo]
            : self::MOTIVOS_DEVOLUCAO['encerrado'];

        if ($aviso !== null) {
            self::registrarAviso($conversaId, $aviso);
        }
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

    /**
     * Encerra o ATENDIMENTO, não a conversa.
     *
     * A distinção importa: `encerrada` tira a conversa do painel e fecha o
     * ciclo com a pessoa, mas não pode virar uma porta trancada. Se ela
     * escrever de novo, `reabrirSeEncerrada()` devolve o assunto ao
     * assistente — e é por isso que o aviso já avisa que dá para continuar.
     */
    /**
     * O que o VISITANTE lê ao ver a conversa encerrar, por motivo.
     *
     * Mesmo desenho de MOTIVOS_DEVOLUCAO, e pela mesma razão: quem chama
     * escolhe um motivo, nunca escreve a frase. Foi assim que uma instrução
     * destinada ao modelo acabou na tela do visitante.
     *
     * `null` = encerra em silêncio.
     */
    private const MOTIVOS_ENCERRAMENTO = [
        'atendente' => 'Atendimento encerrado. Se precisar de mais alguma coisa, é só escrever.',
        'inatividade' => 'Encerramos por inatividade. Se precisar, é só escrever que retomamos daqui.',
        // Conversa só com o assistente, parada: ninguém está olhando, e
        // escrever numa sala vazia não serve a ninguém. Se a pessoa voltar,
        // reabrirSeEncerrada() retoma sem que ela veja nada estranho.
        'inatividade_bot' => null,
    ];

    public static function encerrar(int $conversaId, string $motivo = 'atendente'): void
    {
        Database::connection()->prepare(
            "UPDATE conversas SET modo = 'encerrada', atendente_id = NULL, aguardando_desde = NULL, editado_em = :agora
             WHERE id = :id"
        )->execute(['id' => $conversaId, 'agora' => now()]);

        $aviso = array_key_exists($motivo, self::MOTIVOS_ENCERRAMENTO)
            ? self::MOTIVOS_ENCERRAMENTO[$motivo]
            : self::MOTIVOS_ENCERRAMENTO['atendente'];

        if ($aviso !== null) {
            self::registrarAviso($conversaId, $aviso);
        }
    }

    /**
     * Devolve ao assistente uma conversa que tinha sido encerrada.
     *
     * Existe porque `encerrada` era um buraco negro: o bot não responde (certo)
     * e o widget para de consultar (também certo) — só que ninguém assumia. A
     * pessoa continuava digitando, a mensagem era gravada, e nada acontecia.
     * Chat que engole mensagem em silêncio é o pior resultado possível: ela não
     * sabe se falhou, se foi ignorada, ou se alguém vai ler depois.
     *
     * Chamada no início do turno, antes de decidir se o bot fala.
     */
    public static function reabrirSeEncerrada(int $conversaId): bool
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "UPDATE conversas SET modo = 'bot', editado_em = :agora WHERE id = :id AND modo = 'encerrada'"
        );
        $stmt->execute(['id' => $conversaId, 'agora' => now()]);

        return $stmt->rowCount() > 0;
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
        $limite = date('Y-m-d H:i:s', time() - (ESPERA_MAX_MIN * 60));

        $stmt = $pdo->prepare(
            "SELECT id FROM conversas WHERE modo = 'aguardando' AND aguardando_desde IS NOT NULL AND aguardando_desde < :limite"
        );
        $stmt->execute(['limite' => $limite]);

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            self::devolverAoBot((int) $id, 'expirado');

            // Frase FIXA, dita pelo próprio assistente — não uma instrução para
            // ele improvisar. O que precisa ser dito aqui é curto e sempre o
            // mesmo; pedir ao modelo que componha isso gastaria uma chamada e
            // abriria espaço para ele dizer outra coisa. É o mesmo raciocínio
            // da frase de encaminhamento, fixa desde o começo.
            //
            // Vai como `bot`, então entra no histórico do modelo: no próximo
            // turno ele sabe o que já foi oferecido.
            self::gravar(
                (int) $id,
                'bot',
                'Desculpe a espera! Não encontrei nenhum atendente disponível agora. '
                    . 'Quer que eu registre sua dúvida para alguém retornar?',
                null
            );
        }

        return count($ids);
    }

    /**
     * Fecha conversas paradas — o visitante calado, não a fila sem atendente.
     *
     * O sinal é a última mensagem **do visitante**, e não a última mensagem da
     * conversa: se o atendente escreveu cinco vezes e ninguém respondeu, quem
     * foi embora foi o visitante, e é justamente esse o caso a detectar.
     *
     * Só é seguro fazer isto porque `encerrada` deixou de ser porta trancada:
     * quem voltar e escrever reabre a conversa com o assistente.
     *
     * @return array{avisadas: int, humano: int, bot: int}
     */
    public static function encerrarInativas(): array
    {
        $pdo = Database::connection();
        $placar = ['avisadas' => 0, 'humano' => 0, 'bot' => 0];

        // "Silencioso desde": última fala do visitante, ou o início da conversa
        // se ele nunca falou.
        $ultimaFala = "COALESCE((SELECT MAX(m.criado_em) FROM mensagens m
                                  WHERE m.conversa_id = c.id AND m.autor_tipo = 'usuario'), c.criado_em)";

        $limite = static fn (int $min): string => date('Y-m-d H:i:s', time() - ($min * 60));

        // 1. Em atendimento humano e o visitante sumiu: avisa quem está do
        //    outro lado, em vez de encerrar por baixo dele. Pode ser que a
        //    pessoa tenha ido buscar um documento.
        if (INATIVIDADE_AVISO_MIN > 0) {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.atendente_id FROM conversas c
                  WHERE c.modo = 'humano'
                    AND {$ultimaFala} < :limite
                    -- Não avisa quem o passo 2 vai encerrar logo abaixo: seria
                    -- um bilhete para o atendente sobre uma conversa que fecha
                    -- no mesmo segundo.
                    AND {$ultimaFala} >= :limiteFinal
                    AND NOT EXISTS (
                        SELECT 1 FROM mensagens n
                         WHERE n.conversa_id = c.id AND n.autor_tipo = 'nota'
                           AND n.conteudo LIKE 'Visitante sem responder%'
                    )"
            );
            $stmt->execute([
                'limite' => $limite(INATIVIDADE_AVISO_MIN),
                'limiteFinal' => $limite(INATIVIDADE_HUMANO_MIN),
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                // A nota também serve de marca: o NOT EXISTS acima usa ela
                // para não repetir o aviso a cada passada do worker.
                self::registrarNota(
                    (int) $c['id'],
                    (int) $c['atendente_id'],
                    'Visitante sem responder há ' . INATIVIDADE_AVISO_MIN . ' minutos. '
                        . 'A conversa encerra sozinha em ' . INATIVIDADE_HUMANO_MIN . ' minutos de silêncio.'
                );
                $placar['avisadas']++;
            }
        }

        // 2. Silêncio longo demais, mesmo em atendimento: encerra e diz por quê.
        if (INATIVIDADE_HUMANO_MIN > 0) {
            $stmt = $pdo->prepare(
                "SELECT c.id FROM conversas c WHERE c.modo = 'humano' AND {$ultimaFala} < :limite"
            );
            $stmt->execute(['limite' => $limite(INATIVIDADE_HUMANO_MIN)]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                self::encerrar((int) $id, 'inatividade');
                $placar['humano']++;
            }
        }

        // 3. Conversa só com o assistente, parada. Encerra em SILÊNCIO: não há
        //    ninguém olhando, e um aviso numa aba abandonada não serve a
        //    ninguém — só apareceria dias depois, fora de contexto.
        if (INATIVIDADE_BOT_MIN > 0) {
            $stmt = $pdo->prepare(
                "SELECT c.id FROM conversas c WHERE c.modo = 'bot' AND {$ultimaFala} < :limite"
            );
            $stmt->execute(['limite' => $limite(INATIVIDADE_BOT_MIN)]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                self::encerrar((int) $id, 'inatividade_bot');
                $placar['bot']++;
            }
        }

        return $placar;
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

        // Aviso é visível ao visitante, então também precisa ser entregue —
        // "Fulano entrou na conversa" só faz sentido se a pessoa receber.
        \SimpleAIman\Canais\Saida::entregar($conversaId, $texto);
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

    /**
     * Fala do atendente: grava e ENTREGA.
     *
     * Gravar não basta em todo canal. No widget o navegador consulta e a
     * mensagem aparece sozinha; no WhatsApp ninguém consulta — sem o empurrão,
     * a resposta existe no banco e não no telefone da pessoa. E o atendente vê
     * a própria mensagem na tela e acha que respondeu, que é a pior forma de
     * falhar.
     */
    public static function registrarAtendente(int $conversaId, int $atendenteId, string $texto): int
    {
        $id = self::gravar($conversaId, 'atendente', $texto, $atendenteId);

        \SimpleAIman\Canais\Saida::entregar($conversaId, $texto);

        return $id;
    }

    /**
     * Mensagem do atendente com um arquivo junto.
     *
     * **Só existe para o operador humano.** O agente não tem caminho até aqui
     * — não há ferramenta que exponha envio de arquivo — e é decisão de
     * desenho: ele não pede documento e não devolve documento.
     *
     * Uma mensagem só, não duas: no WhatsApp o arquivo vai com legenda, então
     * gravar texto e anexo separados faria a tela do painel mostrar dois
     * balões onde a pessoa recebeu um.
     *
     * @param string $arquivoTmp caminho do arquivo já validado
     * @return array{id: int, anexo: int, entregue: bool}
     */
    public static function registrarAtendenteComArquivo(
        int $conversaId,
        int $atendenteId,
        string $legenda,
        string $arquivoTmp,
        string $mime,
        ?string $nomeOriginal,
    ): array {
        $tipo = \SimpleAIman\Canais\Anexos::tipoDoMime($mime);

        // O texto da mensagem é a legenda; sem ela, um rótulo, para o balão não
        // ficar vazio no painel e na busca do histórico.
        $id = self::gravar(
            $conversaId,
            'atendente',
            $legenda !== '' ? $legenda : '[' . $tipo . ' enviado]',
            $atendenteId
        );

        $bytes = (string) file_get_contents($arquivoTmp);
        $anexoId = \SimpleAIman\Canais\Anexos::guardar($id, $tipo, $mime, $bytes, $nomeOriginal);

        $anexo = \SimpleAIman\Canais\Anexos::porId($anexoId) ?? [];

        // Entrega falhando não desfaz a gravação: o atendente precisa ver o que
        // tentou mandar, e a tela avisa que não saiu.
        //
        // No widget web não há o que empurrar — gravar já é entregar —, então
        // `entregue` é verdade sem nenhum envio. Sem essa distinção a tela
        // acusaria falha sobre um arquivo que o visitante está vendo.
        $entregue = $anexo !== [] && (
            !\SimpleAIman\Canais\Saida::precisaEnviar($conversaId)
            || \SimpleAIman\Canais\Saida::entregarAnexo($conversaId, $anexo, $legenda)
        );

        return ['id' => $id, 'anexo' => $anexoId, 'entregue' => $entregue];
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
        // Quem tem e-mail utilizável. Sai cedo se ninguém tiver: montar o
        // corpo para depois não enviar a ninguém é trabalho à toa.
        $atendentes = array_values(array_filter(
            $atendentes,
            static function (array $a): bool {
                $email = trim((string) ($a['email'] ?? ''));

                return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            }
        ));

        if ($atendentes === []) {
            return;
        }

        $url = APP_URL !== '' ? APP_URL . '/admin/atendimento.php?c=' . $conversaId : 'o painel';

        $corpo = "Um visitante pediu atendimento humano.\n\n"
            . ($motivo !== '' ? "Motivo: {$motivo}\n\n" : '')
            . "Conversa: #{$conversaId}\n"
            . "Atenda em: {$url}\n\n"
            . 'A conversa volta para o assistente automaticamente após ' . ESPERA_MAX_MIN . " minutos sem ninguém assumir.";

        foreach ($atendentes as $a) {
            try {
                // Mesmo defeito do chamado: faltavam argumentos, e a falha era
                // engolida pelo catch — o atendente simplesmente nunca recebia
                // o aviso de que alguém estava esperando.
                if (!Mailer::send(
                    (string) $a['email'],
                    (string) ($a['nome'] ?: $a['usuario']),
                    "[Assistente] Atendimento pedido — conversa #{$conversaId}",
                    nl2br(e($corpo))
                )) {
                    error_log('[simpleAIman] aviso de handoff não saiu para ' . $a['email'] . '.');
                }
            } catch (Throwable $e) {
                // Aviso que falha não pode derrubar a transferência: a conversa
                // já está na fila e aparece no painel de qualquer forma.
                error_log('[simpleAIman] aviso de handoff falhou: ' . $e->getMessage());
            }
        }
    }
}
