<?php

declare(strict_types=1);

// Pagina de teste do widget, atras do login.
//
// Ficou publica por um tempo e isso era um furo pequeno mas real: servida do
// NOSSO dominio, ela passa na regra de mesma origem, entao quem descobrisse um
// token conversaria por aqui sem estar em nenhum site autorizado. O limite por
// IP conteria o estrago, mas nao ha motivo para deixar a porta encostada.

require_once __DIR__ . '/_init.php';

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Página de teste do widget</title>
<!--
  Página falsa de cliente, para testar o widget num contexto parecido com o
  real: CSS próprio, agressivo de propósito, para provar que o shadow DOM
  isola o chat. Se o widget se desconfigurar aqui, vai se desconfigurar no
  site de alguém.

  O token vem na querystring (?t=...) para não precisar editar o arquivo a
  cada canal novo.
-->
<style>
  /* CSS hostil de propósito: é o tipo de regra que um tema de site aplica
     e que quebraria um widget sem isolamento. */
  * { box-sizing: content-box; }
  button { background: #ff0 !important; border: 4px dashed #f0f; font-size: 28px; padding: 30px; }
  textarea, input { border: 5px solid lime; font-size: 26px; }
  div { line-height: 3; }

  body { font: 16px/1.6 Georgia, serif; margin: 0; background: #fafafa; color: #222; }
  .topo { background: #123; color: #fff; padding: 24px; }
  .conteudo { max-width: 680px; margin: 0 auto; padding: 32px 20px 120px; }
  .aviso { background: #fff3cd; border: 1px solid #ffe08a; padding: 14px 16px; border-radius: 8px; }
  code { background: #eee; padding: 2px 5px; border-radius: 4px; font-size: 14px; }
</style>
</head>
<body>

<div class="topo"><strong>Site de exemplo</strong> — página de teste do widget</div>

<div class="conteudo">
  <h1>Teste do widget</h1>

  <p class="aviso" id="aviso">Carregando o widget...</p>

  <p>
    Esta página aplica CSS propositalmente agressivo — botões amarelos com borda
    tracejada, campos com borda verde, <code>box-sizing: content-box</code>. Nada
    disso pode atravessar para dentro do chat, e nada do chat pode vazar para cá.
  </p>

  <p>Botão desta página (deve continuar feio):</p>
  <p><button type="button">Botão do site</button></p>

  <p>Campo desta página (deve continuar com borda verde):</p>
  <p><textarea rows="2">campo do site</textarea></p>

  <p>
    Se a bolha do chat aparecer no canto inferior direito com aparência normal,
    o isolamento está funcionando. Abra, converse e confira as fontes citadas.
  </p>
</div>

<script>
  var token = new URLSearchParams(location.search).get('t');
  var aviso = document.getElementById('aviso');

  if (!token) {
    aviso.textContent = 'Falta o token: abra esta página como demo.html?t=SEU-TOKEN, '
      + 'ou use o botão "Testar numa página" na tela de Canais.';
  } else {
    aviso.textContent = 'Widget do canal "' + token + '" carregado. A bolha fica no canto inferior direito.';

    var s = document.createElement('script');
    s.src = '../embed.js';
    s.setAttribute('data-token', token);
    s.defer = true;
    document.body.appendChild(s);
  }
</script>

</body>
</html>
