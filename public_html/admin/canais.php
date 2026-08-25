<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'canais.php';
$tituloPagina = 'Canais';

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('canais.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        // As conversas ficam: canal_id é ON DELETE SET NULL. Excluir o canal
        // desliga o widget daquele site, não apaga o histórico de quem
        // conversou por ele.
        $pdo->prepare('DELETE FROM canais WHERE id = :id')->execute(['id' => $id]);
        Auth::log('canal_excluido', 'id=' . $id);
        flash_set('sucesso', 'Canal excluído. As conversas dele continuam no histórico.');
        redirect('canais.php');
    }

    if ($acao === 'salvar') {
        $nome = trim(texto_utf8($_POST['nome'] ?? ''));

        if ($nome === '') {
            flash_set('erro', 'O nome é obrigatório.');
            redirect('canais.php');
        }

        $dominios = [];

        foreach (preg_split('/[\s,;]+/', (string) ($_POST['dominios'] ?? '')) ?: [] as $d) {
            $d = strtolower(trim($d));
            // Aceita a URL inteira colada: é o erro mais provável de quem
            // preenche este campo uma vez só na vida.
            $d = (string) preg_replace('#^https?://#', '', $d);
            $d = trim(explode('/', $d)[0]);

            if ($d !== '') {
                $dominios[] = $d;
            }
        }

        $cor = trim((string) ($_POST['cor'] ?? ''));

        $config = [
            'dominios' => array_values(array_unique($dominios)),
            'limite_minuto' => max(1, (int) ($_POST['limite_minuto'] ?? 6)),
            'limite_dia' => max(1, (int) ($_POST['limite_dia'] ?? 60)),
            'titulo' => trim(texto_utf8($_POST['titulo'] ?? '')),
            'saudacao' => trim(texto_utf8($_POST['saudacao'] ?? '')),
            'cor' => preg_match('/^#[0-9a-fA-F]{6}$/', $cor) === 1 ? $cor : '#2563eb',
        ];

        $dados = [
            'nome' => $nome,
            'agente_id' => ((int) ($_POST['agente_id'] ?? 0)) ?: null,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'retencao_dias' => max(0, (int) ($_POST['retencao_dias'] ?? 0)),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'editado_em' => now(),
        ];

        if ($id > 0) {
            $dados['id'] = $id;

            // O slug NÃO muda ao editar: ele é o token que já está colado no
            // site do cliente. Trocá-lo derrubaria o widget em produção sem
            // aviso, e o campo estaria ali convidando ao acidente.
            $pdo->prepare(
                'UPDATE canais SET nome=:nome, agente_id=:agente_id, config=:config,
                        retencao_dias=:retencao_dias, ativo=:ativo, editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('canal_editado', $nome);
        } else {
            // Sufixo aleatório no token. O domínio é quem autoriza, mas um
            // token adivinhável convidaria a varrer canais alheios só para
            // descobrir quais existem.
            $dados['slug'] = slugify($nome) . '-' . bin2hex(random_bytes(4));
            $dados['tipo'] = 'web';
            $dados['criado_em'] = now();

            $pdo->prepare(
                'INSERT INTO canais (slug, tipo, nome, agente_id, config, retencao_dias, ativo,
                        criado_em, editado_em)
                 VALUES (:slug, :tipo, :nome, :agente_id, :config, :retencao_dias, :ativo,
                        :criado_em, :editado_em)'
            )->execute($dados);

            $id = (int) $pdo->lastInsertId();
            Auth::log('canal_criado', $nome);
        }

        flash_set('sucesso', 'Canal salvo.');
        redirect('canais.php?editar=' . $id);
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM canais WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$canais = $pdo->query(
    'SELECT c.*, a.nome AS agente,
            (SELECT COUNT(*) FROM conversas v WHERE v.canal_id = c.id) AS conversas
     FROM canais c LEFT JOIN agentes a ON a.id = c.agente_id
     ORDER BY c.ativo DESC, c.nome'
)->fetchAll();

$agentes = $pdo->query('SELECT id, nome FROM agentes WHERE ativo = 1 ORDER BY nome')->fetchAll();

$cfgEditando = json_para_array($editando['config'] ?? null);
$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;
$o = static fn (string $campo, mixed $padrao = '') => $cfgEditando[$campo] ?? $padrao;

// A URL base do embed vem do .env. Se estiver errada, o snippet que o admin
// copiar aponta para o lugar errado — e o sintoma aparece só no site do
// cliente, longe daqui.
$baseUrl = APP_URL;

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Canais</h1>
    <p class="page-sub">
        Onde o agente atende. Cada canal tem seu próprio token, seu próprio agente e seus próprios
        limites de uso — assim um site que recebe muita visita não consome a cota do outro.
    </p>
</div>

<?php if ($baseUrl === ''): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-erro">
            <strong>APP_URL não está definida no <code>.env</code>.</strong> O código de instalação
            abaixo sai com o endereço errado, e o problema só aparece no site do cliente.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar: ' . e((string) $editando['nome']) : 'Novo canal' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e((string) $v('nome')) ?>"
                       placeholder="ex.: Site institucional">
                <small>Só para você se achar aqui no painel.</small>
            </label>

            <label>
                Agente
                <select name="agente_id">
                    <option value="">— usar o agente padrão —</option>
                    <?php foreach ($agentes as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $v('agente_id') === (int) $a['id'] ? 'selected' : '' ?>>
                            <?= e((string) $a['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="col-2">
                Domínios autorizados
                <textarea name="dominios" rows="2"
                          placeholder="exemplo.com.br outrosite.com"><?= e(implode(' ', (array) $o('dominios', []))) ?></textarea>
                <small>
                    <strong>É isto que protege o canal, não o token.</strong> O token vai visível no
                    HTML de quem instala o widget — tratá-lo como senha seria autoengano. Um por
                    linha ou separados por espaço; subdomínios entram junto (<code>exemplo.com.br</code>
                    libera <code>www.exemplo.com.br</code>). <strong>Vazio bloqueia todo site externo.</strong>
                </small>
            </label>

            <label>
                Limite por minuto
                <input type="number" min="1" name="limite_minuto" value="<?= (int) $o('limite_minuto', 6) ?>">
                <small>Por visitante. Segura o dedo nervoso e o bot de scraping.</small>
            </label>

            <label>
                Limite por dia
                <input type="number" min="1" name="limite_dia" value="<?= (int) $o('limite_dia', 60) ?>">
                <small>
                    Cada pergunta é uma chamada paga ao provedor. Sem teto, um único visitante
                    esvazia a cota do dia.
                </small>
            </label>

            <label>
                Título do widget
                <input type="text" name="titulo" value="<?= e((string) $o('titulo')) ?>"
                       placeholder="usa o nome do canal se vazio">
                <small>Aparece no topo do chat, para o visitante.</small>
            </label>

            <label>
                Cor
                <input type="text" name="cor" value="<?= e((string) $o('cor', '#2563eb')) ?>" placeholder="#2563eb">
                <small>Hexadecimal. Combine com o site de quem instala.</small>
            </label>

            <label class="col-2">
                Saudação
                <textarea name="saudacao" rows="2"
                          placeholder="Olá! Como posso ajudar?"><?= e((string) $o('saudacao')) ?></textarea>
                <small>Primeira mensagem, escrita por você — não custa token nenhum.</small>
            </label>

            <label>
                Retenção deste canal (dias)
                <input type="number" min="0" name="retencao_dias" value="<?= (int) $v('retencao_dias', 0) ?>">
                <small>0 usa o prazo global de <a href="privacidade.php">Privacidade</a>.</small>
            </label>

            <label>
                <span>
                    <input type="checkbox" name="ativo" value="1" <?= (int) $v('ativo', 1) === 1 ? 'checked' : '' ?>>
                    Ativo
                </span>
                <small>Desmarcar derruba o widget imediatamente, em todos os sites onde ele estiver.</small>
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Salvar canal</button>
            <?php if ($editando): ?>
                <a href="canais.php" class="btn btn-secondary">Novo</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($editando): ?>
    <div class="card">
        <h2 class="card-title">Instalação</h2>
        <p class="page-sub" style="margin-top:-.35rem">
            Cole antes do <code>&lt;/body&gt;</code> do site. Só funciona nos domínios autorizados
            acima — se o widget não aparecer lá, é quase sempre um domínio faltando na lista.
        </p>

        <pre class="bloco-codigo" id="snippet"><code>&lt;script src="<?= e($baseUrl) ?>/embed.js" data-token="<?= e((string) $editando['slug']) ?>" defer&gt;&lt;/script&gt;</code></pre>

        <div class="form-acoes">
            <button type="button" class="btn btn-secondary btn-sm" onclick="
                navigator.clipboard.writeText(document.getElementById('snippet').innerText);
                this.textContent = 'Copiado!';
            ">Copiar</button>
            <a href="demo-widget.php?t=<?= e(urlencode((string) $editando['slug'])) ?>" target="_blank"
               class="btn btn-secondary btn-sm">Testar numa página</a>
        </div>

        <?php if ((array) $o('dominios', []) === []): ?>
            <p class="alerta alerta-aviso" style="margin-top:1rem">
                Nenhum domínio autorizado ainda: por ora o widget só responde a partir deste
                próprio servidor. A página de teste funciona; o site do cliente, não.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= count($canais) ?> canal(is)</h2>

    <?php if (!$canais): ?>
        <p class="vazio">Nenhum canal ainda. Crie um acima para gerar o código de instalação.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Agente</th>
                <th>Domínios</th>
                <th>Conversas</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($canais as $c): ?>
                <?php $co = json_para_array($c['config'] ?? null); ?>
                <tr>
                    <td>
                        <strong><?= e((string) $c['nome']) ?></strong>
                        <?php if (!(int) $c['ativo']): ?>
                            <span class="tag tag-neutro">inativo</span>
                        <?php endif; ?>
                        <br><small style="opacity:.55"><?= e((string) $c['slug']) ?></small>
                    </td>
                    <td><small><?= e((string) $c['tipo']) ?></small></td>
                    <td><small><?= e((string) ($c['agente'] ?? 'padrão')) ?></small></td>
                    <td>
                        <?php $ds = (array) ($co['dominios'] ?? []); ?>
                        <?php if ($ds === []): ?>
                            <span class="tag tag-erro">nenhum</span>
                        <?php else: ?>
                            <small><?= e(implode(', ', $ds)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $c['conversas'] ?></td>
                    <td>
                        <a href="?editar=<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Excluir o canal? O widget para de responder onde estiver instalado.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
