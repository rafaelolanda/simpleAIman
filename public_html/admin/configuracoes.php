<?php

declare(strict_types=1);

/**
 * Identidade da instância e instruções de instalação.
 *
 * Existe porque cinco colunas de `config` — nome, logo, as duas cores e o
 * agente padrão — eram LIDAS em quatro arquivos e editáveis em lugar nenhum.
 * Numa ferramenta feita para ser clonada por cliente, isso é justamente o que
 * diferencia uma instalação da outra, e só se mudava editando o banco.
 *
 * A segunda metade é o que ninguém consegue adivinhar sozinho: a linha do cron
 * e o endereço do webhook, com os caminhos desta instalação já preenchidos.
 */

require_once __DIR__ . '/_init.php';

$paginaAtual = 'configuracoes.php';
$tituloPagina = 'Configurações';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('configuracoes.php');
    }

    if (($_POST['acao'] ?? '') === 'salvar') {
        $nome = trim(texto_utf8($_POST['nome_instancia'] ?? ''));

        if ($nome === '') {
            flash_set('erro', 'O nome da instância não pode ficar em branco — ele aparece no topo do painel e na tela de login.');
            redirect('configuracoes.php');
        }

        $corDe = static function (string $campo, string $padrao): string {
            $cor = trim((string) ($_POST[$campo] ?? ''));

            return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) === 1 ? $cor : $padrao;
        };

        $dados = [
            'nome' => mb_substr($nome, 0, 80),
            'agente' => ((int) ($_POST['agente_padrao_id'] ?? 0)) ?: null,
            'primaria' => $corDe('cor_primaria', '#2563eb'),
            'secundaria' => $corDe('cor_secundaria', '#0f172a'),
            'agora' => now(),
        ];

        // ---------------------------------------------------------------
        // Logo
        //
        // O nome do arquivo é gerado por nós, nunca o enviado: o original
        // permitiria caminho relativo e sobrescrita de arquivo alheio. E o
        // tipo sai do CONTEÚDO, não do cabeçalho do navegador — que vem do
        // cliente e mentiria com facilidade.
        // ---------------------------------------------------------------
        $arquivo = $_FILES['logo'] ?? null;
        $temArquivo = is_array($arquivo) && (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $logoNova = null;

        if ($temArquivo) {
            $tmp = (string) $arquivo['tmp_name'];
            $aceitos = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

            if ((int) $arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                flash_set('erro', 'Não foi possível receber o arquivo da logo.');
                redirect('configuracoes.php');
            }

            if (filesize($tmp) > 2 * 1048576) {
                flash_set('erro', 'A logo passa de 2 MB. Ela aparece no topo do painel, então não precisa ser grande.');
                redirect('configuracoes.php');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) $finfo->file($tmp));

            if (!isset($aceitos[$mime])) {
                flash_set('erro', 'Formato não aceito. Use PNG, JPG, WEBP ou SVG.');
                redirect('configuracoes.php');
            }

            $destino = caminho_uploads('logo');

            if (!is_dir($destino) && !@mkdir($destino, 0775, true)) {
                flash_set('erro', 'Não foi possível criar a pasta da logo.');
                redirect('configuracoes.php');
            }

            $logoNova = 'logo-' . bin2hex(random_bytes(6)) . '.' . $aceitos[$mime];

            if (!move_uploaded_file($tmp, $destino . '/' . $logoNova)) {
                flash_set('erro', 'Não foi possível gravar a logo.');
                redirect('configuracoes.php');
            }
        }

        $sql = 'UPDATE config SET nome_instancia = :nome, agente_padrao_id = :agente,
                       cor_primaria = :primaria, cor_secundaria = :secundaria, editado_em = :agora';

        if ($logoNova !== null) {
            $sql .= ', logo = :logo';
            $dados['logo'] = $logoNova;
        } elseif (isset($_POST['remover_logo'])) {
            $sql .= ', logo = NULL';
        }

        $anterior = (string) ($config['logo'] ?? '');

        $pdo->prepare($sql . ' WHERE id = 1')->execute($dados);

        // A antiga só é apagada DEPOIS do UPDATE dar certo. Apagar antes
        // deixaria o banco apontando para um arquivo que não existe mais se a
        // escrita falhasse.
        if ($anterior !== '' && ($logoNova !== null || isset($_POST['remover_logo']))) {
            @unlink(caminho_uploads('logo') . '/' . $anterior);
        }

        Auth::log('config_editada', $dados['nome']);
        flash_set('sucesso', 'Configurações salvas.');
        redirect('configuracoes.php');
    }
}

$agentes = $pdo->query('SELECT id, nome FROM agentes WHERE ativo = 1 ORDER BY nome')->fetchAll();

// -------------------------------------------------------------------------
// Diagnóstico do agendamento
//
// O que interessa aqui não é "o cron está configurado?" — não temos como
// saber. É o SINTOMA de ele não estar: job esperando há muito tempo.
// -------------------------------------------------------------------------
$pendentes = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'pendente'")->fetchColumn();

$maisAntigo = $pdo->query(
    "SELECT MIN(criado_em) FROM jobs WHERE status = 'pendente'"
)->fetchColumn();

$esperaMin = $maisAntigo ? (int) round((time() - strtotime((string) $maisAntigo)) / 60) : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE status = 'processando' AND lock_ate IS NOT NULL AND lock_ate < :agora");
$stmt->execute(['agora' => now()]);
$presos = (int) $stmt->fetchColumn();

// Caminhos desta instalação, já resolvidos: o valor de copiar e colar é
// justamente não ter que descobri-los.
$caminhoWorker = realpath(__DIR__ . '/../../bin/worker.php') ?: '';
$binarioPhp = PHP_BINARY;
$iniCarregado = php_ini_loaded_file();

$canaisZap = $pdo->query("SELECT COUNT(*) FROM canais WHERE tipo = 'whatsapp' AND ativo = 1")->fetchColumn();

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Configurações</h1>
    <p class="page-sub">
        A identidade desta instalação e as instruções que dependem do servidor onde ela roda.
    </p>
</div>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="salvar">

    <div class="card">
        <h2 class="card-title">Identidade</h2>

        <div class="form-grid">
            <label>
                Nome da instalação
                <input type="text" name="nome_instancia" required maxlength="80"
                       value="<?= e((string) ($config['nome_instancia'] ?? '')) ?>"
                       placeholder="ex.: Atendimento Acme">
                <small>Aparece no topo do painel, na aba do navegador e na tela de login.</small>
            </label>

            <label>
                Agente padrão
                <select name="agente_padrao_id">
                    <option value="">— nenhum —</option>
                    <?php foreach ($agentes as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"
                            <?= (int) ($config['agente_padrao_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                            <?= e((string) $a['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>
                    Usado por quem não escolheu um: o canal marcado como
                    <em>“usar o agente padrão”</em> e o playground caem aqui.
                    <strong>Sem agente padrão, esse canal não responde.</strong>
                </small>
            </label>

            <label>
                Cor primária
                <input type="text" name="cor_primaria" placeholder="#2563eb"
                       value="<?= e((string) ($config['cor_primaria'] ?? '#2563eb')) ?>">
                <small>Hexadecimal. É a cor dos botões e destaques do painel.</small>
            </label>

            <label>
                Cor secundária
                <input type="text" name="cor_secundaria" placeholder="#0f172a"
                       value="<?= e((string) ($config['cor_secundaria'] ?? '#0f172a')) ?>">
                <small>Hexadecimal. Fundo escuro do menu e da tela de login.</small>
            </label>

            <label class="col-2">
                Logo
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                <small>
                    PNG, JPG, WEBP ou SVG, até 2 MB. Aparece no topo do menu e na tela de login —
                    não precisa ser grande.
                </small>

                <?php if (!empty($config['logo'])): ?>
                    <span style="display:flex;align-items:center;gap:.75rem;margin-top:.6rem">
                        <img src="../assets/uploads/logo/<?= e((string) $config['logo']) ?>"
                             alt="Logo atual" style="max-height:44px;background:#fff;padding:4px;border-radius:6px">
                        <label class="linha-check" style="margin:0">
                            <input type="checkbox" name="remover_logo" value="1"> remover
                        </label>
                    </span>
                <?php endif; ?>
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
    </div>
</form>

<div class="card">
    <h2 class="card-title">Tarefa agendada (cron)</h2>
    <p class="page-sub" style="margin-top:-.35rem">
        Uma linha, a cada cinco minutos. Ela processa a fila e fecha o que depende de
        <strong>tempo</strong>: conversa presa com atendente que fechou o navegador, espera longa
        na fila e encerramento por inatividade. Nada disso tem evento que dispare —
        <strong>é justamente quando ninguém está mexendo que precisam rodar.</strong>
    </p>

    <pre class="bloco-codigo" id="linha-cron"><code>*/5 * * * * <?= e($binarioPhp) ?> <?= e($caminhoWorker) ?> &gt;/dev/null 2&gt;&amp;1</code></pre>

    <div class="form-acoes">
        <button type="button" class="btn btn-secondary btn-sm" onclick="
            navigator.clipboard.writeText(document.getElementById('linha-cron').innerText);
            this.textContent = 'Copiado!';
        ">Copiar</button>
    </div>

    <?php if ($iniCarregado === false): ?>
        <p class="alerta alerta-aviso" style="margin-top:1rem">
            <strong>Este PHP de linha de comando não encontrou nenhum <code>php.ini</code>.</strong>
            Sem ele o worker sobe sem extensão nenhuma e morre com um erro que não aponta para a
            causa — enquanto o painel continua funcionando normalmente. Aponte o arquivo na linha
            do agendamento (<code>php -c /caminho/php.ini</code>) ou defina <code>PHPRC</code>.
        </p>
    <?php endif; ?>

    <h3 class="secao-form">Está rodando?</h3>
    <p class="page-sub" style="margin-top:-.35rem">
        Não há como saber se o agendamento existe — mas dá para ver o sintoma de ele faltar.
    </p>

    <table class="tabela">
        <tbody>
        <tr>
            <td>Trabalhos na fila</td>
            <td>
                <?php if ($pendentes === 0): ?>
                    <span class="tag">nenhum</span>
                <?php elseif ($esperaMin >= 10): ?>
                    <span class="tag tag-erro"><?= $pendentes ?>, o mais antigo há <?= $esperaMin ?> min</span>
                <?php else: ?>
                    <span class="tag tag-neutro"><?= $pendentes ?> aguardando</span>
                <?php endif; ?>
            </td>
            <td><small>Fila parada há dezenas de minutos é o sinal mais claro de agendamento ausente.</small></td>
        </tr>
        <tr>
            <td>Travados</td>
            <td>
                <?= $presos > 0
                    ? '<span class="tag tag-erro">' . $presos . '</span>'
                    : '<span class="tag">nenhum</span>' ?>
            </td>
            <td><small>Processo que morreu no meio. A próxima execução do worker devolve à fila sozinha.</small></td>
        </tr>
        </tbody>
    </table>
</div>

<?php if ((int) $canaisZap > 0): ?>
    <div class="card">
        <h2 class="card-title">Webhook do WhatsApp</h2>
        <p class="page-sub" style="margin-top:-.35rem">
            Endereço que a Meta chama a cada mensagem recebida. É o mesmo para todos os números
            desta instalação — quem separa um canal do outro é o número de destino que vem no
            evento, não a URL.
        </p>

        <pre class="bloco-codigo" id="url-webhook-geral"><code><?= e(APP_URL) ?>/api/whatsapp.php</code></pre>

        <div class="form-acoes">
            <button type="button" class="btn btn-secondary btn-sm" onclick="
                navigator.clipboard.writeText(document.getElementById('url-webhook-geral').innerText);
                this.textContent = 'Copiado!';
            ">Copiar</button>
            <a href="canais.php" class="btn btn-secondary btn-sm">Ver credenciais por canal</a>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
