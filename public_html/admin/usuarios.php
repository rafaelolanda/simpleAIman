<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

// Página restrita: só o admin_master gerencia usuários. A checagem fica aqui e não
// só no menu — esconder o link não é controle de acesso, a URL continua alcançável.
if (!$ehAdminMaster) {
    http_response_code(403);
    $paginaAtual = '';
    $tituloPagina = 'Acesso negado';
    include __DIR__ . '/partials/head.php';
    echo '<div class="panel"><h2>Acesso negado</h2><p>Apenas o administrador principal pode gerenciar usuários.</p>'
        . '<a href="index.php" class="btn btn-secondary">Voltar ao painel</a></div>';
    include __DIR__ . '/partials/foot.php';
    exit;
}

$paginaAtual = 'usuarios.php';
$tituloPagina = 'Usuários';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada, tente novamente.');
        redirect('usuarios.php');
    }

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($acao === 'excluir') {
            if ($id === Auth::userId()) {
                throw new RuntimeException('Você não pode excluir o seu próprio usuário.');
            }

            $stmt = $pdo->prepare('SELECT usuario, admin_master FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            if ((int) $alvo['admin_master'] === 1) {
                throw new RuntimeException('O administrador principal não pode ser excluído.');
            }

            $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
            Auth::log('excluir_usuario', $alvo['usuario']);
            flash_set('sucesso', 'Usuário removido.');
        } elseif ($acao === 'papel') {
            $stmt = $pdo->prepare('SELECT usuario, admin_master FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            // Duas travas contra tranca-se-fora, e as duas importam: rebaixar o
            // administrador principal, ou a si mesmo, deixaria o painel sem
            // ninguém capaz de desfazer — e não há tela para consertar isso.
            if ((int) $alvo['admin_master'] === 1) {
                throw new RuntimeException('O administrador principal é sempre administrador.');
            }

            if ($id === Auth::userId()) {
                throw new RuntimeException('Você não pode mudar o seu próprio papel.');
            }

            $papel = Painel::papelValido($_POST['papel'] ?? '');

            $pdo->prepare('UPDATE admin_users SET papel = :p, editado_em = :agora WHERE id = :id')
                ->execute(['p' => $papel, 'agora' => now(), 'id' => $id]);

            Auth::log('usuario_papel', $alvo['usuario'] . ' → ' . $papel);
            flash_set('sucesso', 'Papel de ' . $alvo['usuario'] . ' atualizado.');
        } elseif ($acao === 'atendente') {
            // Marca quem recebe transferências e de qual setor. Fica aqui, e não
            // no perfil de cada um, porque "quem atende" é decisão de gestão. Já
            // a disponibilidade ("estou aqui agora") é do próprio atendente e
            // mora na tela de Atendimento.
            $stmt = $pdo->prepare('SELECT usuario FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            $atende = empty($_POST['atende']) ? 0 : 1;
            $setorId = (int) ($_POST['setor_id'] ?? 0) ?: null;

            // Tirar a marca de atendente zera a disponibilidade junto: senão a
            // pessoa ficaria "online" sem receber nada, e a fila contaria com
            // alguém que não existe.
            $pdo->prepare(
                'UPDATE admin_users
                    SET atende = :a,
                        setor_id = :s,
                        disponivel = CASE WHEN :a2 = 0 THEN 0 ELSE disponivel END,
                        editado_em = :agora
                  WHERE id = :id'
            )->execute([
                'a' => $atende,
                'a2' => $atende,
                's' => $setorId,
                'agora' => now(),
                'id' => $id,
            ]);

            Auth::log('usuario_atendente', $alvo['usuario'] . ' → ' . ($atende ? 'atende' : 'não atende'));
            flash_set('sucesso', 'Atendimento de ' . $alvo['usuario'] . ' atualizado.');
        } elseif ($acao === 'editar') {
            // Edicao dos dados de outra pessoa. Fica aqui, e nao no perfil
            // dela, porque quem administra precisa corrigir um nome errado ou
            // um e-mail que nao recebe sem depender de a pessoa entrar.
            $stmt = $pdo->prepare('SELECT usuario, admin_master FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            $novoUsuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
            $novoNome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 80);
            $novoEmail = trim((string) ($_POST['email'] ?? '')) ?: null;

            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $novoUsuario)) {
                throw new RuntimeException('Usuário deve ter de 3 a 30 caracteres: letras, números, ponto, hífen ou underline.');
            }

            if ($novoEmail !== null && !filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }

            // Login duplicado deixaria duas pessoas disputando a mesma entrada.
            $stmt = $pdo->prepare('SELECT 1 FROM admin_users WHERE usuario = :usuario AND id != :id');
            $stmt->execute(['usuario' => $novoUsuario, 'id' => $id]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Já existe outro usuário com esse login.');
            }

            $pdo->prepare(
                'UPDATE admin_users SET usuario = :usuario, nome = :nome, email = :email, editado_em = :agora
                  WHERE id = :id'
            )->execute([
                'usuario' => $novoUsuario,
                'nome' => $novoNome,
                'email' => $novoEmail,
                'agora' => now(),
                'id' => $id,
            ]);

            // A sessao guarda o login para exibir no canto da tela: trocando o
            // proprio, sem isto o painel continuaria mostrando o antigo ate o
            // proximo login.
            if ($id === Auth::userId()) {
                $_SESSION['admin_username'] = $novoUsuario;
            }

            Auth::log(
                'editar_usuario',
                $alvo['usuario'] . ($alvo['usuario'] !== $novoUsuario ? ' → ' . $novoUsuario : '')
                    . ' (nome: ' . ($novoNome !== '' ? $novoNome : '—') . ')'
            );
            flash_set('sucesso', 'Dados de ' . $novoUsuario . ' atualizados.');
        } elseif ($acao === 'redefinir_senha') {
            $novaSenha = (string) ($_POST['nova_senha'] ?? '');

            if (strlen($novaSenha) < 8) {
                throw new RuntimeException('A nova senha precisa ter ao menos 8 caracteres.');
            }

            $stmt = $pdo->prepare('UPDATE admin_users SET senha_hash = :hash, editado_em = :agora WHERE id = :id');
            $stmt->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'agora' => now(), 'id' => $id]);

            Auth::log('redefinir_senha_usuario', "id={$id}");
            flash_set('sucesso', 'Senha redefinida. Informe a nova senha ao usuário.');
        } else {
            $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
            // Nome de EXIBICAO: o unico campo daqui que o visitante ve.
            $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 80);
            $email = trim((string) ($_POST['email'] ?? '')) ?: null;
            $senha = (string) ($_POST['senha'] ?? '');

            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) {
                throw new RuntimeException('Usuário deve ter de 3 a 30 caracteres: letras, números, ponto, hífen ou underline.');
            }

            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }

            if (strlen($senha) < 8) {
                throw new RuntimeException('A senha precisa ter ao menos 8 caracteres.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM admin_users WHERE usuario = :usuario');
            $stmt->execute(['usuario' => $usuario]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Já existe um usuário com esse nome.');
            }

            // admin_master não é concedido pelo formulário de propósito: o dono do
            // painel é único e definido na instalação
            $papel = Painel::papelValido($_POST['papel'] ?? '');

            $stmt = $pdo->prepare(
                'INSERT INTO admin_users (usuario, nome, email, senha_hash, admin_master, papel, criado_em, editado_em)
                 VALUES (:usuario, :nome, :email, :hash, 0, :papel, :agora, :agora)'
            );
            $stmt->execute([
                'usuario' => $usuario,
                'nome' => $nome,
                'email' => $email,
                'hash' => password_hash($senha, PASSWORD_DEFAULT),
                'papel' => $papel,
                'agora' => now(),
            ]);

            Auth::log('criar_usuario', $usuario . ' (' . $papel . ')');
            flash_set('sucesso', 'Usuário criado. Passe as credenciais para a pessoa e peça que troque a senha no primeiro acesso.');
        }
    } catch (RuntimeException $e) {
        flash_set('erro', $e->getMessage());
    }

    redirect('usuarios.php');
}

$usuarios = $pdo->query(
    // `u.nome` explicito e ANTES do alias do setor: as duas tabelas tem coluna
    // `nome`, e sem o prefixo o PDO devolveria uma so.
    'SELECT u.id, u.usuario, u.nome, u.email, u.admin_master, u.papel, u.criado_em,
            u.atende, u.disponivel, u.setor_id,
            s.nome AS setor
     FROM admin_users u
     LEFT JOIN setores s ON s.id = u.setor_id
     ORDER BY u.admin_master DESC, u.usuario ASC'
)->fetchAll();

$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Usuários do painel</h1>
        <p>Contas com acesso ao painel administrativo. Só você, como administrador principal, vê e usa esta página.</p>
    </div>
</div>

<div class="panel">
    <h2>Novo usuário</h2>
    <p class="dica-painel">
        O novo usuário tem acesso a todo o painel, exceto a esta página de usuários.
    </p>
    <form method="post" action="usuarios.php">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label>Usuário (para login)</label>
                <input type="text" name="usuario" required minlength="3" maxlength="30" placeholder="ex.: maria.silva" autocomplete="off">
            </div>
            <div class="field">
                <label>Nome de exibição</label>
                <input type="text" name="nome" maxlength="80" placeholder="ex.: Maria Silva" autocomplete="off">
                <p class="dica-campo">
                    O que a pessoa atendida vê. Em branco, aparece só como “Atendente” —
                    o usuário de login nunca é mostrado a ela.
                </p>
                <p class="dica-campo">Letras minúsculas, números, ponto, hífen ou underline.</p>
            </div>
            <div class="field">
                <label>E-mail</label>
                <input type="email" name="email" placeholder="usado para recuperar a senha" autocomplete="off">
            </div>
            <div class="campo">
                <label>Papel</label>
                <select name="papel">
                    <?php foreach (Painel::PAPEIS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= $k === 'atendente' ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>
                    Administrador vê tudo. Editor cuida do conteúdo (bases, artefatos, FAQ, setores),
                    sem tocar em agentes, provedores, ferramentas ou leads. Atendente vê só a fila,
                    chamados e o próprio perfil.
                </small>
            </div>
            <div class="field">
                <label>Senha provisória</label>
                <input type="text" name="senha" required minlength="8" autocomplete="new-password">
                <p class="dica-campo">Mínimo de 8 caracteres. Fica visível para você copiar e repassar.</p>
            </div>
        </div>
        <div class="actions-row">
            <button type="submit" class="btn">Criar usuário</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Usuários cadastrados</h2>
    <div class="table-wrap">
    <table class="cards-mobile">
        <thead><tr><th>Usuário</th><th>Nome de exibição</th><th>E-mail</th><th>Papel</th><th>Atendimento</th><th>Criado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td data-label="Usuário"><?= e($u['usuario']) ?><?= (int) $u['id'] === Auth::userId() ? ' <span class="badge on">você</span>' : '' ?></td>
                <td data-label="Nome de exibição">
                    <?php
                    // Vazio aparece marcado, e não em branco: quem atende sem
                    // nome cadastrado chega ao visitante como "Atendente", e
                    // esse é o tipo de detalhe que ninguém descobre sozinho.
                    $nomeExib = trim((string) ($u['nome'] ?? ''));
                    ?>
                    <?php if ($nomeExib !== ''): ?>
                        <?= e($nomeExib) ?>
                    <?php elseif ($u['atende']): ?>
                        <span class="badge alerta" title="Aparece ao visitante apenas como “Atendente”">sem nome</span>
                    <?php else: ?>
                        <span class="vazio">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="E-mail"><?= e($u['email'] ?? '—') ?></td>
                <td data-label="Papel">
                    <?php if ($u['admin_master']): ?>
                        Administrador principal
                    <?php elseif ((int) $u['id'] === Auth::userId()): ?>
                        <?= e(Painel::PAPEIS[$u['papel']] ?? $u['papel']) ?>
                        <small style="opacity:.6">(você)</small>
                    <?php else: ?>
                        <form method="post" action="usuarios.php" class="acao-inline-form" style="margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="papel">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <select name="papel" onchange="this.form.submit()">
                                <?php foreach (Painel::PAPEIS as $k => $rotulo): ?>
                                    <option value="<?= e($k) ?>" <?= $u['papel'] === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <noscript><button type="submit" class="btn btn-sm">Salvar</button></noscript>
                        </form>
                    <?php endif; ?>
                </td>
                <td data-label="Atendimento">
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">
                            <?php if ($u['atende']): ?>
                                Atende<?= $u['setor'] ? ' · ' . e((string) $u['setor']) : '' ?>
                                <?= $u['disponivel'] ? '<span class="badge on">online</span>' : '' ?>
                            <?php else: ?>
                                Não atende
                            <?php endif; ?>
                        </summary>
                        <form method="post" action="usuarios.php" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="atendente">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <label class="linha-check">
                                <input type="checkbox" name="atende" value="1" <?= $u['atende'] ? 'checked' : '' ?>>
                                recebe transferências
                            </label>
                            <select name="setor_id">
                                <option value="">Qualquer setor</option>
                                <?php foreach ($setores as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= (int) $u['setor_id'] === (int) $s['id'] ? 'selected' : '' ?>>
                                        <?= e($s['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Salvar</button>
                        </form>
                    </details>
                </td>
                <td data-label="Criado em"><?= e(date('d/m/Y', strtotime($u['criado_em']))) ?></td>
                <td data-label="">
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">Editar</summary>
                        <form method="post" action="usuarios.php" class="acao-inline-form empilhado">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="editar">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <div class="field">
                                <label>Login</label>
                                <input type="text" name="usuario" required minlength="3" maxlength="30"
                                       value="<?= e((string) $u['usuario']) ?>" autocomplete="off">
                            </div>
                            <div class="field">
                                <label>Nome de exibição</label>
                                <input type="text" name="nome" maxlength="80" placeholder="ex.: Maria Silva"
                                       value="<?= e((string) ($u['nome'] ?? '')) ?>" autocomplete="off">
                            </div>
                            <div class="field">
                                <label>E-mail</label>
                                <input type="email" name="email" placeholder="para recuperar a senha"
                                       value="<?= e((string) ($u['email'] ?? '')) ?>" autocomplete="off">
                            </div>
                            <p class="dica-campo">
                                O <strong>login</strong> serve para entrar no painel e o visitante nunca vê.
                                O <strong>nome de exibição</strong> é o que ele vê na conversa.
                            </p>
                            <button type="submit" class="btn btn-sm">Salvar</button>
                        </form>
                    </details>
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">Redefinir senha</summary>
                        <form method="post" action="usuarios.php" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="redefinir_senha">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <input type="text" name="nova_senha" required minlength="8" placeholder="nova senha" autocomplete="new-password">
                            <button type="submit" class="btn btn-sm">Salvar</button>
                        </form>
                    </details>
                    <?php if (!$u['admin_master'] && (int) $u['id'] !== Auth::userId()): ?>
                        <form method="post" action="usuarios.php" style="display:inline;" data-confirm="Remover o acesso de <?= e($u['usuario']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
