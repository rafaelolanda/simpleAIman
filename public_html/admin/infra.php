<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../app/DiagnosticoInfra.php';

use SimpleAIman\Jobs\Batimento;

/**
 * Infraestrutura: o servidor em que esta instância roda, e se o worker vive.
 *
 * Irmã da tela de Diagnóstico. Aquela olha para dentro do atendimento — onde
 * cada turno gastou tempo; esta olha para o chão em que ele pisa: PHP,
 * extensões, SQLite, rede até o fornecedor e o servidor web.
 *
 * Existe porque, na primeira instalação de produção, nada disso tinha como ser
 * visto: o WhatsApp devolvia só o aviso de turno interrompido e não havia como
 * separar SQLite de worker, de extensão faltando ou de servidor web.
 *
 * Roda sob o PHP DO SITE, e isso é o ponto. OPcache e o comportamento do
 * servidor web não aparecem para a linha de comando, que é outro binário.
 */

$paginaAtual = 'infra.php';
$tituloPagina = 'Infraestrutura';

$completo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('infra.php');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'sonda') {
        $liberar = ($_POST['liberar'] ?? '1') !== '0';
        $id = DiagnosticoInfra::dispararSonda(60, $liberar);

        if ($id === null) {
            flash_set('erro', 'A instância não aceitou o pedido da sonda. Confira APP_URL e WORKER_TOKEN no .env.');
            redirect('infra.php');
        }

        Auth::log('sonda_disparada', $liberar ? 'com liberação' : 'sem liberação');
        redirect('infra.php?sonda=' . $id . '&t=' . time() . '#sonda');
    }

    // Os testes completos escrevem em disco e fazem uma chamada real ao
    // fornecedor. Ficam atrás de um botão para não custar a cada visita.
    if ($acao === 'completo') {
        $completo = true;
    }
}

$verificacoes = DiagnosticoInfra::executar($completo);
$bat = Batimento::resumo();
$execucoes = array_slice(Batimento::ler(), 0, 12);

$sondaId = (string) preg_replace('/[^a-f0-9]/', '', (string) ($_GET['sonda'] ?? ''));
$sonda = null;

if ($sondaId !== '') {
    $sonda = DiagnosticoInfra::lerSonda($sondaId);

    // Sem nenhum sinal depois de alguns segundos, não está atrasada: não rodou.
    if ($sonda['estado'] === 'aguardando' && time() - (int) ($_GET['t'] ?? 0) > 12) {
        $sonda['estado'] = 'nao_iniciou';
    }
}

$calado = $bat['ultima'] === null ? null : time() - (int) $bat['ultima'];
$vivo = $calado !== null && $calado <= 600;

$classe = static fn (string $estado): string => match ($estado) {
    'ok' => 'tag-ok',
    'erro' => 'tag-erro',
    'alerta' => 'tag-alerta',
    default => 'tag-neutro',
};

$gruposVerificacao = [];

foreach ($verificacoes as $v) {
    $gruposVerificacao[$v['grupo']][] = $v;
}

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Infraestrutura</h1>
    <p class="page-sub">
        O servidor em que esta instância roda, e se o worker está vivo. Tudo aqui é medido
        <strong>pelo PHP do site</strong> — que pode ser outro binário, com outra configuração, que o
        da linha de comando.
    </p>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">
        Worker
        <?php if ($vivo): ?>
            <span class="tag tag-ok">ativo</span>
        <?php elseif ($calado === null): ?>
            <span class="tag tag-erro">nenhuma execução registrada</span>
        <?php else: ?>
            <span class="tag tag-erro">parado</span>
        <?php endif; ?>
    </h2>
    <p class="dica-painel">
        O worker processa a fila: indexação de documentos, mensagens do WhatsApp e as rotinas que dependem de
        tempo. Ele roda por dois caminhos — o <strong>cron</strong>, a cada cinco minutos, e o
        <strong>kick</strong>, que o upload e o webhook disparam na hora.
    </p>

    <div class="stat-grid" style="margin-bottom:1rem">
        <div class="stat-card">
            <span class="stat-valor"><?= $bat['ultima'] === null ? '—' : e(DiagnosticoInfra::ha((int) $bat['ultima'])) ?></span>
            <span class="stat-rotulo">última execução</span>
        </div>
        <div class="stat-card">
            <span class="stat-valor"><?= $bat['ultima_cli'] === null ? 'nunca' : e(DiagnosticoInfra::ha((int) $bat['ultima_cli'])) ?></span>
            <span class="stat-rotulo">pelo cron · <?= (int) $bat['cli_ultima_hora'] ?> na última hora (esperado ~12)</span>
        </div>
        <div class="stat-card">
            <span class="stat-valor"><?= $bat['ultima_kick'] === null ? 'nunca' : e(DiagnosticoInfra::ha((int) $bat['ultima_kick'])) ?></span>
            <span class="stat-rotulo">pelo kick</span>
        </div>
        <div class="stat-card">
            <span class="stat-valor"><?= (int) $bat['interrompidas'] ?></span>
            <span class="stat-rotulo">interrompidas — o processo morreu no meio</span>
        </div>
    </div>

    <?php if ($execucoes === []): ?>
        <p class="vazio">
            Nenhuma execução registrada ainda. O registro começa na primeira vez que o worker rodar depois desta
            versão — pelo cron ou por um upload.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="tabela cards-mobile">
                <thead>
                <tr><th>Início</th><th>Origem</th><th>Duração</th><th>Jobs</th><th>Situação</th><th>Liberação</th></tr>
                </thead>
                <tbody>
                <?php foreach ($execucoes as $ex): ?>
                    <?php
                    $situacao = (string) $ex['situacao'];
                    $tag = match (true) {
                        $situacao === 'ok' => 'tag-ok',
                        $situacao === 'em curso' => 'tag-neutro',
                        $situacao === 'com falhas' => 'tag-alerta',
                        default => 'tag-erro',
                    };
                    ?>
                    <tr>
                        <td data-label="Início"><small><?= e(date('d/m H:i:s', (int) $ex['inicio'])) ?></small></td>
                        <td data-label="Origem"><span class="tag"><?= $ex['origem'] === 'cli' ? 'cron / CLI' : 'kick' ?></span></td>
                        <td data-label="Duração"><code><?= $ex['fim'] !== null ? ((int) $ex['fim'] - (int) $ex['inicio']) . ' s' : '—' ?></code></td>
                        <td data-label="Jobs"><?= $ex['jobs'] ?? '—' ?></td>
                        <td data-label="Situação"><span class="tag <?= $tag ?>"><?= e($situacao) ?></span></td>
                        <td data-label="Liberação"><small><?= e((string) ($ex['liberacao'] ?? '—')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="dica-painel" style="margin-top:0.75rem">
            <strong>Interrompida</strong> é execução que começou e nunca terminou, sem erro fatal registrado: o
            processo foi encerrado de fora. Pelo kick, é o servidor web matando o PHP no meio do trabalho.
        </p>
    <?php endif; ?>
</div>

<div class="card" id="sonda" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Sonda de processo em segundo plano</h2>
    <p class="dica-painel">
        O webhook do WhatsApp e o kick respondem primeiro e trabalham depois. A sonda faz o mesmo caminho, mas
        em vez de chamar o modelo marca "ainda vivo" a cada segundo, por até 60 s. Assim dá para ver
        <strong>em que segundo o servidor encerra o processo</strong> — e uma resposta do modelo que demore mais
        que isso é exatamente o turno interrompido.
    </p>

    <?php if ($sonda !== null): ?>
        <?php if ($sonda['estado'] === 'aguardando' || $sonda['estado'] === 'rodando'): ?>
            <p>
                <span class="tag tag-neutro">rodando</span>
                viva há <strong><?= (int) ($sonda['viveu'] ?? 0) ?> s</strong> de <?= (int) ($sonda['meta'] ?? 60) ?>
            </p>
            <div class="barra" style="max-width:100%"><span style="width:<?= (int) round((int) ($sonda['viveu'] ?? 0) / max(1, (int) ($sonda['meta'] ?? 60)) * 100) ?>%"></span></div>
            <script>setTimeout(function () { location.reload(); }, 2000);</script>
        <?php elseif ($sonda['estado'] === 'terminou'): ?>
            <ul class="lista-alertas">
                <li class="alerta alerta-ok">
                    <strong>Sobreviveu os <?= (int) $sonda['meta'] ?> s.</strong> O servidor deixa o trabalho em segundo
                    plano terminar<?= empty($sonda['liberar']) ? ', mesmo sem liberar a conexão' : '' ?>.
                </li>
            </ul>
        <?php elseif ($sonda['estado'] === 'morreu'): ?>
            <ul class="lista-alertas">
                <li class="alerta alerta-erro">
                    <strong>Encerrada aos <?= (int) $sonda['viveu'] ?> s.</strong> Uma resposta do modelo que demore mais
                    que isso morre no meio<?= empty($sonda['liberar']) ? '' : ', mesmo com a conexão liberada' ?>.
                    <?php if (!empty($sonda['liberar'])): ?>
                        O limite é do servidor, não da conexão — o caminho é tirar o trabalho longo do processo web e
                        deixá-lo para o cron.
                    <?php else: ?>
                        Rode de novo <strong>com</strong> a conexão liberada para comparar.
                    <?php endif; ?>
                </li>
            </ul>
        <?php else: ?>
            <ul class="lista-alertas">
                <li class="alerta alerta-erro">
                    <strong>A sonda não chegou a rodar.</strong> O pedido foi aceito, mas nada foi gravado em
                    <code>storage/sondas</code>. Confira se a pasta <code>storage</code> é gravável pelo PHP do site.
                </li>
            </ul>
        <?php endif; ?>

        <?php if (isset($sonda['servidor'])): ?>
            <p class="dica-painel" style="margin-top:0.75rem">
                Servidor: <code><?= e((string) ($sonda['servidor'] ?: '—')) ?></code> ·
                processo: <code><?= e((string) $sonda['sapi']) ?></code> ·
                liberação: <code><?= e((string) $sonda['mecanismo']) ?></code>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="form-acoes">
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="sonda">
            <input type="hidden" name="liberar" value="1">
            <button type="submit" class="btn btn-primary">Rodar sonda (60 s)</button>
        </form>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="sonda">
            <input type="hidden" name="liberar" value="0">
            <button type="submit" class="btn btn-secondary">Comparar: sem liberar a conexão</button>
        </form>
    </div>
    <p class="dica-painel" style="margin-top:0.5rem">
        "Sem liberar" reproduz o comportamento anterior a 11/09/2026. Rodar os dois mostra se a correção
        do LiteSpeed resolveu, ou se o servidor encerra o processo de qualquer jeito.
    </p>
</div>

<div class="card">
    <h2 class="card-title">Verificações</h2>
    <p class="dica-painel">
        <?= $completo
            ? 'Incluindo escrita em disco e rede — foi feita uma chamada real de embedding ao fornecedor.'
            : 'As verificações rápidas. Os testes de disco e rede ficam atrás do botão abaixo porque fazem uma chamada real ao fornecedor.' ?>
    </p>

    <?php foreach ($gruposVerificacao as $grupo => $itens): ?>
        <h3 style="font-size:0.95rem;margin:1.2rem 0 0.5rem"><?= e($grupo) ?></h3>
        <table class="tabela">
            <tbody>
            <?php foreach ($itens as $v): ?>
                <tr>
                    <td style="width:200px"><strong><?= e($v['item']) ?></strong></td>
                    <td style="width:90px"><span class="tag <?= $classe($v['estado']) ?>"><?= e($v['estado']) ?></span></td>
                    <td>
                        <code><?= e($v['valor']) ?></code>
                        <?php if ($v['detalhe'] !== ''): ?>
                            <br><small style="opacity:.75"><?= e($v['detalhe']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <div class="form-acoes">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="completo">
            <button type="submit" class="btn btn-secondary">Rodar testes de disco e rede</button>
        </form>
    </div>

    <p class="dica-painel" style="margin-top:0.75rem">
        O mesmo pela linha de comando: <code>php bin/diagnostico.php</code>, e a sonda com
        <code>php bin/diagnostico.php --sonda</code>. A CLI enxerga o PHP dela, não o do site.
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
