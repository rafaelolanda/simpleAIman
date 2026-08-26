<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'playground.php';
$tituloPagina = 'Playground';

$agentes = $pdo->query(
    'SELECT a.id, a.nome, a.modelo, a.reasoning_effort, a.max_tokens, p.nome AS provedor, p.ativo AS provedor_ativo
     FROM agentes a LEFT JOIN provedores p ON p.id = a.provedor_id
     WHERE a.ativo = 1 ORDER BY a.nome'
)->fetchAll();

$agenteSelecionado = (int) ($_GET['agente'] ?? $config['agente_padrao_id'] ?? 0);

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Playground</h1>
    <p class="page-sub">
        Conversa direta com o agente, já com RAG. As fontes aparecem sob a resposta —
        se um trecho citado estiver errado, o ajuste é em <a href="testar-busca.php">Testar busca</a>,
        não no prompt.
    </p>
</div>

<?php if (!$agentes): ?>
    <div class="card"><p class="alerta alerta-erro">Nenhum agente ativo. Cadastre um em Agentes.</p></div>
<?php else: ?>

<div class="card">
    <div class="chat-topo">
        <label>
            Agente
            <select id="agente">
                <?php foreach ($agentes as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= $agenteSelecionado === (int) $a['id'] ? 'selected' : '' ?>>
                        <?= e($a['nome']) ?> · <?= e((string) $a['modelo']) ?><?= $a['provedor_ativo'] ? '' : ' (provedor inativo)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="button" class="btn btn-secondary btn-sm" id="limpar">Nova conversa</button>
    </div>

    <div id="chat" class="chat" aria-live="polite"></div>

    <form id="form" class="chat-form" autocomplete="off">
        <input type="text" id="pergunta" placeholder="Escreva sua pergunta…" required>
        <button type="submit" class="btn btn-primary" id="enviar">Enviar</button>
    </form>
</div>

<script>
(function () {
    const chat = document.getElementById('chat');
    const form = document.getElementById('form');
    const campo = document.getElementById('pergunta');
    const botao = document.getElementById('enviar');
    const seletor = document.getElementById('agente');

    // Sessão nova a cada aba: o histórico do playground não deve se misturar
    // com outra janela aberta ao lado testando outro prompt.
    let sessao = 'pg-' + Math.random().toString(36).slice(2, 10);

    document.getElementById('limpar').addEventListener('click', () => {
        sessao = 'pg-' + Math.random().toString(36).slice(2, 10);
        chat.innerHTML = '';
        campo.focus();
    });

    function bolha(classe, texto) {
        const div = document.createElement('div');
        div.className = 'bolha ' + classe;
        div.textContent = texto;
        chat.appendChild(div);
        chat.scrollTop = chat.scrollHeight;
        return div;
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const pergunta = campo.value.trim();
        if (!pergunta) return;

        bolha('user', pergunta);
        campo.value = '';
        campo.disabled = botao.disabled = true;

        const resposta = bolha('bot pensando', '…');
        let texto = '';
        const inicio = performance.now();

        const url = '../api/chat.php?q=' + encodeURIComponent(pergunta)
            + '&agente=' + encodeURIComponent(seletor.value)
            + '&sessao=' + encodeURIComponent(sessao);

        const es = new EventSource(url);

        es.addEventListener('pedaco', (e) => {
            resposta.classList.remove('pensando');
            texto += JSON.parse(e.data).texto;
            resposta.textContent = texto;
            chat.scrollTop = chat.scrollHeight;
        });

        es.addEventListener('fontes', (e) => {
            const fontes = JSON.parse(e.data);
            if (!fontes.length) return;
            const bloco = document.createElement('div');
            bloco.className = 'bolha-fontes';
            bloco.textContent = 'Fontes: ' + fontes.map(f => '[' + f.numero + '] ' + f.rotulo).join(' · ');
            resposta.appendChild(bloco);
            chat.scrollTop = chat.scrollHeight;
        });

        es.addEventListener('fim', (e) => {
            const dados = JSON.parse(e.data);
            resposta.classList.remove('pensando');
            if (dados.modo === 'humano') {
                resposta.textContent = 'Conversa em atendimento humano — o bot não responde.';
                resposta.className = 'bolha sistema';
            } else {
                // Troca o texto cru pela versao formatada, que so chega agora:
                // durante o streaming o marcador de abertura vem num pedaco e
                // o de fechamento em outro. O HTML vem de formatar_whatsapp(),
                // que escapa antes de formatar.
                //
                // Os filhos ja anexados (o bloco de Fontes) sao preservados:
                // innerHTML os apagaria, e 'fontes' chega ANTES de 'fim'.
                if (dados.html) {
                    const anexos = [...resposta.children];
                    resposta.innerHTML = dados.html;
                    anexos.forEach(el => resposta.appendChild(el));
                }

                const ms = Math.round(performance.now() - inicio);
                const marca = document.createElement('span');
                marca.className = 'bolha-meta';
                marca.textContent = ms + ' ms';
                resposta.appendChild(marca);
            }
            es.close();
            campo.disabled = botao.disabled = false;
            campo.focus();
        });

        // O servidor só manda a mensagem PÚBLICA. Nada de status HTTP, nome de
        // modelo ou texto de cota chega até aqui — o detalhe fica no log.
        es.addEventListener('erro', (e) => {
            resposta.className = 'bolha sistema';
            resposta.textContent = JSON.parse(e.data).mensagem;
            es.close();
            campo.disabled = botao.disabled = false;
            campo.focus();
        });

        es.onerror = () => {
            if (texto === '') {
                resposta.className = 'bolha sistema';
                resposta.textContent = 'A conexão caiu. Tente novamente.';
            }
            es.close();
            campo.disabled = botao.disabled = false;
        };
    });

    campo.focus();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
