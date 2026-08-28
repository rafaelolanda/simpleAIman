<?php

declare(strict_types=1);

/**
 * Guia de uso, para quem administra o conteúdo do assistente.
 *
 * Vive no painel e não num arquivo do repositório porque o público é o CLIENTE,
 * e cliente não abre GitHub. Documentação que a pessoa não encontra é igual a
 * documentação que não existe.
 *
 * Escrito em PHP direto em vez de Markdown renderizado: pouparia uma
 * dependência nova para um texto que muda pouco, e o painel já tem o estilo.
 *
 * A regra que sustenta o conteúdo: **não repetir o que já está na tela.** Cada
 * campo tem a própria explicação no ponto onde é preenchido, e duplicar aqui
 * criaria duas fontes, das quais esta seria a primeira a envelhecer. O que
 * entra aqui é o que a interface não consegue dizer: a ORDEM das coisas, o que
 * depende de quê, e o que fazer quando a resposta sai errada.
 */

require_once __DIR__ . '/_init.php';

$paginaAtual = 'ajuda.php';
$tituloPagina = 'Ajuda';

$nome = trim((string) ($config['nome_instancia'] ?? '')) ?: 'o assistente';

// O atendente nao administra conteudo, e as secoes de conteudo linkam para
// telas que ele nao pode abrir. Mostra-las faria a ajuda convidar a um clique
// que termina em "voce nao tem acesso" — o pior lugar possivel para isso
// acontecer e justamente a pagina que deveria ensinar.
$administra = in_array($meuPapel ?? 'atendente', ['admin', 'editor'], true);

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Como usar</h1>
    <p class="page-sub">
        Guia curto do que fazer no dia a dia. Cada campo tem a própria explicação na tela onde é
        preenchido; aqui está o que não cabe num campo: a ordem das coisas e o que fazer quando a
        resposta sai errada.
    </p>
</div>

<div class="card">
    <h2 class="card-title">Nesta página</h2>
    <ul class="lista-alertas" style="gap:.25rem">
        <li><a href="#palavras">As palavras que aparecem no painel</a></li>
        <li><a href="#responde">Como <?= e($nome) ?> monta uma resposta</a></li>
        <?php if ($administra): ?>
            <li><a href="#melhorar">Como fazer ele responder melhor</a></li>
            <li><a href="#errado">A resposta saiu errada. E agora?</a></li>
        <?php endif; ?>
        <li><a href="#nao-sabe">O que acontece quando ele não sabe</a></li>
        <?php if ($administra): ?>
            <li><a href="#agentes">Quando criar um assistente novo</a></li>
            <li><a href="#custo">Quanto custa e como não estourar</a></li>
        <?php endif; ?>
        <li><a href="#atender">Para quem atende</a></li>
    </ul>
</div>

<div class="card" id="palavras">
    <h2 class="card-title">As palavras que aparecem no painel</h2>

    <p class="page-sub">
        Cinco palavras explicam quase tudo. Elas se encaixam em ordem: um <strong>agente</strong>
        consulta <strong>bases</strong>, que contêm <strong>documentos</strong>, e atende por um
        <strong>canal</strong>. As <strong>ferramentas</strong> são o que ele consegue fazer além
        de responder.
    </p>

    <table class="tabela">
        <tbody>
        <tr>
            <td style="white-space:nowrap"><strong>Agente</strong></td>
            <td>O assistente em si: como ele se apresenta, como fala e o que sabe consultar.
                Você pode ter mais de um.</td>
        </tr>
        <tr>
            <td><strong>Base</strong></td>
            <td>Uma pasta de conhecimento. Serve para separar assuntos que não se misturam,
                por exemplo regulamentos de um lado e tabela de preços do outro.</td>
        </tr>
        <tr>
            <td><strong>Documento</strong></td>
            <td>O arquivo que você sobe para dentro de uma base. PDF, Word, página de site ou
                texto. Aparece no painel como <em>artefato</em>.</td>
        </tr>
        <tr>
            <td><strong>Canal</strong></td>
            <td>Por onde a pessoa fala com o assistente: o chat no seu site ou o WhatsApp. Cada
                canal aponta para um agente.</td>
        </tr>
        <tr>
            <td><strong>Ferramenta</strong></td>
            <td>Uma ação que ele consegue executar, como abrir um chamado, passar o contato de um
                setor ou consultar um sistema seu.</td>
        </tr>
        <tr>
            <td><strong>Setor</strong></td>
            <td>Para onde as coisas são encaminhadas, com telefone, e-mail e horário. É de onde
                sai todo contato que o assistente informa.</td>
        </tr>
        <tr>
            <td><strong>FAQ</strong></td>
            <td>Perguntas e respostas escritas por você. Quando a pergunta casa com uma delas, a
                sua resposta sai inteira, sem o assistente reescrever.</td>
        </tr>
        </tbody>
    </table>
</div>

<div class="card" id="responde">
    <h2 class="card-title">Como <?= e($nome) ?> monta uma resposta</h2>

    <p class="page-sub">
        Ele tenta três coisas, sempre nesta ordem, e para na primeira que resolve.
    </p>

    <p class="page-sub">
        Primeiro procura na <strong>FAQ</strong>. Se a pergunta casa com uma que você escreveu, a
        sua resposta sai palavra por palavra. É a mais barata e a mais previsível, e é por isso que
        vale escrever FAQ para o que perguntam toda semana.
    </p>

    <p class="page-sub">
        Não casando, ele procura nos <strong>documentos</strong> das bases ligadas àquele agente.
        Os trechos mais parecidos com a pergunta são lidos e viram resposta, com a fonte citada.
        Se nenhum trecho for bom o bastante, ele diz que não encontrou em vez de improvisar.
    </p>

    <p class="page-sub">
        Se a pergunta pedir uma ação, ele usa uma <strong>ferramenta</strong>: abrir chamado,
        informar contato do setor, chamar um sistema seu. Ele nunca inventa o endereço nem os dados
        da chamada; só preenche os campos que você declarou.
    </p>
</div>

<?php if ($administra): ?>
<div class="card" id="melhorar">
    <h2 class="card-title">Como fazer ele responder melhor</h2>

    <p class="page-sub">
        Na prática, quase todo ganho vem de três hábitos, e nenhum deles é mexer no prompt.
    </p>

    <p class="page-sub">
        <strong>Leia o que perguntaram.</strong> Em <a href="conversas.php">Conversas</a> está o que
        as pessoas realmente escreveram, com as palavras delas. É a melhor fonte para decidir o que
        escrever na FAQ, e costuma render mais que qualquer ajuste técnico.
    </p>

    <p class="page-sub">
        <strong>Escreva FAQ para o que se repete.</strong> Toda pergunta que aparece muitas vezes
        merece uma resposta curada. Sai idêntica sempre, custa quase nada e você controla a palavra
        final.
    </p>

    <p class="page-sub">
        <strong>Suba documento limpo.</strong> Um PDF que é foto de página escaneada não tem texto
        para ler, e o assistente não vai achar nada nele. Documento com texto de verdade, mesmo
        feio, funciona bem.
    </p>

    <p class="page-sub">
        Depois de subir um documento, vale testar em
        <a href="testar-busca.php">Testar busca</a> com uma pergunta real. A tela mostra quais
        trechos foram encontrados e com que nota, sem gastar uma conversa.
    </p>
</div>

<div class="card" id="errado">
    <h2 class="card-title">A resposta saiu errada. E agora?</h2>

    <p class="page-sub">
        Vá pelo sintoma. Quase sempre a causa é uma das quatro abaixo, e nenhuma exige mexer em
        código.
    </p>

    <h3 class="secao-form">Ele disse que não encontrou, mas a informação está no documento</h3>
    <p class="page-sub">
        Abra <a href="testar-busca.php">Testar busca</a> e faça a mesma pergunta. Se o trecho certo
        aparece na lista, o problema é o limiar do agente estar alto demais: ele achou e descartou.
        Se o trecho não aparece, o documento provavelmente está numa base que aquele agente não
        consulta, ou o arquivo não tem texto legível.
    </p>

    <h3 class="secao-form">Ele respondeu com informação desatualizada</h3>
    <p class="page-sub">
        O documento antigo continua indexado. Suba a versão nova e remova a antiga em
        <a href="artefatos.php">Artefatos</a>. Enquanto os dois existirem, os dois são consultados,
        e o assistente não tem como saber qual está valendo.
    </p>

    <h3 class="secao-form">Ele inventou um telefone ou um e-mail</h3>
    <p class="page-sub">
        Isso acontece quando não há setor cadastrado com contato válido. Confira
        <a href="setores.php">Setores</a> e garanta que existe um marcado como padrão. Sem destino
        válido, preencher a lacuna é a saída fácil, e o número inventado parece verdadeiro.
    </p>

    <h3 class="secao-form">Ele respondeu certo, mas do jeito errado</h3>
    <p class="page-sub">
        Tom de voz, tamanho da resposta e o que ele prioriza vêm do texto de instruções do agente.
        Ajuste em <a href="agentes.php">Agentes</a> e teste em
        <a href="playground.php">Playground</a>, que conversa sem consumir o canal do site.
    </p>
</div>

<?php endif; ?>

<div class="card" id="nao-sabe">
    <h2 class="card-title">O que acontece quando ele não sabe</h2>

    <p class="page-sub">
        Ele não inventa. Existem três saídas, e qual delas aparece depende do que você configurou.
    </p>

    <p class="page-sub">
        Se houver alguém marcado como atendente e com o painel aberto naquele momento, ele oferece
        transferir. Só oferece se houver de fato alguém online: prometer atendimento para uma sala
        vazia é pior que não oferecer.
    </p>

    <p class="page-sub">
        Não havendo ninguém, ele oferece registrar a dúvida. Isso vira um chamado com número de
        protocolo em <a href="chamados.php">Chamados</a>, e o responsável pelo setor recebe um
        e-mail. Funciona de madrugada e no fim de semana.
    </p>

    <p class="page-sub">
        E ele sempre pode entregar o contato do setor, que sai do seu cadastro em
        <a href="setores.php">Setores</a>. Por isso vale manter esses contatos revisados: o painel
        marca os que estão há muito tempo sem revisão.
    </p>
</div>

<?php if ($administra): ?>
<div class="card" id="agentes">
    <h2 class="card-title">Quando criar um assistente novo</h2>

    <p class="page-sub">
        A resposta curta: quando muda <strong>com quem ele fala</strong>, não quando muda o assunto.
    </p>

    <p class="page-sub">
        Assunto se resolve dentro do mesmo agente, com bases e setores. Criar um assistente por
        assunto parece organizado e vira armadilha: alguém pergunta duas coisas na mesma frase e
        nenhum dos dois sabe responder inteiro.
    </p>

    <p class="page-sub">
        Vale criar outro quando o público é outro, por exemplo um para clientes e outro para uso
        interno, ou quando o canal é outro e o tom precisa mudar.
    </p>
</div>

<div class="card" id="custo">
    <h2 class="card-title">Quanto custa e como não estourar</h2>

    <p class="page-sub">
        Cada pergunta respondida pelo assistente é uma chamada paga ao fornecedor de inteligência
        artificial. Resposta vinda da FAQ custa muito menos, porque não passa pelo modelo.
    </p>

    <p class="page-sub">
        Cada canal tem dois limites, em <a href="canais.php">Canais</a>. O limite por minuto segura
        o dedo nervoso e robôs de varredura. O limite por dia protege o seu bolso: sem ele, um único
        visitante insistente consome a cota inteira.
    </p>

    <p class="page-sub">
        Batendo o limite do dia, a conversa não morre. Ela passa a funcionar sem inteligência
        artificial, apresentando um menu de setores e entregando contatos. A pessoa continua
        chegando a quem resolve, e você não paga mais nada naquele dia.
    </p>
</div>

<?php endif; ?>

<div class="card" id="atender">
    <h2 class="card-title">Para quem atende</h2>

    <p class="page-sub">
        A tela de <a href="atendimento.php">Atendimento</a> foi feita para parecer com o WhatsApp,
        então quase tudo funciona como você espera. Enter envia, Shift com Enter quebra linha.
    </p>

    <p class="page-sub">
        Marque-se como disponível para receber conversas. O sistema também confere se a sua tela
        está de fato aberta: fechando o navegador, você para de receber em poucos minutos, mesmo
        esquecendo de se marcar como ausente.
    </p>

    <p class="page-sub">
        A <strong>nota interna</strong> fica só entre a equipe e nunca chega a quem está do outro
        lado. Ela aparece diferente na tela justamente para não haver confusão.
    </p>

    <p class="page-sub">
        O botão de perguntar ao assistente responde só para você, com as fontes, e você decide o
        que enviar. Serve para quem está começando e ainda não sabe todas as respostas de cabeça.
    </p>

    <p class="page-sub">
        Preencha o seu <a href="perfil.php">nome de exibição</a>. É ele que a pessoa atendida vê
        quando você entra na conversa. Em branco, você aparece apenas como "Atendente".
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
