/**
 * Abas de formulário — usado por Agentes e Ferramentas.
 *
 * Arquivo próprio, e não uma cópia em cada tela: duas cópias divergem na
 * primeira correção, e a que ficaria para trás é justamente a guarda de
 * validação abaixo, que é o motivo de este código existir.
 *
 * Carregado em todas as páginas do painel e inerte onde não há `.form-abas`.
 */
(function () {
    'use strict';

        var nav = document.querySelector('.form-abas');

        if (!nav) { return; }

        var form = nav.closest('form');
        var botoes = nav.querySelectorAll('.form-aba-btn');
        var paineis = form.querySelectorAll('.form-aba');

        function mostrar(alvo) {
            botoes.forEach(function (b) { b.classList.toggle('ativa', b.dataset.alvo === alvo); });
            paineis.forEach(function (p) { p.hidden = p.dataset.aba !== alvo; });
        }

        botoes.forEach(function (b) {
            b.addEventListener('click', function () {
                b.classList.remove('com-erro');
                mostrar(b.dataset.alvo);
            });
        });

        // Campo obrigatorio vazio numa aba fechada: o navegador tenta focar algo
        // invisivel, desiste, nao mostra mensagem — e o Salvar parece nao
        // funcionar. Abrir a aba do primeiro campo invalido resolve, e marcar as
        // demais evita a cacada quando ha erro em mais de uma.
        //
        // Captura (`true`) porque `invalid` nao borbulha, e o handler precisa
        // rodar ANTES de o navegador tentar exibir a mensagem.
        var jaAbriu = false;

        form.addEventListener('invalid', function (ev) {
            var painel = ev.target.closest('.form-aba');

            if (!painel) { return; }

            var botao = nav.querySelector('[data-alvo="' + painel.dataset.aba + '"]');

            if (botao) { botao.classList.add('com-erro'); }

            if (!jaAbriu) {
                jaAbriu = true;
                mostrar(painel.dataset.aba);
            }
        }, true);

        form.addEventListener('submit', function () { jaAbriu = false; });
})();
