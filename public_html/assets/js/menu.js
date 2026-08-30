/**
 * Recolher o menu lateral no desktop.
 *
 * Nasceu da tela de atendimento: cada pixel devolvido ao conteudo e uma linha
 * a mais de conversa visivel.
 *
 * O estado fica em `localStorage` e e aplicado no <head>, antes de o CSS
 * pintar — ver o script embutido no `partials/head.php`. Aplicar aqui, no fim
 * da pagina, faria a barra larga aparecer por um quadro e encolher na frente
 * de quem esta olhando, em TODA navegacao. O painel e renderizado no servidor,
 * entao isso aconteceria dezenas de vezes por sessao.
 */
(function () {
    'use strict';

    var botao = document.getElementById('btn-recolher');

    if (!botao) { return; }

    var raiz = document.documentElement;

    function pintar() {
        var recolhido = raiz.classList.contains('menu-recolhido');

        // A seta aponta para onde o clique leva, nao para o estado atual.
        botao.innerHTML = recolhido ? '&#10097;' : '&#10096;';
        botao.title = recolhido ? 'Expandir menu' : 'Recolher menu';
        botao.setAttribute('aria-label', botao.title);
    }

    botao.addEventListener('click', function () {
        var recolhido = raiz.classList.toggle('menu-recolhido');

        // `try` porque navegador em modo privado recusa gravar, e um menu que
        // nao lembra a preferencia ainda funciona; um que estoura, nao.
        try {
            localStorage.setItem('sa_menu_recolhido', recolhido ? '1' : '0');
        } catch (e) { /* segue sem lembrar */ }

        pintar();
    });

    pintar();
})();
