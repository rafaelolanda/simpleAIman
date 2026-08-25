<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Tools\Executor;

$paginaAtual = 'ferramentas.php';
$tituloPagina = 'Ferramentas';

$TIPOS = [
    'http' => 'Chamar uma API externa',
    'contato_setor' => 'Contato do setor (embutida)',
    'abrir_chamado' => 'Registrar chamado (embutida)',
];

$EFEITOS = ['leitura' => 'Leitura — só consulta', 'escrita' => 'Escrita — registra ou altera algo'];
$AUTHS = ['none' => 'Sem autenticação', 'bearer' => 'Bearer token', 'basic' => 'Basic'];
$FONTES = ['' => '(lista fixa abaixo)', 'setores' => 'Setores ativos', 'bases' => 'Bases ativas', 'agentes' => 'Agentes ativos'];
$TIPOS_PARAM = ['string' => 'Texto', 'number' => 'Número', 'boolean' => 'Sim/não'];

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('ferramentas.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        $pdo->prepare('DELETE FROM ferramentas WHERE id = :id')->execute(['id' => $id]);
        Auth::log('ferramenta_excluida', 'id=' . $id);
        flash_set('sucesso', 'Ferramenta excluída.');
        redirect('ferramentas.php');
    }

    if ($acao === 'salvar') {
        $nome = trim(texto_utf8($_POST['nome'] ?? ''));
        $descricao = trim(texto_utf8($_POST['descricao_llm'] ?? ''));

        if ($nome === '' || $descricao === '') {
            flash_set('erro', 'Nome e descrição são obrigatórios — sem a descrição o agente não sabe quando usar a ferramenta.');
            redirect('ferramentas.php');
        }

        $dados = [
            'nome' => $nome,
            'slug' => preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string) ($_POST['slug'] ?? '')) ?: slugify($nome))),
            'descricao_llm' => $descricao,
            'tipo' => valor_em($_POST['tipo'] ?? '', array_keys($TIPOS), 'http'),
            'efeito' => valor_em($_POST['efeito'] ?? '', array_keys($EFEITOS), 'leitura'),
            'setor_id' => ((int) ($_POST['setor_id'] ?? 0)) ?: null,
            'depende_de' => ((int) ($_POST['depende_de'] ?? 0)) ?: null,
            'metodo' => valor_em($_POST['metodo'] ?? '', ['GET', 'POST', 'PUT', 'PATCH'], 'GET'),
            'url_template' => trim((string) ($_POST['url_template'] ?? '')),
            'headers' => trim((string) ($_POST['headers'] ?? '')) ?: null,
            'corpo_template' => trim((string) ($_POST['corpo_template'] ?? '')) ?: null,
            'auth_tipo' => valor_em($_POST['auth_tipo'] ?? '', array_keys($AUTHS), 'none'),
            'auth_ref' => trim((string) ($_POST['auth_ref'] ?? '')),
            'timeout_ms' => ((int) ($_POST['timeout_ms'] ?? 0)) ?: null,
            'retentativas' => max(0, min(3, (int) ($_POST['retentativas'] ?? 0))),
            'resposta_caminho' => trim((string) ($_POST['resposta_caminho'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        // A chave nunca é digitada aqui: só o NOME da variável do .env.
        if ($dados['auth_ref'] !== '' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $dados['auth_ref'])) {
            flash_set('erro', 'A referência da chave deve ser o NOME de uma variável do .env (ex.: CRM_TOKEN), nunca a chave em si.');
            redirect('ferramentas.php');
        }

        foreach (['headers', 'corpo_template'] as $campo) {
            if ($dados[$campo] !== null && json_decode($dados[$campo]) === null) {
                flash_set('erro', "O campo {$campo} precisa ser um JSON válido.");
                redirect('ferramentas.php');
            }
        }

        $agora = now();

        if ($id > 0) {
            $dados['editado_em'] = $agora;
            $dados['id'] = $id;

            $pdo->prepare(
                'UPDATE ferramentas SET nome=:nome, slug=:slug, descricao_llm=:descricao_llm, tipo=:tipo,
                        efeito=:efeito, setor_id=:setor_id, depende_de=:depende_de, metodo=:metodo,
                        url_template=:url_template, headers=:headers, corpo_template=:corpo_template,
                        auth_tipo=:auth_tipo, auth_ref=:auth_ref, timeout_ms=:timeout_ms,
                        retentativas=:retentativas, resposta_caminho=:resposta_caminho, ativo=:ativo,
                        editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('ferramenta_editada', $nome);
        } else {
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;

            $pdo->prepare(
                'INSERT INTO ferramentas (nome, slug, descricao_llm, tipo, efeito, setor_id, depende_de,
                        metodo, url_template, headers, corpo_template, auth_tipo, auth_ref, timeout_ms,
                        retentativas, resposta_caminho, ativo, criado_em, editado_em)
                 VALUES (:nome, :slug, :descricao_llm, :tipo, :efeito, :setor_id, :depende_de,
                        :metodo, :url_template, :headers, :corpo_template, :auth_tipo, :auth_ref, :timeout_ms,
                        :retentativas, :resposta_caminho, :ativo, :criado_em, :editado_em)'
            )->execute($dados);

            $id = (int) $pdo->lastInsertId();
            Auth::log('ferramenta_criada', $nome);
        }

        // Parâmetros: apaga e regrava. São poucos e sempre vêm inteiros do form.
        $pdo->prepare('DELETE FROM ferramenta_parametros WHERE ferramenta_id = :id')->execute(['id' => $id]);
        $insere = $pdo->prepare(
            'INSERT INTO ferramenta_parametros (ferramenta_id, nome, tipo, descricao_llm, obrigatorio,
                    enum_valores, enum_fonte, padrao, exemplo, ordem)
             VALUES (:f, :n, :t, :d, :o, :ev, :ef, :p, :ex, :ord)'
        );

        $ordem = 0;

        foreach ((array) ($_POST['p_nome'] ?? []) as $i => $pnome) {
            $pnome = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $pnome);

            if ($pnome === '') {
                continue;
            }

            $valores = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($_POST['p_valores'][$i] ?? ''))
            )));

            $insere->execute([
                'f' => $id,
                'n' => $pnome,
                't' => valor_em($_POST['p_tipo'][$i] ?? '', array_keys($TIPOS_PARAM), 'string'),
                'd' => trim(texto_utf8($_POST['p_descricao'][$i] ?? '')),
                'o' => isset($_POST['p_obrigatorio'][$i]) ? 1 : 0,
                'ev' => $valores !== [] ? json_encode($valores, JSON_UNESCAPED_UNICODE) : null,
                'ef' => valor_em($_POST['p_fonte'][$i] ?? '', array_keys($FONTES), ''),
                'p' => trim((string) ($_POST['p_padrao'][$i] ?? '')),
                'ex' => trim((string) ($_POST['p_exemplo'][$i] ?? '')),
                'ord' => $ordem++,
            ]);
        }

        // Agentes vinculados
        $pdo->prepare('DELETE FROM agente_ferramentas WHERE ferramenta_id = :id')->execute(['id' => $id]);
        $vinculo = $pdo->prepare('INSERT INTO agente_ferramentas (agente_id, ferramenta_id, ordem) VALUES (:a, :f, 0)');

        foreach ((array) ($_POST['agentes'] ?? []) as $agenteId) {
            $vinculo->execute(['a' => (int) $agenteId, 'f' => $id]);
        }

        flash_set('sucesso', 'Ferramenta salva.');
        redirect('ferramentas.php');
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM ferramentas WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$parametros = [];
$agentesVinculados = [];

if ($editando) {
    $stmt = $pdo->prepare('SELECT * FROM ferramenta_parametros WHERE ferramenta_id = :id ORDER BY ordem, id');
    $stmt->execute(['id' => (int) $editando['id']]);
    $parametros = $stmt->fetchAll();

    $agentesVinculados = array_map('intval', $pdo->query(
        'SELECT agente_id FROM agente_ferramentas WHERE ferramenta_id = ' . (int) $editando['id']
    )->fetchAll(PDO::FETCH_COLUMN));
}

$agentes = $pdo->query('SELECT id, nome FROM agentes WHERE ativo = 1 ORDER BY nome')->fetchAll();
$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();
$outras = $pdo->query('SELECT id, nome FROM ferramentas WHERE ativo = 1 ORDER BY nome')->fetchAll();

$ferramentas = $pdo->query(
    'SELECT f.*, s.nome AS setor,
            (SELECT COUNT(*) FROM agente_ferramentas af WHERE af.ferramenta_id = f.id) AS agentes,
            (SELECT COUNT(*) FROM ferramenta_parametros p WHERE p.ferramenta_id = f.id) AS params,
            (SELECT COUNT(*) FROM ferramenta_execucoes e WHERE e.ferramenta_id = f.id) AS execucoes,
            (SELECT COUNT(*) FROM ferramenta_execucoes e WHERE e.ferramenta_id = f.id AND e.status = \'erro\') AS erros
     FROM ferramentas f LEFT JOIN setores s ON s.id = f.setor_id
     ORDER BY f.ativo DESC, f.nome'
)->fetchAll();

$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;
$linhasParam = $parametros ?: [[]];

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Ferramentas</h1>
    <p class="page-sub">
        O que o agente sabe <em>fazer</em>, além de conversar. O modelo nunca monta a chamada —
        ele preenche campos declarados aqui, e o endereço é sempre este template fixo.
    </p>
</div>

<?php if (TOOLS_HOSTS_PERMITIDOS === []): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-aviso">
            <code>TOOLS_HOSTS_PERMITIDOS</code> está vazio no <code>.env</code>. Toda chamada externa fica
            bloqueada — é o padrão seguro. Liste ali os domínios que as ferramentas podem acessar,
            separados por vírgula.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar: ' . e((string) $editando['nome']) : 'Nova ferramenta' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <h3 class="secao-form">O que é</h3>
        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e((string) $v('nome')) ?>" placeholder="ex.: Simular mensalidade">
            </label>

            <label>
                Identificador
                <input type="text" name="slug" value="<?= e((string) $v('slug')) ?>" placeholder="simular_mensalidade">
                <small>Só letras minúsculas, números e underscore. É o nome que o modelo chama.</small>
            </label>

            <label class="col-2">
                Quando o agente deve usar
                <textarea name="descricao_llm" rows="3" required placeholder="ex.: Calcula o valor da mensalidade de um curso. Use sempre que perguntarem preço, valor ou quanto custa."><?= e((string) $v('descricao_llm')) ?></textarea>
                <small>
                    <strong>É o campo mais importante.</strong> É este texto que o modelo lê para decidir
                    chamar — escreva para ele, com as palavras que as pessoas usam.
                </small>
            </label>

            <label>
                Tipo
                <select name="tipo">
                    <?php foreach ($TIPOS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= (string) $v('tipo', 'http') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Efeito
                <select name="efeito">
                    <?php foreach ($EFEITOS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= (string) $v('efeito', 'leitura') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Escrita faz o agente confirmar antes de usar.</small>
            </label>

            <label>
                Setor responsável
                <select name="setor_id">
                    <option value="0">—</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $v('setor_id', 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Exige outra ferramenta antes
                <select name="depende_de">
                    <option value="0">—</option>
                    <?php foreach ($outras as $o): ?>
                        <?php if ((int) $o['id'] === (int) ($editando['id'] ?? 0)) { continue; } ?>
                        <option value="<?= (int) $o['id'] ?>" <?= (int) $v('depende_de', 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Trava real: a chamada é recusada se a anterior não rodou nesta conversa.</small>
            </label>
        </div>

        <h3 class="secao-form">Chamada HTTP <small style="text-transform:none;font-weight:400">— só para o tipo "API externa"</small></h3>
        <div class="form-grid">
            <label>
                Método
                <select name="metodo">
                    <?php foreach (['GET', 'POST', 'PUT', 'PATCH'] as $m): ?>
                        <option value="<?= $m ?>" <?= (string) $v('metodo', 'GET') === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="col-2">
                Endereço
                <input type="text" name="url_template" value="<?= e((string) $v('url_template')) ?>" placeholder="https://crm.exemplo.com/api/leads">
                <small>
                    Use <code>{{params.nome}}</code> para inserir um campo. Só HTTPS, e o domínio precisa
                    estar em <code>TOOLS_HOSTS_PERMITIDOS</code>.
                </small>
            </label>

            <label>
                Autenticação
                <select name="auth_tipo">
                    <?php foreach ($AUTHS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= (string) $v('auth_tipo', 'none') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Variável do <code>.env</code> com a chave
                <input type="text" name="auth_ref" value="<?= e((string) $v('auth_ref')) ?>" placeholder="CRM_TOKEN">
                <small><strong>Não cole a chave aqui</strong> — só o nome da variável.</small>
            </label>

            <label>
                Extrair da resposta
                <input type="text" name="resposta_caminho" value="<?= e((string) $v('resposta_caminho')) ?>" placeholder="data.items">
                <small>Evita jogar o JSON inteiro no contexto.</small>
            </label>

            <label>
                Tempo limite (ms)
                <input type="number" name="timeout_ms" value="<?= (int) $v('timeout_ms', 0) ?: '' ?>" placeholder="<?= TOOLS_TIMEOUT_MS ?>">
            </label>

            <label>
                Novas tentativas
                <input type="number" name="retentativas" value="<?= (int) $v('retentativas', 0) ?>" min="0" max="3">
            </label>

            <label class="col-2">
                Cabeçalhos (JSON)
                <textarea name="headers" rows="2" placeholder='{"X-Origem": "assistente"}'><?= e((string) $v('headers')) ?></textarea>
                <small>Aceita <code>{{env.NOME}}</code> para valores vindos do <code>.env</code>.</small>
            </label>

            <label class="col-2">
                Corpo (JSON)
                <textarea name="corpo_template" rows="3" placeholder='{"nome": {{params.nome}}, "email": {{params.email}}}'><?= e((string) $v('corpo_template')) ?></textarea>
                <small>Vazio envia os campos como estão.</small>
            </label>
        </div>

        <h3 class="secao-form">Campos que o agente preenche</h3>
        <p class="vazio" style="margin-bottom:0.7rem">
            O modelo só preenche o que estiver declarado aqui. Campo obrigatório faltando faz ele
            <strong>perguntar à pessoa</strong> antes de chamar — é assim que a conversa coleta os dados.
        </p>

        <table class="tabela" id="tabela-params">
            <thead>
            <tr>
                <th>Nome</th><th>Tipo</th><th>Descrição para o agente</th>
                <th>Opções</th><th>Obrig.</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($linhasParam as $i => $p): ?>
                <tr>
                    <td><input type="text" name="p_nome[]" value="<?= e((string) ($p['nome'] ?? '')) ?>" placeholder="curso"></td>
                    <td>
                        <select name="p_tipo[]">
                            <?php foreach ($TIPOS_PARAM as $k => $rotulo): ?>
                                <option value="<?= e($k) ?>" <?= ($p['tipo'] ?? 'string') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="p_descricao[]" value="<?= e((string) ($p['descricao_llm'] ?? '')) ?>" placeholder="Nome do curso"></td>
                    <td>
                        <select name="p_fonte[]">
                            <?php foreach ($FONTES as $k => $rotulo): ?>
                                <option value="<?= e($k) ?>" <?= ($p['enum_fonte'] ?? '') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="p_valores[]" value="<?= e(implode(', ', json_para_array($p['enum_valores'] ?? null))) ?>" placeholder="manha, noite">
                    </td>
                    <td style="text-align:center"><input type="checkbox" name="p_obrigatorio[<?= $i ?>]" <?= !empty($p['obrigatorio']) ? 'checked' : '' ?>></td>
                    <input type="hidden" name="p_padrao[]" value="<?= e((string) ($p['padrao'] ?? '')) ?>">
                    <input type="hidden" name="p_exemplo[]" value="<?= e((string) ($p['exemplo'] ?? '')) ?>">
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-acoes" style="margin:0.6rem 0 1rem">
            <button type="button" class="btn btn-secondary btn-sm" id="add-param">Acrescentar campo</button>
        </div>

        <h3 class="secao-form">Agentes que podem usar</h3>
        <div class="form-checks">
            <?php foreach ($agentes as $a): ?>
                <label class="check">
                    <input type="checkbox" name="agentes[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $agentesVinculados, true) ? 'checked' : '' ?>>
                    <?= e($a['nome']) ?>
                </label>
            <?php endforeach; ?>
            <label class="check"><input type="checkbox" name="ativo" <?= $v('ativo', 1) ? 'checked' : '' ?>> Ativa</label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?>
                <a href="testar-ferramenta.php?f=<?= (int) $editando['id'] ?>" class="btn btn-secondary">Testar</a>
                <a href="ferramentas.php" class="btn btn-secondary">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Cadastradas</h2>
    <?php if (!$ferramentas): ?>
        <p class="vazio">Nenhuma ferramenta cadastrada.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr><th>Ferramenta</th><th>Tipo</th><th>Uso</th><th>Execuções</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($ferramentas as $f): ?>
                <tr>
                    <td>
                        <strong><?= e($f['nome']) ?></strong>
                        <?php if (!$f['ativo']): ?><span class="tag tag-neutro">inativa</span><?php endif; ?>
                        <?php if ($f['efeito'] === 'escrita'): ?><span class="tag tag-erro">escrita</span><?php endif; ?>
                        <br><small style="opacity:.6"><code><?= e((string) $f['slug']) ?></code> · <?= e(mb_substr((string) $f['descricao_llm'], 0, 74)) ?></small>
                    </td>
                    <td><small><?= e($TIPOS[$f['tipo']] ?? $f['tipo']) ?></small></td>
                    <td>
                        <?php if ((int) $f['agentes'] === 0): ?>
                            <span class="tag tag-erro">nenhum agente</span>
                        <?php else: ?>
                            <small><?= (int) $f['agentes'] ?> agente(s) · <?= (int) $f['params'] ?> campo(s)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= (int) $f['execucoes'] ?></small>
                        <?php if ((int) $f['erros'] > 0): ?>
                            <br><span class="tag tag-erro"><?= (int) $f['erros'] ?> com erro</span>
                        <?php endif; ?>
                    </td>
                    <td class="acoes">
                        <a href="testar-ferramenta.php?f=<?= (int) $f['id'] ?>" class="btn btn-secondary btn-sm">Testar</a>
                        <a href="?editar=<?= (int) $f['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta ferramenta?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.getElementById('add-param').addEventListener('click', () => {
    const corpo = document.querySelector('#tabela-params tbody');
    const linha = corpo.rows[0].cloneNode(true);
    const indice = corpo.rows.length;

    linha.querySelectorAll('input, select').forEach((campo) => {
        if (campo.type === 'checkbox') {
            campo.checked = false;
            campo.name = 'p_obrigatorio[' + indice + ']';
        } else if (campo.type !== 'hidden') {
            campo.value = '';
        }
    });

    corpo.appendChild(linha);
});
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
