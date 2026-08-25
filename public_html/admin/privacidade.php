<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Jobs\Retencao;

$paginaAtual = 'privacidade.php';
$tituloPagina = 'Privacidade';

// Resultado da simulação de exclusão, quando houver. A confirmação acontece na
// mesma tela, logo abaixo da prévia.
$previa = null;
$previaAlvo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('privacidade.php');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $anonimizar = max(0, (int) ($_POST['anonimizacao_conversas_dias'] ?? 0));
        $expurgar = max(0, (int) ($_POST['retencao_conversas_dias'] ?? 0));

        // Expurgar antes de anonimizar não faria sentido: o segundo estágio é
        // mais destrutivo que o primeiro e tem de vir depois dele.
        if ($anonimizar > 0 && $expurgar > 0 && $expurgar < $anonimizar) {
            flash_set('erro', 'O prazo de expurgo precisa ser maior que o de anonimização — ele é o estágio seguinte, não o anterior.');
            redirect('privacidade.php');
        }

        $pdo->prepare(
            'UPDATE config SET anonimizacao_conversas_dias = :a, retencao_conversas_dias = :r,
                    editado_em = :agora WHERE id = 1'
        )->execute(['a' => $anonimizar, 'r' => $expurgar, 'agora' => now()]);

        foreach ((array) ($_POST['canal_dias'] ?? []) as $canalId => $dias) {
            $pdo->prepare('UPDATE canais SET retencao_dias = :d WHERE id = :id')
                ->execute(['d' => max(0, (int) $dias), 'id' => (int) $canalId]);
        }

        Auth::log('retencao_configurada', "anonimizar={$anonimizar} expurgar={$expurgar}");
        flash_set('sucesso', 'Política de retenção salva.');
        redirect('privacidade.php');
    }

    if ($acao === 'rodar') {
        $placar = (new Retencao())->executar();

        Auth::log('retencao_manual', 'anon=' . $placar['anonimizadas'] . ' exp=' . $placar['expurgadas']);
        flash_set(
            'sucesso',
            $placar['anonimizadas'] === 0 && $placar['expurgadas'] === 0
                ? 'Nada vencido — nenhuma conversa atingiu o prazo ainda.'
                : $placar['anonimizadas'] . ' anonimizada(s), ' . $placar['expurgadas'] . ' expurgada(s).'
        );
        redirect('privacidade.php');
    }

    if ($acao === 'simular') {
        $previaAlvo = trim((string) ($_POST['identificador'] ?? ''));
        $previa = $previaAlvo !== '' ? Retencao::apagarPessoa($previaAlvo, true) : null;
    }

    if ($acao === 'apagar') {
        $alvo = trim((string) ($_POST['identificador'] ?? ''));
        $placar = $alvo !== '' ? Retencao::apagarPessoa($alvo) : [];

        // O identificador NÃO entra no log: registrar quem pediu exclusão
        // guardaria justamente o dado que se pediu para apagar.
        Auth::log('dados_pessoa_apagados', 'conversas=' . ($placar['conversas'] ?? 0) . ' leads=' . ($placar['leads'] ?? 0));
        flash_set('sucesso', 'Apagado: ' . ($placar['leads'] ?? 0) . ' lead(s), '
            . ($placar['conversas'] ?? 0) . ' conversa(s), ' . ($placar['mensagens'] ?? 0) . ' mensagem(ns).');
        redirect('privacidade.php');
    }
}

$cfg = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch() ?: [];
$canais = $pdo->query('SELECT id, nome, tipo, retencao_dias FROM canais ORDER BY nome')->fetchAll();

$pendentes = (int) $pdo->query('SELECT COUNT(*) FROM conversas WHERE expurgada_em IS NULL')->fetchColumn();
$tratadas = (int) $pdo->query(
    'SELECT COUNT(*) FROM conversas WHERE anonimizada_em IS NOT NULL OR expurgada_em IS NOT NULL'
)->fetchColumn();

$ligado = ((int) ($cfg['anonimizacao_conversas_dias'] ?? 0)) > 0
    || ((int) ($cfg['retencao_conversas_dias'] ?? 0)) > 0;

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Privacidade</h1>
    <p class="page-sub">
        Por quanto tempo o <strong>conteúdo</strong> das conversas fica guardado, e como apagar os
        dados de uma pessoa que pediu. Estatísticas e fontes citadas não são afetadas — não têm
        dado pessoal e são o que permite melhorar a FAQ depois.
    </p>
</div>

<?php if (!$ligado): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-aviso">
            A retenção está <strong>desligada</strong>: nada é apagado automaticamente. É o padrão
            de fábrica, de propósito — apagar dado de gente sem alguém ter escolhido seria uma
            surpresa ruim. Defina os prazos abaixo quando decidir a política.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Retenção automática</h2>
    <p class="page-sub" style="margin-top:-.35rem">
        Dois estágios, contados a partir da última mensagem da conversa. <strong>0 desliga</strong>.
    </p>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">

        <div class="form-grid">
            <div class="form-group">
                <label for="anon">1. Anonimizar depois de (dias)</label>
                <input type="number" min="0" id="anon" name="anonimizacao_conversas_dias"
                       value="<?= (int) ($cfg['anonimizacao_conversas_dias'] ?? 0) ?>">
                <small>
                    Mascara CPF, e-mail e telefone dentro do texto e remove o IP. O resto continua
                    legível: "quais cursos vocês têm" não tem dado pessoal nenhum e é exatamente o
                    que alimenta a curadoria da FAQ.
                </small>
            </div>

            <div class="form-group">
                <label for="exp">2. Expurgar depois de (dias)</label>
                <input type="number" min="0" id="exp" name="retencao_conversas_dias"
                       value="<?= (int) ($cfg['retencao_conversas_dias'] ?? 0) ?>">
                <small>
                    Apaga o texto das mensagens de vez. Preserva a conversa, as fontes citadas e as
                    métricas. Precisa ser maior que o prazo de anonimização.
                </small>
            </div>
        </div>

        <?php if ($canais): ?>
            <h3 class="card-title" style="font-size:.95rem;margin-top:1.25rem">Prazo por canal</h3>
            <p class="page-sub" style="margin-top:-.35rem">
                Sobrescreve o prazo global. Existe por causa do WhatsApp: ali a pessoa é reconhecida
                pelo telefone e volta semanas depois esperando continuidade — apagar cedo demais faz
                o agente perder o contexto de uma conversa que, para ela, é a mesma. 0 usa o global.
            </p>

            <div class="form-grid">
                <?php foreach ($canais as $c): ?>
                    <div class="form-group">
                        <label for="canal<?= (int) $c['id'] ?>">
                            <?= e((string) $c['nome']) ?>
                            <small style="opacity:.6">(<?= e((string) $c['tipo']) ?>)</small>
                        </label>
                        <input type="number" min="0" id="canal<?= (int) $c['id'] ?>"
                               name="canal_dias[<?= (int) $c['id'] ?>]"
                               value="<?= (int) $c['retencao_dias'] ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Salvar política</button>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Execução</h2>
    <p class="page-sub" style="margin-top:-.35rem">
        <?= $tratadas ?> conversa(s) já tratada(s), <?= $pendentes ?> ainda com conteúdo original.
        O worker roda isso junto com os demais jobs; o botão serve para conferir o efeito na hora,
        sem esperar o próximo ciclo.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="rodar">
        <button type="submit" class="btn btn-secondary">Aplicar retenção agora</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Apagar dados de uma pessoa</h2>
    <p class="page-sub" style="margin-top:-.35rem">
        Busca por e-mail, telefone ou CPF em leads, conversas, mensagens, chamados e execuções de
        ferramenta — o dado pessoal se espalha por cinco tabelas, e atender um pedido de exclusão
        caçando à mão erraria alguma. <strong>Simule primeiro</strong>: a busca é por aproximação e
        a exclusão não tem volta.
    </p>

    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="ident">E-mail, telefone ou CPF</label>
            <input type="text" id="ident" name="identificador" value="<?= e($previaAlvo) ?>"
                   placeholder="fulano@exemplo.com" autocomplete="off" required>
        </div>
        <div class="form-acoes">
            <button type="submit" name="acao" value="simular" class="btn btn-secondary">Simular</button>
        </div>
    </form>

    <?php if ($previa !== null): ?>
        <?php if (array_sum($previa) === 0): ?>
            <p class="alerta alerta-aviso" style="margin-top:1rem">
                Nada encontrado para <strong><?= e($previaAlvo) ?></strong>.
            </p>
        <?php else: ?>
            <div class="alerta alerta-erro" style="margin-top:1rem">
                <p>
                    Seriam atingidos, para <strong><?= e($previaAlvo) ?></strong>:
                    <?= (int) $previa['leads'] ?> lead(s) (excluídos),
                    <?= (int) $previa['conversas'] ?> conversa(s),
                    <?= (int) $previa['mensagens'] ?> mensagem(ns),
                    <?= (int) $previa['chamados'] ?> chamado(s).
                </p>
                <p style="margin-top:.5rem">
                    Confira se esse alcance faz sentido antes de confirmar — um número digitado
                    errado levaria conversa de terceiro junto.
                </p>
            </div>

            <form method="post" onsubmit="return confirm('Confirma? Isso nao tem volta.')">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="apagar">
                <input type="hidden" name="identificador" value="<?= e($previaAlvo) ?>">
                <button type="submit" class="btn btn-danger">Apagar definitivamente</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <p class="page-sub" style="margin-top:1rem">
        Vale lembrar, para não prometer demais a quem pediu: no WhatsApp a conversa continua no
        celular da pessoa. Apagar aqui apaga o nosso lado.
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
