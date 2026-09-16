# Instalação

Clonar o simpleAIman para um cliente novo, do zero até o assistente respondendo.
Leva cerca de meia hora na primeira vez.

Este documento é para quem instala. O guia de uso do dia a dia, para quem
administra o conteúdo, é outro.

## 1. Conferir o servidor antes de subir qualquer coisa

Descobrir que o host não serve depois de subir tudo custa uma tarde. São quatro
coisas, e a primeira reprova mais hospedagem do que parece.

**PHP 8.4 ou superior.** O sistema recusa versões anteriores e diz isso na tela,
sem tentar rodar meio quebrado. Muita hospedagem compartilhada ainda entrega 8.1
ou 8.2 por padrão, e a troca costuma ser um seletor no painel de controle.

**As extensões `pdo_sqlite`, `curl`, `mbstring` e `zip`.** Rode `php -m` e
confira. Sem `pdo_sqlite` nada funciona; sem `curl` o assistente não fala com o
provedor; sem `zip` não dá para ler DOCX.

**Poder apontar o document root para uma subpasta.** As pastas `app/`, `bin/` e
`database/` precisam ficar fora do alcance da web. Se o host obriga a servir a
raiz da conta, não use este host: o `.env` e o banco ficariam acessíveis por URL.

**Disco em armazenamento local, não em rede.** SQLite sobre NFS trava de formas
difíceis de diagnosticar. Na dúvida, pergunte ao suporte.

Se quiser medir antes de decidir, `bin/benchmark-sqlite.php` roda no host e diz
quanto tempo leva a busca vetorial com o volume que você espera.

## 2. Subir o código

```bash
git clone <repositorio> .
```

É só isso. Não rode `composer install`: o `vendor/` já vem versionado, e é essa
a razão de o deploy ser um `git pull` sem etapa de build.

Aponte o document root da conta para `public_html/`.

## 3. Preencher o `.env`

```bash
cp .env.example .env
```

O arquivo tem quarenta variáveis e quase todas têm padrão razoável. Cinco
precisam de atenção, e três delas falham em silêncio se você esquecer.

**`APP_URL`** é o endereço final do sistema, com `https://` e sem barra no fim.
Não é cosmético: é por ele que o upload de documento acorda o worker. Endereço
errado faz o arquivo ficar "pendente" para sempre, e o painel avisa quando
desconfia disso.

**`SESSION_SECRET`** vem com um texto de aviso no lugar do valor. Troque por uma
string longa e aleatória. Deixar o texto de exemplo é o mesmo que publicar a
chave da sessão.

**`WORKER_TOKEN`** vem vazio, e vazio significa que o worker nunca é acordado
sob demanda: tudo passa a depender do agendamento. Gere qualquer string longa.

**A chave do provedor de LLM.** `GEMINI_API_KEY` para o Gemini, ou a variável
correspondente ao fornecedor que você for usar. A chave fica sempre aqui, nunca
no banco: banco vaza em backup e em log.

**`TOOLS_HOSTS_PERMITIDOS`** só importa se o cliente for usar ferramentas que
chamam API externa. É a lista de domínios que o sistema aceita alcançar, separada
por vírgula. Vazia, nenhuma chamada externa acontece, o que é o padrão seguro.

**`TOOLS_RESPOSTA_MAX_KB`** é o teto da resposta de uma ferramenta, 16 KB por padrão. Não é
detalhe: a resposta entra **inteira** no pedido seguinte ao modelo, e uns 16 KB já valem uns 4
mil tokens. Se uma instalação antiga tiver 64 aqui, o pedido estoura o limite de qualquer plano
modesto e o turno morre com HTTP 413.

Para gerar as duas strings aleatórias:

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

## 4. Criar o banco

```bash
php database/migrate.php
```

O comando cria as tabelas, semeia o mínimo para o painel abrir e imprime o
primeiro acesso:

```
Usuário admin criado:
  usuario: admin
  senha:   3f9a1c7e2b04
  (anote agora — não será exibida novamente; troque após o primeiro login)
```

Anote mesmo. A senha é aleatória, não fica gravada em lugar nenhum legível, e
recuperá-la depois exige rodar `bin/redefinir-senha.php` no servidor.

Se o comando não imprimir **nada**, algo falhou antes da primeira linha de
saída — mas você vai ver o motivo: em linha de comando o `bootstrap.php`
força os erros para a saída padrão de erro, independentemente do `php.ini` do
host. A causa mais comum é `.env` ausente ou com caminho de banco inválido.

Se aparecer `could not find driver`, o PHP de linha de comando não achou o
`php.ini` e subiu sem extensão nenhuma. Rode `php --ini` para ver qual arquivo
ele carregou. A solução é chamar com `php -c /caminho/php.ini` ou exportar
`PHPRC`. Guarde esse detalhe: ele volta no passo do agendamento.

Rodar o migrate de novo é seguro. Ele não recria nada que já exista e serve
justamente para aplicar mudanças de versão depois de um `git pull`.

## 5. Primeiro acesso

Abra `https://seu-dominio/admin/`, entre com o usuário impresso acima e troque a
senha em Meu perfil.

Preencha também o **nome de exibição**, no mesmo lugar. Ele é o que a pessoa
atendida vê quando você assume uma conversa. Em branco, você aparece como
"Atendente", e o usuário de login nunca é mostrado a ela.

Em Configurações, defina o nome da instalação, a logo e as cores do cliente.
São esses campos que fazem uma instalação parecer do cliente e não sua.

## 6. Agendar o worker

Uma linha a cada cinco minutos. A tela de Configurações traz essa linha pronta,
com o caminho do PHP e do worker já resolvidos nesta instalação:

```
*/5 * * * * /usr/bin/php /caminho/do/projeto/bin/worker.php >/dev/null 2>&1
```

Se o passo 4 exigiu apontar o `php.ini`, a linha do agendamento precisa do mesmo
tratamento. Sem isso o worker morre em silêncio enquanto o painel continua
funcionando normalmente, e o sintoma é documento que nunca termina de indexar.

O agendamento não é só para a fila. Ele também fecha o que depende de tempo:
conversa presa com um atendente que fechou o navegador, espera longa na fila e
encerramento por inatividade. Nada disso tem evento que dispare, e é justamente
quando ninguém está olhando que precisam acontecer.

---

## 6.1 Hospedagem compartilhada com várias versões de PHP

Escrito depois da instalação na Hostinger em 10/09/2026. O padrão vale para
qualquer host CloudLinux, que é o que a maioria da hospedagem compartilhada usa.

### O PHP do SSH não é o PHP do site

São dois. O seletor do painel (na Hostinger: *Avançado → Configuração PHP*)
define a versão que **serve o site**; o shell do SSH tem o próprio padrão, que
pode ser mais antigo. Como o projeto exige 8.4, é comum o site funcionar e a
linha de comando recusar:

```
simpleAIman exige PHP 8.4 ou superior. Em uso: 8.3.33 (/opt/alt/php83/usr/bin/php)
```

Repare que a mensagem entrega o binário em uso — não é preciso adivinhar qual
`php` o shell pegou.

As versões instaladas ficam em `/opt/alt`:

```bash
ls -d /opt/alt/php8*
```

Use o caminho completo da que você quer:

```bash
/opt/alt/php84/usr/bin/php database/migrate.php
```

Um apelido ajuda no dia a dia, mas **não serve para o cron**, que roda em shell
não interativo:

```bash
echo "alias php=/opt/alt/php84/usr/bin/php" >> ~/.bashrc
```

### Alinhe a versão do site com a do cron

Site numa versão e worker em outra é assimetria que produz bug difícil: algo
falha na indexação e não reproduz no chat, ou o contrário. Escolha uma e use nos
dois lugares.

### A linha do cron

A tela de Configurações monta a linha com o `PHP_BINARY` do processo web. Sob
PHP-FPM isso costuma apontar para um `php-fpm`, que **não funciona como CLI** —
use o caminho de `/opt/alt` que você validou.

O jeito mais seguro de montar sem errar caminho é pedir ao shell:

```bash
echo "/opt/alt/php84/usr/bin/php $HOME/domains/SEU-DOMINIO/bin/worker.php --silencioso 2>> $HOME/domains/SEU-DOMINIO/storage/worker-cron.log"
```

Copie a saída e cole no agendador. Duas escolhas dessa linha:

`--silencioso` porque sem ele o painel manda um e-mail a cada cinco minutos.

`2>>` em vez de `>/dev/null 2>&1` porque erro engolido é como o worker morre sem
ninguém saber. Assim a saída normal já é silenciosa e só a falha é gravada.
**Confirme que a pasta `storage` existe** — se não existir, o shell não consegue
abrir o arquivo e o comando inteiro morre antes de chamar o PHP:

```bash
mkdir -p ~/domains/SEU-DOMINIO/storage
```

### A pegadinha do agendador da Hostinger

Os seletores de Hora, Mês e Dia da semana são obrigatórios e **só oferecem
valores concretos** — não há opção de `*`. O curinga aparece depois que você
escolhe qualquer preset em *Opções Comuns*.

Então: escolha "Uma vez por hora (0 \* \* \* \*)" para liberar os curingas, e
depois troque o **Minuto** para "Cada 5 minutos (\*/5)". O resultado é
`*/5 * * * *`. Os presets sozinhos não servem — o mais fino deles é de meia em
meia hora, e os prazos de `ESPERA_MAX_MIN` e `INATIVIDADE_AVISO_MIN` são
menores que isso.

### Servidor LiteSpeed

A Hostinger serve o site com LiteSpeed. Isso importa para o webhook do WhatsApp
e para o kick do worker, que respondem primeiro e trabalham depois: no LiteSpeed
a função que libera a conexão é outra, e o servidor pode encerrar o processo
antes de o trabalho terminar.

Depois de instalar, rode a **sonda** em Sistema › Infraestrutura. Se ela
sobreviver os 60 s, o trabalho em segundo plano funciona neste servidor. Se
morrer antes, uma resposta do modelo mais longa que esse tempo morre junto — é o
turno interrompido do WhatsApp —, e o caminho é não depender do kick para o
trabalho longo.

### Como confirmar que o worker está rodando

O jeito direto é o cartão **Worker** em Sistema › Infraestrutura, ou
`php bin/diagnostico.php`: cada execução deixa um batimento com horário, origem
(cron ou kick) e desfecho. Com o cron a cada cinco minutos, o esperado é perto de
12 execuções por hora pela linha de comando.

As verificações abaixo continuam valendo quando o painel não abre. O log só
nasce se houver erro, então arquivo ausente não prova nada. Duas verificações
que provam:

```bash
/opt/alt/php84/usr/bin/php ~/domains/SEU-DOMINIO/bin/worker.php --status
```

Isso lê a fila e imprime a contagem por estado, sem processar nada — prova
binário, extensões e banco de uma vez.

Para provar que o **agendador** dispara, consulte a tabela `jobs`: toda execução
do worker agenda a rotina de manutenção, uma vez a cada 24 h. Uma linha
`retencao` com horário posterior ao cadastro do cron é a evidência.

### Os relógios não batem

O sistema de arquivos e o `date` do shell saem em **UTC**; tudo que vem do banco
(conversas, jobs, turnos) e o conteúdo do log do worker saem no fuso da
aplicação. Confirme a diferença antes de comparar horários:

```bash
date; /opt/alt/php84/usr/bin/php -r 'require "$HOME/domains/SEU-DOMINIO/app/bootstrap.php"; echo now(), PHP_EOL;'
```

## 7. Configurar o mínimo para o assistente responder

A ordem importa, porque cada um depende do anterior. É a mesma ordem do menu.

**Provedores.** Cadastre o fornecedor de LLM, apontando `auth_ref` para o nome
da variável do `.env`, não para a chave. Use o botão Testar antes de seguir: ele
mede tempo de resposta e diz se a chave funciona. Atenção ao par driver e
endereço: driver nativo com endereço do caminho compatível com OpenAI devolve
404 em toda pergunta, e o erro não diz que a causa é essa. Na dúvida, deixe o
endereço vazio.

No topo da tela há dois campos que valem para a instância inteira: **provedor
padrão de chat** e **provedor padrão de embedding**. São universos diferentes.
Chat gera a resposta; embedding transforma texto em vetor para a busca da FAQ e
das bases. Nunca é o mesmo modelo, e não precisa ser o mesmo fornecedor — para
usar dois, cadastre **dois provedores** e marque o papel de cada um.

O padrão de embedding governa a busca inteira: a pergunta do visitante, o
índice da FAQ e o das bases precisam sair todos do mesmo modelo. Vetor de modelo
diferente não é comparável, e a comparação **não dá erro** — devolve nota sem
sentido e trecho errado. Pela mesma razão, trocar o modelo de embedding depois
de indexar obriga a **reindexar tudo**; trocar o de chat não exige nada.

**Agentes.** Crie um agente, escolha o provedor e escreva o prompt. É aqui que
você define quem ele é e como fala. Provedor e modelo podem ficar em branco: o
agente herda o padrão de chat do sistema e o modelo desse provedor.

**Bases e Artefatos.** Crie uma base de conhecimento e suba os documentos. A
indexação roda em segundo plano; a tela mostra o progresso. Depois use Testar
busca com perguntas reais para calibrar o limiar antes de gastar chamada de
modelo.

**Ligue as bases ao agente.** É o passo que mais se esquece, porque a tela da
base não o pede: ela só escolhe o provedor de embedding. Quem decide o que o
agente consulta é o próprio agente, na aba **Conhecimento**, marcando as bases e
mantendo ligado "Consultar as bases (RAG)". Base inativa sai da busca de todos os
agentes na hora. A tela de Bases mostra, na coluna **Usada por**, quais agentes
consultam cada uma.

Dois cuidados de escopo. A **FAQ vale para todos os agentes** com ela ligada —
não tem vínculo por agente —, então nada que seja só para a equipe deve ir para
lá. E separar agentes separa o que cada um enxerga, **não quem conversa com
ele**: os canais não pedem login de quem está do outro lado. Um agente com
documentos internos não deve ficar num canal público; a equipe o usa pelo
painel.

**Setores.** Cadastre ao menos um setor com contato válido, e marque um como
padrão. É ele que impede o assistente de inventar telefone quando nenhum setor
casa com a pergunta.

**Canais.** Crie o canal do tipo "chat no site", preencha os domínios
autorizados e copie o trecho de código para o site do cliente. Sem domínio
autorizado o widget só responde a partir do próprio servidor.

## 8. WhatsApp, se o cliente quiser

Precisa de um número exclusivo. Um número registrado na API do WhatsApp deixa de
funcionar no aplicativo comum, então nunca use o celular pessoal de alguém.

No painel da Meta, crie o app, adicione o produto WhatsApp e registre o número.
No simpleAIman, crie um canal do tipo WhatsApp: a tela mostra o endereço do
webhook para colar lá e lista as quatro variáveis que precisam estar no `.env`,
dizendo quais já estão preenchidas.

Duas armadilhas que custam horas. A primeira é confundir o campo `messages`
assinado com a inscrição do app na conta do WhatsApp: são coisas diferentes com
nomes parecidos, e o campo aparecer marcado não garante a inscrição. A segunda é
o token: o que o painel da Meta oferece de imediato expira em cerca de 24 horas.
O permanente sai de Usuários do sistema, no Business Manager.

## 9. Antes de dizer que está no ar

Confira, nesta ordem:

O painel abre por HTTPS e o login funciona. O `.env` não é acessível pela web
(tente `https://dominio/.env` e espere 403 ou 404). O banco também não
(`https://dominio/database/`). O bloco de estado do sistema, no Dashboard, está
sem alertas. Um documento subiu e terminou de indexar. Uma pergunta real
respondeu citando fonte. O widget aparece no site do cliente e responde de lá.
Um chamado de teste chegou por e-mail ao responsável do setor.

Esse último falha com frequência em hospedagem compartilhada, e falha calado.
Teste antes de entregar.

## 10. Quando algo não funciona

### Onde olhar primeiro

**Sistema › Infraestrutura.** Se a dúvida é o chão em que o sistema pisa — o
worker está rodando? falta extensão? o SQLite está lento? o servidor encerra o
processo no meio? —, comece por aqui. A tela mostra se o worker está vivo, as
últimas execuções dele com a origem de cada uma (cron ou kick) e como
terminaram, e as verificações de PHP, banco e rede. Tudo medido **pelo PHP do
site**, que pode não ser o da linha de comando.

Uma execução marcada como **interrompida** começou e nunca terminou, sem erro
registrado: o processo foi encerrado de fora. Se isso aparece nas execuções do
kick, rode a **sonda** na mesma tela — ela mede em que segundo o servidor web
mata um processo em segundo plano. Uma resposta do modelo que demore mais que
isso é o turno interrompido do WhatsApp.

O mesmo pela linha de comando, útil quando o painel não abre:

```bash
php bin/diagnostico.php
```

`--rapido` pula os testes de disco e rede (que fazem uma chamada real de
embedding); `--sonda` dispara a sonda daqui e espera o resultado.

**Sistema › Diagnóstico.** Com a infraestrutura em ordem, é a próxima parada. A tela mostra a latência típica (mediana e
p95), a taxa de erro, quanto do atendimento sai sem chamar o modelo, para onde o tempo está
indo por etapa, e as falhas agrupadas por causa. Os quinze turnos mais lentos ficam listados
— é por onde começar quando alguém reclama de lentidão.

Clicando em **Abrir** num turno você vê aquele atendimento inteiro: o tempo de cada etapa,
as mensagens, as ferramentas chamadas e o link para a conversa.

Cada resposta deixa uma segunda trilha, no **log de erros do PHP**: uma linha de JSON por
evento, prefixada com `[simpleAIman]`. Elas se ligam pelo mesmo `trace_id`, que a tela exibe.
Para ver o que a tela não mostra:

```bash
grep '"trace":"<o id>"' /caminho/do/error_log
```

Nem a tela, nem o log, nem a tabela `turnos` guardam o texto da pergunta ou da resposta — só
metadado. A única exceção é o detalhe de um turno, que lê as mensagens direto de
`mensagens`, respeitando a retenção configurada em Configurações: o que foi expurgado
desaparece de lá também.

### Sintomas

**A busca traz trecho sem relação com a pergunta.** Antes de mexer no limiar, confira em
Provedores se as bases estão indexadas pelo **mesmo** provedor de embedding definido como
padrão. A tela avisa quando divergem. Vetor de modelo diferente não é comparável e a
comparação não dá erro — devolve nota sem sentido. A correção é reindexar a base.

**A FAQ parou de responder direto, mas o resto funciona.** O índice da FAQ e a pergunta
precisam sair do provedor padrão de embedding. Se ele estiver sem chave, inativo ou
apontando para um provedor de chat, a FAQ falha em silêncio e o turno segue pelo RAG. No log
isso aparece como `embedding_pergunta_falhou` ou `faq_busca_falhou`.

**As respostas ficaram lentas e ninguém sabe por quê.** Abra Sistema › Diagnóstico e olhe
"Para onde vai o tempo". Inferência alta é o modelo ou a rede até o fornecedor; ferramentas
altas é um endpoint externo lento, e aí o detalhe do turno diz qual. Se a coluna Ferr.
estiver no teto em muitos turnos, o agente está chamando ferramenta em círculo — reveja as
descrições delas.

**O agente não usa a ferramenta e diz que não encontrou nos documentos.** Confira se a
ferramenta está ativa e marcada para aquele agente. Se estiver, o problema costuma ser a
descrição: ela deve dizer em 2 a 4 frases QUANDO chamar. Procedimento, fórmula e formato de
resposta vão no campo "Instruções para o modelo ao usar a resposta", que viaja junto do
resultado — na descrição, eles diluem o gatilho e ainda são cobrados em toda conversa.

**Erro `pedido_grande` (HTTP 413) logo depois de uma ferramenta responder.** A resposta da
ferramenta entra inteira no pedido seguinte. Ferramenta sem parâmetro, que devolve a lista
completa, estoura qualquer limite modesto: dê a ela um parâmetro de filtro e recorte no próprio
endpoint. O teto é `TOOLS_RESPOSTA_MAX_KB`.

**A resposta chega com buracos no meio das palavras, ou com o raciocínio do modelo em inglês.**
Modelos de raciocínio aberto mandam o pensamento junto do texto. Rode
`php bin/ver-mensagem.php 10` e olhe a linha "suspeitas": ela aponta `<think>`, `<|` e
asteriscos soltos no conteúdo gravado.

**Não consigo entrar no painel, mesmo com a senha nova.** Pode ser bloqueio por tentativas, que
é por usuário **e** IP — daí entrar de outro computador funcionar. Rode
`php bin/diagnostico-login.php`, e `--destravar` para liberar.

**O assistente responde "atendimento indisponível".** Provedor sem chave, chave
inválida ou cota estourada. O Dashboard aponta, e o botão Testar do provedor
confirma.

**Documento fica "pendente" para sempre.** O worker não está rodando. Confira o
agendamento e o `php.ini` da linha de comando.

**O widget não aparece no site do cliente.** Quase sempre é domínio faltando na
lista do canal. O painel funciona, a página de teste funciona, e só o site real
não, o que confunde bastante.

**A busca não encontra o que está no documento.** Use Testar busca. Ela mostra a
nota de cada trecho e por qual caminho ele foi achado, e normalmente o problema
é limiar alto demais ou o documento numa base que o agente não consulta — confira
a aba Conhecimento do agente, ou a coluna Usada por, em Bases.

**Desativei uma base, mas o agente continua respondendo sobre o assunto.** A base
sai da busca na hora. O que sobra costuma ser o histórico: o modelo recebe as
mensagens anteriores da conversa, e o que ele já respondeu a partir da base
continua lá. Ou o assunto também está na FAQ, que não depende de base. Teste
numa conversa nova; em Sistema › Diagnóstico, o turno mostra *busca · 0
trechos* quando nada foi recuperado.

**No WhatsApp a resposta é só "estou com dificuldade para responder".** O
fornecedor falhou — no plano gratuito do Gemini, quase sempre um 503 de
sobrecarga ("high demand"). O chat do site troca para o menu de setores nesse
caso; o WhatsApp ainda não, e devolve a mensagem pedindo para tentar de novo. O
código da falha aparece em Sistema › Diagnóstico, em "Falhas por causa".

**O WhatsApp não recebe nada.** Confira a inscrição do app na conta, no painel
da Meta, e depois se as quatro variáveis do `.env` estão preenchidas. A tela do
canal mostra o estado de cada uma.
