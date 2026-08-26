<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Tools\Executor;
use SimpleAIman\Tools\HttpTool;
use SimpleAIman\Tools\UrlGuard;

$paginaAtual = 'testar-ferramenta.php';
$tituloPagina = 'Testar ferramenta';

$ferramentas = $pdo->query('SELECT * FROM ferramentas ORDER BY ativo DESC, nome')->fetchAll();
$escolhida = null;
$parametros = [];

$selecionada = (int) ($_POST['ferramenta_id'] ?? $_GET['f'] ?? 0);

if ($selecionada > 0) {
    foreach ($ferramentas as $f) {
        if ((int) $f['id'] === $selecionada) {
            $escolhida = $f;
        }
    }

    if ($escolhida) {
        $stmt = $pdo->prepare('SELECT * FROM ferramenta_parametros WHERE ferramenta_id = :id ORDER BY ordem, id');
        $stmt->execute(['id' => $selecionada]);
        $parametros = $stmt->fetchAll();
    }
}

/**
 * Diagnóstico camada por camada.
 *
 * A ordem é a mesma da execução real, e cada etapa só roda se a anterior
 * passou. É isso que transforma "deu erro" em "parou aqui, por isto" — que é
 * a diferença entre corrigir em um minuto e ficar tentando às cegas.
 */
$etapas = [];
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $escolhida !== null) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('testar-ferramenta.php');
    }

    $enviarDeVerdade = isset($_POST['enviar']);
    $valores = [];

    foreach ($parametros as $p) {
        $valores[(string) $p['nome']] = (string) ($_POST['v_' . $p['nome']] ?? '');
    }

    $etapa = static function (string $nome, string $estado, string $detalhe, string $dica = '') use (&$etapas): void {
        $etapas[] = ['nome' => $nome, 'estado' => $estado, 'detalhe' => $detalhe, 'dica' => $dica];
    };

    // ---------------------------------------------------------------
    // 1. Validação dos campos — a mesma que o agente enfrenta
    // ---------------------------------------------------------------
    $validos = null;

    try {
        $reflexao = new ReflectionMethod(Executor::class, 'validar');
        $reflexao->setAccessible(true);
        $validos = $reflexao->invoke(new Executor(0), (int) $escolhida['id'], array_filter($valores, static fn ($v): bool => $v !== ''));

        $etapa('Campos', 'ok', json_encode($validos, JSON_UNESCAPED_UNICODE) ?: '{}');
    } catch (Throwable $e) {
        $etapa(
            'Campos',
            'erro',
            $e->getMessage(),
            'O agente receberia esta mesma recusa. Campo obrigatório faltando faz ele perguntar à pessoa antes de chamar.'
        );
    }

    if ($validos !== null && $escolhida['tipo'] === 'http') {
        // -----------------------------------------------------------
        // 2. Requisição montada — mostra o que SAIRIA
        // -----------------------------------------------------------
        $requisicao = null;

        try {
            $requisicao = (new HttpTool())->montar($escolhida, $validos);

            $texto = $requisicao['metodo'] . ' ' . HttpTool::ocultarUrl($escolhida, $requisicao['url']) . "\n"
                . implode("\n", HttpTool::ocultarSegredos($requisicao['cabecalhos']))
                . ($requisicao['corpo'] !== null ? "\n\n" . $requisicao['corpo'] : '');

            $etapa('Requisição montada', 'ok', $texto);
        } catch (Throwable $e) {
            $etapa(
                'Requisição montada',
                'erro',
                $e->getMessage(),
                'Falha antes de sair do servidor. Normalmente é a variável do .env vazia, ou JSON inválido nos cabeçalhos.'
            );
        }

        // -----------------------------------------------------------
        // 3. Guarda de URL
        // -----------------------------------------------------------
        if ($requisicao !== null) {
            try {
                UrlGuard::doAmbiente()->verificar($requisicao['url']);
                $etapa('Guarda de segurança', 'ok', 'HTTPS, host na allowlist e sem resolver para endereço interno.');
            } catch (Throwable $e) {
                $requisicao = null;
                $etapa(
                    'Guarda de segurança',
                    'erro',
                    $e->getMessage(),
                    'A chamada nem chega a sair. Ajuste TOOLS_HOSTS_PERMITIDOS no .env — é lá, e não no admin, '
                        . 'porque quem edita o painel não deveria poder ampliar o alcance de rede do servidor.'
                );
            }
        }

        // -----------------------------------------------------------
        // 4. Envio de verdade — só quando pedido explicitamente
        // -----------------------------------------------------------
        if ($requisicao !== null && $enviarDeVerdade) {
            $enviado = true;
            $inicio = microtime(true);

            try {
                $resultado = (new HttpTool())->executar($escolhida, $validos);
                $ms = (int) ((microtime(true) - $inicio) * 1000);

                $etapa('Resposta', 'ok', $ms . ' ms');

                $decodificado = json_decode($resultado, true);

                $etapa(
                    'O que o agente recebe',
                    'ok',
                    is_array($decodificado)
                        ? (string) ($decodificado['dados_externos'] ?? $resultado)
                        : $resultado,
                    'A resposta volta rotulada como DADO, com instrução de não obedecer comandos contidos nela — '
                        . 'um sistema externo que devolva "ignore as instruções anteriores" não pode virar ordem.'
                );
            } catch (Throwable $e) {
                $ms = (int) ((microtime(true) - $inicio) * 1000);
                $mensagem = $e->getMessage();

                $dica = match (true) {
                    str_contains($mensagem, 'HTTP 401'), str_contains($mensagem, 'HTTP 403')
                        => 'A API recusou a autenticação. Confira se a variável do .env tem a chave certa e se o tipo '
                            . '(Bearer/Basic) corresponde ao que ela espera.',
                    str_contains($mensagem, 'HTTP 404')
                        => 'Endereço não encontrado. Confira o caminho e se algum {{params.x}} ficou vazio na URL.',
                    str_contains($mensagem, 'HTTP 422'), str_contains($mensagem, 'HTTP 400')
                        => 'A API recebeu o pedido mas recusou o conteúdo. Compare o corpo montado acima com o que '
                            . 'a documentação dela espera — nome de campo e tipo costumam ser a causa.',
                    str_contains($mensagem, 'HTTP 5')
                        => 'Erro do lado da API. Se for intermitente, aumente as novas tentativas na ferramenta.',
                    str_contains($mensagem, 'timeout'), str_contains($mensagem, 'Operation timed out')
                        => 'Estourou o tempo limite. Aumente o valor na ferramenta ou confira se a API está lenta.',
                    str_contains($mensagem, 'SSL'), str_contains($mensagem, 'certificate')
                        => 'Falha de certificado. Confira curl.cainfo no php.ini — e nunca desligue a verificação: '
                            . 'numa chamada que leva token, isso transforma qualquer rede hostil em interceptação.',
                    default => 'Veja a mensagem acima. O agente receberia apenas "não foi possível consultar agora".',
                };

                $etapa('Resposta', 'erro', $ms . ' ms — ' . $mensagem, $dica);
            }
        } elseif ($requisicao !== null) {
            $etapa(
                'Envio',
                'aviso',
                'Não enviado.',
                $escolhida['efeito'] === 'escrita'
                    ? 'Esta ferramenta ESCREVE — enviar de verdade criaria um registro real no sistema de destino.'
                    : 'Marque "enviar de verdade" para chamar a API.'
            );
        }
    } elseif ($validos !== null) {
        $etapa(
            'Tipo embutido',
            'aviso',
            'Ferramentas embutidas (contato de setor, abrir chamado) leem e escrevem no próprio banco.',
            'Teste-as pelo Playground: abrir chamado de verdade registraria um protocolo e avisaria o responsável.'
        );
    }
}

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Testar ferramenta</h1>
    <p class="page-sub">
        Roda a mesma sequência que o agente enfrenta e mostra <strong>onde parou</strong>: validação dos
        campos, requisição montada, guarda de segurança e resposta da API.
    </p>
</div>

<?php if (!$ferramentas): ?>
    <div class="card"><p class="alerta alerta-erro">Nenhuma ferramenta cadastrada. Crie uma em <a href="ferramentas.php">Ferramentas</a>.</p></div>
<?php else: ?>

<div class="card">
    <form method="get">
        <div class="form-grid">
            <label class="col-2">
                Ferramenta
                <select name="f" onchange="this.form.submit()">
                    <option value="0">— escolha —</option>
                    <?php foreach ($ferramentas as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= $selecionada === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= e($f['nome']) ?> (<?= e((string) $f['tipo']) ?><?= $f['efeito'] === 'escrita' ? ', escrita' : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </form>
</div>

<?php if ($escolhida): ?>
    <div class="card">
        <h2 class="card-title">
            <?= e((string) $escolhida['nome']) ?>
            <span class="tag"><?= e((string) $escolhida['tipo']) ?></span>
            <?php if ($escolhida['efeito'] === 'escrita'): ?><span class="tag tag-erro">escrita</span><?php endif; ?>
        </h2>

        <p class="vazio" style="margin-bottom:1rem"><?= e((string) $escolhida['descricao_llm']) ?></p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="ferramenta_id" value="<?= (int) $escolhida['id'] ?>">

            <?php if ($parametros): ?>
                <div class="form-grid">
                    <?php foreach ($parametros as $p): ?>
                        <?php $opcoes = (new Executor(0))->opcoesDoParametro($p); ?>
                        <label>
                            <?= e((string) $p['nome']) ?>
                            <?php if ($p['obrigatorio']): ?><span class="tag tag-erro">obrigatório</span><?php endif; ?>

                            <?php if ($opcoes !== []): ?>
                                <select name="v_<?= e((string) $p['nome']) ?>">
                                    <option value="">—</option>
                                    <?php foreach ($opcoes as $o): ?>
                                        <option value="<?= e($o) ?>" <?= ($_POST['v_' . $p['nome']] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="v_<?= e((string) $p['nome']) ?>"
                                       value="<?= e((string) ($_POST['v_' . $p['nome']] ?? $p['exemplo'] ?? '')) ?>"
                                       placeholder="<?= e((string) ($p['exemplo'] ?? '')) ?>">
                            <?php endif; ?>

                            <small><?= e((string) $p['descricao_llm']) ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="vazio" style="margin-bottom:1rem">Esta ferramenta não declara campos.</p>
            <?php endif; ?>

            <div class="form-checks">
                <label class="check">
                    <input type="checkbox" name="enviar" <?= $enviado ? 'checked' : '' ?>>
                    Enviar de verdade
                    <?php if ($escolhida['efeito'] === 'escrita'): ?>
                        <strong style="color:var(--danger)">— esta ferramenta escreve no sistema de destino</strong>
                    <?php endif; ?>
                </label>
            </div>

            <div class="form-acoes">
                <button type="submit" class="btn btn-primary">Testar</button>
                <a href="ferramentas.php?editar=<?= (int) $escolhida['id'] ?>" class="btn btn-secondary">Editar ferramenta</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($etapas): ?>
    <div class="card">
        <h2 class="card-title">Diagnóstico</h2>
        <?php foreach ($etapas as $i => $et): ?>
            <div class="resultado">
                <div class="resultado-topo">
                    <span class="resultado-pos"><?= $i + 1 ?></span>
                    <strong><?= e($et['nome']) ?></strong>
                    <span class="tag tag-<?= $et['estado'] === 'ok' ? 'ok' : ($et['estado'] === 'erro' ? 'erro' : 'neutro') ?>">
                        <?= $et['estado'] === 'ok' ? 'passou' : ($et['estado'] === 'erro' ? 'parou aqui' : 'pulado') ?>
                    </span>
                </div>
                <div class="resultado-texto"><?= e($et['detalhe']) ?></div>
                <?php if ($et['dica'] !== ''): ?>
                    <div class="resultado-fonte" style="margin-top:0.5rem">→ <?= e($et['dica']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="vazio" style="margin-top:0.8rem">
            O histórico completo de chamadas reais fica em cada ferramenta, na coluna de execuções.
        </p>
    </div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
