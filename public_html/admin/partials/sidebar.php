<?php
/** @var string $paginaAtual */

// Agrupado por camada da arquitetura (ARQUITETURA.md §2): quem mexe em
// conhecimento não mexe em ferramenta, e ver isso no menu ajuda a não
// confundir "o que o agente sabe" com "o que o agente faz".
$grupos = [
    '' => [
        'index.php' => ['icone' => 'grafico', 'label' => 'Dashboard'],
    ],
    'Agentes' => [
        'agentes.php' => ['icone' => 'bot', 'label' => 'Agentes'],
        'provedores.php' => ['icone' => 'plug', 'label' => 'Provedores'],
        'playground.php' => ['icone' => 'chat', 'label' => 'Playground'],
        'conversas.php' => ['icone' => 'chat', 'label' => 'Conversas'],
    ],
    'Conhecimento' => [
        'bases.php' => ['icone' => 'base', 'label' => 'Bases'],
        'artefatos.php' => ['icone' => 'arquivo', 'label' => 'Artefatos'],
        'faq.php' => ['icone' => 'faq', 'label' => 'FAQ'],
    ],
    'Ações' => [
        'ferramentas.php' => ['icone' => 'ferramenta', 'label' => 'Ferramentas'],
        'setores.php' => ['icone' => 'setor', 'label' => 'Setores'],
        'leads.php' => ['icone' => 'lead', 'label' => 'Leads'],
        'chamados.php' => ['icone' => 'chamado', 'label' => 'Chamados'],
    ],
    'Sistema' => [
        'logs.php' => ['icone' => 'log', 'label' => 'Logs'],
    ],
];

// item só existe para o admin_master — quem não é nem vê o link (o acesso é
// barrado no próprio usuarios.php de qualquer forma)
if (!empty($ehAdminMaster)) {
    $grupos['Sistema']['usuarios.php'] = ['icone' => 'chave', 'label' => 'Usuários'];
}

$badges = [
    'chamados.php' => $chamadosAbertos ?? 0,
    'artefatos.php' => ($artefatosPendentes ?? 0) + ($artefatosComErro ?? 0),
];
?>
<aside class="admin-sidebar">
    <div class="admin-brand">
        <span class="dot"></span>
        <span><?= e($config['nome_instancia'] ?? 'simpleAIman') ?></span>
    </div>
    <nav class="admin-nav">
        <?php foreach ($grupos as $titulo => $itens): ?>
            <?php if ($titulo !== ''): ?>
                <span class="admin-nav-grupo"><?= e($titulo) ?></span>
            <?php endif; ?>
            <?php foreach ($itens as $arquivo => $menuItem): ?>
                <a href="<?= e($arquivo) ?>" class="<?= $paginaAtual === $arquivo ? 'active' : '' ?>">
                    <span class="icon"><?= svg_icon($menuItem['icone']) ?></span>
                    <span><?= e($menuItem['label']) ?></span>
                    <?php if (!empty($badges[$arquivo])): ?>
                        <span class="nav-badge"><?= (int) $badges[$arquivo] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-footer">
        Logado como <strong><?= e(Auth::username() ?? '') ?></strong>
        <a href="perfil.php" class="btn btn-secondary btn-sm" style="width:100%;margin-top:0.5rem;display:block;text-align:center;">Meu perfil</a>
        <form method="post" action="logout.php" style="margin-top:0.5rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">Sair</button>
        </form>
    </div>
</aside>
