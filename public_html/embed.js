/**
 * Widget de chat do simpleAIman.
 *
 * Uso, no site de quem consome:
 *
 *   <script src="https://SEU-HOST/embed.js" data-token="SEU-TOKEN" defer></script>
 *
 * Regras que moldaram este arquivo:
 *
 * - **Shadow DOM.** O widget entra numa página de terceiro cujo CSS não
 *   controlamos. Sem isolamento, um `button { }` do tema do cliente
 *   desconfigura o chat, e um `* { box-sizing }` nosso quebraria o site dele.
 *   O shadow root corta os dois sentidos.
 *
 * - **Zero dependência.** Nada de framework: o peso do widget é cobrado do
 *   tempo de carregamento do site do cliente, não do nosso.
 *
 * - **Nenhuma mensagem técnica na tela.** O que o visitante lê vem do servidor
 *   já tratado, ou é um texto genérico daqui. Nome de provedor, status HTTP e
 *   stack trace não aparecem em hipótese alguma.
 */
(function () {
  'use strict';

  var script = document.currentScript;

  // Com `defer`, currentScript é null no momento em que o callback roda em
  // alguns navegadores. O fallback pega a própria tag pelo atributo.
  if (!script) {
    script = document.querySelector('script[data-token]');
  }

  if (!script) {
    return;
  }

  var token = script.getAttribute('data-token') || '';
  var base = script.src.replace(/\/embed\.js.*$/, '');

  if (!token) {
    return;
  }

  // ------------------------------------------------------------------
  // Identidade da sessão
  //
  // É o que amarra as mensagens numa conversa só. Fica no localStorage para
  // sobreviver a um F5 — perder o histórico ao recarregar a página seria a
  // primeira coisa que o visitante notaria.
  // ------------------------------------------------------------------
  var CHAVE = 'simpleaiman:sessao:' + token;
  var sessao;

  try {
    sessao = localStorage.getItem(CHAVE);

    if (!sessao) {
      sessao = 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
      localStorage.setItem(CHAVE, sessao);
    }
  } catch (e) {
    // Navegação privada ou cookies bloqueados: a conversa vale só para esta
    // aba, o que é melhor que não funcionar.
    sessao = 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  var host = document.createElement('div');
  host.setAttribute('data-simpleaiman', '');
  var raiz = host.attachShadow({ mode: 'open' });

  var CSS = [
    ':host{all:initial}',
    '*,*::before,*::after{box-sizing:border-box}',
    '.wrap{position:fixed;right:20px;bottom:20px;z-index:2147483000;',
    'font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',

    '.bolha{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;',
    'background:var(--cor);color:#fff;box-shadow:0 6px 20px rgba(0,0,0,.25);',
    'display:flex;align-items:center;justify-content:center;transition:transform .15s}',
    '.bolha:hover{transform:scale(1.06)}',
    '.bolha svg{width:26px;height:26px}',

    '.painel{display:none;flex-direction:column;width:min(380px,calc(100vw - 40px));',
    'height:min(560px,calc(100vh - 120px));background:#fff;color:#111827;border-radius:14px;',
    'box-shadow:0 12px 40px rgba(0,0,0,.28);overflow:hidden;margin-bottom:12px}',
    '.aberto .painel{display:flex}',
    '.aberto .bolha{display:none}',

    '.topo{background:var(--cor);color:#fff;padding:14px 16px;display:flex;',
    'align-items:center;justify-content:space-between;flex:0 0 auto}',
    '.topo strong{font-size:15px;font-weight:600}',
    '.fechar{background:none;border:0;color:#fff;font-size:22px;cursor:pointer;',
    'line-height:1;padding:0 4px;opacity:.85}',
    '.fechar:hover{opacity:1}',

    '.corpo{flex:1 1 auto;overflow-y:auto;padding:16px;background:#f8fafc}',
    '.msg{margin-bottom:12px;display:flex}',
    '.msg .balao{max-width:82%;padding:9px 13px;border-radius:14px;white-space:pre-wrap;',
    'word-wrap:break-word;overflow-wrap:anywhere}',
    '.de-bot .balao{background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:4px}',
    '.de-usuario{justify-content:flex-end}',
    '.de-usuario .balao{background:var(--cor);color:#fff;border-bottom-right-radius:4px}',
    '.fontes{margin-top:6px;font-size:12px;color:#6b7280}',

    '.pensando .balao{color:#6b7280;font-style:italic}',

    '.rodape{flex:0 0 auto;display:flex;gap:8px;padding:12px;background:#fff;',
    'border-top:1px solid #e5e7eb}',
    '.rodape textarea{flex:1;resize:none;border:1px solid #d1d5db;border-radius:9px;',
    'padding:9px 11px;font:inherit;color:inherit;background:#fff;max-height:96px;',
    'min-height:40px;outline:none}',
    '.rodape textarea:focus{border-color:var(--cor)}',
    '.enviar{border:0;background:var(--cor);color:#fff;border-radius:9px;width:42px;',
    'cursor:pointer;display:flex;align-items:center;justify-content:center}',
    '.enviar:disabled{opacity:.5;cursor:default}',
    '.enviar svg{width:18px;height:18px}',
    '.marca{text-align:center;font-size:11px;color:#9ca3af;padding:0 0 8px}',

    '@media (max-width:480px){',
    '.wrap{right:12px;bottom:12px;left:12px}',
    '.painel{width:100%;height:min(70vh,540px)}}'
  ].join('');

  var ICONE_CHAT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 ' +
    '8.5 8.5 0 0 1-3.9-.9L3 21l1.9-5.1A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg>';

  var ICONE_ENVIAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/>' +
    '<path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>';

  raiz.innerHTML =
    '<style>' + CSS + '</style>' +
    '<div class="wrap" style="--cor:#2563eb">' +
      '<div class="painel" role="dialog" aria-label="Chat de atendimento">' +
        '<div class="topo"><strong class="titulo">Atendimento</strong>' +
          '<button class="fechar" aria-label="Fechar">&times;</button></div>' +
        '<div class="corpo" aria-live="polite"></div>' +
        '<div class="rodape">' +
          '<textarea rows="1" placeholder="Escreva sua mensagem..." aria-label="Mensagem"></textarea>' +
          '<button class="enviar" aria-label="Enviar">' + ICONE_ENVIAR + '</button>' +
        '</div>' +
        '<div class="marca"></div>' +
      '</div>' +
      '<button class="bolha" aria-label="Abrir atendimento">' + ICONE_CHAT + '</button>' +
    '</div>';

  document.body.appendChild(host);

  var wrap = raiz.querySelector('.wrap');
  var corpo = raiz.querySelector('.corpo');
  var campo = raiz.querySelector('textarea');
  var btnEnviar = raiz.querySelector('.enviar');
  var ocupado = false;
  var abriuAlgumaVez = false;

  function balao(quem, texto) {
    var linha = document.createElement('div');
    linha.className = 'msg de-' + quem;

    var b = document.createElement('div');
    b.className = 'balao';
    // textContent, nunca innerHTML: o texto vem da LLM, que por sua vez leu
    // documentos que alguém subiu. Interpretar isso como HTML seria abrir XSS
    // no site do cliente através do nosso widget.
    b.textContent = texto;

    linha.appendChild(b);
    corpo.appendChild(linha);
    corpo.scrollTop = corpo.scrollHeight;

    return b;
  }

  function fim() {
    ocupado = false;
    btnEnviar.disabled = false;
    campo.focus();
  }

  function enviar() {
    var texto = campo.value.trim();

    if (!texto || ocupado) {
      return;
    }

    ocupado = true;
    btnEnviar.disabled = true;
    campo.value = '';
    campo.style.height = 'auto';

    balao('usuario', texto);

    var linha = document.createElement('div');
    linha.className = 'msg de-bot pensando';
    linha.innerHTML = '<div class="balao">digitando...</div>';
    corpo.appendChild(linha);
    corpo.scrollTop = corpo.scrollHeight;

    var url = base + '/api/publico.php?t=' + encodeURIComponent(token) +
      '&sessao=' + encodeURIComponent(sessao) + '&q=' + encodeURIComponent(texto);

    var es = new EventSource(url);
    var alvo = null;
    var acumulado = '';
    var encerrado = false;

    function encerrar() {
      if (encerrado) {
        return;
      }

      encerrado = true;
      es.close();
      fim();
    }

    function garantirBalao() {
      if (!alvo) {
        linha.remove();
        alvo = balao('bot', '');
      }

      return alvo;
    }

    es.addEventListener('pedaco', function (ev) {
      acumulado += JSON.parse(ev.data).texto;
      garantirBalao().textContent = acumulado;
      corpo.scrollTop = corpo.scrollHeight;
    });

    es.addEventListener('fontes', function (ev) {
      var fontes = JSON.parse(ev.data);

      if (!fontes.length || !alvo) {
        return;
      }

      var p = document.createElement('div');
      p.className = 'fontes';
      p.textContent = 'Fontes: ' + fontes.map(function (f) { return f.rotulo; }).join(' · ');
      alvo.parentNode.appendChild(p);
    });

    es.addEventListener('erro', function (ev) {
      garantirBalao().textContent = JSON.parse(ev.data).mensagem;
      encerrar();
    });

    es.addEventListener('fim', function () {
      encerrar();
    });

    // Queda de rede, aba suspensa, servidor reiniciado. O EventSource tentaria
    // reconectar sozinho e REFAZER a pergunta -- cobrando outra chamada à LLM
    // e duplicando a resposta na tela. Fechar na mão é obrigatório.
    es.onerror = function () {
      if (encerrado) {
        return;
      }

      if (acumulado) {
        // Já havia resposta na tela: manter o que chegou é melhor que trocar
        // por uma mensagem de falha.
        encerrar();
        return;
      }

      garantirBalao().textContent =
        'Não consegui responder agora. Pode tentar de novo em instantes?';
      encerrar();
    };
  }

  raiz.querySelector('.bolha').addEventListener('click', function () {
    wrap.classList.add('aberto');
    campo.focus();

    if (!abriuAlgumaVez) {
      abriuAlgumaVez = true;
      carregarConfig();
    }
  });

  raiz.querySelector('.fechar').addEventListener('click', function () {
    wrap.classList.remove('aberto');
  });

  btnEnviar.addEventListener('click', enviar);

  campo.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      enviar();
    }
  });

  campo.addEventListener('input', function () {
    campo.style.height = 'auto';
    campo.style.height = Math.min(campo.scrollHeight, 96) + 'px';
  });

  // A configuração só é buscada quando alguém abre o chat. A maioria dos
  // visitantes nunca abre, e uma requisição por pageview seria custo de banda
  // do cliente para nada.
  function carregarConfig() {
    fetch(base + '/api/publico.php?acao=config&t=' + encodeURIComponent(token))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (cfg) {
        if (!cfg) {
          return;
        }

        wrap.style.setProperty('--cor', cfg.cor);
        raiz.querySelector('.titulo').textContent = cfg.titulo;

        if (!corpo.children.length) {
          balao('bot', cfg.saudacao);
        }
      })
      .catch(function () {
        // Token errado ou domínio não liberado. O widget fica com a aparência
        // padrão e a falha aparece ao enviar -- avisar aqui só assustaria o
        // visitante por um problema que é de configuração do site.
      });
  }
})();
