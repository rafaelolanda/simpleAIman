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
            $id = (int) $c['id'];

            // Há OUTRO atendente para assumir?
            //
            // Sem esta pergunta, a conversa voltava para a fila mesmo com o
            // painel inteiro vazio, e a pessoa ouvia "estamos transferindo
            // você" para uma sala onde não havia ninguém. É a mesma promessa
            // que `solicitar()` se recusa a fazer desde o começo — faltava
            // aplicar a regra aqui, que é justamente onde ela é mais provável:
            // se o único atendente sumiu, o normal é não haver outro.
            //
            // Medido em uso real: 44 minutos de espera sem uma palavra.
            if (!self::haDisponivel()) {
                self::registrarNota(
                    $id,
                    (int) $c['atendente_id'],
                    'Atendimento encerrado automaticamente: ' . self::nomeDoAtendente((int) $c['atendente_id'])
                        . ' saiu do painel e não havia outro atendente disponível.'
                );

                self::devolverAoBot($id, 'expirado');

                self::registrarBot(
                    $id,
                    'O atendente precisou sair e não encontrei outra pessoa disponível agora. '
                        . 'Quer que eu registre sua dúvida para alguém retornar? '
                        . 'Se preferir, é só tentar de novo mais tarde.'
                );

                continue;
            }

            $pdo->prepare(
                "UPDATE conversas SET modo = 'aguardando', atendente_id = NULL,
                        aguardando_desde = :agora, editado_em = :agora
                 WHERE id = :id AND modo = 'humano'"
            )->execute(['id' => $id, 'agora' => now()]);

            self::registrarAviso($id, 'Estamos transferindo você para outro atendente. Um instante.');

            self::registrarNota(
                $id,
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

    /**
     * Quem está de plantão agora e com quantas conversas cada um.
     *
     * O NÚMERO, nunca o conteúdo. É o que permite a equipe se distribuir sem
     * abrir conversa alheia: ver que o colega está com sete e você com uma
     * muda a decisão de assumir a próxima da fila.
     *
     * Esta é a única informação sobre outros atendentes que um atendente comum
     * enxerga. A lista de conversas dos outros é de administrador — conversa
     * alheia é dado de terceiro, e a curiosidade não é justificativa.
     *
     * @return list<array{id: int, nome: string, conversas: int, eu: bool}>
     */
    public static function cargaDosAtendentes(int $euId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(u.nome, ''), u.usuario) AS nome,
                    (SELECT COUNT(*) FROM conversas c
                      WHERE c.atendente_id = u.id AND c.modo = 'humano') AS conversas
               FROM admin_users u
              WHERE u.atende = 1 AND u.disponivel = 1
                AND u.visto_em IS NOT NULL AND u.visto_em >= :desde
              ORDER BY conversas ASC, nome ASC"
        );
        $stmt->execute(['desde' => date('Y-m-d H:i:s', time() - PRESENCA_JANELA_SEG)]);

        return array_map(
            static fn (array $u): array => [
                'id' => (int) $u['id'],
                'nome' => (string) $u['nome'],
                'conversas' => (int) $u['conversas'],
                'eu' => (int) $u['id'] === $euId,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
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

        // Nome PUBLICO: este texto vai para o visitante.
        //
        // Entre asteriscos porque essa e a marcacao que os dois canais
        // entendem: `formatar_whatsapp()` transforma em <strong> no painel e no
        // widget, e o WhatsApp faz negrito nativo. Uma escrita, dois destinos.
        // Asterisco DENTRO do nome sai antes, senao quebra a marcacao e o
        // visitante ve o simbolo cru.
        $nome = str_replace(['*', '_', '~'], '', self::nomeDoAtendente($atendenteId, publico: true));
        self::registrarAviso($conversaId, '*' . $nome . '* entrou na conversa.');

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
            // `registrarBot()` e não `gravar()`: a frase precisa CHEGAR. Com
            // `gravar()` ela ficava só no banco, e quem esperou pelo WhatsApp
            // não recebia nem o aviso de que não havia ninguém — a pior versão
            // possível deste caso, porque a pessoa continua esperando.
            self::registrarBot(
                (int) $id,
                'Desculpe a espera! Não encontrei nenhum atendente disponível agora. '
                    . 'Quer que eu registre sua dúvida para alguém retornar? '
                    . 'Se preferir, é só tentar de novo mais tarde.'
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
    /**
     * Aproveita o que a pessoa escreveu enquanto esperava na fila.
     *
     * Chamado só para conversa que NÃO está sendo respondida pelo assistente,
     * ou seja, quem está aguardando atendente. Nesse ponto ela acabou de ser
     * convidada a se identificar: pelo roteador, junto da confirmação da
     * transferência, ou pela ferramenta, no modo com IA.
     *
     * **O convite é o que torna a captação legítima.** Extrair e-mail de
     * qualquer mensagem porque tem formato de e-mail seria coletar dado pessoal
     * sem pedir, e este projeto já decidiu não fazer isso. Por isso a função
     * também só preenche o que ainda está vazio: quem já se identificou não é
     * reinterpretado a cada frase.
     *
     * O nome é o que sobra depois de tirar o contato e as palavras de ligação.
     * Reconhecer nome próprio de verdade exigiria modelo, e aqui não há um —
     * este caminho existe justamente para funcionar quando o provedor caiu.
     */
    public static function anotarContatoDeEspera(int $conversaId, string $texto): void
    {
        // A JANELA DO CONVITE, e não apenas "está aguardando".
        //
        // A premissa anterior era que quem está na fila acabou de ser convidado
        // a se identificar. É falsa: uma conversa fica em `aguardando`
        // indefinidamente quando ninguém assume e o cron não roda para
        // expirá-la. Em teste real, um widget reaberto DIAS depois caiu aqui, e
        // a primeira frase digitada na sessão nova foi lida como resposta a um
        // convite feito em outro dia.
        //
        // `humano` também sai: com um atendente na conversa, quem pergunta o
        // nome é ele, e o que a pessoa escreve é resposta a outra coisa.
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(contato_nome, '') n, COALESCE(contato_valor, '') v,
                    modo, aguardando_desde
               FROM conversas WHERE id = :id"
        );
        $stmt->execute(['id' => $conversaId]);
        $atual = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (($atual['modo'] ?? '') !== 'aguardando' || ($atual['aguardando_desde'] ?? null) === null) {
            return;
        }

        if (strtotime((string) $atual['aguardando_desde']) < time() - 300) {
            return;
        }

        if (($atual['n'] ?? '') !== '' && ($atual['v'] ?? '') !== '') {
            return;
        }

        self::registrarContatoAnotado(
            $conversaId,
            self::nomeDeclarado($texto),
            self::contatoNoTexto($texto)
        );
    }

    /**
     * E-mail ou telefone dentro de uma frase.
     *
     * Aqui adivinhar é seguro porque o FORMATO decide: um endereço de e-mail
     * não se confunde com outra coisa, e uma sequência longa de dígitos com
     * pontuação de telefone também não.
     */
    private static function contatoNoTexto(string $texto): string
    {
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', $texto, $m) === 1) {
            return $m[0];
        }

        if (preg_match('/(?:\+?\d[\d\s().-]{8,}\d)/u', $texto, $m) === 1) {
            return trim($m[0]);
        }

        return '';
    }

    /**
     * Nome, e SÓ quando a pessoa o declara.
     *
     * A versão anterior tentava deduzir: tirava o contato e as palavras de
     * ligação e aceitava o que sobrasse, desde que curto. Em uso real, a
     * primeira frase de um visitante — "quero desconto" — virou o nome dele.
     * Duas palavras, catorze caracteres, passou em todos os testes que eu tinha
     * escrito.
     *
     * O erro não era o limite estar frouxo: era eu estar adivinhando. Apertar o
     * número só trocaria "quero desconto" por outra frase curta qualquer, e
     * cada aperto novo derrubaria junto um nome legítimo.
     *
     * Agora só entra o que vem numa fórmula de apresentação. "Sou o Pedro" é a
     * pessoa dizendo quem é; "quero desconto" é ela dizendo o que quer, e a
     * diferença entre as duas não está no tamanho.
     *
     * O custo é perder "João Silva, 55 99999-8888", em que o nome está lá sem
     * fórmula nenhuma. Aceito de propósito: o telefone ainda é capturado, e o
     * atendente prefere ver um telefone a ser apresentado a alguém chamado
     * "Quero Desconto".
     */
    private static function nomeDeclarado(string $texto): string
    {
        // A borda de palavra impede casar no meio de outra: sem ela, "pessoa"
        // conteria "sou". Cada alternativa consome o próprio espaço final, para
        // a captura começar já na primeira letra do nome.
        $formulas = [
            // O artigo opcional vale para TODAS as fórmulas, não só para "sou":
            // "aqui é o Carlos" é tão comum quanto "sou o Carlos", e sem isto o
            // "o" caía dentro da captura e era descartado por ser curto demais.
            '/\b(?:meu\s+nome\s+(?:é|eh|e)|me\s+chamo|aqui\s+(?:é|eh|e)|sou)\s+(?:o\s+|a\s+)?([\p{L}][\p{L}\s]{1,39})/iu',
            '/^\s*nome\s*[:\-]\s*([\p{L}][\p{L}\s]{1,39})/iu',
        ];

        // "Sou de Porto Alegre" não apresenta ninguém: diz de onde a pessoa é.
        // O mesmo vale para "sou cliente", "sou aluno". Sem esta lista, a forma
        // mais comum de continuar a frase depois de "sou" virava nome próprio.
        $naoSaoNomes = [
            'de', 'da', 'do', 'dos', 'das', 'um', 'uma', 'muito', 'bem', 'apenas',
            'so', 'só', 'cliente', 'aluno', 'novo', 'nova', 'aqui', 'eu',
        ];

        foreach ($formulas as $formula) {
            if (preg_match($formula, $texto, $m) !== 1) {
                continue;
            }

            // Corta na primeira pontuação ou conectivo: em "sou o Pedro e
            // preciso de ajuda", o nome acaba no Pedro.
            $nome = preg_split('/\s+(?:e|mas|que|do|da|preciso|quero|gostaria)\s+/iu', trim($m[1]))[0] ?? '';
            $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
            $palavras = $nome === '' ? [] : explode(' ', $nome);

            // Até três palavras: nome composto cabe, frase não.
            if ($palavras === [] || count($palavras) > 3) {
                continue;
            }

            if (in_array(mb_strtolower($palavras[0]), $naoSaoNomes, true)) {
                continue;
            }

            foreach ($palavras as $palavra) {
                if (mb_strlen($palavra) < 2) {
                    continue 2;
                }
            }

            return mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
        }

        return '';
    }

    private static function registrarContatoAnotado(int $conversaId, string $nome, string $valor): void
    {
        \SimpleAIman\Llm\ChatService::anotarContato($conversaId, $nome, $valor);
    }

    /**
     * A conversa está esperando o ATENDENTE, e não o contrário?
     *
     * Verdade quando o atendente ainda não falou nada, ou falou antes da última
     * mensagem do visitante. Não precisa de coluna nova: a própria ordem das
     * mensagens diz quem deve a resposta.
     *
     * Existe porque `encerrarInativas()` mede o silêncio do VISITANTE, e nesse
     * caso ele não está calado, está esperando. Sem esta distinção o sistema
     * encerrava a conversa por inatividade de quem não tinha o que fazer além
     * de aguardar. Aconteceu em teste.
     */
    private const ESPERANDO_ATENDENTE = "(
        SELECT MAX(a.criado_em) FROM mensagens a
         WHERE a.conversa_id = c.id AND a.autor_tipo = 'atendente'
    ) IS NULL OR (
        SELECT MAX(a.criado_em) FROM mensagens a
         WHERE a.conversa_id = c.id AND a.autor_tipo = 'atendente'
    ) < (
        SELECT MAX(u.criado_em) FROM mensagens u
         WHERE u.conversa_id = c.id AND u.autor_tipo = 'usuario'
    )";

    /**
     * Atendente assumiu e não respondeu. Cobra, e depois tira da mão dele.
     *
     * O caso é diferente do órfão (`resgatarOrfas`), onde a pessoa sumiu do
     * painel: aqui ela está online, com a tela aberta, e simplesmente não
     * respondeu. Do lado de fora as duas situações são idênticas, e o visitante
     * não tem como saber a diferença.
     *
     * Passado o prazo, a conversa volta para a fila se houver outro atendente
     * disponível, e encerra com a oferta honesta se não houver. Nunca fica
     * parada esperando alguém que já teve a chance.
     *
     * @return array{devolvidas: int, encerradas: int}
     */
    public static function cobrarAtendentesMudos(): array
    {
        $placar = ['devolvidas' => 0, 'encerradas' => 0];

        if (ESPERA_MAX_MIN <= 0) {
            return $placar;
        }

        $pdo = Database::connection();

        // Reaproveita o prazo da fila de propósito: os dois medem a mesma
        // coisa do ponto de vista de quem espera, que é quanto tempo alguém
        // fica sem resposta. Dois números diferentes para a mesma experiência
        // seriam duas coisas para calibrar e nenhuma razão para divergirem.
        $stmt = $pdo->prepare(
            "SELECT c.id, c.atendente_id FROM conversas c
              WHERE c.modo = 'humano'
                AND c.atendente_id IS NOT NULL
                AND (" . self::ESPERANDO_ATENDENTE . ")
                AND (
                    SELECT MAX(u.criado_em) FROM mensagens u
                     WHERE u.conversa_id = c.id AND u.autor_tipo = 'usuario'
                ) < :limite"
        );
        $stmt->execute(['limite' => date('Y-m-d H:i:s', time() - (ESPERA_MAX_MIN * 60))]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int) $c['id'];
            $atendente = (int) $c['atendente_id'];

            self::registrarNota(
                $id,
                $atendente,
                self::nomeDoAtendente($atendente) . ' assumiu e não respondeu em '
                    . ESPERA_MAX_MIN . ' minutos. A conversa saiu da fila dele.'
            );

            // Solta a conversa ANTES de perguntar quem está livre: senão o
            // próprio atendente mudo contaria como disponível e poderia
            // receber de volta a conversa que acabou de largar.
            $pdo->prepare(
                "UPDATE conversas SET modo = 'aguardando', atendente_id = NULL,
                        aguardando_desde = :agora, editado_em = :agora
                 WHERE id = :id AND modo = 'humano'"
            )->execute(['id' => $id, 'agora' => now()]);

            $outros = array_filter(
                self::disponiveis(),
                static fn (array $u): bool => (int) $u['id'] !== $atendente
            );

            if ($outros !== []) {
                self::registrarAviso($id, 'Estamos transferindo você para outro atendente. Um instante.');
                self::avisarAtendentes($id, $outros, 'Conversa devolvida à fila sem resposta.');
                $placar['devolvidas']++;

                continue;
            }

            self::devolverAoBot($id, 'expirado');
            self::registrarBot(
                $id,
                'Desculpe a demora. Não consegui falar com um atendente agora. '
                    . 'Quer que eu registre sua dúvida para alguém retornar? '
                    . 'Se preferir, é só tentar de novo mais tarde.'
            );
            $placar['encerradas']++;
        }

        return $placar;
    }

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
                    -- Quem deve a resposta e o ATENDENTE: o visitante nao esta
                    -- sumido, esta esperando. Esse caso e da
                    -- `cobrarAtendentesMudos()`, e avisar aqui seria dizer ao
                    -- atendente que o outro lado sumiu quando o silencio e dele.
                    AND NOT (" . self::ESPERANDO_ATENDENTE . ")
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
                // Mesma ressalva do passo 1: encerrar aqui puniria quem so
                // aguarda resposta. Conversa esperando atendente sai pela
                // `cobrarAtendentesMudos()`, que devolve a fila em vez de fechar.
                "SELECT c.id FROM conversas c
                  WHERE c.modo = 'humano'
                    AND NOT (" . self::ESPERANDO_ATENDENTE . ")
                    AND {$ultimaFala} < :limite"
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
                    c.contato_nome, c.contato_valor, c.externo_id,
                    ca.tipo AS canal_tipo,
                    (SELECT m.conteudo FROM mensagens m
                      WHERE m.conversa_id = c.id AND m.autor_tipo IN ('usuario', 'atendente', 'bot')
                      ORDER BY m.id DESC LIMIT 1) AS ultima,
                    (SELECT m.autor_tipo FROM mensagens m
                      WHERE m.conversa_id = c.id AND m.autor_tipo IN ('usuario', 'atendente', 'bot')
                      ORDER BY m.id DESC LIMIT 1) AS ultima_de,
                    (SELECT MAX(m.criado_em) FROM mensagens m WHERE m.conversa_id = c.id) AS ultima_em
             FROM conversas c
             LEFT JOIN agentes a ON a.id = c.agente_id
             LEFT JOIN canais ca ON ca.id = c.canal_id
             WHERE c.modo = 'aguardando'
             ORDER BY c.aguardando_desde ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function emAtendimento(?int $atendenteId = null): array
    {
        $pdo = Database::connection();

        $sql = "SELECT c.id, c.atendente_id, c.editado_em, a.nome AS agente,
                       COALESCE(NULLIF(u.nome, ''), u.usuario) AS atendente,
                       c.contato_nome, c.contato_valor, c.externo_id,
                       ca.tipo AS canal_tipo,
                       (SELECT m.conteudo FROM mensagens m
                         WHERE m.conversa_id = c.id AND m.autor_tipo IN ('usuario', 'atendente', 'bot')
                         ORDER BY m.id DESC LIMIT 1) AS ultima,
                       (SELECT m.autor_tipo FROM mensagens m
                         WHERE m.conversa_id = c.id AND m.autor_tipo IN ('usuario', 'atendente', 'bot')
                         ORDER BY m.id DESC LIMIT 1) AS ultima_de,
                       (SELECT MAX(m.criado_em) FROM mensagens m WHERE m.conversa_id = c.id) AS ultima_em
                FROM conversas c
                LEFT JOIN agentes a ON a.id = c.agente_id
                LEFT JOIN admin_users u ON u.id = c.atendente_id
                LEFT JOIN canais ca ON ca.id = c.canal_id
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
            // O canal vem junto porque quem atende precisa saber por onde a
            // pessoa está falando: no WhatsApp existe um telefone e a janela
            // de 24h; no widget não existe nem um nem outra. Atender os dois
            // como se fossem o mesmo leva a prometer retorno "mais tarde" para
            // quem vai fechar a aba e sumir.
            // Nome de exibição, não o login. O cabeçalho da conversa dizia "em
            // atendimento com admin" — o usuário de entrada no painel, que é
            // credencial e não identidade.
            'SELECT c.*, a.nome AS agente,
                    COALESCE(NULLIF(u.nome, \'\'), u.usuario) AS atendente,
                    ca.tipo AS canal_tipo, ca.nome AS canal_nome
             FROM conversas c
             LEFT JOIN agentes a ON a.id = c.agente_id
             LEFT JOIN admin_users u ON u.id = c.atendente_id
             LEFT JOIN canais ca ON ca.id = c.canal_id
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
    /**
     * O próprio assistente falando, por decisão nossa e não do modelo.
     *
     * Existe porque `gravar()` só escreve no banco. No widget isso bastaria —
     * a tela consulta e a mensagem aparece —, mas no WhatsApp ninguém consulta:
     * a frase ficava no banco e nunca no telefone da pessoa. Foi assim que
     * quem esperou 44 minutos na fila não recebeu nem o aviso de que não havia
     * atendente.
     *
     * Vai como `bot`, e não como `aviso`, porque entra no histórico do modelo:
     * no turno seguinte ele sabe o que já foi oferecido e não repete.
     */
    public static function registrarBot(int $conversaId, string $texto): void
    {
        self::gravar($conversaId, 'bot', $texto, null);

        \SimpleAIman\Canais\Saida::entregar($conversaId, $texto);
    }

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

        // O nome vai para a ENTREGA, nunca para o conteudo gravado: no painel
        // o autor ja aparece ao lado do balao, e gravar "Fulano:" no texto
        // duplicaria ali e sujaria o historico. Quem precisa do prefixo e o
        // WhatsApp, que nao tem onde exibir autor.
        \SimpleAIman\Canais\Saida::entregar(
            $conversaId,
            $texto,
            self::nomeDoAtendente($atendenteId, publico: true)
        );

        return $id;
    }

    /**
     * Alguém de carne e osso assumiu esta conversa?
     *
     * Diferente de "pediu atendimento": `aguardando` é só uma frase digitada
     * pelo visitante, e por isso não serve como permissão para nada — quem
     * quiser abusar digita a frase. `humano` exige que um atendente tenha
     * clicado em assumir, e é o que o visitante não consegue acionar sozinho.
     *
     * É a trava que libera o recebimento de arquivos no WhatsApp.
     */
    public static function comAtendenteHumano(int $conversaId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT modo, atendente_id FROM conversas WHERE id = :id'
        );
        $stmt->execute(['id' => $conversaId]);

        $conversa = $stmt->fetch(PDO::FETCH_ASSOC);

        return $conversa !== false
            && (string) $conversa['modo'] === 'humano'
            && (int) $conversa['atendente_id'] > 0;
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
            || \SimpleAIman\Canais\Saida::entregarAnexo(
                $conversaId,
                $anexo,
                $legenda,
                self::nomeDoAtendente($atendenteId, publico: true)
            )
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

    /**
     * @param bool $publico o texto vai para o VISITANTE, não para o painel
     *
     * A diferença não é estética. Sem nome cadastrado, a versão interna cai no
     * usuário de LOGIN — útil numa nota para o staff, e credencial vazando se
     * chegar ao visitante. O `api/publico.php` já tomava esse cuidado ao montar
     * o JSON do widget; `assumir()` não tomava, e mandava "joao.silva entrou na
     * conversa" para quem estivesse do outro lado.
     */
    private static function nomeDoAtendente(int $id, bool $publico = false): string
    {
        $stmt = Database::connection()->prepare(
            $publico
                ? 'SELECT nome FROM admin_users WHERE id = :id'
                : 'SELECT COALESCE(NULLIF(nome, \'\'), usuario) FROM admin_users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return trim((string) ($stmt->fetchColumn() ?: '')) ?: 'Atendente';
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
