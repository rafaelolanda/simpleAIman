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

// -------------------------------------------------------------------------
// Ações
//
// Duas, como nas outras telas de cadastro: `salvar` (cria ou atualiza) e
// `excluir`. Antes eram cinco — papel, atendimento e senha tinham cada uma seu
// formulariozinho embutido na linha da tabela. Aquilo nasceu para habilitar
// atendente depressa e ficou; um cadastro editado em quatro lugares diferentes
// é onde uma regra é aplicada num e esquecida nos outros.
// -------------------------------------------------------------------------
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
        } elseif ($acao === 'salvar') {
            $alvo = null;

            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT usuario, admin_master, papel FROM admin_users WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $alvo = $stmt->fetch();

                if (!$alvo) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
            }

            $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
            // Nome de EXIBIÇÃO: o único campo desta tela que o visitante vê.
            $nome = mb_substr(trim(texto_utf8($_POST['nome'] ?? '')), 0, 80);
            $email = trim((string) ($_POST['email'] ?? '')) ?: null;
            $senha = (string) ($_POST['senha'] ?? '');
            $atende = empty($_POST['atende']) ? 0 : 1;
            $setorId = (int) ($_POST['setor_id'] ?? 0) ?: null;

            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) {
                throw new RuntimeException('Usuário deve ter de 3 a 30 caracteres: letras, números, ponto, hífen ou underline.');
            }

            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }

            // Login duplicado deixaria duas pessoas disputando a mesma entrada.
            // Na edição, o próprio registro fica de fora da checagem.
            $stmt = $pdo->prepare('SELECT 1 FROM admin_users WHERE usuario = :usuario AND id != :id');
            $stmt->execute(['usuario' => $usuario, 'id' => $id]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Já existe outro usuário com esse login.');
            }

            // Papel: as duas travas contra tranca-se-fora seguem valendo.
            // Rebaixar o administrador principal, ou a si mesmo, deixaria o
            // painel sem ninguém capaz de desfazer — e não há tela para
            // consertar isso. Em vez de recusar o salvamento inteiro por causa
            // de um <select> que a pessoa nem mexeu, o papel atual é mantido.
            $papel = Painel::papelValido($_POST['papel'] ?? '');

            if ($alvo && ((int) $alvo['admin_master'] === 1 || $id === Auth::userId())) {
                $papel = (string) $alvo['papel'];
            }

            $agora = now();

            if ($id > 0) {
                // Senha em branco MANTÉM a atual: este formulário é de cadastro,
                // e exigir senha para corrigir um e-mail obrigaria a trocar a
                // senha de alguém sem necessidade.
                if ($senha !== '' && strlen($senha) < 8) {
                    throw new RuntimeException('A nova senha precisa ter ao menos 8 caracteres.');
                }

                // Tirar a marca de atendente zera a disponibilidade junto: senão
                // a pessoa ficaria "online" sem receber nada, e a fila contaria
                // com alguém que não existe.
                $sql = 'UPDATE admin_users
                           SET usuario = :usuario, nome = :nome, email = :email, papel = :papel,
                               atende = :atende, setor_id = :setor,
                               disponivel = CASE WHEN :atende2 = 0 THEN 0 ELSE disponivel END,
                               editado_em = :agora';

                $dados = [
                    'usuario' => $usuario,
                    'nome' => $nome,
                    'email' => $email,
                    'papel' => $papel,
                    'atende' => $atende,
                    'atende2' => $atende,
                    'setor' => $setorId,
                    'agora' => $agora,
                    'id' => $id,
                ];

                if ($senha !== '') {
                    $sql .= ', senha_hash = :hash';
                    $dados['hash'] = password_hash($senha, PASSWORD_DEFAULT);
                }

                $pdo->prepare($sql . ' WHERE id = :id')->execute($dados);

                // A sessão guarda o login para exibir no canto da tela: trocando
                // o próprio, sem isto o painel mostraria o antigo até o próximo
                // login.
                if ($id === Auth::userId()) {
                    $_SESSION['admin_username'] = $usuario;
                }

                Auth::log(
                    'editar_usuario',
                    $alvo['usuario'] . ($alvo['usuario'] !== $usuario ? ' → ' . $usuario : '')
                        . ' (' . $papel . ($senha !== '' ? ', senha redefinida' : '') . ')'
                );
                flash_set('sucesso', 'Dados de ' . $usuario . ' atualizados.');
            } else {
                if (strlen($senha) < 8) {
                    throw new RuntimeException('A senha precisa ter ao menos 8 caracteres.');
                }

                // admin_master não é concedido pelo formulário de propósito: o
                // dono do painel é único e definido na instalação.
                $pdo->prepare(
                    'INSERT INTO admin_users (usuario, nome, email, senha_hash, admin_master, papel,
                                              atende, setor_id, criado_em, editado_em)
                     VALUES (:usuario, :nome, :email, :hash, 0, :papel, :atende, :setor, :agora, :agora)'
                )->execute([
                    'usuario' => $usuario,
                    'nome' => $nome,
                    'email' => $email,
                    'hash' => password_hash($senha, PASSWORD_DEFAULT),
                    'papel' => $papel,
                    'atende' => $atende,
                    'setor' => $setorId,
                    'agora' => $agora,
                ]);

                Auth::log('criar_usuario', $usuario . ' (' . $papel . ')');
                flash_set('sucesso', 'Usuário criado. Passe as credenciais para a pessoa e peça que troque a senha no primeiro acesso.');
            }
        }
    } catch (RuntimeException $e) {
        flash_set('erro', $e->getMessage());
    }

    redirect('usuarios.php');
}

$editando = null;

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$usuarios = $pdo->query(
    // `u.nome` explícito e ANTES do alias do setor: as duas tabelas têm coluna
    // `nome`, e sem o prefixo o PDO devolveria uma só.
    'SELECT u.id, u.usuario, u.nome, u.email, u.admin_master, u.papel, u.criado_em,
            u.atende, u.disponivel, u.setor_id,
            s.nome AS setor
     FROM admin_users u
     LEFT JOIN setores s ON s.id = u.setor_id
     ORDER BY u.admin_master DESC, u.usuario ASC'
)->fetchAll();

$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;

// Papel travado: o administrador principal é sempre administrador, e ninguém
// muda o próprio papel. O <select> aparece desabilitado em vez de sumir, para
// a regra ficar visível em vez de virar surpresa no salvamento.
$papelTravado = $editando !== null
    && ((int) $editando['admin_master'] === 1 || (int) $editando['id'] === Auth::userId());

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Usuários do painel</h1>
        <p>Contas com acesso ao painel administrativo. Só você, como administrador principal, vê e usa esta página.</p>
    </div>
</div>

<div class="panel">
    <h2><?= $editando ? 'Editar: ' . e((string) $editando['usuario']) : 'Novo usuário' ?></h2>
    <p class="dica-painel">
        <?php if ($editando): ?>
            Deixe a senha em branco para mantê-la como está.
        <?php else: ?>
            O novo usuário tem acesso a todo o painel, exceto a esta página de usuários.
        <?php endif; ?>
    </p>

    <form method="post" action="usuarios.php">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field">
                <label>Usuário (para login)</label>
                <input type="text" name="usuario" required minlength="3" maxlength="30"
                       value="<?= e((string) $v('usuario')) ?>"
                       placeholder="ex.: maria.silva" autocomplete="off">
                <p class="dica-campo">
                    Letras minúsculas, números, ponto, hífen ou underline.
                    Serve para entrar no painel — <strong>o visitante nunca vê</strong>.
                </p>
            </div>

            <div class="field">
                <label>Nome de exibição</label>
                <input type="text" name="nome" maxlength="80"
                       value="<?= e((string) $v('nome')) ?>"
                       placeholder="ex.: Maria Silva" autocomplete="off">
                <p class="dica-campo">
                    O que a pessoa atendida vê: <em>“Fulano entrou na conversa”</em> e o nome antes
                    de cada mensagem no WhatsApp.
                    <strong>Em branco, aparece só como “Atendente”.</strong>
                </p>
            </div>

            <div class="field">
                <label>E-mail</label>
                <input type="email" name="email" value="<?= e((string) $v('email')) ?>"
                       placeholder="usado para recuperar a senha" autocomplete="off">
            </div>

            <div class="field">
                <label>Papel</label>
                <select name="papel" <?= $papelTravado ? 'disabled' : '' ?>>
                    <?php foreach (Painel::PAPEIS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>"
                            <?= (string) $v('papel', 'atendente') === $k ? 'selected' : '' ?>>
                            <?= e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="dica-campo">
                    <?php if ($papelTravado): ?>
                        <strong>
                            <?= (int) $editando['admin_master'] === 1
                                ? 'O administrador principal é sempre administrador.'
                                : 'Você não pode mudar o seu próprio papel.' ?>
                        </strong>
                    <?php else: ?>
                        Administrador vê tudo. Editor cuida do conteúdo (bases, artefatos, FAQ, setores),
                        sem tocar em agentes, provedores, ferramentas ou leads. Atendente vê só a fila,
                        chamados e o próprio perfil.
                    <?php endif; ?>
                </p>
            </div>

            <div class="field">
                <label><?= $editando ? 'Nova senha' : 'Senha provisória' ?></label>
                <input type="text" name="senha" <?= $editando ? '' : 'required' ?> minlength="8"
                       autocomplete="new-password" placeholder="<?= $editando ? 'deixe em branco para manter' : '' ?>">
                <p class="dica-campo">Mínimo de 8 caracteres. Fica visível para você copiar e repassar.</p>
            </div>

            <div class="field">
                <label>Atendimento humano</label>
                <label class="linha-check">
                    <input type="checkbox" name="atende" value="1" <?= $v('atende') ? 'checked' : '' ?>>
                    recebe transferências de conversa
                </label>
                <select name="setor_id">
                    <option value="">Qualquer setor</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= (int) $v('setor_id', 0) === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="dica-campo">
                    Quem recebe a fila é decisão de gestão e mora aqui. Já a disponibilidade
                    (“estou aqui agora”) é do próprio atendente, na tela de Atendimento.
                </p>
            </div>
        </div>

        <div class="actions-row">
            <button type="submit" class="btn"><?= $editando ? 'Salvar alterações' : 'Criar usuário' ?></button>
            <?php if ($editando): ?>
                <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
            <?php endif; ?>
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
                    <?= $u['admin_master']
                        ? 'Administrador principal'
                        : e(Painel::PAPEIS[$u['papel']] ?? $u['papel']) ?>
                </td>
                <td data-label="Atendimento">
                    <?php if ($u['atende']): ?>
                        Atende<?= $u['setor'] ? ' · ' . e((string) $u['setor']) : '' ?>
                        <?= $u['disponivel'] ? '<span class="badge on">online</span>' : '' ?>
                    <?php else: ?>
                        <span class="vazio">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="Criado em"><?= e(date('d/m/Y', strtotime($u['criado_em']))) ?></td>
                <td data-label="">
                    <a href="?editar=<?= (int) $u['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
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
