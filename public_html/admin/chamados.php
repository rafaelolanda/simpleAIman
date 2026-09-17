<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'chamados.php';
$tituloPagina = 'Chamados';

$ESTADOS = ['aberto' => 'Aberto', 'respondido' => 'Respondido', 'fechado' => 'Fechado'];
$filtro = valor_em($_GET['status'] ?? 'aberto', [...array_keys($ESTADOS), 'todos'], 'aberto');

/**
 * Quem atende vê os chamados DO SETOR dele; admin e editor veem todos.
 *
 * Chamado carrega dado pessoal de quem pediu retorno — nome, telefone, e-mail,
 * e a dúvida por extenso. Não há razão para quem atende o NTI ler o que alguém
 * contou ao setor financeiro.
 *
 * O recorte vale para a lista E para as ações: filtrar só a tela seria
 * aparência, porque responder e fechar recebem o id por POST e qualquer id
 * serviria. Aqui as duas leem o mesmo `$escopoSetor`.
 */
$souAtendente = $meuPapel === 'atendente';
$meuSetor = null;

if ($souAtendente) {
    $stmt = $pdo->prepare('SELECT setor_id FROM admin_users WHERE id = :id');
    $stmt->execute(['id' => Auth::userId()]);
    $valor = $stmt->fetchColumn();
    $meuSetor = $valor !== false && $valor !== null ? (int) $valor : null;
}

/** Cláusula de escopo, pronta para entrar em qualquer WHERE. */
$escopoSetor = static function (string $coluna = 'c.setor_id') use ($souAtendente, $meuSetor): string {
    if (!$souAtendente) {
        return '1 = 1';
    }

    // Atendente sem setor não vê chamado nenhum — e a tela diz isso, em vez de
    // mostrar uma lista vazia sem explicação.
    return $meuSetor === null ? '1 = 0' : $coluna . ' = ' . $meuSetor;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('chamados.php');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'responder') {
        $resposta = trim(texto_utf8($_POST['resposta'] ?? ''));

        $pdo->prepare(
            'UPDATE chamados SET resposta = :r, status = :s, editado_em = :agora
              WHERE id = :id AND ' . $escopoSetor('setor_id')
        )->execute([
            'r' => $resposta,
            's' => $resposta !== '' ? 'respondido' : 'aberto',
            'agora' => now(),
            'id' => $id,
        ]);

        Auth::log('chamado_respondido', 'id=' . $id);
        flash_set('sucesso', 'Anotação salva. Lembre-se de responder a pessoa pelo contato informado — o sistema não envia por ela.');
        redirect('chamados.php?status=' . $filtro);
    }

    if ($acao === 'fechar') {
        $pdo->prepare(
            'UPDATE chamados SET status = \'fechado\', editado_em = :agora
              WHERE id = :id AND ' . $escopoSetor('setor_id')
        )->execute(['agora' => now(), 'id' => $id]);
        Auth::log('chamado_fechado', 'id=' . $id);
        flash_set('sucesso', 'Chamado fechado.');
        redirect('chamados.php?status=' . $filtro);
    }
}

$sql = 'SELECT c.*, s.nome AS setor, s.email AS setor_email
        FROM chamados c LEFT JOIN setores s ON s.id = c.setor_id
        WHERE ' . $escopoSetor();

if ($filtro !== 'todos') {
    $sql .= " AND c.status = '" . $filtro . "'";
}

$chamados = $pdo->query($sql . ' ORDER BY c.id DESC LIMIT 200')->fetchAll();

// A contagem das abas segue o MESMO escopo da lista.
//
// Contar tudo e listar um pedaço faria a aba dizer "12 abertos" com dois na
// tela — o tipo de número que ninguém confere e todo mundo repete em reunião.
$contagem = [];

foreach ($pdo->query('SELECT c.status, COUNT(*) t FROM chamados c WHERE ' . $escopoSetor() . ' GROUP BY c.status')->fetchAll() as $r) {
    $contagem[$r['status']] = (int) $r['t'];
}

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Chamados</h1>
    <p class="page-sub">
        Dúvidas que o agente registrou porque não soube responder. <strong>O agente prometeu retorno
        a uma pessoa real</strong> — cada linha aqui é alguém esperando.
        <?php if ($souAtendente && $meuSetor !== null): ?>
            <br>Você vê os chamados do seu setor.
        <?php elseif ($souAtendente): ?>
            <br><strong>Você não está vinculado a nenhum setor</strong>, então nenhum chamado aparece aqui.
            Peça a um administrador para definir seu setor em Usuários.
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <div class="chat-topo">
        <h2 class="card-title" style="margin:0"><?= count($chamados) ?> chamado(s)</h2>
        <div class="form-acoes">
            <?php foreach (['aberto' => 'Abertos', 'respondido' => 'Respondidos', 'fechado' => 'Fechados', 'todos' => 'Todos'] as $k => $rotulo): ?>
                <a href="?status=<?= e($k) ?>" class="btn btn-<?= $filtro === $k ? 'primary' : 'secondary' ?> btn-sm">
                    <?= e($rotulo) ?><?= isset($contagem[$k]) ? ' (' . $contagem[$k] . ')' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$chamados): ?>
        <p class="vazio">Nenhum chamado <?= $filtro !== 'todos' ? e($filtro) : '' ?>.</p>
    <?php else: ?>
        <?php foreach ($chamados as $c): ?>
            <div class="resultado">
                <div class="resultado-topo">
                    <span class="resultado-pos">#<?= (int) $c['id'] ?></span>
                    <span class="tag tag-<?= $c['status'] === 'aberto' ? 'erro' : ($c['status'] === 'fechado' ? 'neutro' : 'ok') ?>">
                        <?= e($c['status']) ?>
                    </span>
                    <span class="tag"><?= e((string) ($c['setor'] ?? 'sem setor')) ?></span>

                    <?php if (!$c['email_enviado_em']): ?>
                        <span class="tag tag-erro" title="O responsável não foi avisado por e-mail — só este painel mostra o chamado">
                            responsável não avisado
                        </span>
                    <?php endif; ?>
                </div>

                <div class="resultado-fonte">
                    <strong>Contato:</strong> <?= e((string) $c['contato']) ?>
                    · <?= e(date('d/m/Y H:i', strtotime((string) $c['criado_em']))) ?>
                    <?php if ($c['conversa_id']): ?>
                        · <a href="conversas.php?ver=<?= (int) $c['conversa_id'] ?>">ver a conversa</a>
                    <?php endif; ?>
                </div>

                <div class="resultado-texto">
                    <strong><?= e((string) $c['assunto']) ?></strong>
                    <?php if ($c['descricao']): ?>

<?= e((string) $c['descricao']) ?>
                    <?php endif; ?>
                </div>

                <?php if ($c['status'] !== 'fechado'): ?>
                    <form method="post" style="margin-top:0.7rem">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <div class="form-grid" style="margin-bottom:0.6rem">
                            <label class="col-2">
                                Anotação do atendimento
                                <textarea name="resposta" rows="2" placeholder="o que foi respondido, para o histórico"><?= e((string) $c['resposta']) ?></textarea>
                                <small>
                                    Registro interno. <strong>O sistema não responde a pessoa por você</strong> —
                                    o retorno sai pelo contato acima.
                                </small>
                            </label>
                        </div>
                        <div class="form-acoes">
                            <button type="submit" name="acao" value="responder" class="btn btn-primary btn-sm">Salvar anotação</button>
                            <button type="submit" name="acao" value="fechar" class="btn btn-secondary btn-sm">Fechar chamado</button>
                        </div>
                    </form>
                <?php elseif ($c['resposta']): ?>
                    <div class="resultado-fonte" style="margin-top:0.5rem">
                        <strong>Anotação:</strong> <?= e((string) $c['resposta']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
