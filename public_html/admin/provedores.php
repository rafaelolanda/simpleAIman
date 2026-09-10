<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Llm\DiagnosticoProvedor;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Llm\ProviderFactory;

$paginaAtual = 'provedores.php';
$tituloPagina = 'Provedores';

/**
 * Drivers suportados. Validação em PHP, não em CHECK no banco: SQLite não
 * permite ALTER de constraint, então enumerar lá vira dívida.
 */
$DRIVERS = [
    'gemini' => 'Google Gemini (nativo)',
    'openai' => 'OpenAI / compatível',
    'anthropic' => 'Anthropic',
    'ollama' => 'Ollama (local)',
];

$DRIVERS_EMBEDDING = [
    '' => '(mesmo do chat)',
    'gemini_nativo' => 'Gemini nativo — único que aplica task_type',
    'openai' => 'OpenAI / compatível',
    'ollama' => 'Ollama (local)',
];

/**
 * Papel: a que universo esta linha serve.
 *
 * Chat e embedding são trabalhos diferentes, feitos por modelos diferentes, e
 * nem todo fornecedor faz os dois. Como a chave é UMA por linha, combinar dois
 * fornecedores se faz com DUAS linhas — não com duas chaves na mesma linha.
 */
$PAPEIS = [
    'ambos' => 'Chat e embedding (mesmo fornecedor)',
    'chat' => 'Somente chat',
    'embedding' => 'Somente embedding',
];

/** Drivers sem API de embeddings — a linha só pode ser de chat. */
$SEM_EMBEDDING = ['anthropic'];

$editando = null;
$resultadoTeste = null;
$provedorTestado = null;

// ---------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('provedores.php');
    }

    $acao = $_POST['acao'] ?? '';

    // -----------------------------------------------------------------
    // Testar: mesma classe que o `php bin/testar-provedor.php` usa.
    // Dois códigos separados garantiriam que um deles ficasse desatualizado
    // — justamente o que alguém usa às 23h tentando descobrir o que quebrou.
    // -----------------------------------------------------------------
    if ($acao === 'testar') {
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $fabrica = ProviderFactory::porId($id);
            $provedorTestado = $fabrica->nome();
            $resultadoTeste = (new DiagnosticoProvedor($fabrica))->executar();

            $total = count($resultadoTeste);
            $falhas = count(array_filter($resultadoTeste, static fn (array $r): bool => !$r['ok']));
            Auth::log('provedor_testado', $fabrica->nome() . ': ' . ($total - $falhas) . '/' . $total);
        } catch (ErroAgente $e) {
            $provedorTestado = 'Provedor #' . $id;
            $resultadoTeste = [[
                'item' => 'Carregar provedor',
                'ok' => false,
                'detalhe' => $e->paraLog(),
                'sugestao' => $e->sugestaoAdmin(),
                'publica' => $e->mensagemPublica(),
            ]];
        }
    }

    // -----------------------------------------------------------------
    // Padrões por universo.
    //
    // Ficam nesta tela, e não em Configurações, porque a decisão só faz
    // sentido ao lado da lista de provedores: é aqui que se vê qual linha
    // serve a quê.
    // -----------------------------------------------------------------
    if ($acao === 'padroes') {
        $chat = ((int) ($_POST['provedor_chat_padrao_id'] ?? 0)) ?: null;
        $embed = ((int) ($_POST['provedor_embedding_padrao_id'] ?? 0)) ?: null;

        $pdo->prepare(
            'UPDATE config SET provedor_chat_padrao_id = :chat,
                    provedor_embedding_padrao_id = :embed, editado_em = :agora WHERE id = 1'
        )->execute(['chat' => $chat, 'embed' => $embed, 'agora' => now()]);

        Auth::log('provedores_padroes', 'chat=' . ($chat ?? 0) . ' embedding=' . ($embed ?? 0));
        flash_set('sucesso', 'Padrões atualizados.');
        redirect('provedores.php');
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);

        $emUso = (int) $pdo->query('SELECT COUNT(*) FROM agentes WHERE provedor_id = ' . $id)->fetchColumn()
            + (int) $pdo->query('SELECT COUNT(*) FROM bases WHERE provedor_embedding_id = ' . $id)->fetchColumn();

        if ($emUso > 0) {
            flash_set('erro', "Este provedor está em uso por {$emUso} agente(s)/base(s). Troque-os antes de excluir.");
        } else {
            $pdo->prepare('DELETE FROM provedores WHERE id = :id')->execute(['id' => $id]);
            Auth::log('provedor_excluido', 'id=' . $id);
            flash_set('sucesso', 'Provedor excluído.');
        }

        redirect('provedores.php');
    }

    if ($acao === 'salvar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $slug = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $nome);

        $dados = [
            'nome' => $nome,
            'slug' => $slug,
            'driver' => valor_em($_POST['driver'] ?? '', array_keys($DRIVERS), 'openai'),
            'papel' => valor_em($_POST['papel'] ?? '', array_keys($PAPEIS), 'ambos'),
            'base_url' => trim((string) ($_POST['base_url'] ?? '')),
            'auth_ref' => trim((string) ($_POST['auth_ref'] ?? '')),
            'modelo_chat' => trim((string) ($_POST['modelo_chat'] ?? '')),
            'driver_embedding' => valor_em($_POST['driver_embedding'] ?? '', array_keys($DRIVERS_EMBEDDING), ''),
            'base_url_embedding' => trim((string) ($_POST['base_url_embedding'] ?? '')),
            'modelo_embedding' => trim((string) ($_POST['modelo_embedding'] ?? '')),
            'dimensoes' => (int) ($_POST['dimensoes'] ?? 0),
            'suporta_tools' => isset($_POST['suporta_tools']) ? 1 : 0,
            'suporta_stream' => isset($_POST['suporta_stream']) ? 1 : 0,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        $precisaChat = $dados['papel'] !== 'embedding';
        $precisaEmbedding = $dados['papel'] !== 'chat';

        if ($nome === '' || ($precisaChat && $dados['modelo_chat'] === '')) {
            flash_set('erro', 'Nome e modelo de chat são obrigatórios.');
            redirect('provedores.php');
        }

        if ($precisaEmbedding && $dados['modelo_embedding'] === '') {
            flash_set('erro', 'Este papel inclui embedding, então o modelo de embedding é obrigatório.');
            redirect('provedores.php');
        }

        // Recusa na entrada em vez de erro na primeira indexação.
        //
        // A Anthropic não tem API de embeddings. Antes desta checagem dava
        // para salvar a linha e escolhê-la como origem de embedding; a falha
        // só aparecia depois, como erro de outro fornecedor, porque o código
        // caía no provider da OpenAI usando a chave da Anthropic.
        $driverEmbed = $dados['driver_embedding'] ?: $dados['driver'];

        if ($precisaEmbedding && in_array($driverEmbed, $SEM_EMBEDDING, true)) {
            flash_set(
                'erro',
                'A Anthropic não tem API de embeddings. Deixe este provedor como "Somente chat" e '
                . 'cadastre um segundo provedor (OpenAI, Gemini ou Ollama) para os embeddings.'
            );
            redirect('provedores.php');
        }

        // A chave NUNCA é digitada aqui: guardamos só o nome da variável do
        // .env. Chave no banco vaza em backup, em export e em log de consulta.
        if ($dados['auth_ref'] !== '' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $dados['auth_ref'])) {
            flash_set('erro', 'A referência da chave deve ser o NOME de uma variável do .env (ex.: GEMINI_API_KEY), não a chave em si.');
            redirect('provedores.php');
        }

        $agora = now();

        if ($id > 0) {
            $sql = 'UPDATE provedores SET nome=:nome, slug=:slug, driver=:driver, papel=:papel, base_url=:base_url,
                    auth_ref=:auth_ref, modelo_chat=:modelo_chat, driver_embedding=:driver_embedding,
                    base_url_embedding=:base_url_embedding, modelo_embedding=:modelo_embedding,
                    dimensoes=:dimensoes, suporta_tools=:suporta_tools, suporta_stream=:suporta_stream,
                    ativo=:ativo, editado_em=:editado_em WHERE id=:id';
            $dados['editado_em'] = $agora;
            $dados['id'] = $id;
            $pdo->prepare($sql)->execute($dados);
            Auth::log('provedor_editado', $nome);
            flash_set('sucesso', 'Provedor atualizado.');
        } else {
            $sql = 'INSERT INTO provedores (nome, slug, driver, papel, base_url, auth_ref, modelo_chat,
                    driver_embedding, base_url_embedding, modelo_embedding, dimensoes,
                    suporta_tools, suporta_stream, ativo, criado_em, editado_em)
                    VALUES (:nome, :slug, :driver, :papel, :base_url, :auth_ref, :modelo_chat,
                    :driver_embedding, :base_url_embedding, :modelo_embedding, :dimensoes,
                    :suporta_tools, :suporta_stream, :ativo, :criado_em, :editado_em)';
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;
            $pdo->prepare($sql)->execute($dados);
            Auth::log('provedor_criado', $nome);
            flash_set('sucesso', 'Provedor criado. Use "Testar" antes de ativar.');
        }

        if ($resultadoTeste === null) {
            redirect('provedores.php');
        }
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM provedores WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$provedores = $pdo->query('SELECT * FROM provedores ORDER BY ativo DESC, nome ASC')->fetchAll();
$config = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch() ?: [];

$paraChat = array_filter($provedores, static fn (array $p): bool => $p['papel'] !== 'embedding' && $p['ativo']);
$paraEmbedding = array_filter($provedores, static fn (array $p): bool => $p['papel'] !== 'chat' && $p['ativo']);

/**
 * Bases indexadas por um provedor de embedding diferente do padrão.
 *
 * Isto NÃO é preferência de estilo: o vetor da pergunta sai do provedor
 * padrão, e vetor só é comparável com vetor do mesmo modelo. Base indexada
 * por outro modelo não devolve erro — devolve nota sem sentido e trecho
 * errado, calada. O aviso existe porque o sintoma não aponta para a causa.
 */
$padraoEmbeddingId = (int) ($config['provedor_embedding_padrao_id'] ?? 0);
$basesDivergentes = [];

if ($padraoEmbeddingId > 0) {
    $stmt = $pdo->prepare(
        'SELECT b.nome, p.nome AS provedor FROM bases b
         LEFT JOIN provedores p ON p.id = b.provedor_embedding_id
         WHERE b.provedor_embedding_id IS NOT NULL AND b.provedor_embedding_id != :padrao'
    );
    $stmt->execute(['padrao' => $padraoEmbeddingId]);
    $basesDivergentes = $stmt->fetchAll();
}

/** Situação da chave sem NUNCA exibir o valor. */
$situacaoChave = static function (?string $ref): array {
    if ($ref === null || $ref === '') {
        return ['—', 'neutro'];
    }

    $valor = env_secret($ref);

    return $valor === null || $valor === ''
        ? [$ref . ' (vazia)', 'erro']
        : [$ref . ' (definida)', 'ok'];
};

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Provedores</h1>
    <p class="page-sub">
        Onde o agente busca o modelo. A <strong>chave nunca fica aqui</strong> — o campo guarda o
        nome da variável do <code>.env</code>, e só o servidor resolve o valor.
    </p>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Dois universos, duas escolhas</h2>
    <p class="dica-painel">
        <strong>Chat</strong> e <strong>embedding</strong> são trabalhos diferentes, feitos por
        modelos diferentes: um gera texto, o outro transforma texto em vetor para a busca. Nunca é
        o mesmo modelo, e não precisa ser o mesmo fornecedor.
    </p>
    <p class="dica-painel">
        Como cada provedor guarda <strong>uma única chave</strong>, misturar fornecedores se faz
        com <strong>dois provedores cadastrados</strong> — um marcado “somente chat”, outro
        “somente embedding” — e não com dois campos na mesma linha. É assim que se usa, por
        exemplo, Anthropic no chat e OpenAI nos embeddings.
    </p>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="padroes">

        <div class="form-grid">
            <label>
                Provedor padrão de chat
                <select name="provedor_chat_padrao_id">
                    <option value="0">— nenhum —</option>
                    <?php foreach ($paraChat as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($config['provedor_chat_padrao_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Vale para o agente que não escolheu um provedor próprio.</small>
            </label>

            <label>
                Provedor padrão de embedding
                <select name="provedor_embedding_padrao_id">
                    <option value="0">— nenhum —</option>
                    <?php foreach ($paraEmbedding as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($config['provedor_embedding_padrao_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>
                    <strong>Governa a busca inteira.</strong> A pergunta do visitante, o índice da
                    FAQ e o das bases precisam sair todos deste mesmo modelo — vetores de modelos
                    diferentes não são comparáveis, e a comparação não dá erro: dá resposta errada.
                </small>
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Salvar padrões</button>
        </div>
    </form>

    <?php if ($basesDivergentes): ?>
        <ul class="lista-alertas" style="margin-top:1rem;">
            <li class="alerta alerta-erro">
                <strong>Bases indexadas por outro provedor de embedding.</strong>
                A busca nelas compara vetores de modelos diferentes e devolve resultado sem sentido,
                sem apresentar erro. Reindexe-as com o padrão acima ou ajuste o provedor da base:
                <?= e(implode(', ', array_map(
                    static fn (array $b): string => $b['nome'] . ' (' . ($b['provedor'] ?? 'sem provedor') . ')',
                    $basesDivergentes
                ))) ?>.
            </li>
        </ul>
    <?php endif; ?>
</div>

<?php if ($resultadoTeste !== null): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <h2 class="card-title">Teste · <?= e((string) $provedorTestado) ?></h2>
        <ul class="lista-alertas">
            <?php foreach ($resultadoTeste as $r): ?>
                <li class="alerta alerta-<?= $r['ok'] ? 'ok' : 'erro' ?>">
                    <strong><?= e($r['item']) ?></strong> — <?= e($r['detalhe']) ?>
                    <?php if (!$r['ok']): ?>
                        <div style="margin-top:0.4rem;font-size:0.86em;">
                            <div>→ <?= e($r['sugestao']) ?></div>
                            <div style="opacity:0.75;margin-top:0.25rem;">
                                No chat o visitante veria apenas:
                                <em>“<?= e($r['publica']) ?>”</em>
                            </div>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="vazio" style="margin-top:0.75rem;">
            O mesmo teste pelo terminal: <code>php bin/testar-provedor.php</code>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar provedor' : 'Novo provedor' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e($editando['nome'] ?? '') ?>" placeholder="ex.: Google Gemini Flash">
            </label>

            <label>
                Identificador
                <input type="text" name="slug" value="<?= e($editando['slug'] ?? '') ?>" placeholder="gerado do nome se vazio">
            </label>

            <label class="col-2">
                Este provedor serve para
                <select name="papel">
                    <?php foreach ($PAPEIS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= ($editando['papel'] ?? 'ambos') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>
                    Só os campos do papel escolhido são exigidos, e o teste só roda o que faz
                    sentido. A <strong>Anthropic não tem API de embeddings</strong>: com ela, use
                    “somente chat” e cadastre um segundo provedor para a busca.
                </small>
            </label>

            <h3 class="form-secao">
                Chat — gerar a resposta
                <small>O modelo que conversa com o visitante.</small>
            </h3>

            <label>
                Driver de chat
                <select name="driver">
                    <?php foreach ($DRIVERS as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= ($editando['driver'] ?? 'gemini') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>
                    <strong>Precisa combinar com o endereço abaixo.</strong> Driver nativo com endereço
                    do caminho compatível com OpenAI (ou o contrário) devolve 404 em toda pergunta —
                    e o erro não diz que a causa é esta. Na dúvida, deixe o endereço vazio: cada driver
                    conhece o padrão dele.
                </small>
            </label>

            <label>
                Modelo de chat
                <input type="text" name="modelo_chat" value="<?= e($editando['modelo_chat'] ?? '') ?>" placeholder="ex.: gemini-3.7-flash">
                <small>Fixe a versão. Aliases como <code>*-latest</code> mudam custo e comportamento sem aviso, e já responderam 503 enquanto as versões fixas funcionavam.</small>
            </label>

            <label class="col-2">
                Endpoint de chat
                <input type="text" name="base_url" value="<?= e($editando['base_url'] ?? '') ?>" placeholder="deixe vazio para o padrão do driver">
                <small>Preenchido, cobre Groq, DeepSeek, OpenRouter e qualquer outro compatível com OpenAI — sem código novo.</small>
            </label>

            <label class="col-2">
                Variável do <code>.env</code> com a chave
                <input type="text" name="auth_ref" value="<?= e($editando['auth_ref'] ?? '') ?>" placeholder="GEMINI_API_KEY">
                <small><strong>Não cole a chave aqui.</strong> Digite o nome da variável; o valor fica só no <code>.env</code>, fora do banco e fora do backup.</small>
            </label>

            <h3 class="form-secao">
                Embedding — encontrar o material
                <small>
                    Modelo diferente e trabalho diferente: transforma texto em vetor para a busca
                    da FAQ e das bases. <strong>Trocar qualquer campo daqui obriga a reindexar
                    tudo</strong> — os vetores antigos passam a viver num espaço matemático
                    diferente e viram lixo, mesmo quando o número de dimensões coincide.
                </small>
            </h3>

            <label>
                Driver de embedding
                <select name="driver_embedding">
                    <?php foreach ($DRIVERS_EMBEDDING as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>" <?= ($editando['driver_embedding'] ?? '') === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>No Gemini, só o caminho nativo aplica <code>task_type</code> — o compatível recusa com erro 400.</small>
            </label>

            <label>
                Modelo de embedding
                <input type="text" name="modelo_embedding" value="<?= e($editando['modelo_embedding'] ?? '') ?>" placeholder="ex.: gemini-embedding-001">
            </label>

            <label>
                Endpoint de embedding
                <input type="text" name="base_url_embedding" value="<?= e($editando['base_url_embedding'] ?? '') ?>" placeholder="padrão do driver">
            </label>

            <label>
                Dimensões
                <input type="number" name="dimensoes" value="<?= (int) ($editando['dimensoes'] ?? 768) ?>" min="0" step="1">
                <small>768 em vez de 1536 dobra o teto de chunks por base, com perda desprezível.</small>
            </label>
        </div>

        <p class="dica-campo" style="margin-bottom:0.5rem">
            Desmarcar <strong>desliga o recurso para todos os agentes</strong> que usam este provedor,
            sem aviso na conversa: as ferramentas simplesmente deixam de ser chamadas, e a resposta
            sai como se elas não existissem. Só desmarque se o fornecedor realmente não suportar.
        </p>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="suporta_tools" <?= ($editando['suporta_tools'] ?? 1) ? 'checked' : '' ?>> Suporta ferramentas</label>
            <label class="check"><input type="checkbox" name="suporta_stream" <?= ($editando['suporta_stream'] ?? 1) ? 'checked' : '' ?>> Suporta streaming</label>
            <label class="check"><input type="checkbox" name="ativo" <?= ($editando['ativo'] ?? 0) ? 'checked' : '' ?>> Ativo</label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?>
                <a href="provedores.php" class="btn btn-secondary">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Cadastrados</h2>
    <?php if (!$provedores): ?>
        <p class="vazio">Nenhum provedor cadastrado.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Serve para</th>
                <th>Driver</th>
                <th>Modelo</th>
                <th>Chave</th>
                <th>Embedding</th>
                <th>Situação</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($provedores as $p): ?>
                <?php [$rotuloChave, $estadoChave] = $situacaoChave($p['auth_ref']); ?>
                <tr>
                    <td>
                        <strong><?= e($p['nome']) ?></strong><br><small style="opacity:.6"><?= e($p['slug']) ?></small>
                        <?php if ((int) ($config['provedor_chat_padrao_id'] ?? 0) === (int) $p['id']): ?>
                            <br><span class="tag tag-ok">padrão de chat</span>
                        <?php endif; ?>
                        <?php if ((int) ($config['provedor_embedding_padrao_id'] ?? 0) === (int) $p['id']): ?>
                            <br><span class="tag tag-ok">padrão de embedding</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="tag"><?= e($PAPEIS[$p['papel']] ?? $p['papel']) ?></span></td>
                    <td><span class="tag"><?= e($p['driver']) ?></span></td>
                    <td><code><?= e((string) $p['modelo_chat']) ?></code></td>
                    <td>
                        <span class="tag tag-<?= $estadoChave ?>"><?= e($rotuloChave) ?></span>
                    </td>
                    <td>
                        <?php if ($p['papel'] === 'chat'): ?>
                            <small style="opacity:.6">não se aplica</small>
                        <?php else: ?>
                            <code><?= e((string) $p['modelo_embedding']) ?></code>
                            <br><small style="opacity:.6"><?= (int) $p['dimensoes'] ?> dim · <?= e($p['driver_embedding'] ?: $p['driver']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="tag tag-<?= $p['ativo'] ? 'ok' : 'neutro' ?>"><?= $p['ativo'] ? 'ativo' : 'inativo' ?></span></td>
                    <td class="acoes">
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="testar">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Testar</button>
                        </form>
                        <a href="?editar=<?= (int) $p['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir este provedor?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
