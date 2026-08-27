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

        // Prefixo das credenciais no `.env`. Só letras, números e sublinhado
        // porque ele é concatenado para formar nomes de variável — um espaço
        // ali produziria uma busca que nunca encontra nada, e o sintoma seria
        // "todo webhook recusado" sem pista da causa.
        $credenciaisRef = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['credenciais_ref'] ?? '')) ?? '');

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
            $dados['credenciais_ref'] = $credenciaisRef !== '' ? $credenciaisRef : null;

            // Nem o SLUG nem o TIPO mudam ao editar, e pelo mesmo motivo: os
            // dois já estão em uso lá fora. O slug é o token colado no HTML do
            // cliente; o tipo decide como as mensagens saem, e virá-lo numa
            // conversa em andamento entregaria ao WhatsApp mensagens que o
            // widget deveria puxar — ou o contrário. O prefixo das credenciais
            // MUDA: corrigir um erro de digitação não pode exigir recriar o
            // canal e perder o vínculo com as conversas.
            $pdo->prepare(
                'UPDATE canais SET nome=:nome, agente_id=:agente_id, config=:config,
                        credenciais_ref=:credenciais_ref,
                        retencao_dias=:retencao_dias, ativo=:ativo, editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('canal_editado', $nome);
        } else {
            // Sufixo aleatório no token. O domínio é quem autoriza, mas um
            // token adivinhável convidaria a varrer canais alheios só para
            // descobrir quais existem.
            $dados['slug'] = slugify($nome) . '-' . bin2hex(random_bytes(4));
            $dados['tipo'] = in_array($_POST['tipo'] ?? '', ['web', 'whatsapp'], true)
                ? (string) $_POST['tipo']
                : 'web';

            // Padrão `WHATSAPP` para quem tem um número só — que é o caso
            // comum. O prefixo existe para a instalação que atende dois
            // números, cada um com seu bloco no `.env`.
            $dados['credenciais_ref'] = $dados['tipo'] === 'whatsapp'
                ? ($credenciaisRef !== '' ? $credenciaisRef : 'WHATSAPP')
                : null;

            $dados['criado_em'] = now();

            $pdo->prepare(
                'INSERT INTO canais (slug, tipo, nome, agente_id, config, credenciais_ref,
                        retencao_dias, ativo, criado_em, editado_em)
                 VALUES (:slug, :tipo, :nome, :agente_id, :config, :credenciais_ref,
                        :retencao_dias, :ativo, :criado_em, :editado_em)'
            )->execute($dados);

            $id = (int) $pdo->lastInsertId();
            Auth::log('canal_criado', $nome . ' (' . $dados['tipo'] . ')');
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

// Editando, o tipo vem do registro; criando, o formulário começa em `web` e o
// JS troca os blocos. Nada aqui adivinha: canal antigo sem tipo gravado é
// widget, que era o único que existia.
$tipoAtual = (string) $v('tipo', 'web') ?: 'web';

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
                Tipo
                <?php if ($editando): ?>
                    <input type="text" value="<?= e($tipoAtual === 'whatsapp' ? 'WhatsApp' : 'Chat no site') ?>" disabled>
                    <small>
                        <strong>Não muda depois de criado.</strong> O tipo decide como a mensagem
                        sai — virá-lo numa conversa em andamento entregaria ao WhatsApp o que o
                        widget deveria puxar. Para trocar, crie outro canal.
                    </small>
                <?php else: ?>
                    <select name="tipo" id="campo-tipo">
                        <option value="web">Chat no site (widget)</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                    <small>
                        <strong>Escolha com atenção: não muda depois.</strong> O widget é instalado
                        com um trecho de código no site; o WhatsApp recebe por webhook e exige as
                        credenciais no <code>.env</code>.
                    </small>
                <?php endif; ?>
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
                <small>
                    Quem responde neste canal. <em>Usar o agente padrão</em> depende de haver um
                    escolhido em <a href="configuracoes.php">Configurações</a> —
                    <strong>sem ele, este canal não responde.</strong>
                </small>
            </label>

            <label class="so-whatsapp">
                Prefixo das credenciais
                <input type="text" name="credenciais_ref" value="<?= e((string) $v('credenciais_ref', 'WHATSAPP')) ?>"
                       placeholder="WHATSAPP">
                <small>
                    Nome-base das variáveis no <code>.env</code>: com <code>WHATSAPP</code>, o sistema
                    procura <code>WHATSAPP_TOKEN</code>, <code>WHATSAPP_PHONE_NUMBER_ID</code>,
                    <code>WHATSAPP_APP_SECRET</code> e <code>WHATSAPP_VERIFY_TOKEN</code>.
                    <strong>Só mude se tiver mais de um número</strong> — aí cada canal usa um prefixo
                    e um bloco próprio no arquivo.
                </small>
            </label>

            <label class="col-2 so-web">
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

            <label class="so-web">
                Limite por minuto
                <input type="number" min="1" name="limite_minuto" value="<?= (int) $o('limite_minuto', 6) ?>">
                <small>Por visitante. Segura o dedo nervoso e o bot de scraping.</small>
            </label>

            <label class="so-web">
                Limite por dia
                <input type="number" min="1" name="limite_dia" value="<?= (int) $o('limite_dia', 60) ?>">
                <small>
                    Cada pergunta é uma chamada paga ao provedor. Sem teto, um único visitante
                    esvazia a cota do dia.
                </small>
            </label>

            <label class="so-web">
                Título do widget
                <input type="text" name="titulo" value="<?= e((string) $o('titulo')) ?>"
                       placeholder="usa o nome do canal se vazio">
                <small>Aparece no topo do chat, para o visitante.</small>
            </label>

            <label class="so-web">
                Cor
                <input type="text" name="cor" value="<?= e((string) $o('cor', '#2563eb')) ?>" placeholder="#2563eb">
                <small>Hexadecimal. Combine com o site de quem instala.</small>
            </label>

            <label class="col-2 so-web">
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

<?php if ($editando && $tipoAtual === 'web'): ?>
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

<?php if ($editando && $tipoAtual === 'whatsapp'): ?>
    <?php
    // Espelho do bloco de instalação do widget: lá é o trecho de código para
    // colar no site, aqui é o endereço para colar no painel da Meta. Quem
    // acabou de criar o canal procura "e agora?" no mesmo lugar.
    //
    // O ESTADO das credenciais aparece, o VALOR nunca. O que resolve um
    // webhook mudo é saber QUAL variável está vazia — e mostrar o segredo na
    // tela anularia a razão de ele não ficar no banco.
    $prefixo = trim((string) $v('credenciais_ref', '')) ?: 'WHATSAPP';

    $variaveis = [
        '_PHONE_NUMBER_ID' => 'ID do número, no painel do app da Meta. É por ele que o evento encontra este canal.',
        '_TOKEN' => 'Token de acesso. O do painel expira em ~24h; o permanente sai de Usuários do sistema.',
        '_APP_SECRET' => 'Fica em Configurações do app > Básico, não na tela do WhatsApp. Sem ele, todo webhook é recusado.',
        '_VERIFY_TOKEN' => 'Uma senha inventada por você. A mesma precisa ser digitada na Meta ao salvar o webhook.',
    ];

    $faltando = 0;

    foreach (array_keys($variaveis) as $sufixo) {
        if ((string) env_secret($prefixo . $sufixo) === '') {
            $faltando++;
        }
    }
    ?>
    <div class="card">
        <h2 class="card-title">Ligação com a Meta</h2>
        <p class="page-sub" style="margin-top:-.35rem">
            Configure em <strong>WhatsApp &rsaquo; Configuração &rsaquo; Webhook</strong>, no painel do
            seu app. A Meta faz uma verificação na hora de salvar: se este endereço não estiver
            acessível pela internet, ela recusa.
        </p>

        <label style="display:block;margin-bottom:.75rem">
            URL de callback
            <pre class="bloco-codigo" id="url-webhook"><code><?= e($baseUrl) ?>/api/whatsapp.php</code></pre>
        </label>

        <div class="form-acoes" style="margin-bottom:1rem">
            <button type="button" class="btn btn-secondary btn-sm" onclick="
                navigator.clipboard.writeText(document.getElementById('url-webhook').innerText);
                this.textContent = 'Copiado!';
            ">Copiar URL</button>
        </div>

        <p class="page-sub">
            Assine o campo <code>messages</code>. E confira também que o <strong>app está inscrito
            nesta conta do WhatsApp</strong> — são duas coisas com nomes parecidos, e o campo
            aparecer marcado não garante a inscrição. Sem ela o webhook fica mudo, sem erro nenhum.
        </p>

        <h3 class="secao-form">Credenciais no <code>.env</code></h3>
        <p class="page-sub" style="margin-top:-.35rem">
            Os valores ficam no arquivo, nunca no banco — em backup ou log, o banco vaza junto.
            Aqui só aparece se cada uma está preenchida.
        </p>

        <table class="tabela">
            <tbody>
            <?php foreach ($variaveis as $sufixo => $paraQueServe): ?>
                <?php $ok = (string) env_secret($prefixo . $sufixo) !== ''; ?>
                <tr>
                    <td style="white-space:nowrap"><code><?= e($prefixo . $sufixo) ?></code></td>
                    <td>
                        <?= $ok
                            ? '<span class="tag">preenchida</span>'
                            : '<span class="tag tag-erro">vazia</span>' ?>
                    </td>
                    <td><small><?= e($paraQueServe) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($faltando > 0): ?>
            <p class="alerta alerta-aviso" style="margin-top:1rem">
                <strong><?= $faltando ?> variável(is) sem valor.</strong> Enquanto faltar qualquer
                uma, este canal não recebe nem responde — e a Meta não mostra o motivo.
            </p>
        <?php endif; ?>

        <p class="page-sub" style="margin-top:1rem">
            Fora da janela de 24 horas desde a última mensagem da pessoa, a Meta só aceita modelo
            aprovado — texto livre é recusado. Vale para a resposta do agente e para o arquivo que
            o atendente enviar.
        </p>
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
                    <td>
                        <?php if (($c['tipo'] ?? 'web') === 'whatsapp'): ?>
                            <span class="tag">WhatsApp</span>
                            <?php
                            // Credencial faltando aparece na LISTA, não só ao
                            // abrir o canal: um canal que não responde parece
                            // igual a um que responde, e a diferença é uma
                            // variável vazia num arquivo que ninguém revisita.
                            $pref = trim((string) ($c['credenciais_ref'] ?? '')) ?: 'WHATSAPP';
                            $vazias = 0;
                            foreach (['_PHONE_NUMBER_ID', '_TOKEN', '_APP_SECRET', '_VERIFY_TOKEN'] as $sf) {
                                if ((string) env_secret($pref . $sf) === '') {
                                    $vazias++;
                                }
                            }
                            ?>
                            <?php if ($vazias > 0): ?>
                                <br><span class="tag tag-erro" title="Sem elas o canal não recebe nem responde"><?= $vazias ?> credencial(is) faltando</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="tag tag-neutro">Chat no site</span>
                        <?php endif; ?>
                    </td>
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

<script>
(function () {
    // Campos de widget e campos de WhatsApp nao se misturam: limite por
    // minuto, cor e saudacao nao existem no WhatsApp, e mostrar campo que
    // nao faz nada e a mesma mentira de um campo que ninguem le.
    var tipo = document.getElementById('campo-tipo');
    var atual = <?= json_encode($tipoAtual) ?>;

    function aplicar(t) {
        document.querySelectorAll('.so-web').forEach(function (el) {
            el.style.display = t === 'web' ? '' : 'none';
        });
        document.querySelectorAll('.so-whatsapp').forEach(function (el) {
            el.style.display = t === 'whatsapp' ? '' : 'none';
        });
    }

    aplicar(tipo ? tipo.value : atual);

    if (tipo) {
        tipo.addEventListener('change', function () { aplicar(tipo.value); });
    }
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
