<?php
/**
 * A lista de conversas da tela de atendimento.
 *
 * Arquivo proprio porque e renderizada em DOIS momentos: ao abrir a pagina e a
 * cada consulta do polling, que devolve este mesmo HTML pronto. Montar a linha
 * de novo em JavaScript seria uma segunda implementacao da mesma coisa, e a que
 * ficasse para tras apareceria como conversa com aparencia diferente das
 * outras.
 *
 * Espera: $fila, $minhas, $outras, $plantao, $abrindo, $vejoTudo.
 */
?>
    <section class="card lista-conversas" id="lista-conversas">
        <?php
        // Uma linha de conversa, do mesmo jeito nas tres listas.
        //
        // Fechada num closure e nao repetida tres vezes: a diferenca entre elas
        // e o que se faz ao clicar, nao o que se mostra. Tres copias divergiriam
        // na primeira correcao de layout.
        $linha = static function (array $c, string $estado) use ($abrindo): void {
            $nome = nome_do_contato($c);
            $av = avatar_do_contato($nome);
            $zap = ($c['canal_tipo'] ?? '') === 'whatsapp';
            $previa = trim((string) ($c['ultima'] ?? ''));

            // Prefixo de quem falou por ultimo: sem ele, a previa da resposta do
            // proprio atendente parece fala do visitante.
            if ($previa !== '' && ($c['ultima_de'] ?? '') !== 'usuario') {
                $previa = 'Você: ' . $previa;
            }

            $quando = $estado === 'fila'
                ? tempo_relativo((string) ($c['aguardando_desde'] ?? ''))
                : tempo_relativo((string) ($c['ultima_em'] ?? $c['editado_em'] ?? ''));
            ?>
            <div class="conversa-linha <?= $abrindo === (int) $c['id'] ? 'ativa' : '' ?>">
                <span class="conversa-avatar" style="background: <?= e($av['cor']) ?>"><?= e($av['iniciais']) ?></span>
                <span class="conversa-corpo">
                    <span class="conversa-topo">
                        <strong class="conversa-nome"><?= e($nome) ?></strong>
                        <span class="conversa-quando"><?= e($quando) ?></span>
                    </span>
                    <span class="conversa-previa"><?= e(mb_substr($previa, 0, 90)) ?></span>
                    <span class="conversa-marcas">
                        <span class="canal-tag <?= $zap ? 'canal-zap' : 'canal-web' ?>"><?= $zap ? 'WhatsApp' : 'Site' ?></span>
                        <?php if ($estado === 'outras' && !empty($c['atendente'])): ?>
                            <span class="tag tag-neutro">com <?= e((string) $c['atendente']) ?></span>
                        <?php endif; ?>
                    </span>
                </span>
            </div>
            <?php
        };
        ?>

        <h2 class="card-title">
            Esperando
            <?php if ($fila !== []): ?><span class="nav-badge" id="badge-fila"><?= count($fila) ?></span><?php endif; ?>
        </h2>

        <?php if ($fila === []): ?>
            <p class="vazio">Ninguém esperando.</p>
        <?php else: ?>
            <ul class="conversa-lista">
                <?php foreach ($fila as $c): ?>
                    <li>
                        <?php // A fila e a UNICA lista com botao: aqui o clique
                              // assume a conversa, e assumir e uma acao, nao
                              // navegacao. Um link abriria conversa de outro. ?>
                        <form method="post" class="conversa-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="assumir">
                            <input type="hidden" name="conversa" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="conversa-botao" title="Assumir esta conversa">
                                <?php $linha($c, 'fila'); ?>
                                <span class="conversa-acao">Assumir</span>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 class="secao-form">Meus atendimentos<?= $minhas !== [] ? ' (' . count($minhas) . ')' : '' ?></h3>

        <?php if ($minhas === []): ?>
            <p class="vazio">Nenhuma conversa sua no momento.</p>
        <?php else: ?>
            <ul class="conversa-lista">
                <?php foreach ($minhas as $c): ?>
                    <li><a href="?c=<?= (int) $c['id'] ?>"><?php $linha($c, 'minhas'); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($vejoTudo && $outras !== []): ?>
            <h3 class="secao-form">Conversas de outros (<?= count($outras) ?>)</h3>
            <ul class="conversa-lista">
                <?php foreach ($outras as $c): ?>
                    <li><a href="?c=<?= (int) $c['id'] ?>"><?php $linha($c, 'outras'); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php
        // Voce sai da lista: o titulo diz "outros", e a sua propria contagem ja
        // esta no titulo de "Meus atendimentos" logo acima.
        $outrosAtendentes = array_values(array_filter($plantao, static fn (array $u): bool => !$u['eu']));
        ?>
        <?php if ($outrosAtendentes !== []): ?>
            <h3 class="secao-form">Outros atendentes</h3>
            <?php // So o numero de conversas de cada um. Basta para a equipe se
                  // distribuir, e nao expoe conversa alheia a quem nao administra. ?>
            <ul class="plantao-lista">
                <?php foreach ($outrosAtendentes as $u): ?>
                    <li>
                        <span><?= e($u['nome']) ?></span>
                        <span class="tag tag-neutro" title="conversas em atendimento"><?= $u['conversas'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
