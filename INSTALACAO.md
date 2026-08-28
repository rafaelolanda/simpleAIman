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

## 7. Configurar o mínimo para o assistente responder

A ordem importa, porque cada um depende do anterior. É a mesma ordem do menu.

**Provedores.** Cadastre o fornecedor de LLM, apontando `auth_ref` para o nome
da variável do `.env`, não para a chave. Use o botão Testar antes de seguir: ele
mede tempo de resposta e diz se a chave funciona. Atenção ao par driver e
endereço: driver nativo com endereço do caminho compatível com OpenAI devolve
404 em toda pergunta, e o erro não diz que a causa é essa. Na dúvida, deixe o
endereço vazio.

**Agentes.** Crie um agente, escolha o provedor e escreva o prompt. É aqui que
você define quem ele é e como fala.

**Bases e Artefatos.** Crie uma base de conhecimento e suba os documentos. A
indexação roda em segundo plano; a tela mostra o progresso. Depois use Testar
busca com perguntas reais para calibrar o limiar antes de gastar chamada de
modelo.

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
é limiar alto demais ou o documento na base errada.

**O WhatsApp não recebe nada.** Confira a inscrição do app na conta, no painel
da Meta, e depois se as quatro variáveis do `.env` estão preenchidas. A tela do
canal mostra o estado de cada uma.
