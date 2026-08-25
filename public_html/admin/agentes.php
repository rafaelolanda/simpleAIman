<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'agentes.php';
$tituloPagina = 'Agentes';

/** Validação em PHP, não em CHECK no banco — SQLite não permite ALTER de constraint. */
$IDIOMAS = [
    'pt-BR' => 'Português do Brasil (sempre)',
    'en' => 'Inglês (sempre)',
    'es' => 'Espanhol (sempre)',
    'auto' => 'Acompanhar o idioma da pergunta',
];

$ESFORCOS = [
    'none' => 'Mínimo — mais rápido e barato',
    'low' => 'Baixo',
    'medium' => 'Médio',
    'high' => 'Alto — o modelo decide quanto pensar',
];

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('agentes.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        $conversas = (int) $pdo->query('SELECT COUNT(*) FROM conversas WHERE agente_id = ' . $id)->fetchColumn();

        if ($conversas > 0) {
            flash_set('erro', "Este agente tem {$conversas} conversa(s) registradas. Desative-o em vez de excluir — o histórico perderia a referência.");
        } else {
            $pdo->prepare('DELETE FROM agentes WHERE id = :id')->execute(['id' => $id]);
            Auth::log('agente_excluido', 'id=' . $id);
            flash_set('sucesso', 'Agente excluído.');
        }

        redirect('agentes.php');
    }

    if ($acao === 'novo_token') {
        $pdo->prepare('UPDATE agentes SET token_publico = :t, editado_em = :agora WHERE id = :id')
            ->execute(['t' => bin2hex(random_bytes(16)), 'agora' => now(), 'id' => $id]);
        Auth::log('agente_token_regerado', 'id=' . $id);
        flash_set('sucesso', 'Token regerado. O widget publicado com o token antigo para de funcionar.');
        redirect('agentes.php?editar=' . $id);
    }

    if ($acao === 'salvar') {
        // texto_utf8() na ENTRADA: conteudo colado de Word ou de sistema legado
        // chega em CP1252, entra no SQLite sem reclamar e so explode depois, no
        // json_encode da requisicao para a LLM.
        $nome = trim(texto_utf8($_POST['nome'] ?? ''));

        if ($nome === '') {
            flash_set('erro', 'O nome é obrigatório.');
            redirect('agentes.php');
        }

        $dados = [
            'nome' => $nome,
            'slug' => slugify(trim(texto_utf8($_POST['slug'] ?? '')) ?: $nome),
            'descricao' => trim(texto_utf8($_POST['descricao'] ?? '')),
            'provedor_id' => ((int) ($_POST['provedor_id'] ?? 0)) ?: null,
            'modelo' => trim(texto_utf8($_POST['modelo'] ?? '')),
            'system_prompt' => trim(texto_utf8($_POST['system_prompt'] ?? '')),
            'mensagem_abertura' => trim(texto_utf8($_POST['mensagem_abertura'] ?? '')),
            'idioma' => valor_em($_POST['idioma'] ?? '', array_keys($IDIOMAS), 'pt-BR'),
            'temperatura' => max(0.0, min(2.0, (float) ($_POST['temperatura'] ?? 0.3))),
            'max_tokens' => max(64, (int) ($_POST['max_tokens'] ?? 1024)),
            'reasoning_effort' => valor_em($_POST['reasoning_effort'] ?? '', array_keys($ESFORCOS), 'none'),
            'top_k' => max(1, min(20, (int) ($_POST['top_k'] ?? 5))),
            'limiar_similaridade' => max(0.0, min(1.0, (float) ($_POST['limiar_similaridade'] ?? 0.55))),
            'max_iteracoes_tool' => max(1, min(10, (int) ($_POST['max_iteracoes_tool'] ?? 5))),
            'usa_rag' => isset($_POST['usa_rag']) ? 1 : 0,
            'usa_faq' => isset($_POST['usa_faq']) ? 1 : 0,
            'captura_lead' => isset($_POST['captura_lead']) ? 1 : 0,
            'lead_destino_id' => ((int) ($_POST['lead_destino_id'] ?? 0)) ?: null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        $agora = now();

        if ($id > 0) {
            $dados['editado_em'] = $agora;
            $dados['id'] = $id;

            $pdo->prepare(
                'UPDATE agentes SET nome=:nome, slug=:slug, descricao=:descricao, provedor_id=:provedor_id,
                        modelo=:modelo, system_prompt=:system_prompt, mensagem_abertura=:mensagem_abertura,
                        idioma=:idioma, temperatura=:temperatura, max_tokens=:max_tokens,
                        reasoning_effort=:reasoning_effort, top_k=:top_k,
                        limiar_similaridade=:limiar_similaridade, max_iteracoes_tool=:max_iteracoes_tool,
                        usa_rag=:usa_rag, usa_faq=:usa_faq, captura_lead=:captura_lead,
                        lead_destino_id=:lead_destino_id, ativo=:ativo, editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('agente_editado', $nome);
        } else {
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;
            $dados['token_publico'] = bin2hex(random_bytes(16));

            $pdo->prepare(
                'INSERT INTO agentes (nome, slug, descricao, provedor_id, modelo, system_prompt,
                        mensagem_abertura, idioma, temperatura, max_tokens, reasoning_effort, top_k,
                        limiar_similaridade, max_iteracoes_tool, usa_rag, usa_faq, captura_lead,
                        lead_destino_id, ativo, token_publico, criado_em, editado_em)
                 VALUES (:nome, :slug, :descricao, :provedor_id, :modelo, :system_prompt,
                        :mensagem_abertura, :idioma, :temperatura, :max_tokens, :reasoning_effort, :top_k,
                        :limiar_similaridade, :max_iteracoes_tool, :usa_rag, :usa_faq, :captura_lead,
                        :lead_destino_id, :ativo, :token_publico, :criado_em, :editado_em)'
            )->execute($dados);

            $id = (int) $pdo->lastInsertId();
            Auth::log('agente_criado', $nome);
        }

        // Bases vinculadas: apaga e regrava, que é mais simples e seguro que
        // calcular diferença — a tabela é só o par de ids.
        $pdo->prepare('DELETE FROM agente_bases WHERE agente_id = :id')->execute(['id' => $id]);
        $vinculo = $pdo->prepare('INSERT INTO agente_bases (agente_id, base_id) VALUES (:a, :b)');

        foreach ((array) ($_POST['bases'] ?? []) as $baseId) {
            $vinculo->execute(['a' => $id, 'b' => (int) $baseId]);
        }

        $semBase = $dados['usa_rag'] === 1 && empty($_POST['bases']);

        flash_set(
            $semBase ? 'erro' : 'sucesso',
            $semBase
                ? 'Agente salvo, mas ele usa RAG e não tem nenhuma base vinculada — vai responder sempre que não encontrou a informação.'
                : 'Agente salvo.'
        );

        redirect('agentes.php');
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM agentes WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$basesDoAgente = [];

if ($editando) {
    $basesDoAgente = array_map('intval', $pdo->query(
        'SELECT base_id FROM agente_bases WHERE agente_id = ' . (int) $editando['id']
    )->fetchAll(PDO::FETCH_COLUMN));
}

$provedores = $pdo->query('SELECT id, nome, modelo_chat, ativo FROM provedores ORDER BY nome')->fetchAll();
$bases = $pdo->query('SELECT id, nome, (SELECT COUNT(*) FROM embeddings e WHERE e.base_id = bases.id) AS vetores FROM bases WHERE ativo = 1 ORDER BY nome')->fetchAll();

$destinos = $pdo->query(
    "SELECT id, nome FROM ferramentas WHERE ativo = 1 AND tipo = 'http' AND efeito = 'escrita' ORDER BY nome"
)->fetchAll();

$agentes = $pdo->query(
    'SELECT a.*, p.nome AS provedor, p.ativo AS provedor_ativo,
            (SELECT COUNT(*) FROM agente_bases ab WHERE ab.agente_id = a.id) AS bases,
            (SELECT COUNT(*) FROM conversas c WHERE c.agente_id = a.id) AS conversas
     FROM agentes a LEFT JOIN provedores p ON p.id = a.provedor_id
     ORDER BY a.ativo DESC, a.nome'
)->fetchAll();

$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Agentes</h1>
    <p class="page-sub">
        Cada agente tem prompt, modelo, idioma e parâmetros de busca próprios. Depois de mexer aqui,
        confira o efeito em <a href="playground.php">Playground</a> e, se o problema for o que ele
        <em>encontra</em>, em <a href="testar-busca.php">Testar busca</a>.
    </p>
</div>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar: ' . e((string) $editando['nome']) : 'Novo agente' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <h3 class="secao-form">Identidade</h3>
        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e((string) $v('nome')) ?>" placeholder="ex.: Atendimento ao candidato">
            </label>

            <label>
                Identificador
                <input type="text" name="slug" value="<?= e((string) $v('slug')) ?>" placeholder="gerado do nome se vazio">
            </label>

            <label class="col-2">
                Descrição interna
                <input type="text" name="descricao" value="<?= e((string) $v('descricao')) ?>" placeholder="para que serve este agente — não aparece para o visitante">
            </label>

            <label class="col-2">
                Instruções e tom de voz
                <textarea name="system_prompt" rows="7" placeholder="Quem ele é, como fala, o que prioriza."><?= e((string) $v('system_prompt')) ?></textarea>
                <small>
                    As regras de segurança (não inventar valor, telefone ou prazo) são aplicadas
                    <strong>depois</strong> deste texto e não podem ser desligadas por ele — o que vem por último pesa mais.
                </small>
            </label>

            <label class="col-2">
                Mensagem de abertura
                <input type="text" name="mensagem_abertura" value="<?= e((string) $v('mensagem_abertura')) ?>" placeholder="Olá! Como posso ajudar?">
            </label>

            <label>
                Idioma da resposta
                <select name="idioma">
                    <?php foreach ($IDIOMAS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= (string) $v('idioma', 'pt-BR') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>“Acompanhar a pergunta” responde em inglês a quem escreveu em inglês.</small>
            </label>
        </div>

        <h3 class="secao-form">Modelo</h3>
        <div class="form-grid">
            <label>
                Provedor
                <select name="provedor_id">
                    <option value="0">(provedor ativo)</option>
                    <?php foreach ($provedores as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $v('provedor_id', 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nome']) ?><?= $p['ativo'] ? '' : ' (inativo)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Modelo
                <input type="text" name="modelo" value="<?= e((string) $v('modelo')) ?>" placeholder="ex.: gemini-3.7-flash">
                <small>Fixe a versão — aliases mudam custo e comportamento sem aviso.</small>
            </label>

            <label>
                Temperatura
                <input type="number" name="temperatura" value="<?= e((string) $v('temperatura', 0.3)) ?>" min="0" max="2" step="0.1">
                <small>Baixa para atendimento factual. Variedade não tem valor aqui.</small>
            </label>

            <label>
                Limite de tokens da resposta
                <input type="number" name="max_tokens" value="<?= (int) $v('max_tokens', 1024) ?>" min="64" step="64">
                <small>
                    <strong>Não use valor baixo.</strong> Modelos pensantes gastam esse orçamento raciocinando
                    antes de sobrar texto — com teto curto a resposta volta vazia, sem erro nenhum.
                </small>
            </label>

            <label>
                Esforço de raciocínio
                <select name="reasoning_effort">
                    <?php foreach ($ESFORCOS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= (string) $v('reasoning_effort', 'none') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Custa tokens de saída. Para FAQ, o mínimo basta.</small>
            </label>
        </div>

        <h3 class="secao-form">Conhecimento</h3>
        <div class="form-grid">
            <label>
                Trechos por pergunta (top_k)
                <input type="number" name="top_k" value="<?= (int) $v('top_k', 5) ?>" min="1" max="20">
                <small>Valor alto com trechos grandes empurra o histórico da conversa para fora da janela.</small>
            </label>

            <label>
                Piso de similaridade
                <input type="number" name="limiar_similaridade" value="<?= e((string) $v('limiar_similaridade', 0.55)) ?>" min="0" max="1" step="0.05">
                <small>
                    É <strong>piso</strong>, não seletor: descarta busca que não casou com nada. A escolha
                    fina é feita por proximidade ao melhor resultado da própria busca.
                </small>
            </label>

            <label>
                Máximo de ferramentas por turno
                <input type="number" name="max_iteracoes_tool" value="<?= (int) $v('max_iteracoes_tool', 5) ?>" min="1" max="10">
                <small>Teto contra o agente chamar a mesma ferramenta em círculo.</small>
            </label>
        </div>

        <div class="form-checks">
            <?php foreach ($bases as $b): ?>
                <label class="check">
                    <input type="checkbox" name="bases[]" value="<?= (int) $b['id'] ?>" <?= in_array((int) $b['id'], $basesDoAgente, true) ? 'checked' : '' ?>>
                    <?= e($b['nome']) ?> <span class="tag tag-neutro"><?= (int) $b['vetores'] ?> vetores</span>
                </label>
            <?php endforeach; ?>
            <?php if (!$bases): ?>
                <span class="vazio">Nenhuma base ativa — crie uma em <a href="bases.php">Bases</a>.</span>
            <?php endif; ?>
        </div>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="usa_rag" <?= $v('usa_rag', 1) ? 'checked' : '' ?>> Consultar as bases (RAG)</label>
            <label class="check"><input type="checkbox" name="usa_faq" <?= $v('usa_faq', 1) ? 'checked' : '' ?>> Consultar a FAQ curada</label>
            <label class="check"><input type="checkbox" name="ativo" <?= $v('ativo', 1) ? 'checked' : '' ?>> Ativo</label>
        </div>

        <h3 class="secao-form">Captação de contato</h3>
        <div class="form-grid">
            <label>
                Destino do lead
                <select name="lead_destino_id">
                    <option value="0">Só o painel (sem envio externo)</option>
                    <?php foreach ($destinos as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $v('lead_destino_id', 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>
                    Uma ferramenta de escrita — seu CRM, o RD Station. O lead é gravado no painel de
                    qualquer forma; o destino é para onde ele <em>também</em> vai.
                </small>
            </label>

            <label class="check" style="align-self:end">
                <input type="checkbox" name="captura_lead" <?= $v('captura_lead', 0) ? 'checked' : '' ?>>
                Captar contato durante a conversa
                <small>
                    Ligue também a ferramenta de captura em <a href="ferramentas.php">Ferramentas</a> —
                    esta caixa só marca a intenção.
                </small>
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?>
                <a href="playground.php?agente=<?= (int) $editando['id'] ?>" class="btn btn-secondary">Testar no playground</a>
                <a href="agentes.php" class="btn btn-secondary">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($editando): ?>
    <div class="card">
        <h2 class="card-title">Token do widget</h2>
        <p class="vazio" style="margin-bottom:0.6rem;">
            Identifica este agente quando o chat for embutido num site (etapa 9). Regerar invalida
            imediatamente qualquer widget já publicado com o token anterior.
        </p>
        <div class="form-acoes">
            <code style="align-self:center"><?= e((string) $editando['token_publico']) ?></code>
            <form method="post" onsubmit="return confirm('Regerar o token? Widgets publicados param de funcionar.')">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="novo_token">
                <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">Regerar</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Cadastrados</h2>
    <?php if (!$agentes): ?>
        <p class="vazio">Nenhum agente cadastrado.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Agente</th>
                <th>Modelo</th>
                <th>Conhecimento</th>
                <th>Uso</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($agentes as $a): ?>
                <tr>
                    <td>
                        <strong><?= e($a['nome']) ?></strong>
                        <?php if (!$a['ativo']): ?><span class="tag tag-neutro">inativo</span><?php endif; ?>
                        <br><small style="opacity:.6"><?= e((string) $a['descricao']) ?: e((string) $a['slug']) ?></small>
                    </td>
                    <td>
                        <code><?= e((string) $a['modelo']) ?></code>
                        <br><small style="opacity:.6">
                            <?= e((string) ($a['provedor'] ?? 'provedor ativo')) ?>
                            <?php if ($a['provedor_id'] && !$a['provedor_ativo']): ?>
                                <span class="tag tag-erro">provedor inativo</span>
                            <?php endif; ?>
                            · <?= e((string) $a['idioma']) ?>
                        </small>
                    </td>
                    <td>
                        <?php if ($a['usa_rag']): ?>
                            <?= (int) $a['bases'] ?> base(s) · top <?= (int) $a['top_k'] ?> · piso <?= e((string) $a['limiar_similaridade']) ?>
                            <?php if ((int) $a['bases'] === 0): ?>
                                <br><span class="tag tag-erro">sem base vinculada</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="tag tag-neutro">sem RAG</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $a['conversas'] ?> conversa(s)</td>
                    <td class="acoes">
                        <a href="playground.php?agente=<?= (int) $a['id'] ?>" class="btn btn-secondary btn-sm">Testar</a>
                        <a href="?editar=<?= (int) $a['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir este agente?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
