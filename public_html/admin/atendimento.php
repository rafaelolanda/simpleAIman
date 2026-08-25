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

$paginaAtual = 'atendimento.php';
$tituloPagina = 'Atendimento';

$eu = (int) Auth::userId();
$abrindo = (int) ($_GET['c'] ?? 0);

// Fecha o laço de quem esperou demais. Roda a cada carga da tela porque este é
// o momento em que há alguém olhando — não dá para depender só do cron.
Fila::expirarAbandonadas();

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

        if ($texto === '') {
            redirect('atendimento.php?c=' . $conversaId);
        }

        // Só quem assumiu escreve. Sem isto, dois atendentes com a tela aberta
        // digitariam na mesma conversa e o visitante veria duas vozes.
        if (!$conversa || (int) $conversa['atendente_id'] !== $eu || $conversa['modo'] !== 'humano') {
            flash_set('erro', 'Esta conversa não está com você.');
            redirect('atendimento.php');
        }

        Fila::registrarAtendente($conversaId, $eu, mb_substr(texto_utf8($texto), 0, 4000));
        redirect('atendimento.php?c=' . $conversaId);
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

    $desde = max(0, (int) ($_GET['desde'] ?? 0));

    $resposta = [
        'aguardando' => count(Fila::aguardando()),
        'mensagens' => [],
        'modo' => null,
    ];

    if ($abrindo > 0) {
        $conversa = Fila::conversa($abrindo);
        $resposta['modo'] = $conversa['modo'] ?? null;

        $resposta['mensagens'] = array_map(
            static fn (array $m): array => [
                'id' => (int) $m['id'],
                'quem' => $m['autor_tipo'],
                'texto' => $m['conteudo'],
                'hora' => date('H:i', strtotime((string) $m['criado_em'])),
            ],
            Fila::mensagensDesde($abrindo, $desde)
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

if ($conversa) {
    $stmt = $pdo->prepare(
        'SELECT m.*, u.nome AS autor_nome FROM mensagens m
         LEFT JOIN admin_users u ON u.id = m.autor_id
         WHERE m.conversa_id = :id ORDER BY m.id'
    );
    $stmt->execute(['id' => $abrindo]);
    $mensagens = $stmt->fetchAll();
    $ultimoId = $mensagens === [] ? 0 : (int) end($mensagens)['id'];
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
            <?= count($disponiveis) ?> pessoa(s) disponível(is).
            Sem ninguém disponível, o agente deixa de oferecer transferência automaticamente.
        <?php endif; ?>
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
                        <div class="fila-cabeca">
                            <strong>#<?= (int) $c['id'] ?></strong>
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
            <h2 class="card-title">
                Conversa #<?= (int) $conversa['id'] ?>
                <span class="tag"><?= e((string) $conversa['modo']) ?></span>
            </h2>

            <div class="chat-historico" id="historico">
                <?php foreach ($mensagens as $m): ?>
                    <?php
                    $classe = match ($m['autor_tipo']) {
                        'usuario' => 'msg-usuario',
                        'atendente' => 'msg-atendente',
                        'sistema' => 'msg-sistema',
                        default => 'msg-bot',
                    };
                    $quem = match ($m['autor_tipo']) {
                        'usuario' => 'Visitante',
                        'atendente' => trim((string) ($m['autor_nome'] ?? '')) ?: 'Atendente',
                        'sistema' => 'Sistema',
                        default => 'Assistente',
                    };
                    ?>
                    <div class="msg <?= $classe ?>" data-id="<?= (int) $m['id'] ?>">
                        <span class="msg-quem"><?= e($quem) ?> · <?= e(date('H:i', strtotime((string) $m['criado_em']))) ?></span>
                        <div class="msg-texto"><?= nl2br(e((string) $m['conteudo'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($conversa['modo'] === 'humano' && (int) $conversa['atendente_id'] === $eu): ?>
                <form method="post" class="chat-envio">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="responder">
                    <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                    <textarea name="texto" rows="3" placeholder="Sua resposta ao visitante…" required autofocus></textarea>
                    <div class="chat-acoes">
                        <button type="submit" class="btn btn-primary">Enviar</button>
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
                    <small>
                        Devolver mantém a conversa viva com o assistente, que continua com todo o
                        contexto do que você disse. Encerrar fecha de vez.
                    </small>
                </div>
            <?php elseif ($conversa['modo'] === 'aguardando'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="assumir">
                    <input type="hidden" name="conversa" value="<?= (int) $conversa['id'] ?>">
                    <button type="submit" class="btn btn-primary">Assumir esta conversa</button>
                </form>
            <?php else: ?>
                <p class="vazio">Esta conversa não está com você.</p>
            <?php endif; ?>
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

                // Alguém novo entrou na fila: avisa quem está com a tela aberta.
                if (d.aguardando > filaAnterior) { tocar(); }
                filaAnterior = d.aguardando;

                (d.mensagens || []).forEach(function (m) {
                    if (!historico || m.id <= ultimo) { return; }
                    ultimo = m.id;

                    var div = document.createElement('div');
                    div.className = 'msg msg-' + (m.quem === 'usuario' ? 'usuario' : (m.quem === 'atendente' ? 'atendente' : (m.quem === 'sistema' ? 'sistema' : 'bot')));

                    var cab = document.createElement('span');
                    cab.className = 'msg-quem';
                    cab.textContent = (m.quem === 'usuario' ? 'Visitante' : m.quem === 'atendente' ? 'Atendente' : m.quem === 'sistema' ? 'Sistema' : 'Assistente') + ' · ' + m.hora;

                    var txt = document.createElement('div');
                    txt.className = 'msg-texto';
                    txt.textContent = m.texto;

                    div.appendChild(cab);
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
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
