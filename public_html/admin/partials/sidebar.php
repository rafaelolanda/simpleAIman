<?php
/** @var string $paginaAtual */

// Agrupado por QUANDO se usa, não pela camada da arquitetura.
//
// O agrupamento anterior espelhava o ARQUITETURA.md — Agentes, Conhecimento,
// Ações — e descrevia bem o sistema para quem o construiu. Só que a pessoa que
// abre este painel todo dia é o atendente, e o que ela usa (Atendimento,
// Chamados) estava no fim de um grupo chamado "Ações", junto de ferramentas
// HTTP e setores, que ela nem pode abrir.
//
// A divisão agora é: o que se olha todo dia, o que se alimenta de vez em
// quando, o que se configura uma vez, e o que quase nunca se mexe.
//
// Dentro de Configuração a ordem é a da DEPENDÊNCIA, não alfabética: um agente
// precisa de provedor para responder, e um canal precisa de agente. Quem
// instala pela primeira vez segue a lista de cima para baixo e nunca esbarra
// num campo cujo pré-requisito ainda não existe.
$grupos = [
    '' => [
        'index.php' => ['icone' => 'grafico', 'label' => 'Dashboard'],
    ],
    'Operação' => [
        'atendimento.php' => ['icone' => 'usuario', 'label' => 'Atendimento'],
        'chamados.php' => ['icone' => 'chamado', 'label' => 'Chamados'],
        'conversas.php' => ['icone' => 'chat', 'label' => 'Conversas'],
        'leads.php' => ['icone' => 'lead', 'label' => 'Leads'],
    ],
    'Conhecimento' => [
        'bases.php' => ['icone' => 'base', 'label' => 'Bases'],
        'artefatos.php' => ['icone' => 'arquivo', 'label' => 'Artefatos'],
        'faq.php' => ['icone' => 'faq', 'label' => 'FAQ'],
        'testar-busca.php' => ['icone' => 'grafico', 'label' => 'Testar busca'],
    ],
    'Configuração' => [
        'provedores.php' => ['icone' => 'plug', 'label' => 'Provedores'],
        'agentes.php' => ['icone' => 'bot', 'label' => 'Agentes'],
        'canais.php' => ['icone' => 'plug', 'label' => 'Canais'],
        'ferramentas.php' => ['icone' => 'ferramenta', 'label' => 'Ferramentas'],
        'setores.php' => ['icone' => 'setor', 'label' => 'Setores'],
        'playground.php' => ['icone' => 'chat', 'label' => 'Playground'],
        'testar-ferramenta.php' => ['icone' => 'grafico', 'label' => 'Testar ferramenta'],
    ],
    'Sistema' => [
        'ajuda.php' => ['icone' => 'faq', 'label' => 'Como usar'],
        'configuracoes.php' => ['icone' => 'engrenagem', 'label' => 'Configurações'],
        'privacidade.php' => ['icone' => 'chave', 'label' => 'Privacidade'],
        'logs.php' => ['icone' => 'log', 'label' => 'Logs'],
    ],
];

// item só existe para o admin_master — quem não é nem vê o link (o acesso é
// barrado no próprio usuarios.php de qualquer forma)
if (!empty($ehAdminMaster)) {
    $grupos['Sistema']['usuarios.php'] = ['icone' => 'chave', 'label' => 'Usuários'];
}

// O menu lê exatamente a mesma lista que a trava do _init. Duas listas
// divergiriam na primeira tela nova — e a divergência aqui é ou link que dá
// erro, ou item escondido de quem podia ver.
$grupos = Painel::filtrarMenu($grupos, $meuPapel ?? 'atendente');

$badges = [
    'atendimento.php' => $filaAtendimento ?? 0,
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
