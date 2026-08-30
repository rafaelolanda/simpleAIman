<?php

declare(strict_types=1);

/**
 * Painel do atendente — o handoff Nível 2 visto de dentro.
 *
 * Três ações no mesmo arquivo, pelo mesmo motivo do `api/publico.php`: a
 * sessão de admin e as checagens valem para todas, e separar criaria a chance
 * de alguém proteger só uma delas.
 *
 *   (padrão)     a tela
 *   ?acao=json   estado da fila e mensagens novas, para o polling
 *   POST         assumir, responder, devolver ao bot, encerrar, disponibilidade
 *
 * Por que polling e não SSE: um atendimento dura minutos, e SSE prenderia um
 * processo PHP todo esse tempo. Em hospedagem compartilhada, com 10 a 30
 * processos no total, dois ou três atendentes com a tela aberta consumiriam o
 * servidor inteiro. SSE fica só para a resposta do bot, que dura segundos.
 */

require_once __DIR__ . '/_init.php';

use SimpleAIman\Atendimento\Fila;
use SimpleAIman\Canais\Anexos;

$paginaAtual = 'atendimento.php';
$tituloPagina = 'Atendimento';

$eu = (int) Auth::userId();
$abrindo = (int) ($_GET['c'] ?? 0);

// Bate ponto ANTES das varreduras. Fora de ordem, `resgatarOrfas()` acharia
// que quem está abrindo a tela agora sumiu, e devolveria à fila a conversa da
// própria pessoa que está olhando para ela.
Fila::baterPonto($eu);

// Fecha o laço de quem esperou demais, de quem parou de responder e das
// conversas presas com quem saiu. Roda a cada carga da tela porque este é o
// momento em que há alguém olhando — não dá para depender só do cron, que em
// compartilhada pode nem existir.
Fila::expirarAbandonadas();
Fila::cobrarAtendentesMudos();
Fila::encerrarInativas();
Fila::resgatarOrfas();

/** Meu próprio cadastro de atendente. */
$stmt = $pdo->prepare('SELECT atende, disponivel, setor_id FROM admin_users WHERE id = :id');
$stmt->execute(['id' => $eu]);
$souAtendente = $stmt->fetch() ?: ['atende' => 0, 'disponivel' => 0, 'setor_id' => null];

// -------------------------------------------------------------------------
// Ações
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente de novo.');
        redirect('atendimento.php');
    }

    $acao = (string) ($_POST['acao'] ?? '');
    $conversaId = (int) ($_POST['conversa'] ?? 0);

    if ($acao === 'disponibilidade') {
        $novo = empty($souAtendente['disponivel']) ? 1 : 0;

        $pdo->prepare('UPDATE admin_users SET disponivel = :d, editado_em = :agora WHERE id = :id')
            ->execute(['d' => $novo, 'agora' => now(), 'id' => $eu]);

        // Marcar-se ausente encerra a presença na hora, em vez de esperar a
        // janela expirar — quem clicou está dizendo que saiu agora.
        if (!$novo) {
            Fila::encerrarPresenca($eu);
        } else {
            Fila::baterPonto($eu);
        }

        Auth::log('atendimento_disponibilidade', $novo ? 'disponível' : 'ausente');
        flash_set('sucesso', $novo ? 'Você está disponível para atender.' : 'Você está marcado como ausente.');
        redirect('atendimento.php');
    }

    if ($conversaId <= 0) {
        redirect('atendimento.php');
    }

    if ($acao === 'assumir') {
        if (Fila::assumir($conversaId, $eu)) {
            Auth::log('atendimento_assumido', 'conversa #' . $conversaId);
            redirect('atendimento.php?c=' . $conversaId);
        }

        // Perdeu a corrida para outro atendente, ou a conversa expirou entre
        // a tela ter sido pintada e o clique. Melhor dizer do que abrir uma
        // conversa que já é de outra pessoa.
        flash_set('erro', 'Essa conversa já foi assumida por outra pessoa (ou expirou).');
        redirect('atendimento.php');
    }

    if ($acao === 'responder') {
        $texto = trim((string) ($_POST['texto'] ?? ''));
        $conversa = Fila::conversa($conversaId);
        $arquivo = $_FILES['arquivo'] ?? null;
        $temArquivo = is_array($arquivo) && (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        // Sem texto E sem arquivo não há o que enviar. Com arquivo, o texto
        // vira legenda e pode ser vazio.
        if ($texto === '' && !$temArquivo) {
            redirect('atendimento.php?c=' . $conversaId);
        }

        // Só quem assumiu escreve. Sem isto, dois atendentes com a tela aberta
        // digitariam na mesma conversa e o visitante veria duas vozes.
        if (!$conversa || (int) $conversa['atendente_id'] !== $eu || $conversa['modo'] !== 'humano') {
            flash_set('erro', 'Esta conversa não está com você.');
            redirect('atendimento.php');
        }

        if ($temArquivo) {
            $erro = (int) $arquivo['error'];
            $tmp = (string) $arquivo['tmp_name'];

            if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                // INI_SIZE e FORM_SIZE são o caso comum e têm explicação útil;
                // o resto é falha de servidor e não ajuda ninguém detalhar.
                flash_set('erro', in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'Arquivo grande demais para o servidor.'
                    : 'Não foi possível receber o arquivo.');
                redirect('atendimento.php?c=' . $conversaId);
            }

            if (filesize($tmp) > Anexos::tetoBytes()) {
                flash_set('erro', 'O arquivo passa do limite de ' . MIDIA_MAX_MB . ' MB.');
                redirect('atendimento.php?c=' . $conversaId);
            }

            // O tipo sai do CONTEÚDO, não do que o navegador declarou: o
            // cabeçalho vem do cliente e um `.php` renomeado chegaria como
            // "image/png" se acreditássemos nele.
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) $finfo->file($tmp));

            if (!in_array($mime, Anexos::mimesAceitos(), true)) {
                flash_set('erro', 'Tipo de arquivo não aceito: ' . e($mime ?: 'desconhecido'));
                redirect('atendimento.php?c=' . $conversaId);
            }

            $resultado = Fila::registrarAtendenteComArquivo(
                $conversaId,
                $eu,
                mb_substr(texto_utf8($texto), 0, 1000),
                $tmp,
                $mime,
                trim((string) ($arquivo['name'] ?? '')) ?: null
            );

            // Gravado sempre, entregue nem sempre. Calar sobre a diferença
            // faria o atendente ver a própria mensagem na tela e acreditar
            // que a pessoa recebeu.
            if (!$resultado['entregue']) {
                flash_set('erro', 'O arquivo foi registrado na conversa, mas NÃO foi entregue pelo WhatsApp. '
                    . 'Verifique se a conversa está dentro da janela de 24 horas.');
            }

            redirect('atendimento.php?c=' . $conversaId);
        }

        Fila::registrarAtendente($conversaId, $eu, mb_substr(texto_utf8($texto), 0, 4000));
        redirect('atendimento.php?c=' . $conversaId);
    }

    if ($acao === 'copiloto') {
        $pergunta = trim((string) ($_POST['texto'] ?? ''));
        $conversa = Fila::conversa($conversaId);

        // Só quem assumiu consulta: a pergunta custa token de verdade, e quem
        // não está atendendo não tem o que fazer com a resposta.
        if ($pergunta === '' || !$conversa || (int) $conversa['atendente_id'] !== $eu) {
            redirect('atendimento.php?c=' . $conversaId);
        }

        try {
            \SimpleAIman\Llm\ChatService::paraAgente((int) $conversa['agente_id'])
                ->consultar($conversaId, mb_substr(texto_utf8($pergunta), 0, 1000), $eu);
        } catch (Throwable $e) {
            error_log('[simpleAIman] copiloto: ' . $e->getMessage());
            flash_set('erro', 'Não consegui consultar o assistente agora.');
        }

        redirect('atendimento.php?c=' . $conversaId);
    }

    if ($acao === 'nota') {
        $texto = trim((string) ($_POST['texto'] ?? ''));
        $conversa = Fila::conversa($conversaId);

        if ($texto !== '' && $conversa) {
            Fila::registrarNota($conversaId, $eu, mb_substr(texto_utf8($texto), 0, 4000));
        }

        redirect('atendimento.php?c=' . $conversaId);
    }

    if ($acao === 'repassar') {
        $para = (int) ($_POST['para'] ?? 0) ?: null;
        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if (Fila::repassar($conversaId, $eu, $para, mb_substr(texto_utf8($motivo), 0, 500))) {
            Auth::log('atendimento_repassado', 'conversa #' . $conversaId);
            flash_set('sucesso', 'Conversa devolvida à fila. Quem estiver disponível pode assumir.');
        } else {
            flash_set('erro', 'Não foi possível repassar: a conversa não está com você.');
        }

        redirect('atendimento.php');
    }

    if ($acao === 'devolver') {
        Fila::devolverAoBot($conversaId);
        Auth::log('atendimento_devolvido', 'conversa #' . $conversaId);
        flash_set('sucesso', 'Conversa devolvida ao assistente.');
        redirect('atendimento.php');
    }

    if ($acao === 'encerrar') {
        Fila::encerrar($conversaId);
        Auth::log('atendimento_encerrado', 'conversa #' . $conversaId);
        flash_set('sucesso', 'Atendimento encerrado.');
        redirect('atendimento.php');
    }

    redirect('atendimento.php');
}

// -------------------------------------------------------------------------
// Polling
// -------------------------------------------------------------------------
if (($_GET['acao'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    // Batimento. A presença sai de graça de uma requisição que já existia: a
    // tela consulta a cada 4s de qualquer forma. Quem fechou o navegador para
    // de bater e some da fila sozinho, sem depender de lembrar do botão.
    Fila::baterPonto($eu);

    $desde = max(0, (int) ($_GET['desde'] ?? 0));

    $resposta = [
        'aguardando' => count(Fila::aguardando()),
        'mensagens' => [],
        'modo' => null,
    ];

    if ($abrindo > 0) {
        $conversa = Fila::conversa($abrindo);
        $resposta['modo'] = $conversa['modo'] ?? null;

        $novas = Fila::mensagensDesde($abrindo, $desde);
        $anexos = Anexos::porMensagens(array_map(
            static fn (array $m): int => (int) $m['id'],
            $novas
        ));

        $resposta['mensagens'] = array_map(
            static fn (array $m): array => [
                'id' => (int) $m['id'],
                'quem' => $m['autor_tipo'],
                'texto' => $m['conteudo'],
                // O anexo vai junto do html da mensagem: a tela insere isso de
                // uma vez, e separar faria a foto aparecer um passo depois do
                // texto a que ela pertence.
                'html' => formatar_whatsapp((string) $m['conteudo'])
                    . anexos_html($anexos[(int) $m['id']] ?? []),
                'hora' => date('H:i', strtotime((string) $m['criado_em'])),
            ],
            $novas
        );
    }

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// Tela
// -------------------------------------------------------------------------
$fila = Fila::aguardando();
$minhas = Fila::emAtendimento($eu);
$outras = array_values(array_filter(
    Fila::emAtendimento(),
    static fn (array $c): bool => (int) $c['atendente_id'] !== $eu
));

$conversa = $abrindo > 0 ? Fila::conversa($abrindo) : null;
$mensagens = [];
$ultimoId = 0;
$anexosPorMensagem = [];

if ($conversa) {
    $stmt = $pdo->prepare(
        'SELECT m.*, u.nome AS autor_nome FROM mensagens m
         LEFT JOIN admin_users u ON u.id = m.autor_id
         WHERE m.conversa_id = :id ORDER BY m.id'
    );
    $stmt->execute(['id' => $abrindo]);
    $mensagens = $stmt->fetchAll();
    $ultimoId = $mensagens === [] ? 0 : (int) end($mensagens)['id'];

    // Numa consulta só. Uma por mensagem apareceria como lentidão sem causa
    // visível numa conversa longa.
    $anexosPorMensagem = Anexos::porMensagens(array_map(
        static fn (array $m): int => (int) $m['id'],
        $mensagens
    ));

    // Rótulo das fontes que embasaram cada resposta do copiloto. Sem isto o
    // atendente mandaria ao visitante um texto sem saber de onde veio — que é
    // exatamente o que o RAG existe para evitar.
    $fontes = [];
    $stmt = $pdo->prepare(
        "SELECT f.mensagem_id, COALESCE(a.titulo, q.pergunta) AS rotulo
           FROM mensagem_fontes f
           LEFT JOIN chunks c ON f.tipo = 'chunk' AND c.id = f.referencia_id
           LEFT JOIN artefatos a ON a.id = c.artefato_id
           LEFT JOIN faq q ON f.tipo = 'faq' AND q.id = f.referencia_id
          WHERE f.mensagem_id IN (
              SELECT id FROM mensagens WHERE conversa_id = :id AND autor_tipo = 'copiloto'
          )
          ORDER BY f.score DESC"
    );
    $stmt->execute(['id' => $abrindo]);

    foreach ($stmt->fetchAll() as $f) {
        $rotulo = trim((string) ($f['rotulo'] ?? ''));

        if ($rotulo !== '' && !in_array($rotulo, $fontes[(int) $f['mensagem_id']] ?? [], true)) {
            $fontes[(int) $f['mensagem_id']][] = mb_substr($rotulo, 0, 60);
        }
    }
}

$disponiveis = Fila::disponiveis();

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Atendimento</h1>
    <p class="page-sub">
        Conversas transferidas pelo assistente. Enquanto você atende, o bot fica em silêncio.
    </p>
</div>

<?php if (empty($souAtendente['atende'])): ?>
    <div class="card">
        <p class="alerta alerta-aviso">
            Você não está marcado como atendente. Peça a um administrador para habilitar isso em
            <strong>Usuários</strong> — sem essa marca você não recebe transferências.
        </p>
    </div>
<?php endif; ?>

<div class="card atendimento-topo">
    <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="disponibilidade">
        <button type="submit" class="btn <?= !empty($souAtendente['disponivel']) ? 'btn-primary' : 'btn-secondary' ?>"
                <?= empty($souAtendente['atende']) ? 'disabled' : '' ?>>
            <?= !empty($souAtendente['disponivel']) ? '● Disponível' : '○ Ausente' ?>
        </button>
    </form>
    <p class="atendimento-nota">
        <?php if ($disponiveis === []): ?>
            <strong>Ninguém disponível agora.</strong> O agente não vai oferecer transferência —
            ele cai em registrar chamado, que funciona fora do horário.
        <?php else: ?>
            <?= count($disponiveis) ?> pessoa(s) disponível(is) neste momento.
        <?php endif; ?>
        <br>
        <small>
            Você só conta como disponível <strong>com esta tela aberta</strong>. Ao fechar,
            some da fila sozinho em <?= (int) round(PRESENCA_JANELA_SEG / 60) ?> minuto(s) —
            e conversa sua que ficar parada volta para a fila em <?= (int) PRESENCA_ORFA_MIN ?>.
        </small>
    </p>
</div>

<div class="atendimento-grade">
    <section class="card">
        <h2 class="card-title">
            Na fila
            <?php if ($fila !== []): ?><span class="nav-badge" id="badge-fila"><?= count($fila) ?></span><?php endif; ?>
        </h2>

        <?php if ($fila === []): ?>
            <p class="vazio">Ninguém esperando.</p>
        <?php else: ?>
            <ul class="fila-lista">
                <?php foreach ($fila as $c): ?>
                    <?php $espera = max(0, time() - strtotime((string) $c['aguardando_desde'])); ?>
                    <li>
                        <?php $zap = ($c['canal_tipo'] ?? '') === 'whatsapp'; ?>
                        <div class="fila-cabeca">
                            <strong>#<?= (int) $c['id'] ?></strong>
                            <span class="canal-tag <?= $zap ? 'canal-zap' : 'canal-web' ?>"
                                  title="<?= $zap ? e(telefone_legivel((string) ($c['externo_id'] ?? ''))) : 'Chat do site' ?>">
                                <?= $zap ? '📱 WhatsApp' : '💬 Site' ?>
                            </span>
                            <span class="tag <?= $espera > 120 ? 'tag-alerta' : '' ?>">
                                esperando <?= $espera < 60 ? $espera . 's' : intdiv($espera, 60) . 'min' ?>
                            </span>
                        </div>
                        <p class="fila-previa"><?= e(mb_substr((string) ($c['ultima'] ?? ''), 0, 140)) ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="assumir">
                            <input type="hidden" name="conversa" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Assumir</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($minhas !== []): ?>
            <h3 class="secao-form">Comigo</h3>
            <ul class="fila-lista">
                <?php foreach ($minhas as $c): ?>
                    <li>
                        <a href="?c=<?= (int) $c['id'] ?>" class="<?= $abrindo === (int) $c['id'] ? 'ativo' : '' ?>">
                            <strong>#<?= (int) $c['id'] ?></strong> · <?= (int) $c['msgs'] ?> msgs
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($outras !== []): ?>
            <h3 class="secao-form">Com outros</h3>
            <ul class="fila-lista">
                <?php foreach ($outras as $c): ?>
                    <li><small>#<?= (int) $c['id'] ?> — <?= e((string) $c['atendente']) ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <?php if (!$conversa): ?>
            <h2 class="card-title">Conversa</h2>
            <p class="vazio">Escolha uma conversa na fila para atender.</p>
        <?php else: ?>
            <div class="zap">
                <?php
                // Por onde a pessoa está falando muda o que o atendente pode
                // fazer: no WhatsApp há um telefone e a janela de 24h; no
                // widget não há nem um nem outra, e quem fecha a aba some.
                $ehZap = ($conversa['canal_tipo'] ?? '') === 'whatsapp';
                $telefone = $ehZap ? telefone_legivel((string) ($conversa['externo_id'] ?? '')) : '';
                ?>
                <div class="zap-cabecalho">
                    <span class="zap-avatar"><?= $ehZap ? '📱' : '💬' ?></span>
                    <div>
                        <div class="zap-quem">
                            <?= $ehZap && $telefone !== '' ? e($telefone) : 'Visitante' ?>
                            · conversa #<?= (int) $conversa['id'] ?>
                        </div>
                        <div class="zap-estado">
                            <span class="canal-tag <?= $ehZap ? 'canal-zap' : 'canal-web' ?>">
                                <?= $ehZap ? 'WhatsApp' : 'Chat do site' ?>
                            </span>
                            <?php if ($conversa['modo'] === 'humano'): ?>
                                em atendimento com <?= e((string) ($conversa['atendente'] ?? 'você')) ?>
                            <?php elseif ($conversa['modo'] === 'aguardando'): ?>
                                aguardando alguém assumir
                            <?php else: ?>
                                <?= e((string) $conversa['modo']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="zap-corpo" id="historico">
                <?php foreach ($mensagens as $m): ?>
                    <?php
                    $classe = match ($m['autor_tipo']) {
                        'usuario' => 'msg-usuario',
                        'atendente' => 'msg-atendente',
                        'copiloto' => 'msg-copiloto',
                        'nota' => 'msg-nota',
                        'sistema', 'aviso' => 'msg-sistema',
                        default => 'msg-bot',
                    };
                    $quem = match ($m['autor_tipo']) {
                        'usuario' => 'Visitante',
                        'atendente' => trim((string) ($m['autor_nome'] ?? '')) ?: 'Atendente',
                        'copiloto' => 'Assistente · só o staff vê',
                        'nota' => 'Nota interna · ' . (trim((string) ($m['autor_nome'] ?? '')) ?: 'staff'),
                        'aviso' => 'Aviso',
                        'sistema' => 'Sistema',
                        default => 'Assistente',
                    };
                    ?>
                    <div class="msg <?= $classe ?>" data-id="<?= (int) $m['id'] ?>">
                        <div class="msg-texto">
                            <span class="msg-quem"><?= e($quem) ?></span><span class="msg-corpo"><?= formatar_whatsapp((string) $m['conteudo']) ?></span><?= anexos_html($anexosPorMensagem[(int) $m['id']] ?? []) ?><span class="msg-hora"><?= e(date('H:i', strtotime((string) $m['criado_em']))) ?></span>
                        </div>
                        <?php if ($m['autor_tipo'] === 'copiloto' && !str_starts_with((string) $m['conteudo'], '❓')): ?>
                            <?php if (!empty($fontes[(int) $m['id']])): ?>
                                <div class="copiloto-fontes">
                                    Fontes: <?= e(implode(' · ', array_slice($fontes[(int) $m['id']], 0, 3))) ?>
                                </div>
                            <?php endif; ?>
                            <?php /* Preenche o campo, nunca envia: a resposta é um rascunho
                                     do assistente, e quem responde ao visitante é a pessoa. */ ?>
                            <button type="button" class="btn-usar" data-texto="<?= e((string) $m['conteudo']) ?>">
                                usar esta resposta
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div><!-- .zap-corpo -->

            <?php if ($conversa['modo'] === 'humano' && (int) $conversa['atendente_id'] === $eu): ?>
                <form method="post" class="zap-escrita" id="form-envio" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="responder" id="campo-acao">
                    <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">

                    <div class="editor-barra">
                        <?php
                        // Os marcadores são os do WhatsApp de propósito: é o
                        // destino que não dá para mudar. O widget renderiza.
                        $marcadores = [
                            '*' => ['negrito', '<strong>B</strong>'],
                            '_' => ['itálico', '<em>I</em>'],
                            '~' => ['riscado', '<s>S</s>'],
                            '`' => ['mono', '<code>&lt;&gt;</code>'],
                        ];
                        foreach ($marcadores as $marca => [$titulo, $rotulo]):
                            ?>
                            <button type="button" class="btn-marca" data-marca="<?= e($marca) ?>" title="<?= e($titulo) ?>"><?= $rotulo ?></button>
                        <?php endforeach; ?>
                        <span class="editor-sep"></span>
                        <?php foreach (['🙂', '👍', '🙏', '✅', '⚠️', '📞', '🎓'] as $emoji): ?>
                            <button type="button" class="btn-emoji" data-emoji="<?= $emoji ?>"><?= $emoji ?></button>
                        <?php endforeach; ?>
                        <span class="editor-sep"></span>
                        <?php
                        // Anexar é do OPERADOR HUMANO e de mais ninguém: o
                        // agente não tem ferramenta de enviar arquivo, por
                        // decisão de desenho. Por isso o botão vive aqui, na
                        // barra de quem está atendendo.
                        ?>
                        <button type="button" class="btn-marca btn-anexar" id="btn-anexo"
                                title="Anexar arquivo (até <?= MIDIA_MAX_MB ?> MB)">📎</button>
                        <input type="file" name="arquivo" id="campo-arquivo" hidden
                               accept="<?= e(implode(',', Anexos::mimesAceitos())) ?>">
                    </div>

                    <div class="anexo-escolhido" id="anexo-escolhido" hidden>
                        <span id="anexo-nome"></span>
                        <button type="button" id="btn-anexo-remover" title="Remover">✕</button>
                    </div>

                    <textarea name="texto" id="campo-texto" rows="3"
                              placeholder="Enter envia · Shift+Enter quebra linha · *negrito* _itálico_ ~riscado~ `mono`"
                              autofocus></textarea>

                    <div class="chat-acoes">
                        <label class="linha-check" title="Só o staff vê. O visitante não recebe.">
                            <input type="checkbox" id="campo-nota"> nota interna
                        </label>
                        <button type="submit" class="zap-enviar" id="btn-enviar">Enviar</button>
                    </div>
                </form>

                <div class="chat-encerramento">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="devolver">
                        <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">Devolver ao assistente</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="encerrar">
                        <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">Encerrar</button>
                    </form>
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">Perguntar ao assistente</summary>
                        <form method="post" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="copiloto">
                            <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                            <input type="text" name="texto" maxlength="1000" required
                                   placeholder="ex.: o que dizem os documentos sobre trancamento?">
                            <button type="submit" class="btn btn-sm">Consultar</button>
                        </form>
                    </details>

                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">Repassar</summary>
                        <form method="post" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="repassar">
                            <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                            <select name="para">
                                <option value="">Qualquer atendente</option>
                                <?php foreach ($disponiveis as $d): ?>
                                    <?php if ((int) $d['id'] === $eu) { continue; } ?>
                                    <option value="<?= (int) $d['id'] ?>"><?= e(trim((string) $d['nome']) ?: (string) $d['usuario']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="motivo" placeholder="por quê? (vira nota interna)" maxlength="500">
                            <button type="submit" class="btn btn-sm">Repassar</button>
                        </form>
                    </details>
                    <small>
                        Repassar devolve à fila — quem você escolher recebe o aviso, mas qualquer um
                        pode assumir, para a conversa não ficar presa esperando quem saiu.
                        Devolver manda de volta ao assistente, que segue com todo o contexto.
                        Encerrar fecha de vez.
                    </small>
                </div>
            <?php elseif ($conversa['modo'] === 'aguardando'): ?>
                <div class="zap-escrita">
                    <form method="post" style="margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="assumir">
                        <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                        <button type="submit" class="zap-enviar">Assumir esta conversa</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="zap-escrita">
                    <p class="vazio" style="margin:0">Esta conversa não está com você.</p>
                </div>
            <?php endif; ?>
            </div><!-- .zap -->
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    var conversa = <?= (int) $abrindo ?>;
    var ultimo = <?= (int) $ultimoId ?>;
    var historico = document.getElementById('historico');
    var badge = document.getElementById('badge-fila');
    var filaAnterior = <?= count($fila) ?>;

    if (historico) { historico.scrollTop = historico.scrollHeight; }

    // Polling: leve o bastante para rodar de 4 em 4 segundos sem pesar, e sem
    // prender processo nenhum — ao contrário do SSE, que uma conversa humana
    // manteria aberto por minutos.
    function consultar() {
        var url = 'atendimento.php?acao=json&desde=' + ultimo + (conversa ? '&c=' + conversa : '');

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { return; }

                if (badge) { badge.textContent = d.aguardando; }

                // A fila mudou de tamanho. O crachá sozinho não basta: a LISTA
                // precisa mostrar a conversa nova, senão o atendente vê o número
                // subir e não tem no que clicar — foi preciso F5 no primeiro teste.
                //
                // Recarregar só vale quando não há conversa aberta: dentro de uma,
                // recarregar apagaria o que o atendente estivesse digitando.
                if (d.aguardando !== filaAnterior) {
                    if (d.aguardando > filaAnterior) { tocar(); }
                    filaAnterior = d.aguardando;

                    if (!conversa) {
                        location.reload();
                        return;
                    }
                }

                (d.mensagens || []).forEach(function (m) {
                    if (!historico || m.id <= ultimo) { return; }
                    ultimo = m.id;

                    var classes = {
                        usuario: 'usuario', atendente: 'atendente', nota: 'nota',
                        sistema: 'sistema', aviso: 'sistema'
                    };
                    var rotulos = {
                        usuario: 'Visitante', atendente: 'Atendente', nota: 'Nota interna',
                        sistema: 'Sistema', aviso: 'Aviso'
                    };

                    var div = document.createElement('div');
                    div.className = 'msg msg-' + (classes[m.quem] || 'bot');

                    // Mesma estrutura do render em PHP: quem e hora ficam DENTRO
                    // do balão. Duas montagens diferentes fariam a mensagem que
                    // chega pelo polling parecer de outro sistema.
                    var txt = document.createElement('div');
                    txt.className = 'msg-texto';

                    var cab = document.createElement('span');
                    cab.className = 'msg-quem';
                    cab.textContent = rotulos[m.quem] || 'Assistente';

                    var corpo = document.createElement('span');
                    corpo.className = 'msg-corpo';
                    // HTML produzido por formatar_whatsapp(), que escapa antes
                    // de formatar. Ver o comentário no endpoint.
                    corpo.innerHTML = m.html;

                    var hora = document.createElement('span');
                    hora.className = 'msg-hora';
                    hora.textContent = m.hora;

                    txt.appendChild(cab);
                    txt.appendChild(corpo);
                    txt.appendChild(hora);
                    div.appendChild(txt);
                    historico.appendChild(div);
                    historico.scrollTop = historico.scrollHeight;
                });
            })
            .catch(function () { /* rede caiu; a próxima volta resolve */ });
    }

    // Sem arquivo de som: um bipe curto sintetizado basta para chamar atenção
    // e não acrescenta asset nenhum para servir.
    function tocar() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var vol = ctx.createGain();
            osc.connect(vol); vol.connect(ctx.destination);
            osc.frequency.value = 880; vol.gain.value = 0.08;
            osc.start(); osc.stop(ctx.currentTime + 0.15);
        } catch (e) { /* navegador bloqueou áudio sem interação; tudo bem */ }
    }

    setInterval(consultar, 4000);

    // -----------------------------------------------------------------
    // Editor do atendente
    // -----------------------------------------------------------------
    var campo = document.getElementById('campo-texto');
    var form = document.getElementById('form-envio');

    if (campo && form) {
        // Enter envia, Shift+Enter quebra linha — a convenção de todo chat.
        // Sem isto o atendente escreve num <textarea> e precisa ir de mouse
        // até o botão a cada frase, o que numa conversa ao vivo é um atraso
        // por mensagem.
        campo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
                ev.preventDefault();

                var temArquivo = inputArquivo && inputArquivo.files && inputArquivo.files.length > 0;

                if (campo.value.trim() || temArquivo) {
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            }
        });

        // Nota interna troca a ação do MESMO formulário, em vez de existir um
        // segundo formulário embaixo: quem está atendendo escreve num lugar só
        // e decide na hora se aquilo é resposta ou recado.
        var check = document.getElementById('campo-nota');
        var acao = document.getElementById('campo-acao');
        var botao = document.getElementById('btn-enviar');

        if (check && acao && botao) {
            check.addEventListener('change', function () {
                acao.value = check.checked ? 'nota' : 'responder';
                botao.textContent = check.checked ? 'Salvar nota' : 'Enviar';
                botao.classList.toggle('nota', check.checked);

                // Nota interna nao sai da casa, entao nao carrega arquivo.
                // Deixar o anexo pendurado faria o atendente achar que mandou.
                if (check.checked) { limparAnexo(); }

                campo.focus();
            });
        }

        // ---------------------------------------------------------------
        // Anexo — do operador humano, e de mais ninguem
        // ---------------------------------------------------------------
        var inputArquivo = document.getElementById('campo-arquivo');
        var btnAnexo = document.getElementById('btn-anexo');
        var caixaAnexo = document.getElementById('anexo-escolhido');
        var nomeAnexo = document.getElementById('anexo-nome');
        var btnRemover = document.getElementById('btn-anexo-remover');

        function limparAnexo() {
            if (!inputArquivo) { return; }
            inputArquivo.value = '';
            if (caixaAnexo) { caixaAnexo.hidden = true; }
        }

        if (inputArquivo && btnAnexo && caixaAnexo && nomeAnexo) {
            btnAnexo.addEventListener('click', function () {
                if (check && check.checked) {
                    check.checked = false;
                    check.dispatchEvent(new Event('change'));
                }
                inputArquivo.click();
            });

            inputArquivo.addEventListener('change', function () {
                var f = inputArquivo.files && inputArquivo.files[0];

                if (!f) { limparAnexo(); return; }

                // Confere aqui tambem para a pessoa nao esperar o upload de um
                // arquivo que o servidor vai recusar. O servidor confere de
                // novo, e e ele quem manda: isto e conveniencia, nao seguranca.
                var teto = <?= (int) MIDIA_MAX_MB ?> * 1048576;

                if (f.size > teto) {
                    alert('O arquivo tem ' + (f.size / 1048576).toFixed(1)
                        + ' MB e o limite e <?= (int) MIDIA_MAX_MB ?> MB.');
                    limparAnexo();
                    return;
                }

                nomeAnexo.textContent = f.name;
                caixaAnexo.hidden = false;
                campo.focus();
            });

            if (btnRemover) {
                btnRemover.addEventListener('click', limparAnexo);
            }
        }

        function envolver(marca) {
            var i = campo.selectionStart;
            var f = campo.selectionEnd;
            var sel = campo.value.slice(i, f);

            campo.value = campo.value.slice(0, i) + marca + sel + marca + campo.value.slice(f);
            // Cursor entre os marcadores quando nada estava selecionado, para
            // a pessoa simplesmente continuar digitando.
            campo.selectionStart = i + marca.length;
            campo.selectionEnd = f + marca.length;
            campo.focus();
        }

        function inserir(texto) {
            var i = campo.selectionStart;
            campo.value = campo.value.slice(0, i) + texto + campo.value.slice(campo.selectionEnd);
            campo.selectionStart = campo.selectionEnd = i + texto.length;
            campo.focus();
        }

        // `[data-marca]` no seletor, e nao so a classe: o botao de anexo
        // compartilha a aparencia dos marcadores mas nao envolve nada. Sem
        // isto ele chamava envolver(undefined) e escrevia "undefined" no
        // campo — que foi exatamente o que aconteceu no primeiro uso.
        document.querySelectorAll('.btn-marca[data-marca]').forEach(function (b) {
            b.addEventListener('click', function () { envolver(b.dataset.marca); });
        });

        document.querySelectorAll('.btn-emoji').forEach(function (b) {
            b.addEventListener('click', function () { inserir(b.dataset.emoji); });
        });

        // Preenche, nunca envia. A resposta do assistente é rascunho: quem fala
        // com o visitante é a pessoa, e ela precisa poder ajustar antes.
        document.querySelectorAll('.btn-usar').forEach(function (b) {
            b.addEventListener('click', function () {
                campo.value = b.dataset.texto;
                campo.dispatchEvent(new Event('input'));
                campo.focus();
                campo.setSelectionRange(campo.value.length, campo.value.length);
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
