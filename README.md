# simpleAIman

Atendimento por IA que responde a partir dos **seus** documentos, não do que o
modelo acha que sabe.

Você sobe os regulamentos, tabelas e páginas que já tem. O assistente lê,
responde no chat do seu site ou no WhatsApp, e cita de onde tirou. Quando não
souber, ele não inventa: passa para uma pessoa, registra a dúvida ou mostra o
contato do setor certo.

Feito para ser clonado por cliente e rodar em hospedagem compartilhada barata.
PHP e SQLite, sem banco externo, sem fila externa, sem build. Deploy é `git pull`.

## O que ele faz quando não sabe a resposta

A maioria dos chatbots erra de um jeito caro: inventa um telefone plausível,
promete que "alguém vai entrar em contato" e some. O cliente descobre o
problema depois, quando já virou reclamação.

Aqui existem três saídas, nessa ordem. A primeira é transferir para um
atendente, e só acontece se houver alguém de fato online, com a tela aberta
naquele instante; sem ninguém, ele nem chega a oferecer. A segunda é registrar
a dúvida como chamado, com protocolo, e avisar por e-mail o responsável pelo
setor, o que funciona às 3h da manhã. A terceira é entregar o contato do setor:
telefone, e-mail e horário saem do seu cadastro, nunca da imaginação do modelo.

O painel do atendente imita o WhatsApp de propósito: quem já usa WhatsApp opera
sem treinamento. Tem histórico completo da conversa, nota interna que o
visitante não vê, transferência entre atendentes, e um copiloto que responde ao
atendente em privado, com as fontes.

## Como ele responde

Duas camadas de conhecimento, e a mais barata tem prioridade.

A **FAQ curada** guarda pares de pergunta e resposta escritos por você. Quando a
pergunta casa, a resposta sai inteira, palavra por palavra, sem passar pelo
modelo. Custa quase nada e sai sempre igual.

O que não casa vai para a **busca nos documentos**. PDF, DOCX, HTML e texto são
quebrados em trechos e indexados de duas formas ao mesmo tempo: por palavra
(FTS5) e por significado (vetores). Os dois resultados são fundidos, e o
assistente responde citando os trechos que usou.

Ele também executa **ferramentas** que você configura: consultar uma API sua,
gravar um lead no CRM, abrir um chamado. O modelo nunca monta a URL nem o corpo
da requisição. Ele só preenche campos que você declarou, com tipo e validação,
dentro de um template que ele não alcança.

## Multiagente, multiprovedor

Cada **agente** tem prompt, modelo, temperatura, bases de conhecimento e
ferramentas próprios. Um para o público externo, outro para o pessoal interno,
outro para uma campanha específica.

Cada **canal** aponta para um agente. O widget do site e o número de WhatsApp
podem atender agentes diferentes, ou o mesmo. Um número, um agente.

O **provedor** é configurável na tela: driver, endereço e modelo. Hoje roda com
Gemini pelo driver nativo, e o caminho compatível com OpenAI abre Groq,
DeepSeek e OpenRouter sem escrever código novo. Trocar de fornecedor é editar um
cadastro, não refazer a integração.

### Modo roteador: atendimento sem IA nenhuma

Um agente pode rodar **sem chamar modelo algum**. Ele apresenta um menu numerado
de setores, entrega contato e transfere para a fila. Zero custo por conversa.

Serve para o cliente que ainda não quer pagar LLM. Mas o uso menos óbvio é o que
mais importa: quando o provedor cai ou a cota do dia estoura, a conversa
degrada para esse modo em vez de morrer com "não consegui responder agora". A
pessoa continua chegando a quem resolve.

## WhatsApp

Opcional, por canal. Você cadastra o número, cola o endereço do webhook no
painel da Meta e põe as credenciais no `.env`. A tela mostra quais variáveis
estão preenchidas e quais faltam, porque webhook mudo sem explicação é o jeito
mais comum de perder uma tarde.

Recebe texto, foto, áudio e documento. Arquivo só é baixado quando um atendente
humano já assumiu a conversa: com o assistente respondendo, ele recusa, para
ninguém encher o disco do cliente mandando arquivo de graça.

Do lado de dentro, o arquivo fica fora da pasta pública e só é servido a quem
está logado. A retenção apaga o arquivo do disco junto com a mensagem, senão o
banco diria "expurgado" com a foto inteira lá.

## Privacidade

Retenção em dois estágios, configurável por instalação e por canal, desligada
por padrão.

O primeiro mascara CPF, e-mail e telefone dentro do texto e remove o IP. O que
sobra ainda serve para você ver o que as pessoas perguntam. O segundo apaga o
conteúdo e preserva só o que não identifica ninguém: quantas conversas houve e
quais documentos respondem de fato.

Tem também exclusão a pedido do titular, com prévia antes de confirmar: a busca
usa correspondência parcial, e um telefone digitado errado levaria conversa de
terceiro junto.

## O que você precisa para rodar

- PHP 8.4 ou superior, com `pdo_sqlite`, `curl`, `mbstring` e `zip`
- Apache com `mod_rewrite`, ou nginx equivalente
- Uma chave de API de algum provedor de LLM (o free tier do Gemini dá conta de
  uma instalação pequena)
- Um agendamento a cada cinco minutos para o worker

Não precisa de Composer no servidor, nem de Node, nem de banco de dados
separado. O `vendor/` vai versionado justamente para o deploy ser um `git pull`.

## Instalação

```bash
git clone <repositorio> .
cp .env.example .env      # edite APP_URL e a chave do provedor
php database/migrate.php  # cria o banco e imprime a senha do primeiro acesso
```

Aponte o document root para `public_html/`. As pastas `app/`, `bin/` e
`database/` precisam ficar fora do alcance HTTP.

Depois, no painel: cadastre o provedor, crie um agente, suba um documento,
gere um canal e cole o `<script>` no site.

A tela de Configurações traz a linha do agendamento já montada com os caminhos
da sua instalação.

## Limites conhecidos

Escrito aqui porque descobrir depois é pior.

A busca vetorial é força bruta em PHP. Funciona bem até algo entre dez e vinte
mil trechos indexados, o que cobre com folga uma instituição de porte médio. Não
serve para milhões de documentos.

Em hospedagem compartilhada o teto real não é o banco: é o número de processos
PHP simultâneos. Cada conversa segura um enquanto espera o modelo responder, o
que dá algo entre cinco e quinze conversas ao vivo ao mesmo tempo.

O agente não interpreta imagem nem áudio. Ele recebe e guarda o arquivo para o
atendente, e diz isso claramente a quem enviou.

Fora da janela de 24 horas, o WhatsApp só aceita modelo de mensagem aprovado
pela Meta. É regra deles, e vale para qualquer sistema.

## Documentação

- `ARQUITETURA.md` registra as decisões e o porquê de cada uma, incluindo as que
  deram errado antes de darem certo.
- As telas do painel explicam cada campo no ponto onde ele é preenchido, com o
  que quebra se estiver errado.
