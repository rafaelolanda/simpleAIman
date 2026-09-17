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
 *
 * Revisada em 11/09/2026 depois de a primeira pessoa a configurar em produção
 * procurar o vínculo entre agente e base do lado da base — onde ele não fica —,
 * e estranhar o agente "lembrar" de uma base já desativada. As duas dúvidas
 * vêm de coisas que a interface não mostra, e por isso estão aqui.
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

// Diagnostico e Infraestrutura sao so do administrador; o editor administra
// conteudo, mas nao abre essas telas. Mesma razao do bloco acima.
$ehAdmin = ($meuPapel ?? '') === 'admin';

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
            <li><a href="#escopo">O que cada assistente consegue ver</a></li>
            <li><a href="#melhorar">Como fazer ele responder melhor</a></li>
            <li><a href="#errado">A resposta saiu errada. E agora?</a></li>
        <?php endif; ?>
        <?php if ($ehAdmin): ?>
            <li><a href="#ver">Onde ver o que aconteceu</a></li>
        <?php endif; ?>
        <li><a href="#nao-sabe">O que acontece quando ele não sabe</a></li>
        <li><a href="#chamados">Chamados: alguém está esperando retorno</a></li>
        <li><a href="#quem-ve">Quem enxerga o quê</a></li>
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
        consulta as <strong>bases</strong> marcadas para ele, que contêm <strong>documentos</strong>,
        e atende por um <strong>canal</strong>. As <strong>ferramentas</strong> são o que ele consegue
        fazer além de responder.
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
                por exemplo regulamentos de um lado e tabela de preços do outro. Quem decide qual
                agente consulta qual base é o <strong>agente</strong>, na aba Conhecimento dele — a
                base não escolhe agentes.</td>
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
                sua resposta sai inteira, sem o assistente reescrever. Vale para
                <strong>todos</strong> os agentes que estiverem com a FAQ ligada.</td>
        </tr>
        <tr>
            <td><strong>Provedor</strong></td>
            <td>O fornecedor de inteligência artificial. Um faz a <em>busca</em> nos documentos
                (embedding) e outro <em>escreve</em> a resposta (chat). Podem ser fornecedores
                diferentes.</td>
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

    <p class="page-sub">
        Em todas elas, ele também lê <strong>o que já foi dito na mesma conversa</strong>. É o que
        permite a pessoa perguntar "e o prazo?" sem repetir o assunto. A consequência menos óbvia:
        se ele respondeu algo a partir de um documento e você desativar a base depois, aquela
        resposta continua na conversa, e ele pode voltar a ela. Para testar uma mudança, comece uma
        conversa nova.
    </p>

    <p class="page-sub">
        Um agente em <strong>modo roteador</strong> não faz nada disso: ele só mostra o menu de
        setores e entrega contatos, sem inteligência artificial nenhuma — no chat do site e no
        WhatsApp.
    </p>
</div>

<?php if ($administra): ?>
<div class="card" id="escopo">
    <h2 class="card-title">O que cada assistente consegue ver</h2>

    <p class="page-sub">
        O vínculo entre assistente e documentos fica <strong>na tela do agente</strong>, não na da
        base: em <a href="agentes.php">Agentes</a>, abra o agente e vá na aba
        <strong>Conhecimento</strong>. Ali você marca as bases que ele pode consultar e liga
        "Consultar as bases". A tela da base só pede o provedor de embedding, porque ela decide
        <em>como</em> os documentos são indexados, não <em>quem</em> os consulta. Em
        <a href="bases.php">Bases</a>, a coluna <strong>Usada por</strong> mostra o caminho inverso.
    </p>

    <p class="page-sub">
        Isso permite ter, por exemplo, um assistente para o público com a base pública e um
        assistente interno com a base de documentos da equipe. O público nunca encontra um trecho
        da base interna — nem pela busca por significado, nem por palavra exata.
    </p>

    <p class="page-sub">
        Três coisas <strong>não</strong> seguem esse vínculo, e vale saber antes de separar
        conteúdo sensível:
    </p>

    <ul class="lista-alertas">
        <li class="alerta alerta-aviso">
            <strong>A FAQ é de todos.</strong> Qualquer agente com a FAQ ligada pode responder com
            qualquer pergunta cadastrada nela. Não coloque na FAQ nada que seja só para a equipe.
        </li>
        <li class="alerta alerta-aviso">
            <strong>O que já foi dito fica na conversa.</strong> Desativar uma base tira ela da busca
            na hora, mas não apaga o que o assistente já respondeu naquela conversa.
        </li>
        <li class="alerta alerta-aviso">
            <strong>Separar assistentes não é controlar quem conversa.</strong> O chat do site e o
            WhatsApp não pedem login de quem está do outro lado. Um assistente com documentos
            internos não deve ficar num canal público; a equipe o usa por aqui, pelo painel, que
            exige login.
        </li>
    </ul>
</div>

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
        Vá pelo sintoma. Quase sempre a causa é uma das cinco abaixo, e nenhuma exige mexer em
        código.
    </p>

    <h3 class="secao-form">Ele disse que não encontrou, mas a informação está no documento</h3>
    <p class="page-sub">
        Abra <a href="testar-busca.php">Testar busca</a> e faça a mesma pergunta. Se o trecho certo
        aparece na lista, o problema é o limiar do agente estar alto demais: ele achou e descartou.
        Se o trecho não aparece, o documento provavelmente está numa base que aquele agente não
        consulta — confira a aba Conhecimento do agente —, ou o arquivo não tem texto legível.
    </p>

    <h3 class="secao-form">Ele respondeu com informação desatualizada</h3>
    <p class="page-sub">
        O documento antigo continua indexado. Suba a versão nova e remova a antiga em
        <a href="artefatos.php">Artefatos</a>. Enquanto os dois existirem, os dois são consultados,
        e o assistente não tem como saber qual está valendo.
    </p>

    <h3 class="secao-form">Desativei uma base, mas ele continua respondendo sobre o assunto</h3>
    <p class="page-sub">
        A base sai da busca na hora; o que costuma sobrar é outra coisa. Ou a resposta já estava
        naquela conversa e ele voltou a ela, ou o assunto também está na FAQ, que não depende de
        base. Teste numa conversa nova. Se a resposta não trouxer "Fontes:", não houve busca em
        documento nenhum.
    </p>

    <h3 class="secao-form">Ele inventou um telefone ou um e-mail</h3>
    <p class="page-sub">
        Isso acontece quando não há setor cadastrado com contato válido. Confira
        <a href="setores.php">Setores</a> e garanta que existe um marcado como padrão. Sem destino
        válido, preencher a lacuna é a saída fácil, e o número inventado parece verdadeiro.
    </p>

    <h3 class="secao-form">Antes de tudo: veja o mapa</h3>
    <p class="page-sub">
        <a href="mapa.php">Mapa de ligações</a> mostra, num desenho só, o que está conectado a quê e o
        que ficou solto — agente sem base, ferramenta que ninguém chama, canal sem agente, provedor
        inativo em uso. Boa parte dos sintomas desta página aparece lá antes, com o efeito descrito.
    </p>

    <h3 class="secao-form">Ele não usa a ferramenta: diz que não encontrou nos documentos</h3>
    <p class="page-sub">
        Confira em <a href="ferramentas.php">Ferramentas</a> se ela está ativa e marcada para
        aquele agente. Estando, o problema costuma ser a <strong>descrição</strong>: ela responde
        a uma pergunta só, "quando devo chamar?", e quer 2 a 4 frases. Fórmula, formato da resposta
        e o que perguntar depois vão no campo <strong>"Instruções para o modelo ao usar a
        resposta"</strong>, enviado junto do resultado, apenas no atendimento em que a ferramenta
        roda. Descrição longa tem dois efeitos ruins: dilui o gatilho, e é cobrada em toda
        conversa, inclusive nas que nada têm a ver com ela.
    </p>

    <h3 class="secao-form">O documento e a ferramenta dizem valores diferentes</h3>
    <p class="page-sub">
        Vale o da ferramenta, e o assistente foi instruído assim: ela consulta o dado agora,
        enquanto o documento é a foto do dia em que foi indexado. Os documentos respondem pela
        regra — quem tem direito ao desconto, que condições valem — e a ferramenta responde pelo
        número. Se um documento antigo com valores continuar indexado, remova-o em
        <a href="artefatos.php">Artefatos</a>: ele segue aparecendo na busca e pode ser citado.
    </p>

    <h3 class="secao-form">A tabela veio quebrada, cheia de barras</h3>
    <p class="page-sub">
        No site a tabela é renderizada normalmente. No WhatsApp não existe tabela, e o assistente
        já é instruído a responder ali em linhas curtas, uma informação por linha. Se mesmo assim
        vier tabela, quase sempre é porque o texto de instruções do agente, ou o da ferramenta,
        está pedindo "apresente em tabela" — essa ordem vale para os dois canais.
    </p>

    <h3 class="secao-form">Ele errou uma conta</h3>
    <p class="page-sub">
        Modelo de linguagem erra aritmética, e em valores isso vira quase-promessa de preço. O
        assistente é instruído a usar o total que a ferramenta devolver, sem recalcular, e a
        mostrar as parcelas quando ele mesmo somar. A correção de verdade é a ferramenta devolver
        o total <strong>já calculado</strong>, em vez de devolver as partes para o modelo
        multiplicar.
    </p>

    <h3 class="secao-form">Ele respondeu certo, mas do jeito errado</h3>
    <p class="page-sub">
        Tom de voz, tamanho da resposta e o que ele prioriza vêm do texto de instruções do agente.
        Ajuste em <a href="agentes.php">Agentes</a> e teste em
        <a href="playground.php">Playground</a>, que conversa sem consumir o canal do site.
    </p>
</div>

<?php endif; ?>

<?php if ($ehAdmin): ?>
<div class="card" id="ver">
    <h2 class="card-title">Onde ver o que aconteceu</h2>

    <p class="page-sub">
        Duas telas, em Sistema, respondem às duas perguntas que aparecem primeiro quando algo sai
        do normal. Nenhuma guarda o texto das conversas — só o que aconteceu com cada resposta.
    </p>

    <p class="page-sub">
        <strong><a href="turnos.php">Turnos</a></strong> responde <em>"o que aconteceu nesta
        resposta?"</em>. Mostra o tempo típico de resposta, quantas falharam e por qual motivo, e
        quanto do atendimento saiu sem chamar a inteligência artificial. Clicando em
        <strong>Abrir</strong> num atendimento, você vê por onde a resposta veio — FAQ, documentos
        ou menu —, quantos trechos foram usados, as ferramentas chamadas e onde o tempo foi gasto.
        É a primeira parada quando alguém reclama de lentidão ou de uma resposta estranha.
    </p>

    <p class="page-sub">
        <strong><a href="infra.php">Diagnóstico</a></strong> responde <em>"o servidor está
        saudável?"</em>. Mostra se a rotina que processa documentos e mensagens está rodando, e
        confere o servidor, o banco e a conexão com o fornecedor de inteligência artificial. Se um
        documento ficar parado em "pendente" ou o WhatsApp parar de responder, comece por ela.
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

<div class="card" id="chamados">
    <h2 class="card-title">Chamados: alguém está esperando retorno</h2>

    <p class="page-sub">
        Um chamado nasce quando <?= e($nome) ?> não soube responder e a pessoa aceitou deixar o contato.
        Cada linha em <a href="chamados.php">Chamados</a> é alguém que recebeu uma promessa de retorno.
    </p>

    <p class="page-sub">
        <strong>O sistema não responde por você.</strong> Ele registra o chamado, avisa o setor por e-mail
        (quando o setor tem e-mail cadastrado) e guarda o contato que a pessoa informou. O retorno em si
        sai por telefone, e-mail ou WhatsApp, feito por uma pessoa. O campo de resposta na tela é
        <strong>anotação interna</strong>: serve para registrar o que foi combinado, e não chega a ninguém
        de fora.
    </p>

    <p class="page-sub">
        Por isso fechar um chamado significa "isto foi resolvido", não "isto foi enviado". Um chamado
        aberto há dias é uma promessa não cumprida — e é assim que ele deve ser lido.
    </p>

    <p class="page-sub">
        Quem atende vê <strong>apenas os chamados do próprio setor</strong>, porque cada um carrega dado
        pessoal de quem pediu retorno: nome, contato e a dúvida por extenso. Administrador e editor veem
        todos. Se você atende e não está vinculado a um setor, a lista vem vazia e a tela avisa — nesse
        caso, peça a um administrador para definir seu setor.
    </p>

    <p class="page-sub">
        Para o agente conseguir registrar chamados, a ferramenta <strong>Registrar chamado</strong>
        precisa estar ativa e marcada para ele, e o setor precisa de e-mail. Sem isso, ele nem oferece —
        e é melhor assim: oferecer o que não se cumpre é pior que dizer "não sei".
    </p>
</div>

<div class="card" id="quem-ve">
    <h2 class="card-title">Quem enxerga o quê</h2>

    <p class="page-sub">
        São três papéis. <strong>Administrador</strong> vê tudo. <strong>Editor</strong> cuida do
        conteúdo — bases, artefatos, FAQ, setores — e não entra em provedores, canais nem ferramentas.
        <strong>Atendente</strong> trabalha na conversa: fila, atendimento e os chamados do setor dele.
    </p>

    <p class="page-sub">
        O <strong>Dashboard</strong> abre para os três, com dados diferentes. Quem atende vê o próprio
        atendimento: as conversas que passaram pelas mãos dele, quantas respondeu por dia e quantas estão
        com ele agora. Sem configuração, sem estado do sistema e sem o número dos colegas.
    </p>

    <p class="page-sub">
        A tela <strong>Agora</strong> fica com administrador e editor. Ela mostra quem está com cada
        conversa e há quanto tempo; entre quem atende lado a lado, isso vira placar. Quem atende já tem a
        fila e a própria conversa na tela de Atendimento. Os outros dois mapas são só do administrador,
        porque tratam de configuração e de contatos internos.
    </p>

    <p class="page-sub">
        Esconder um item do menu não é segurança: quem digita o endereço é barrado do mesmo jeito, pela
        mesma lista que monta o menu. É uma lista só, justamente para as duas coisas nunca divergirem.
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
        interno, ou quando o canal é outro e o tom precisa mudar. Cada um consulta só as bases
        marcadas para ele — veja <a href="#escopo">o que cada assistente consegue ver</a>.
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
        No chat do site, batendo o limite do dia, a conversa não morre. Ela passa a funcionar sem
        inteligência artificial, apresentando um menu de setores e entregando contatos. A pessoa
        continua chegando a quem resolve, e você não paga mais nada naquele dia.
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
