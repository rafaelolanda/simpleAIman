<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Llm\ProviderFactory;
use SimpleAIman\Rag\Retriever;
use SimpleAIman\Rag\SqliteVectorStore;

$paginaAtual = 'testar-busca.php';
$tituloPagina = 'Testar busca';

$bases = $pdo->query(
    'SELECT b.id, b.nome, b.dimensoes,
            (SELECT COUNT(*) FROM embeddings e WHERE e.base_id = b.id) AS vetores
     FROM bases b WHERE b.ativo = 1 ORDER BY b.nome'
)->fetchAll();

$pergunta = trim((string) ($_GET['q'] ?? ''));
$basesSelecionadas = array_map('intval', (array) ($_GET['bases'] ?? array_column($bases, 'id')));
$topK = max(1, min(20, (int) ($_GET['k'] ?? 5)));
$limiar = max(0.0, min(1.0, (float) ($_GET['limiar'] ?? 0)));
$usarLexical = !isset($_GET['q']) || isset($_GET['lexical']);
$usarVetorial = !isset($_GET['q']) || isset($_GET['vetorial']);

$resultados = [];
$erro = null;
$tempos = ['embedding' => 0.0, 'vetorial' => 0.0, 'lexical' => 0.0, 'total' => 0.0];

if ($pergunta !== '' && $basesSelecionadas !== []) {
    try {
        // Os tempos vêm do próprio Retriever. Medir aqui exigiria embeddar a
        // pergunta duas vezes — uma para cronometrar e outra dentro da busca
        // —, dobrando a chamada de rede, que é justamente a parte cara.
        $retriever = new Retriever();
        $resultados = $retriever->buscar($pergunta, $basesSelecionadas, $topK, $limiar, $usarLexical, $usarVetorial);
        $tempos = $retriever->tempos;
    } catch (ErroAgente $e) {
        // Aqui é tela de admin: mostra o detalhe técnico e o que fazer.
        $erro = $e->paraLog() . ' — ' . $e->sugestaoAdmin();
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$totalIndexado = (new SqliteVectorStore())->contar($basesSelecionadas);

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Testar busca</h1>
    <p class="page-sub">
        Mostra <strong>quais trechos</strong> o agente encontraria e com que nota, sem chamar a LLM.
        É aqui que se calibra <code>top_k</code> e limiar — cada ajuste custa uma chamada de embedding,
        não um turno de conversa inteiro.
    </p>
</div>

<?php if (!$bases): ?>
    <div class="card"><p class="alerta alerta-erro">Nenhuma base ativa. Crie uma em <a href="bases.php">Bases</a>.</p></div>
<?php else: ?>

<div class="card">
    <form method="get">
        <div class="form-grid">
            <label class="col-2">
                Pergunta
                <input type="text" name="q" value="<?= e($pergunta) ?>" placeholder="ex.: qual o prazo de entrega?" autofocus>
            </label>

            <label>
                Resultados (top_k)
                <input type="number" name="k" value="<?= $topK ?>" min="1" max="20">
            </label>

            <label>
                Limiar de similaridade
                <input type="number" name="limiar" value="<?= e((string) $limiar) ?>" min="0" max="1" step="0.05">
                <small>Corta pela nota do cosseno. A do RRF não serve: ela depende de quantas listas houve.</small>
            </label>
        </div>

        <div class="form-checks">
            <?php foreach ($bases as $b): ?>
                <label class="check">
                    <input type="checkbox" name="bases[]" value="<?= (int) $b['id'] ?>"
                        <?= in_array((int) $b['id'], $basesSelecionadas, true) ? 'checked' : '' ?>>
                    <?= e($b['nome']) ?> <span class="tag tag-neutro"><?= (int) $b['vetores'] ?> vetores</span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="vetorial" <?= $usarVetorial ? 'checked' : '' ?>> Busca semântica (cosseno)</label>
            <label class="check"><input type="checkbox" name="lexical" <?= $usarLexical ? 'checked' : '' ?>> Busca lexical (FTS5/BM25)</label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </div>
    </form>
</div>

<?php if ($erro !== null): ?>
    <div class="card"><p class="alerta alerta-erro"><?= e($erro) ?></p></div>
<?php endif; ?>

<?php if ($pergunta !== '' && $erro === null): ?>
    <div class="card">
        <div class="chat-topo">
            <h2 class="card-title" style="margin:0"><?= count($resultados) ?> trecho(s) para “<?= e($pergunta) ?>”</h2>
            <small style="opacity:.75">
                embedding <strong><?= number_format($tempos['embedding'], 0, ',', '.') ?> ms</strong>
                · cosseno <strong><?= number_format(max(0, $tempos['vetorial']), 2, ',', '.') ?> ms</strong>
                · lexical <strong><?= number_format(max(0, $tempos['lexical']), 2, ',', '.') ?> ms</strong>
                · <?= number_format($totalIndexado, 0, ',', '.') ?> vetores
            </small>
        </div>

        <?php if ($tempos['embedding'] > 0): ?>
            <?php $pesoRede = $tempos['embedding'] / max(0.01, $tempos['total']) * 100; ?>
            <p class="alerta alerta-ok" style="margin-bottom:1rem;">
                <strong><?= number_format($pesoRede, 1, ',', '.') ?>%</strong> do tempo é a chamada de rede para
                embeddar a pergunta — não o cálculo de similaridade. Reduzir dimensões ou trocar o algoritmo
                de busca só passa a valer a pena quando “cosseno” se aproximar de “embedding”.
            </p>
        <?php endif; ?>

        <?php if (!$resultados): ?>
            <p class="vazio">
                Nada encontrado.
                <?= $limiar > 0 ? 'O limiar de ' . e((string) $limiar) . ' pode estar alto demais — tente baixar.' : '' ?>
            </p>
        <?php else: ?>
            <?php foreach ($resultados as $i => $r): ?>
                <div class="resultado">
                    <div class="resultado-topo">
                        <span class="resultado-pos">#<?= $i + 1 ?></span>
                        <span class="tag">RRF <?= number_format($r['score'], 4, ',', '.') ?></span>

                        <?php if ($r['pos_vetorial'] !== null): ?>
                            <span class="tag tag-ok">
                                semântica <?= $r['pos_vetorial'] ?>º · <?= number_format((float) $r['score_vetorial'], 3, ',', '.') ?>
                            </span>
                        <?php else: ?>
                            <span class="tag tag-neutro">fora da semântica</span>
                        <?php endif; ?>

                        <?php if ($r['pos_lexical'] !== null): ?>
                            <span class="tag tag-ok">lexical <?= $r['pos_lexical'] ?>º</span>
                        <?php else: ?>
                            <span class="tag tag-neutro">fora da lexical</span>
                        <?php endif; ?>
                    </div>

                    <div class="resultado-fonte">
                        <?= e($r['artefato']) ?>
                        <?php if (!empty($r['metadados']['secao'])): ?> · <?= e((string) $r['metadados']['secao']) ?><?php endif; ?>
                        <?php if (!empty($r['metadados']['pagina'])): ?> · página <?= (int) $r['metadados']['pagina'] ?><?php endif; ?>
                        · <span style="opacity:.6"><?= e($r['base']) ?></span>
                    </div>

                    <div class="resultado-texto"><?= e($r['conteudo']) ?></div>
                </div>
            <?php endforeach; ?>

            <p class="vazio" style="margin-top:1rem;">
                Um trecho que aparece em <strong>só uma</strong> das listas mostra o que cada método enxerga
                sozinho: a lexical acerta termo exato (número de artigo, sigla) e a semântica acerta a
                intenção (“trancar” encontrando “trancamento”).
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
