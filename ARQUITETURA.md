# simpleAIman — Arquitetura

Plataforma de agentes de IA com RAG, ferramentas configuráveis e múltiplos canais. Um "Dify simplificado" em PHP, feito pra rodar em hospedagem compartilhada de baixo orçamento e ser clonado por cliente.

Primeiro case: assistente da URI (dúvidas de alunos e candidatos, simulação de mensalidade, captação de leads). O produto, porém, é genérico — nada específico de universidade entra no código.

---

## 1. Premissas

- **PHP 8.4+, sem framework.** Composer entra só como fornecedor de bibliotecas. `vendor/` vai **commitado** — deploy continua sendo `git pull`, sem build.
  O piso é 8.4 e não 8.2 por um motivo concreto: `Pdo\Sqlite::loadExtension()` só existe a
  partir do 8.4, e é o único caminho limpo para o sqlite-vec (§8). Fixar o piso agora
  custa nada; descobrir na etapa 4 que o host está no 8.2 custa a otimização inteira.
  `app/bootstrap.php` recusa versão anterior com mensagem explícita, em vez de deixar
  o erro aparecer torto lá na frente.
- **SQLite**, com o acesso vetorial isolado atrás de interface (troca por pgvector é substituir uma classe).
- **Document root isolado** em `public_html/`; `app/`, `bin/` e `database/` fora do alcance HTTP.
- **Uma instância por cliente.** Cada um tem seu `.env`, seu `.sqlite` e seus uploads, fora do controle de versão.
- **Multi-agente**: vários agentes por instância, cada um com prompt, modelo, bases e ferramentas próprios.

### Reaproveitado da base institucional (quase sem tocar)

`Database.php` (PDO SQLite, WAL, foreign_keys), `Auth.php` (login, bloqueio por tentativas, log de ações), `Mailer.php`, `Metrics.php`, shell do admin (partials, CSS, padrão de CRUD com CSRF e flash), `migrate.php` idempotente, helper de link de WhatsApp.

### Biblioteca de LLM: Neuron AI (não LLPhant)

`neuron-core/neuron-ai`, **versão fixa**. O LLPhant foi instalado, testado e descartado
em 2026-08-24 por um motivo que não dava para contornar com configuração:

- **Não fecha o ciclo de ferramenta com o Gemini.** Os modelos 3.x exigem devolver
  `thought_signature` junto do resultado; ela chega em `tool_calls[].extra_content` e o
  modelo `Message` do LLPhant não tem onde guardá-la, então some no caminho de volta.
  Medido: com a assinatura → HTTP 200; removendo **apenas** ela → HTTP 400.
- **Executa a ferramenta sozinho**, sem deixar lugar para validar parâmetro, checar
  `depende_de`, aplicar a allowlist de host ou gravar em `ferramenta_execucoes`.
- **Não tem embedder para Gemini**, logo nada de `task_type`.
- Depois de uma tool call, o streaming vira não-streaming (comentário do próprio código:
  *"Maybe it could be improved"*).

O Neuron passa nos quatro casos. Tem provider **nativo** de Gemini que captura
`thoughtSignature` como metadado e devolve no `MessageMapper` — e faz o mesmo com a
assinatura de raciocínio da Anthropic e do Bedrock, ou seja, o princípio do **envelope
opaco** é premissa de projeto dele, não remendo. `Tool::setCallable()` mantém a nossa
função no comando, que é onde a validação e a auditoria precisam morar.

Ressalvas registradas: o pacote foi **renomeado** (`inspector-apm/neuron-ai` está
abandonado) e publica versões em ritmo alto — por isso versão fixa. A documentação do
pacote está fora de sincronia com o código (usa `NeuronAI\Agent`, a classe é
`NeuronAI\Agent\Agent`). E o `PdfReader` dele depende de `symfony/process`, ou seja,
**executa binário externo** — inviável em hospedagem compartilhada. Nosso leitor de PDF
é o `smalot/pdfparser`, em PHP puro; o de DOCX, `phpoffice/phpword`. Ambos entram como
dependência direta.

### A fronteira: o que e do Neuron e o que e nosso

O framework aparece em **4 dos 43 arquivos PHP**: `Llm/ChatService.php`,
`Llm/ProviderFactory.php`, `Llm/DiagnosticoProvedor.php` e `Tools/ToolRegistry.php`. Fora
dai, nao ha `use NeuronAI\` em lugar nenhum — e essa e a regra pratica para se localizar.

**Do Neuron** vem: os providers de chat, o loop de tool-calling (mandar a mensagem, receber
o pedido de ferramenta, executar o callable, devolver o resultado, repetir), o streaming, os
tipos de mensagem e de ferramenta, e o modulo **Embeddings**.

**Nosso** e todo o resto, inclusive coisas que o Neuron tambem oferece e que decidimos nao
usar:

| Modulo do Neuron | Usamos? | Por que |
|---|---|---|
| `Providers`, `Chat`, `Tools` | sim | e a razao de o pacote existir aqui |
| `RAG\Embeddings` | sim | so a chamada que devolve o vetor |
| `RAG` (o resto) | **nao** | sem vector store de SQLite, busca so vetorial (a nossa e hibrida com FTS lexical), sem leitor de DOCX |
| `Observability` | **sim** | so o `EventBus`, para cronometrar a inferencia. Ver secao 12 |
| `Workflow`, `MCP`, `StructuredOutput`, `Evaluation` | **nao** | nao ha caso de uso |

Consequencia pratica, e o motivo de isto estar escrito aqui: **problema de qualidade de
busca, de contexto ou de guardrail e codigo nosso** (`app/Rag/`, `app/Tools/`,
`app/Llm/PromptBuilder.php`) e a documentacao do Neuron nao ajuda. Problema de "o modelo nao
chamou a ferramenta" ou de streaming cortado e territorio do Neuron.

O Neuron **nao** oferece nada de guardrail que usemos: nao ha modulo para isso, e
`StructuredOutput` nao e importado em lugar nenhum. Todo controle real e nosso — ver secao 7.

---

## 2. As quatro camadas

```
┌─ CANAIS ─────────  widget web (SSE) · [futuro] WhatsApp Cloud API · API REST
├─ ORQUESTRAÇÃO ───  ChatService: prompt + histórico + loop de tool-calling + guardrails
├─ FERRAMENTAS ────  http · tabela · lead · handoff/contato_setor
└─ CONHECIMENTO ───  FAQ curada · RAG sobre artefatos · dados estruturados
```

O RAG é **uma** das camadas, não o produto. O que separa isto de um buscador semântico é a camada de ferramentas.

---

## 3. Estrutura de pastas

```
simpleAIman/
├── public_html/                  ← document root
│   ├── index.php                 playground de chat (admin)
│   ├── widget.php                widget público embedável
│   ├── embed.js                  loader do widget pra outros sites
│   ├── admin/                    partials: head, sidebar, foot, auth-open/close
│   │   ├── agentes/ bases/ artefatos/ faq/ setores/
│   │   ├── ferramentas/ provedores/ leads/ chamados/ conversas/
│   │   └── usuarios/ dashboard.php
│   ├── api/
│   │   ├── chat.php              SSE (turno do bot)
│   │   ├── mensagens.php         polling (?desde=<id>) — atendimento humano
│   │   ├── upload.php
│   │   ├── worker-kick.php       dispara o worker após upload
│   │   └── feedback.php          feedback por mensagem
│   ├── assets/  ├── css/ js/ └── uploads/artefatos/
│   └── partials/
├── app/
│   ├── Database.php  Auth.php  Mailer.php  Metrics.php     ← do cofre
│   ├── Turno.php  Log.php                                 observabilidade (§12)
│   ├── Llm/
│   │   ├── ProviderFactory.php   monta o provider Neuron a partir da tabela provedores
│   │   ├── ChatService.php       orquestração; modos stream e completo
│   │   ├── PromptBuilder.php     system + contexto + fontes + guardrails
│   │   ├── ObservadorNeuron.php  cronometra a inferência pelo EventBus do Neuron
│   │   └── ToolLoop.php          loop de tool-calling com tetos
│   ├── Rag/
│   │   ├── VectorStore.php       interface
│   │   ├── SqliteVectorStore.php implementação (BLOB float32 + cosseno)
│   │   ├── Ingestor.php  Chunker.php  Retriever.php
│   │   └── Reader/  Pdf.php Docx.php Txt.php Markdown.php Html.php
│   ├── Tools/
│   │   ├── ToolRegistry.php      monta o JSON Schema a partir do banco
│   │   ├── HttpTool.php  TabelaTool.php  LeadTool.php  SetorTool.php
│   │   ├── UrlGuard.php          allowlist, anti-SSRF
│   │   └── Expression.php        avaliador seguro de fórmula
│   ├── Canais/
│   │   ├── Canal.php             interface
│   │   ├── CanalWeb.php          SSE / polling
│   │   └── CanalWhatsapp.php     [stub]
│   ├── Jobs/  Queue.php  Worker.php
│   ├── config.php  bootstrap.php  helpers.php
├── bin/
│   ├── worker.php                fila (cron + kick)
│   └── benchmark-sqlite.php      no host real: concorrência de escrita (NFS?),
│                                 custo do cosseno em PHP, sqlite-vec carrega?
├── database/  schema.sql  migrate.php
├── vendor/                       ← commitado
├── composer.json
├── .env.example
└── ARQUITETURA.md
```

---

## 4. Schema

### Configuração e provedores

```sql
config              id=1 singleton — nome da instância, agente padrão, tema
                    provedor_chat_padrao_id, provedor_embedding_padrao_id
provedores          slug, driver(openai|anthropic|ollama|gemini), base_url,
                    papel(chat|embedding|ambos),            -- a que universo a linha serve
                    auth_ref,                 -- nome da var no .env, NUNCA a chave
                    modelo_chat,
                    base_url_embedding, driver_embedding,   -- embeddings por outro caminho
                    modelo_embedding, dimensoes,
                    suporta_tools, suporta_stream, ativo
```

> **Chat e embedding sao universos distintos.** Nunca e o mesmo modelo — um gera texto, o
> outro transforma texto em vetor — e nao precisa ser o mesmo fornecedor. Como `auth_ref`
> guarda **uma** chave por linha, combinar fornecedores se faz com **duas linhas**, uma de
> papel `chat` e outra de papel `embedding`, e nao com duas chaves na mesma linha. E assim
> que se usa Anthropic no chat e OpenAI (ou Gemini, ou Ollama) nos embeddings.

> A **Anthropic nao tem API de embeddings**. A tela recusa a combinacao na entrada e
> `ProviderFactory::embeddings()` recusa de novo, para o caso de banco editado a mao. Antes
> disso o codigo caia no `default` e montava um provider da OpenAI com a chave da Anthropic:
> o erro que chegava era um 401 do lado errado, que nao dizia nada sobre a causa.

> **Quem escolhe o provedor:** `agentes.provedor_id` para o chat, `bases.provedor_embedding_id`
> para o embedding daquela base. Vazio em qualquer um dos dois cai no padrao gravado em
> `config`. Ate 2026-09-09 o padrao era `WHERE ativo = 1 ORDER BY id LIMIT 1` — ou seja,
> **quem foi cadastrado primeiro**, o que ninguem escolheu e ninguem via.

> `driver` + `base_url` + `modelo` cobre Gemini, Groq, DeepSeek e OpenRouter pelo caminho
> OpenAI-compatible, sem classe nova por fornecedor.

> **Embeddings têm endpoint próprio.** Medido em 2026-08-24: a camada OpenAI-compatible do
> Gemini **recusa `task_type`** (HTTP 400, "Unknown name"), enquanto o endpoint nativo aceita
> e de fato aplica — `RETRIEVAL_DOCUMENT` e `RETRIEVAL_QUERY` produzem vetores diferentes.
> Como usar o tipo certo de cada lado é recall de graça, o embedding vai pelo **nativo**.
> Provedor que faça tudo por um caminho só deixa as duas colunas em branco.
>
> Historicamente o chat ia pelo compat e só o embedding pelo nativo — dai as duas colunas.
> Hoje o chat do Gemini também usa o nativo (por causa do `thoughtSignature`, seção 1), e o
> provedor semeado pelo `migrate.php` configura os dois lados no caminho nativo. As colunas
> continuam existindo porque provedor em que chat e embedding moram em caminhos diferentes
> ainda e um caso real.

### Agentes

```sql
agentes             slug, nome, descricao, provedor_id, modelo, modo(ia|roteador), idioma,
                    system_prompt, temperatura, max_tokens, reasoning_effort,
                    top_k, limiar_similaridade, max_iteracoes_tool,
                    usa_rag, usa_faq, captura_lead, lead_destino_id,
                    token_publico, ativo
agente_bases        agente_id, base_id
agente_ferramentas  agente_id, ferramenta_id, config_override(JSON)
```

> `top_k` e `limiar_similaridade` ficam **no agente**, não globais: suporte quer recall alto,
> política interna quer precisão. É o botão que mais move qualidade na prática.

> `provedor_id` e `modelo` em branco **herdam**: o agente usa o provedor padrão de chat e o
> modelo desse provedor. Preencher é para quando este agente precisa de outro fornecedor ou de
> outro modelo — um de FAQ num modelo barato, outro que encadeia ferramentas num mais capaz.

### Escopo do conhecimento: o que cada agente enxerga

`agente_bases` é a única ligação entre agente e conteúdo, e ela mora **no agente** (aba
Conhecimento). A base não escolhe agentes; só decide com que provedor de embedding seus
documentos viram vetores. A tela de Bases mostra a relação ao contrário, na coluna "Usada por",
porque a primeira pessoa a configurar em produção procurou o vínculo do lado da base.

- **As duas buscas filtram** pela lista de bases do agente: a vetorial (`embeddings.base_id
  IN (...)`) e a lexical (`chunks.base_id IN (...)`). Se só uma filtrasse, um trecho privado
  vazaria por palavra-chave.
- **Base inativa** sai da lista de todos os agentes na hora (`b.ativo = 1` em `recuperar()`).
- **A FAQ é global.** `FaqBusca` procura em todas as perguntas ativas, e a resposta sai
  literal. Decisão de 11/09/2026: fica assim, com um aviso fixo na tela de FAQ.
- **O histórico da conversa não obedece às bases.** O modelo recebe as mensagens anteriores, e
  o que ele já respondeu a partir de uma base continua na conversa depois que ela é desativada.
  Para testar um corte de escopo, use uma conversa nova.
- **Escopo de agente não é controle de acesso.** Os canais não autenticam quem conversa — o
  token do widget é público por desenho (§10.2), e o WhatsApp aceita qualquer número. Agente
  com base interna não deve ficar em canal público; a equipe o usa pelo painel, que exige login.

### Conhecimento

```sql
bases               nome, slug, provedor_embedding_id, modelo_embedding, dimensoes
artefatos           base_id, setor_id, tipo, titulo, arquivo, hash, tamanho,
                    status(pendente|processando|ok|erro), erro, chunks_total
chunks              artefato_id, base_id, ordem, conteudo, tokens, metadados(JSON)
chunks_fts          FTS5 espelho de chunks.conteudo
embeddings          chunk_id PK, modelo, vetor BLOB, norma REAL

setores             slug, nome, descricao_llm,
                    email, telefone, whatsapp, ramal,
                    horario_atendimento, local, responsavel_nome,
                    ativo, atualizado_em
faq_categorias      nome, ordem
faq                 categoria_id, setor_id, pergunta, resposta, tags, ativo, ordem
faq_embeddings      faq_id PK, vetor BLOB, norma REAL
```

> `dimensoes` fica na **base**, não global: trocar de modelo de embedding invalida todos os
> vetores daquela base. O campo torna isso explícito e permite reindexar uma base por vez.

### Ferramentas

```sql
ferramentas         slug, nome, setor_id,
                    descricao_llm,            -- o texto que o modelo lê pra decidir chamar
                    tipo(http|tabela|lead|handoff|contato_setor),
                    efeito(leitura|escrita), requer_confirmacao, depende_de,
                    -- http:
                    metodo, url_template, headers(JSON), corpo_template(JSON),
                    auth_tipo(none|bearer|basic|header|query), auth_ref,
                    timeout_ms, retentativas,
                    resposta_caminho, resposta_template,
                    -- tabela:
                    dataset_id, formula,
                    ativo
ferramenta_parametros
                    ferramenta_id, nome, tipo(string|number|boolean|enum),
                    descricao_llm, obrigatorio, enum_valores(JSON),
                    enum_fonte,               -- ex: setores → enum dinâmico do banco
                    padrao, exemplo, ordem
ferramenta_execucoes
                    conversa_id, mensagem_id, ferramenta_id, params(JSON),
                    status, http_status, resposta, duracao_ms, erro
datasets            nome, colunas(JSON)       -- CSV carregado no admin
dataset_linhas      dataset_id, dados(JSON)
```

### Canais e conversas

```sql
canais              tipo(web|whatsapp|api), nome, agente_id,
                    credenciais_ref, config(JSON), ativo
conversas           canal_id, agente_id, externo_id, titulo, ip,
                    modo(bot|aguardando|humano|encerrada),
                    atendente_id, aguardando_desde,
                    ultima_msg_usuario_em,    -- janela de 24h da Meta
                    criado_em
mensagens           conversa_id, autor_tipo(bot|usuario|atendente|sistema), autor_id,
                    conteudo, tokens_in, tokens_out, custo, latencia_ms, feedback
mensagem_fontes     mensagem_id, tipo(chunk|faq), referencia_id, score
```

### Leads, chamados, operacional

```sql
leads               conversa_id, agente_id, nome, email, telefone,
                    campos_extra(JSON), consentimento_em, origem
lead_destinos       lead_id, ferramenta_id,
                    status(pendente|ok|erro|descartado), tentativas,
                    proxima_tentativa_em, http_status, resposta, erro
chamados            conversa_id, setor_id, assunto, descricao, contato,
                    status(aberto|respondido|fechado), resposta
jobs                tipo, payload(JSON), progresso(JSON), status, prioridade,
                    tentativas, proxima_execucao_em, lock_ate, erro
admin_users         + setor_id, atende, disponivel     -- Nível 2 do handoff
admin_logs  login_tentativas  metricas
```

> **Nomes de `admin_users`, `admin_logs`, `login_tentativas` e `metricas` vêm da base
> institucional de propósito**: é o que permite copiar `Auth.php` e `Metrics.php` sem
> tocar numa linha. Renomear para `usuarios`/`log_acoes` custaria reescrever as classes
> mais testadas do conjunto, em troca de nada.

> **Sem `CHECK`** nas enumerações (`driver`, `tipo`, `status`). SQLite não permite ALTER de
> constraint e mudar o conjunto exigiria recriar a tabela. Validação fica no PHP.

---

## 4.1 Quem escolhe o agente

**O canal escolhe, nunca o visitante.** Cada lugar onde o chat aparece está
amarrado a um agente por `canais.agente_id` ou por `agentes.token_publico`:

```
widget no site institucional  → agente "Atendimento ao candidato"
widget no portal do aluno     → agente "Aluno matriculado"
WhatsApp institucional        → agente "Atendimento ao candidato"
site de outro cliente         → agente daquele cliente
```

O visitante nunca vê uma lista de agentes. Ele entra numa página, o widget
carrega com um token, e o token determina quem responde.

### Agente não é assunto

É a distinção que sustenta o desenho. Cria-se agente novo quando muda **o
público, o canal ou o cliente** — porque aí mudam o tom, as bases e o que ele
pode fazer. Candidato e aluno matriculado são públicos diferentes: um quer
preço e forma de ingresso, o outro quer prazo de rematrícula.

**Não** se cria agente por assunto. "Agente do financeiro" e "agente de
cursos" parecem organização e são armadilha: um candidato pergunta *"quanto
custa Direito e tem bolsa?"* numa frase só, e não há resposta boa para "qual
dos dois atende". Dentro de um agente, assunto se resolve com **bases** e
**setores** — é para isso que os setores existem.

### Por que não roteamento automático

Um agente orquestrador que classifica a pergunta e despacha custa **uma
chamada de LLM a mais por mensagem**. Como a rede responde por ~99% do tempo
de um turno (§8), isso dobra latência e custo, cria um modo de falha novo
(rotear errado) e perde contexto na passagem. Quase sempre é melhor dar
**mais ferramentas a um agente** do que rotear entre vários.

### A exceção legítima

Mesma página servindo públicos claramente distintos, sem como saber qual é.
Aí o widget abre com dois botões — *"Você é candidato ou já é aluno?"* — e a
escolha fixa o agente da conversa. Sem chamada de LLM, sem adivinhação. Só
vale quando o público difere de fato; se for só assunto, um agente resolve
melhor.

---

## 5. Fluxo de um turno

```
mensagem do usuário
  │
  ├─ conversa em modo humano? → grava e PARA (o bot não fala por cima do atendente)
  │
  ├─ agente em modo roteador? → menu de setores, sem provedor, e encerra
  │    (vale no widget e no WhatsApp; até 11/09/2026 só valia no widget)
  │
  ├─ comando de navegação ("menu", número de setor)? → roteador, e encerra
  │
  ├─ FAQ  (global: não depende das bases do agente)
  │    embed da pergunta (RETRIEVAL_QUERY, provedor PADRÃO de embedding)
  │    casou acima do limiar alto? → resposta curada, sem modelo, e encerra
  │
  ├─ RECUPERAÇÃO  (só nas bases ATIVAS ligadas ao agente em agente_bases)
  │    FTS5/BM25 + cosseno, reaproveitando o vetor da FAQ → fusão → top_k → corte por limiar
  │
  ├─ PROMPT
  │    system do agente + trechos CERCADOS + guardrails + histórico + schema das ferramentas
  │
  ├─ LOOP DE FERRAMENTAS  (máx. max_iteracoes_tool, orçamento de tokens, timeout total)
  │    modelo pede ferramenta → valida params → checa depende_de → executa → devolve
  │    resultado marcado como DADO EXTERNO, nunca como instrução
  │
  ├─ RESPOSTA
  │    stream (web) ou completa (WhatsApp) — mesmo pipeline, transportes diferentes
  │    provedor falhou? no stream degrada para o menu; no WhatsApp, mensagem de erro
  │
  └─ grava mensagem + fontes + execuções + tokens + turno (caminho e tempo por etapa)
```

**Modelo pensante falha em silêncio.** Os Gemini 3.x gastam o orçamento de saída pensando
**antes** de sobrar texto. Medido em 2026-08-24 com `max_tokens = 20`: HTTP **200** com
conteúdo **vazio** e `finish_reason = length`. Não é erro — é resposta em branco, e o sintoma
no widget seria "o bot não respondeu", sem nada no log. O `ChatService` **precisa** tratar
`finish_reason = length` com conteúdo vazio como erro legível. `reasoning_effort` (none/low/
medium/high) controla esse gasto por agente.

**Fixar a versão do modelo, nunca o alias.** `gemini-flash-latest` devolveu **503 high demand**
enquanto os modelos de versão fixa respondiam normalmente — e um alias ainda muda comportamento
e custo sem aviso.

**Slot filling.** Faltando parâmetro obrigatório, o modelo pergunta ao usuário. Isso não é
encadeamento de ferramenta, é conversa — emerge sozinho dos campos obrigatórios.

**Ordem entre ferramentas**, da mais fraca à mais forte:

1. `descricao_llm` ("só chame depois de ter `curso_id`")
2. **parâmetro obrigatório** — impossível chamar sem o dado; mais forte que instrução
3. `system_prompt` do agente (o procedimento)
4. `depende_de` — trava real: o orquestrador **recusa** se o pré-requisito não rodou

---

## 6. Ingestão

```
upload → artefato(pendente) → job na fila → responde na hora
   ↓
worker (cron 1–5min + kick após upload)
   extrai texto → chunk → embed em LOTE (task_type = RETRIEVAL_DOCUMENT)
   → grava chunks + embeddings + FTS5 → progresso no job
```

- **Retomável em lotes.** Processa N chunks, grava progresso, sai limpo antes do
  `max_execution_time`. Um PDF de 300 páginas atravessa várias execuções sem reprocessar nada.
- **Falha passageira pausa o job, não marca erro.** Cota (`429`) pausa por 90 s,
  sem teto — o `Retry-After` **não** é lido, apesar do que este documento dizia até
  11/09/2026. Na indexação, `503` e timeout também pausam, com espera crescente e
  teto de seis falhas **seguidas**; o contador zera a cada grupo que dá certo, porque
  a indexação regrava o progresso sem ele. No WhatsApp não há retentativa: a pessoa
  já foi avisada, e o dedup descartaria a segunda passada (§10.3). Ver
  `Jobs/PoliticaDeFalha`.
- **Nunca segurar transação durante chamada HTTP.** Chama a API fora, grava dentro,
  transação curta. É o bug nº 1 desse tipo de app.
- **Leitura de PDF página a página** e teto de upload configurável — parser guloso derruba
  o processo em host com 128–256MB.

---

### Teto de ferramentas por turno

`agentes.max_iteracoes_tool` limita quantas ferramentas o modelo executa numa mesma resposta.
Cada volta do laço é uma **chamada nova ao provedor**, com o histórico inteiro — é onde o
crédito some sem ninguém notar.

A checagem fica **fora** do `try` do `Executor`: o `catch` devolve uma frase neutra e genérica
ao modelo, que engoliria a instrução de parar, e ele tentaria de novo. E a recusa devolve
texto acionável ("responda agora com o que já foi obtido"), não erro — "falhou" o faria
tentar outra ferramenta. Fica registrada como `recusado`, para auditoria.

### Limite de uso: enxurrada e gasto são coisas diferentes

O canal tem dois tetos, e eles pedem tratamentos opostos:

- **Por minuto** é proteção de **carga**. Corta seco: fazer qualquer trabalho ali derrotaria
  a proteção, que existe justamente para quem (ou o que) está batendo sem parar.
- **Por dia** é proteção de **gasto** — do provedor de LLM. Cortar seco criava um beco sem
  saída: a mensagem dizia "deixe seu contato que alguém retorna" e não havia caminho nenhum
  para deixar. A pessoa repetia e recebia a mesma frase.

Batendo a cota do dia, a conversa passa ao **roteador**, que é PHP e banco — nada toca o
provedor, então o teto de gasto continua respeitado. Ela vê os contatos dos setores, e o
contato que deixar é **registrado de verdade**, pela mesma captação de sempre (`leads` +
`lead_destinos`, com retry e dead letter). Sem caminho paralelo: um segundo lugar de onde
lead some sem ninguém saber seria pior que o beco.

**A captação só ocorre quando foi oferecida.** `Roteador::responder()` recebe
`captarContato: true` de quem acabou de convidar. Gravar e-mail ou telefone de quem
simplesmente escreveu um endereço no meio de outra conversa seria coletar dado pessoal sem
pedido — a permissão vem do convite, não do formato do texto.

E quando não há setor nenhum cadastrado, a frase volta a ser honesta: *"tente novamente
amanhã"*, sem prometer contato que ninguém vai recolher.

### Autenticação das ferramentas HTTP

Quatro tipos, e agora os quatro funcionam:

| tipo | onde a chave entra | precisa de `auth_nome`? |
|---|---|---|
| `bearer` | `Authorization: Bearer …` | não |
| `basic` | `Authorization: Basic …` | não |
| `header` | cabeçalho próprio | **sim** — qual cabeçalho |
| `query` | query string da URL | **sim** — qual parâmetro |

`header` e `query` apareciam no formulário e caíam num `default` silencioso: a chave não era
enviada e a API respondia 401 sem explicação. Faltava a **informação**, não o código — nada
dizia *qual* cabeçalho ou *qual* parâmetro. É o que `auth_nome` guarda, e o formulário passa
a recusar salvar sem ele.

A chave continua saindo do `.env` por `auth_ref`, nunca do banco. E como `query` põe o
segredo **na URL**, a tela de teste passa a mascará-la também (`ocultarUrl`) — antes só os
cabeçalhos eram mascarados, e exibir a URL crua anularia a razão de a chave não ficar no
banco.

### Aviso de origem: quando o dado não veio da base

`ferramentas.aviso_resposta` guarda uma frase acrescentada ao fim da resposta sempre que
aquela ferramenta for usada. Serve para dizer que a informação **não veio dos documentos
curados** — busca na web, sistema de terceiro — e precisa ser conferida.

**Anexado pelo código, não pedido ao modelo.** Aviso que depende de o modelo lembrar some
justamente na resposta em que importava. É o mesmo raciocínio da frase de encaminhamento
fixa: o que precisa ser dito sempre não se pede, se escreve.

No streaming ele sai como **último pedaço**, porque só existe depois de a ferramenta ter
rodado — e rodar acontece no meio da geração.

### Busca na web com domínio restrito

Não precisa de tipo novo: o tipo `http` já resolve, e a restrição é **estrutural**. O template
é fixo pelo admin e o modelo só preenche parâmetros declarados, com o valor percent-encoded —
ele não alcança o `site:` nem consegue injetar `&` ou `/` para sair da query string.

```
https://api.search.brave.com/res/v1/web/search?q=site%3ASEUDOMINIO.COM.BR+{{params.termo}}&count=5
```

O catálogo traz esse modelo pronto (`busca_web`), com cabeçalho, `resposta_caminho` e
parâmetro preenchidos. Falta trocar o domínio e pôr a chave em `BUSCA_API_KEY`.

Dois limites conhecidos, que valem saber antes de confiar:

- A restrição depende do **provedor honrar o `site:`**. É promessa dele, não garantia nossa.
  Filtrar os domínios das URLs devolvidas seria a nossa camada, e ainda não existe.
- A resposta é JSON verboso. `resposta_caminho` extrai o array, mas cada item traz muitos
  campos, e tudo isso vira contexto — cobrado em toda chamada seguinte do turno.

**Para conteúdo estável, ingerir como artefato ganha da busca ao vivo**: o RAG chunka, embedda
e cita a fonte, enquanto a busca custa uma ida e volta a mais e devolve texto sem
ranqueamento semântico. Busca ao vivo vence só para o que muda toda hora — edital de ontem,
vaga, preço.

> **Pendência conhecida:** `auth_tipo` oferece `header` e `query` no formulário, mas o
> `HttpTool` só implementa `bearer` e `basic` — os outros dois caem no `default` e não fazem
> nada. Quem precisa de cabeçalho próprio (como o `X-Subscription-Token` do Brave) usa
> `auth_tipo = none` e põe `{{env.NOME}}` no cabeçalho, que funciona.

### Embutidas são código, mas precisam virar linha

`contato_setor`, `transferir_atendimento`, `abrir_chamado` e `lead` existem como classes,
mas **só existem para o modelo depois de virarem registro em `ferramentas`**. Isso é
deliberado: `descricao_llm` é o campo que decide QUANDO o modelo chama, e ele precisa ser
afinado por cliente — "falar com uma pessoa" numa universidade não é a mesma frase que numa
concessionária. Uma embutida com descrição fixa no código tiraria justamente o botão que
mais move o comportamento do agente.

O que isso custava era descoberta: quem instalava do zero não sabia que elas existiam, e
quem sabia reescrevia descrição e parâmetros toda vez. Resolvido em três pontos, sem abrir
mão da configurabilidade:

- **`Tools\Catalogo`** guarda os modelos prontos (descrição que funciona, parâmetros certos,
  enum dinâmico de setores). Um clique instancia; o texto fica editável depois.
- **`migrate.php` semeia as quatro** numa instalação nova — criadas e visíveis na lista,
  **sem vínculo a agente nenhum**. Descoberta resolvida sem ligar nada por conta própria.
- **A tela de agentes marca "sem encaminhamento"** quando nenhuma ferramenta de handoff está
  vinculada (`ToolRegistry::temHandoff()`). Um agente sem caminho para humano, quando não
  sabe responder, só tem duas saídas: inventar ou dar de ombros.

---

### Modo roteador: atendimento sem IA

`agentes.modo` vale `ia` ou `roteador`. Em `roteador`, o `ChatService` **não chama o provedor
nenhuma vez** — sai antes da FAQ e do RAG, que também não fazem sentido sem chave de API.
O menu é montado da tabela `setores`, que já existia como espinha de roteamento.

Serve a três situações com a mesma máquina:

1. **Cliente que não quer pagar LLM.** Um "fale conosco" com roteamento resolve muita gente.
2. **Degradação quando o provedor cai.** Sem isso, um `429` de cota encerra a conversa com
   "não consegui responder agora" e a pessoa fica sem nada — justamente quando mais precisava
   de um caminho. É o ganho maior, e o que menos se pensa antes de acontecer.
3. **Caminho de adoção.** Começa como roteador, liga a IA depois.

**Menu numerado, não botões**: número funciona igual no widget e no WhatsApp, sem interface
nova nem mensagem interativa da Meta.

**Sem estado.** Cada mensagem é interpretada sozinha — número escolhe setor, palavra-chave
dispara ação (`ATENDENTE`, `MENU`), o resto mostra a lista. Guardar "em que passo a pessoa
está" exigiria coluna e quebraria no instante em que ela digitasse fora de ordem, que é o que
as pessoas fazem.

**Funciona com zero atendentes.** Telefone, e-mail e horário são dados nossos e não dependem
de ninguém online; a opção `ATENDENTE` só aparece quando há alguém, e quem digitar assim mesmo
recebe os contatos. É a regra de sempre: não se promete o que não se pode cumprir.

A degradação não acontece se **parte da resposta já saiu** — emendar um menu no meio de um
texto que o visitante está lendo confunde mais que o erro.

**Dois limites de alcance, registrados em 11/09/2026.** O modo roteador em si passou a valer
também no WhatsApp: até então a checagem só existia no `stream()`, e o `responder()` — o caminho
do WhatsApp — seguia para FAQ, RAG e modelo mesmo com o agente em `roteador`. Já a
**degradação** do item 2 continua só no `stream()`: no WhatsApp, quando o provedor falha, a
pessoa recebe a mensagem pública do `ErroAgente` pedindo para tentar de novo, não o menu.

---

## 7. Segurança

### Camada `http` (maior superfície do projeto)

- **Allowlist de hosts** no `.env`, nunca campo livre no admin.
- Bloquear IP privado/loopback/metadata (`169.254.169.254`) **após resolver o DNS** — senão
  DNS rebinding passa. Só HTTPS.
- **O modelo nunca monta URL nem corpo.** Preenche slots tipados e validados; o template é
  fixo. Concatenação livre = cliente HTTP arbitrário nas mãos de quem conversa com o bot.
- **Segredos por referência ao `.env`.** No banco, vazariam em backup e log.
- Resposta é **dado, não instrução**: tamanho limitado; texto vindo de terceiro nunca vira comando.
- Escrita com **chave de idempotência** — retry não pode criar dois leads.

### Agente

- **Guardrails persuasivos x estruturais.** Ver seção 12: o que está no prompt é pedido, o
  que está no `Executor`/`UrlGuard` é recusa. A régua para decidir onde pôr um guardrail
  novo é: *e se o modelo ignorar?* Se a resposta envolve dinheiro, dado pessoal, chamada
  externa ou promessa ao cliente, é código — não texto.
- **Injeção indireta pelo material do RAG.** Cada trecho entra no prompt entre
  `<<<TRECHO n>>>` e `<<<FIM DO TRECHO n>>>`, com o conteúdo sanitizado para não forjar a
  marca de fechamento (`<<<` vira `< <<`) nem abrir seção markdown (`##` vira `\##`), mais
  uma regra dizendo que ordem encontrada lá dentro é texto citado, não comando. Sem isso, um
  PDF contendo "ignore as instruções anteriores" chegava ao modelo com o mesmo peso das
  regras da casa. Não é filtro de conteúdo malicioso: o texto continua chegando, o que muda
  é que ele chega reconhecível como dado.
- Teto de iterações, de tokens e de tempo por turno.
- Nunca afirmar valor, prazo ou edital sem ferramenta ou citação.
- **Nunca inventar contato** — telefone e e-mail só saem de `contato_setor`; setor não
  encontrado cai no padrão, não chuta.
- Valor de mensalidade sai sempre como **simulação sujeita a confirmação**, com caminho pro humano.
- Rate limit por IP/telefone; log completo pra auditoria.

### Painel: quem enxerga o quê

Três papéis em `admin_users.papel`: **admin** (tudo), **editor** (conteúdo — bases,
artefatos, FAQ, setores, além de conversas e playground) e **atendente** (fila, chamados,
perfil). `admin_master` continua sendo o único que gerencia usuários.

- **A trava é no servidor, não no menu.** `Painel::podeVer()` roda no `_init.php`, que toda
  tela autenticada carrega. Esconder o link não protege: a pessoa digita o nome do arquivo
  na barra de endereço.
- **Menu e trava leem a MESMA lista** (`Painel::TELAS`). Duas listas divergiriam na primeira
  tela nova, e a divergência é ou link quebrado, ou tela sensível aberta por omissão.
- **Padrão fechado.** Tela ausente do mapa é acessível só por admin. Esquecer de listar uma
  tela nova erra para o lado seguro, e o sintoma aparece rápido e é inofensivo.
- **Endpoint também é tela.** `api/chat.php` gasta token de verdade e é barrado pelo mesmo
  mapa; senão a trava do painel seria enfeite para quem soubesse montar a URL.
- **O papel é lido do banco a cada requisição**, nunca guardado na sessão: um papel gravado
  no login continuaria valendo depois do rebaixamento, até a pessoa sair e entrar de novo.
- **`papel` é independente de `atende`.** Um administrador pode atender; derivar um do outro
  tiraria o painel dele no instante em que entrasse na fila.
- Rebaixar o `admin_master`, ou o próprio usuário, é recusado — não há tela para desfazer.

### Dados

- Consentimento explícito antes de captar lead; finalidade, retenção e caminho de exclusão.
- Só canal institucional em `setores` — nunca contato pessoal.

---

## 8. SQLite — limites e regras

O gargalo real **não é o banco**: é o número de processos PHP simultâneos (~10–30 na
compartilhada), cada chat segurando um enquanto espera a LLM. Teto prático: ~5–15 conversas
ao vivo.

| Aspecto | Ordem de grandeza | Alavanca |
|---|---|---|
| Escrita de chat | 1 transação de ms por turno | não é problema |
| Busca vetorial | 5k chunks ≈ 0,3s · 20k ≈ 1–2s (1536 dim) | 768 dim dobra o teto; pré-filtro FTS5; int8 |
| Ingestão | PDF grande estoura memória/tempo | worker retomável, teto de upload |

**Regras inegociáveis**

1. `busy_timeout = 5000` — sem isso, escritor concorrente recebe `SQLITE_BUSY` na hora.
2. Nunca manter transação aberta durante chamada HTTP.
3. Backup por `VACUUM INTO`, nunca `cp` (arquivo em WAL copiado ingenuamente vem corrompido).

**O JIT é uma alavanca real, e barata.** Medido no PHP 8.4.21, o mesmo benchmark com
`opcache.jit=tracing` ficou ~37% mais rápido: 768 dim / 5k chunks caiu de 273 ms para
171 ms, e 20k de 1060 ms para 760 ms. Não muda a classe de escalabilidade — continua sendo
varredura linear — mas é o ajuste de configuração que mais rende antes de considerar o
sqlite-vec. Vale conferir se o host permite ligar; muita hospedagem compartilhada não.

**Verificar antes de fechar:** se o host usa storage de rede (NFS), o lock do SQLite é lento
e não confiável. Único cenário que inviabilizaria a rota. `bin/benchmark-sqlite.php` testa isso.

### E o sqlite-vec?

`sqlite-vec` **não faz busca aproximada (ANN)** — faz a mesma varredura por força bruta que a
nossa, só que em C com SIMD. Não muda a classe de escalabilidade; muda o multiplicador, que é
grande: 10–30× mais rápido que PHP, com quantização int8/binária nativa e filtro por metadado
antes do scan.

Não é a implementação inicial por três motivos, nenhum deles relacionado a qualidade:

1. **Carregar extensão pelo PHP.** O método é `Pdo\Sqlite::loadExtension()` — **não**
   `PDO::loadExtension()`, que não existe. Ele vive na subclasse `Pdo\Sqlite`, introduzida
   no PHP 8.4 (por isso o piso do projeto é 8.4), e **só uma conexão criada por
   `PDO::connect()` é instância dela**: `new PDO()` devolve um `PDO` puro, sem o método.
   Por isso `Database::connection()` usa `PDO::connect()` — do contrário a porta ficaria
   fechada mesmo com o PHP certo. O caminho alternativo, `SQLite3::loadExtension()`, várias
   distribuições compilam desabilitado, e em compartilhada não se escolhe como o PHP foi
   compilado. `bin/benchmark-sqlite.php` verifica isso no host de destino.
   Tudo isto verificado no PHP 8.4.21 em 2026-08-24.
2. **Binário por plataforma.** É um `.so`/`.dll` compilado. Versionar binário por arquitetura
   corrói a premissa que nos fez commitar o `vendor/`: "`git pull` e funciona em qualquer
   lugar" viraria "funciona se o host bater com o binário certo".
3. **Pré-1.0**, com troca de API entre versões — dependência a mais que pode quebrar num host
   e não em outro, num produto clonado por cliente.

**Plano:** `bin/benchmark-sqlite.php` verifica se a instalação consegue carregar a extensão.
O `SqliteVectorStore` em PHP puro é o piso garantido em qualquer lugar. **Se e quando** a
medição com dados reais mostrar necessidade, entra `SqliteVecStore` como segunda implementação
da mesma interface, com detecção em runtime. Construir as duas antes de ter número seria
otimizar sem medida.

Isso vale enquanto ninguém escrever cálculo de similaridade fora da interface `VectorStore`.
É o que torna PHP puro, sqlite-vec e pgvector uma troca de classe em vez de uma reescrita.

---

## 9. Handoff — Níveis 1 e 2

**Nível 1 (assíncrono).** `contato_setor` devolve telefone/e-mail/WhatsApp/horário do setor
certo; `abrir_chamado` registra e dispara e-mail ao responsável. Funciona 24/7, sem ninguém
de plantão, e continua sendo o piso: é para onde o Nível 2 cai quando não há atendente.

**Nível 2 (ao vivo).** `transferir_atendimento` coloca a conversa na fila; um atendente
assume pelo painel (`admin/atendimento.php`) e digita para o visitante.

### A máquina de estados

```
bot ──solicitar()──> aguardando ──assumir()──> humano ──encerrar()──> encerrada
                          │                      │
                          └──expirar()───────────┴──devolverAoBot()──> bot
```

Toda transição de `conversas.modo` passa por `SimpleAIman\Atendimento\Fila`. É deliberado:
o modo decide se o bot fala ou cala, e `UPDATE conversas SET modo` espalhado pelo código
criaria a chance de transferir sem calar o bot, ou calar o bot sem avisar ninguém.

### As regras que sustentam

- **`modo = humano` (e `aguardando`) cala o bot.** `ChatService::botDeveResponder()`. A
  mensagem do visitante é gravada mas não vai para a LLM.
- **Não se promete o que não se pode cumprir.** Sem atendente disponível, `solicitar()`
  **recusa** a transferência e a ferramenta instrui o agente a oferecer o Nível 1. Prometer
  transferência para uma sala vazia é pior que dizer desde o começo que ninguém está online.
- **Quem assume, assume sozinho.** O `WHERE modo = 'aguardando'` do `UPDATE` é o que impede
  dois atendentes de abrirem a mesma conversa; quem perder a corrida recebe `false`.
- **Ninguém espera para sempre.** Passados `Fila::ESPERA_MAX_MIN`, a conversa volta ao bot
  com instrução de pedir desculpas e oferecer registrar a dúvida. A varredura roda de forma
  oportunista (cada carga do painel e cada consulta do widget) **e** no worker — as telas
  cobrem o caso de alguém estar olhando; o worker cobre justamente o contrário.
- **Voltar ao bot preserva contexto.** O histórico do atendente fica marcado com
  `autor_tipo = 'atendente'`. Sem essa coluna, o bot ao retomar leria a fala do atendente
  como se fosse dele e passaria a se contradizer.
- **Login não atravessa.** Para o visitante vai o nome de exibição, ou "Atendente". O
  usuário de login é credencial.

### Consulta vaga: navegação e confiança

Uma palavra solta como `menu` gera um vetor de busca **difuso**: nenhum trecho casa de fato,
todos ficam com nota parecida e apenas os menos distantes sobrevivem ao piso. O agente então
responde com a confiança de sempre sobre material sem relação — e cita as fontes
corretamente, o que torna o erro ainda mais convincente.

**Subir o limiar não resolve**, e isso está medido: a pergunta legítima *"quais cursos vcs
tem"* pontuou **0.683**, e a palavra solta *"ajuda"* pontuou **0.651**. As faixas se tocam.
Um corte no meio acertaria o vago e derrubaria a pergunta mal escrita — que é justamente a de
quem mais precisa de ajuda.

Duas camadas, tratando problemas diferentes:

**Palavras de navegação** (`menu`, `opções`, `ajuda`, `atendente`, `humano`…) acionam o
roteamento por setor **antes de qualquer busca**, no modo IA também. Determinístico, sem
gastar token. Existe porque essas palavras já eram reservadas no modo roteador e não
significavam nada no modo IA — quem digitasse `menu` esperando o menu recebia o RAG
adivinhando. A comparação é **exata contra a lista**: sem isso, "não quero atendente" viraria
comando de transferência e "qual o menu do RU" viraria menu de setores.

**Aviso de confiança** para o resto. Quando a melhor nota fica a menos de `MARGEM_CONFIANCA`
(0.10) acima do piso, o prompt ganha um bloco dizendo que a busca não achou nada claramente
relacionado e que a pessoa deve ser questionada em vez de adivinhada. **Informa, não
bloqueia** — descartar acertaria o vago e erraria o informal. A margem é conservadora de
propósito: pega só o claramente fraco.

Medido nesta base: consulta vaga fica **0.04–0.10** acima do piso; pergunta real, **0.19–0.23**.

### Copiloto: o atendente pergunta ao assistente em privado

`ChatService::consultar()` parece um turno de conversa e é outra coisa. As diferenças são
todas deliberadas:

- **Não grava como `usuario`/`bot`.** Fosse assim, pergunta e resposta apareceriam para o
  visitante e entrariam no histórico do modelo, que passaria a achar que ele mesmo disse
  aquilo.
- **Sem ferramentas.** O copiloto não transfere conversa, não abre chamado e não captura
  lead em nome do atendente. Consulta não pode ter efeito colateral: quem age é a pessoa,
  depois de ler.
- **A conversa vai como CONTEXTO nas instruções**, e a pergunta do atendente é o único turno.
  Emendar a pergunta no fim do histórico parecia natural e quebrava — o histórico termina
  numa fala do visitante (que o atendente assumiu sem responder), e duas mensagens de usuário
  seguidas fazem o Gemini recusar com *invalid message sequence*.
- **Tom próprio.** O `system_prompt` do agente é feito para o visitante, sotaque incluído.
  Quem lê aqui é colega com um atendimento aberto na tela: quer o fato, não a conversa.

Registrado com `autor_tipo = 'copiloto'`: invisível ao visitante por construção (o filtro é
lista de permissão), fora do histórico do modelo (que só lê `usuario|bot|atendente`), e
disponível para quem assumir a conversa depois. As **fontes ficam gravadas** e aparecem sob a
resposta — sem isso o atendente mandaria um texto ao visitante sem saber de onde veio, que é
exatamente o que o RAG existe para evitar.

O botão **preenche o campo, nunca envia**: a resposta é rascunho, e quem fala com o visitante
é a pessoa.

> **Armadilha de configuração:** `provedores.base_url` significa coisas diferentes conforme o
> `driver`. Com `driver = gemini` (nativo), a URL correta termina em `/v1beta/models`; deixar
> ali o endpoint OpenAI-compatible (`/v1beta/openai/`) produz
> `/v1beta/openai/modelo:generateContent` e **HTTP 404 em todo o agente**. Ao trocar o driver,
> revisar o `base_url` — ou deixá-lo vazio, que usa o padrão certo de cada um.

### O que o visitante vê, e o que fica dentro

`mensagens.autor_tipo` tem cinco valores, e a distinção entre eles é de
**visibilidade**, não de estilo:

| tipo | visitante | staff | o que é |
|---|---|---|---|
| `usuario` / `bot` | ✅ | ✅ | a conversa |
| `atendente` | ✅ | ✅ | a pessoa respondendo |
| `aviso` | ✅ | ✅ | "Fulano entrou na conversa" |
| `nota` | — | ✅ | recado entre quem atende |
| `sistema` | — | ✅ | diagnóstico do provedor (`[provedor_cota 429] …`) |

O filtro do widget é uma **lista de permissão** (`atendente`, `aviso`), e isso não é
detalhe: tipo novo nasce invisível para fora, sem ninguém precisar lembrar de excluí-lo.
Fosse lista de bloqueio, esquecer uma linha vazaria nota interna ao visitante — foi
exatamente assim que os diagnósticos do provedor quase atravessaram, quando `sistema`
ainda acumulava dois significados.

A mesma regra vale para o **texto** dos avisos: o repasse entre atendentes diz ao visitante
apenas *"Estamos transferindo você para outro atendente"*. Quem passou para quem, e por quê,
é nota interna — dizer o nome de cada funcionário expõe a organização por dentro e soa como
empurrar a pessoa de mão em mão.

### Formatação: o WhatsApp manda no formato

Mensagens são guardadas com os marcadores do WhatsApp (`*negrito*`, `_itálico_`,
`~riscado~`, `` `mono` ``), porque o WhatsApp é o destino que não dá para mudar — ele os
interpreta literalmente. Guardar HTML e converter na saída perderia informação e exigiria um
conversor sem ida e volta.

`formatar_whatsapp()` é a **única** implementação, em PHP: os endpoints entregam o HTML
pronto, em vez de existir uma cópia da regra em JS no painel e outra no widget. Três versões
divergiriam na primeira correção.

Ela **escapa antes de formatar** — as únicas tags no resultado são as que ela mesma criou. É
isso, e só isso, que torna aceitável inserir o resultado com `innerHTML` no widget, cuja
regra padrão é `textContent`. Inverter essa ordem transforma a função em XSS no site do
cliente.

### Presença: quem está de fato com a tela aberta

Disponível de verdade exige **três** condições, e as três significam coisas diferentes:

| campo | o que é | quem decide |
|---|---|---|
| `atende` | recebe fila | quem administra, em Usuários |
| `disponivel` | **intenção** agora (o botão) | o próprio atendente |
| `visto_em` | **presença** de fato | ninguém — é carimbado automaticamente |

Sem a terceira, quem fechasse o navegador sem clicar em "ausente" continuaria recebendo
transferência, e o agente prometeria uma pessoa que não está lá. E intenção esquecida ligada
é o **padrão**, não a exceção: ninguém lembra de se desligar ao ir embora.

O batimento sai de graça de um mecanismo que já existia: a tela de atendimento consulta o
servidor a cada 4 segundos, e cada consulta carimba `visto_em`. Não há timer novo nem
requisição extra. Quem fecha a aba para de bater e some da fila em `PRESENCA_JANELA_SEG`
(padrão 120s — generoso de propósito, porque o navegador estrangula temporizadores em aba de
fundo, e uma janela curta derrubaria quem apenas minimizou).

Intenção desligada **vence** presença: dá para ficar com a tela aberta e marcar-se ausente
para almoçar. O contrário não vale.

**Conversa órfã.** O atendente que fecha o navegador no meio de um atendimento levaria a
conversa junto — ela ficaria dele para sempre, com o visitante esperando uma resposta que não
vem de ninguém. Passados `PRESENCA_ORFA_MIN` sem batimento, ela volta para `aguardando`. O
visitante vê o mesmo aviso neutro do repasse comum; que alguém sumiu é nota interna.

Ordem que importa: a tela **bate ponto antes** de rodar as varreduras. Fora de ordem,
`resgatarOrfas()` acharia que quem está abrindo a tela sumiu, e devolveria à fila a conversa
da própria pessoa que está olhando para ela.

### Os relógios

Quatro prazos, todos no `.env` porque são operacionais e mudam de cliente para cliente:

| variável | padrão | o que faz |
|---|---|---|
| `ESPERA_MAX_MIN` | 5 | fila sem ninguém assumir → volta ao assistente |
| `INATIVIDADE_AVISO_MIN` | 10 | visitante calado em atendimento → **nota interna** ao atendente |
| `INATIVIDADE_HUMANO_MIN` | 30 | silêncio longo em atendimento → encerra, dizendo o motivo |
| `INATIVIDADE_BOT_MIN` | 60 | conversa só com o bot, parada → encerra **em silêncio** |

O sinal de inatividade é a última mensagem **do visitante**, não a última da conversa: se o
atendente escreveu cinco vezes e ninguém respondeu, quem foi embora foi o visitante — e é
esse o caso a detectar.

Três decisões dentro disso:

- **Avisar antes de encerrar um atendimento em curso.** Encerrar por baixo do atendente seria
  grosseiro: a pessoa pode ter ido buscar um documento. A própria nota serve de marca para o
  aviso não se repetir a cada passada do worker — e quem já passou do prazo final não recebe
  bilhete nenhum, que seria um recado sobre uma conversa fechando no mesmo segundo.
- **Conversa só com o bot encerra calada.** Não há ninguém olhando, e um aviso numa aba
  abandonada só apareceria dias depois, fora de contexto.
- **Isto só é seguro porque `encerrada` deixou de ser porta trancada.** Quem voltar e
  escrever reabre a conversa com o assistente.

A varredura roda no worker **e** a cada carga do painel: as telas cobrem quando há alguém
olhando; o worker cobre justamente o contrário.

### Transporte: polling, nunca SSE

Um atendimento dura minutos, e SSE prenderia um processo PHP esse tempo todo. Em
compartilhada, com 10–30 processos no total, meia dúzia de visitantes esperando derrubaria
o site. Consulta a cada 4s custa ordens de grandeza menos, e o widget **para de consultar**
quando o modo volta a `bot` — sem isso, uma aba esquecida bateria no servidor para sempre.
SSE fica só para a resposta do bot, que dura segundos.

O polling do visitante também **não consome a cota do canal**: o limite existe para proteger
a conta do provedor de LLM, e aqui não há chamada a provedor nenhuma.

### Quem é atendente

`admin_users.atende` e `setor_id` são definidos por quem administra, em **Usuários** — é
decisão de gestão. Já `disponivel` ("estou aqui agora") é do próprio atendente, na tela de
**Atendimento**. Desmarcar `atende` zera `disponivel` junto, para não deixar alguém marcado
como online sem receber nada.

A fila procura primeiro atendentes do setor; não havendo, aceita qualquer um disponível —
um setor sem plantão nunca transferiria, e alguém que pode redirecionar internamente é
melhor que ninguém.

---

## 10. Ordem de construção

| # | Etapa | Fecha funcionando com |
|---|---|---|
| 1 | ~~**Esqueleto**~~ ✅ — `public_html/`+`app/`, classes do cofre, `schema.sql`, `migrate.php`, admin logando, `benchmark-sqlite.php` | painel acessível, zero IA |
| 2 | ~~**Provedores + Neuron AI**~~ ✅ — CRUD, `ProviderFactory`, chat cru sem RAG | conversa com Gemini ponta a ponta |
| 3 | ~~**Ingestão**~~ ✅ — jobs, worker retomável, leitores, chunker, embeddings | artefatos indexados com status no admin |
| 4 | ~~**Retrieval**~~ ✅ — `SqliteVectorStore`, FTS5, RRF + tela "testar busca" | calibrar top_k e limiar sem queimar token |
| 5 | ~~**RAG no chat**~~ ✅ — junta 2+4, citações, log de custo | agente que responde citando fonte |
| 6 | ~~**FAQ + setores**~~ ✅ curadoria, curto-circuito, leitura de conversas | atendimento com encaminhamento |
| 7 | ~~**Ferramentas**~~ ✅ registry, `http`, guardas, `depende_de`, `contato_setor`, `abrir_chamado` | encaminhamento real |
| 8 | ~~**Leads**~~ ✅ captura, `lead_destinos` com backoff e dead letter, export CSV | leads chegando no CRM |
| 9 | ~~**Widget**~~ ✅ `embed.js`, token público, canal web, limites de uso | plugável em qualquer site |
| 10 | ~~**Handoff Nível 2**~~ ✅ fila, painel do atendente, polling, disponibilidade, abandono | conversa ao vivo com gente |
| 11 | *(futuro)* WhatsApp Cloud API | — |

Parando na 8, já existe produto.

**O teste obrigatório da etapa 2 foi feito e passou** (2026-08-24): o Gemini pelo caminho
OpenAI-compatible **aguenta tool calling com streaming** — o modelo pediu a ferramenta com os
argumentos corretos, entregues em deltas ao longo do stream. O `ChatService` pode nascer com
pipeline único (stream e completo), como planejado.

---

## 10.1 Retenção e privacidade

O conteúdo de uma conversa não é um bloco só. São três classes de dado com
risco e validade muito diferentes:

| dado | risco | valor a longo prazo |
|---|---|---|
| o que foi dito (`mensagens.conteudo`) | alto — é onde o CPF aparece | cai rápido |
| o que foi citado (`mensagem_fontes`) | nenhum | alto: mostra quais documentos respondem de fato |
| estatística (`metricas`, latência, tokens) | nenhum | alto |

Apagar em bloco jogaria fora as duas últimas junto com a primeira. Por isso a
retenção tem **dois estágios**, em `app/Jobs/Retencao.php`:

1. **Anonimizar** (`config.anonimizacao_conversas_dias`) — mascara CPF, e-mail
   e telefone dentro do texto e remove `conversas.ip`. O que sobra continua
   servindo à curadoria: *"quais cursos vocês têm"* não tem dado pessoal nenhum
   e é exatamente o insumo da lista de perguntas sem resposta. Essa é a tensão
   que obriga a existir um primeiro estágio — a melhor fonte de curadoria da
   FAQ é o texto da pergunta.
2. **Expurgar** (`config.retencao_conversas_dias`) — apaga o conteúdo das
   mensagens e os parâmetros das execuções de ferramenta. A **linha** da
   mensagem é preservada de propósito: `mensagem_fontes` referencia a mensagem,
   e apagá-la levaria por cascata a informação de quais documentos respondem.

**Os dois nascem em 0 = desligado.** Apagar dado de gente por padrão numa
instalação nova seria surpresa ruim; a política é uma decisão de quem opera.

`canais.retencao_dias` sobrescreve o prazo global. Existe por causa do
WhatsApp, que muda uma peça do desenho: ali `conversas.externo_id` é o **número
de telefone** — dado pessoal que ao mesmo tempo é o *endereço*. Não dá para
apagar sem perder a forma de responder. E a pessoa volta semanas depois
esperando continuidade, então um prazo curto faz o agente perder o contexto de
uma conversa que, para ela, é a mesma.

Duas consequências práticas que valem lembrar antes de prometer algo a quem
pede exclusão: a thread continua no celular da pessoa (apagar aqui apaga só o
nosso lado), e "apaguem meus dados" no WhatsApp significa também parar de
responder ali.

`Retencao::apagarPessoa()` atende o direito de exclusão varrendo `leads`,
`conversas`, `mensagens`, `chamados` e `ferramenta_execucoes` — o dado pessoal
se espalha por cinco tabelas e atender à mão erraria alguma. Tem modo
**simulação**, e a tela obriga a passar por ele: a busca é por aproximação e um
telefone digitado errado levaria conversa de terceiro junto.

Três detalhes que só apareceram testando:

- **Onze dígitos podem ser CPF ou telefone.** Adivinhar pelo formato erra nos
  dois sentidos; conferir os dígitos verificadores resolve sem heurística. Por
  isso o CPF é mascarado primeiro, com validação de verdade.
- **`` não casa antes de `+` nem de `(`**, e o regex de telefone deixava
  sobras como `+(telefone removido)`. Âncoras de dígito (`(?<!\d)`) fazem o
  mesmo serviço sem o efeito.
- **PDO liga inteiro como TEXTO, e no SQLite todo TEXT é maior que todo
  número.** `100 >= '90'` é falso e `'1' = 1` também. Isso matou duas consultas
  em silêncio: nada vencia nunca e a busca não achava ninguém. Onde o valor é
  numérico, ou `CAST(:x AS INTEGER)`, ou o WHERE montado em PHP com placeholder
  só para valor.

## 10.2 Canal público (widget)

A premissa que define o desenho inteiro: **o token não é segredo**. Ele vai
dentro de um `<script>` na página de quem instala, visível para qualquer um que
aperte Ctrl+U. Tratá-lo como senha seria autoengano. Ele diz *qual canal
responde*; quem autoriza é a **lista de domínios** (`canais.config.dominios`).

Por isso o slug é o token: já é único, já é legível, já não é segredo — uma
segunda coluna só repetiria a função e criaria mais uma coisa para manter em
sincronia. O sufixo aleatório no slug não protege nada sozinho; só evita que
alguém enumere canais para descobrir quais existem.

E autorizar não basta. Um endpoint público que chama a LLM é **torneira ligada
na conta do provedor**: sem teto, um visitante (ou um bot de scraping) esvazia
a cota em minutos. Daí `limite_minuto` e `limite_dia`, verificados **antes** de
tocar no provedor — limite que roda depois da chamada cara não serve para nada.

Decisões que valem lembrar:

- **CORS devolve a origem específica, nunca `*`.** Com curinga, qualquer site
  poderia embutir o widget e gastar a cota do dono do canal.
- **Token inválido e origem não autorizada devolvem a mesma resposta.**
  Distinguir contaria a quem está sondando se o token existe.
- **Lista de domínios vazia bloqueia todo site externo.** Um canal recém-criado
  que aceitasse o mundo seria uma janela aberta que ninguém lembraria de fechar.
- **O slug não muda ao editar.** Ele já está colado no site do cliente; um campo
  editável ali estaria convidando a derrubar o widget em produção sem aviso.
- **A contagem do limite é por canal, não por IP puro.** Ver as armadilhas.

No widget (`public_html/embed.js`):

- **Shadow DOM**, porque ele entra em página de terceiro cujo CSS não
  controlamos: sem isolamento um `button {}` do tema do cliente desconfigura o
  chat, e um `* { box-sizing }` nosso quebraria o site dele. Verificado contra
  uma página de CSS hostil de propósito (`admin/demo-widget.php`).
- **`textContent`, nunca `innerHTML`.** O texto vem da LLM, que leu documentos
  que alguém subiu. Interpretar isso como HTML abriria XSS no site do cliente
  através do nosso widget.
- **`EventSource` fechado na mão em `onerror`.** O padrão dele é reconectar
  sozinho — e reconectar aqui significaria **refazer a pergunta**, cobrando
  outra chamada à LLM e duplicando a resposta na tela.
- **A configuração só é buscada quando alguém abre o chat.** A maioria dos
  visitantes nunca abre; uma requisição por pageview seria banda do cliente
  gasta à toa.


### Ditado: a entrada continua sendo texto

O botão de microfone usa a `SpeechRecognition` do próprio navegador. O áudio **não passa
pelo servidor**: o navegador transcreve, o texto cai no campo, e a pessoa revisa antes de
enviar. Sem upload, sem armazenamento, sem transcrição para pagar — e nada disso encosta na
máquina de mídia que o WhatsApp vai exigir, que é outro problema.

- **Não envia sozinho ao terminar de falar.** Reconhecimento erra nome próprio o tempo todo,
  e mandar "matrícula" como "matriculado" sem a pessoa ver é o que faz desistir do recurso.
- **O botão só aparece onde a API existe** — Chrome e Edge, Safari com prefixo; Firefox não
  tem. Microfone que não funciona é pior que microfone nenhum.
- **O idioma vem do agente** (`?acao=config` devolve `agentes.idioma`): `lang` errado
  transcreve mal, e o widget não tem como adivinhar qual agente responde por aquele canal.
- **O que já estava digitado é preservado** — dá para escrever metade e ditar o resto.
- No Chrome o áudio é transcrito nos servidores do Google. É o navegador da pessoa e a
  escolha é dela ao apertar o botão, mas convém saber, porque a pergunta aparece.

## 10.3 Canal WhatsApp (Cloud API)

Duas diferenças em relação ao widget mandam no desenho inteiro:

**A Meta exige 200 em segundos.** O webhook não pode esperar a LLM: valida, enfileira e
responde. Quem conversa é o worker, depois, e a resposta sai pela API de envio. Demorar faz a
Meta reenviar o evento — e reenvio vira **resposta duplicada** para a pessoa.

**Sem streaming.** A mensagem sai inteira. É para isso que `ChatService::responder()` existe
ao lado de `stream()` desde o começo: mesmo pipeline, transportes diferentes.

### Segurança do webhook

O endpoint é público, e a **assinatura é a única prova de origem**. Sem conferir o
`X-Hub-Signature-256` (HMAC-SHA256 do corpo cru com o *app secret*), qualquer um injeta
mensagem falsa e faz o agente responder a quem quiser, gastando a conta do cliente.

Canal desconhecido e assinatura inválida devolvem o **mesmo 403** — distinguir contaria a
quem sonda quais números existem na instalação. Mesma regra do canal público.

O corpo precisa ser lido **cru** (`php://input`) e conferido **antes** de qualquer
interpretação: assinar o JSON reserializado dá outro hash.

### Credenciais

`canais.credenciais_ref` guarda um **prefixo** (ex.: `WHATSAPP`), e daí saem
`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET` e
`WHATSAPP_VERIFY_TOKEN`. O prefixo permite mais de um número na mesma instalação sem inventar
tabela de segredo — e cada número pode ter seu agente.

O canal é identificado pelo `phone_number_id` de **destino** que a Meta manda no evento, não
pelo remetente.

### `Canais\Saida` — o empurrão que o widget não precisa

Os canais puxam e empurram de formas opostas:

| | como a mensagem chega ao visitante |
|---|---|
| widget web | o navegador consulta a cada 4s — **gravar já é entregar** |
| WhatsApp | ninguém consulta — sem envio, a resposta fica só no banco |

Sem esse despachante, o painel do atendente funcionaria no web e **falharia em silêncio** no
WhatsApp: o atendente vê a própria mensagem na tela e acha que respondeu. Por isso
`registrarAtendente()` e `registrarAviso()` entregam, não só gravam.

### Limites conhecidos

- **Só texto.** Áudio, imagem e documento recebem uma resposta dizendo isso — ficar em
  silêncio faria a pessoa achar que a mensagem sumiu. A máquina de mídia é o próximo passo.
- **Janela de 24h.** Fora dela a Meta recusa texto livre; só template aprovado. `Saida`
  verifica antes de tentar e registra o motivo, em vez de receber um erro que ninguém liga à
  causa.
- **Deduplicação por `wamid`, em três estados.** A Meta reenvia eventos quando não recebe 200
  depressa. `mensagens.externo_id` guarda o id recebido, sob índice único parcial, e o worker
  classifica o reenvio antes de agir:

  | Estado | Quando | O que faz |
  |---|---|---|
  | `nova` | nunca vista | responde |
  | `duplicada` | já respondida, **ou** recém-chegada | descarta em silêncio |
  | `interrompida` | gravada, sem resposta, e velha | avisa o visitante e registra |

  O terceiro estado existe por um incidente real (10/09/2026): o turno morreu no meio — o
  *kick* roda sob o limite de tempo do PHP-FPM e a chamada ao modelo estourou —, o job voltou
  à fila pelo `liberarPresos()`, e o dedup de então tratou como duplicata. A pessoa nunca
  recebeu resposta e **nada registrou isso**; o job fechou com sucesso. Só ficou visível
  porque a mensagem tinha `trace_id` e não havia linha correspondente em `turnos`.

  A carência antes de declarar `interrompida` (o dobro de `WORKER_TEMPO_MAX_S`) separa turno
  morto de webhook simultâneo ainda em processamento. E avisamos em vez de responder por
  conta própria porque regravar a fala do visitante esbarraria no índice único — o caminho
  limpo é o reenvio dela, que gera `wamid` novo.

---

## 11. Armadilhas conhecidas

**PDO liga inteiro como TEXTO, e no SQLite todo TEXT é maior que todo número.**
`100 >= '90'` é falso; `'1' = 1` também. Não dá erro: a consulta simplesmente
não acha nada. Custou dois bugs no mesmo dia — a retenção nunca vencia e a
exclusão a pedido não achava ninguém. Onde o valor é numérico, use
`CAST(:x AS INTEGER)` ou monte o `WHERE` em PHP com placeholder só para valor.

**Limite de uso precisa ter escopo.** A primeira versão contava mensagens por
IP em todos os canais, incluindo as do playground do admin (`canal_id` nulo).
Um dia de testes no painel deixaria o widget do site recusando visitante de
verdade — e um canal movimentado gastaria a cota do outro, exatamente o
contrário do que a tela promete. Apareceu no primeiro turno real pelo endpoint
público, recusado por mensagens que não eram dele.

**Classe CSS que não existe não dá erro, dá tela feia.** `.form-group` e
`var(--borda)` foram escritos duas vezes em telas diferentes; o padrão da casa
é `<label>` dentro de `.form-grid`, e as variáveis são `--border`/`--text`.
Antes de inventar classe, procure no CSS herdado.

- **O CLI pode não achar o `php.ini` que o servidor web usa.** O PHP procura o arquivo ao
  lado do binário; stacks que o mantêm em outro lugar (o Devaron usa `etc/php/php.ini`)
  fazem o CLI subir sem extensão nenhuma — sem `pdo_sqlite`, o erro que aparece é
  `could not find driver`, que não aponta para a causa. A cron do `bin/worker.php` roda
  pelo CLI e cai exatamente nisso: **a linha do cron precisa de `php -c <caminho>` ou da
  variável `PHPRC`**, senão o worker morre em silêncio enquanto o painel funciona normal.
  Conferir com `php --ini` antes de culpar o código.
- `php -S` não processa `.htaccess`: URLs limpas, bloqueio de `.sqlite` e cache de assets
  só valem sob **Apache com `mod_rewrite`**. Testar essas rotas exige servidor real — e se
  o ambiente local for nginx, as regras do `.htaccess` precisam de equivalente próprio, ou
  o que funciona na sua máquina não é o que roda em produção.
- **Vetor de modelo diferente não é comparável, e a comparação não falha.** Ela devolve
  nota sem sentido e trecho errado, em silêncio. O vetor da pergunta, o índice da FAQ e o
  das bases precisam sair do **mesmo** provedor de embedding. Trocar o modelo de embedding
  obriga a reindexar tudo, mesmo quando o número de dimensões coincide.
- **A Anthropic não tem API de embeddings.** Provedor com `driver = anthropic` só serve de
  papel `chat`; para a busca, cadastre um segundo provedor.
- **`fastcgi_finish_request()` não existe no LiteSpeed.** Lá o equivalente é
  `litespeed_finish_request()`. Código que só conhece o primeiro deixa a conexão
  aberta até o cliente desistir. Use `liberar_conexao()`, que tenta os dois e
  registra qual usou.
- **O cURL escreve "timed out", não "timeout".** Classificar erro pela palavra
  `timeout` deixava passar `cURL error 28: Operation timed out`, que caía adiante
  em "Network error" e virava indisponibilidade: o visitante lia a mensagem errada.
  Procure as duas grafias.
- **Coluna gravada como string vazia escapa do `??`.** A tela de provedores grava
  `''` para "(mesmo do chat)", e `$a ?? $b` só troca nulo: o driver de embedding
  resolvia vazio e caía no `default` do `match`, montando o embedder da OpenAI
  com a chave do Gemini. Para "vazio ou nulo", `?:`.
- **Variável de tela definida antes do `head.php` pode ser sobrescrita.** O
  `sidebar.php` usa `$grupos`, `$titulo`, `$itens`, `$arquivo` e `$menuItem` no
  escopo global. A tela de Infraestrutura quebrou com erro fatal por guardar os
  próprios grupos em `$grupos`. Nome específico da tela resolve.
- **Em CLI, `display_errors` desligado transforma erro fatal em silencio absoluto.** O
  `bootstrap.php` força `stderr` quando `PHP_SAPI === 'cli'` — sem isso, `migrate.php`
  com `.env` ausente não imprime uma linha sequer, embora a mensagem exista.
- **"Já gravada" não é "já respondida".** Dedup que olha só a existência do id externo
  engole mensagem cujo turno morreu no meio. Ver §10.3.
- SQLite não permite ALTER de `CHECK`; enumerações validam no PHP.
- Índice sobre coluna incremental não pode ficar no `schema.sql` (roda antes da migração).
- Coluna nova depois de instância no ar precisa de `garantir_colunas()` no `migrate.php` —
  `CREATE TABLE IF NOT EXISTS` não altera tabela existente.
- `$_FILES` de upload múltiplo vem transposto; remontar arquivo a arquivo.
- Curto-circuito na ordem certa: `empty()` ou `(!$x || $x['k'])`, nunca `$x['k'] !== null && $x`.

---

## 12. Guardrails e observabilidade

Dois termos que circulam muito e significam coisas bem diferentes por aqui.

### Guardrails: existe padrão?

A **implementação** é livre — não há norma dizendo "use allowlist de host" ou "limite
iterações". O que existe:

- **Normas regulatórias** dizem o *quê*, nunca o *como*: ISO/IEC 42001 e 23894, NIST AI RMF,
  EU AI Act (um bot de atendimento cai tipicamente em "risco limitado", cuja obrigação
  principal é transparência — deixar claro que se fala com uma máquina) e a **LGPD**, que já
  se aplica aqui por causa da captura de leads, independentemente de IA.
- **Convenção prática de mercado**: o **OWASP Top 10 for LLM Applications**. É a lista
  contra a qual vale auditar. As categorias que nos tocam: *Prompt Injection* (inclusive a
  indireta), *Insecure Output Handling*, *Excessive Agency* e *Sensitive Information
  Disclosure*.
- **Ferramentas dedicadas** existem no ecossistema (NeMo Guardrails, Guardrails AI, Llama
  Guard, Bedrock Guardrails, Azure AI Content Safety), mas quase todas são Python. Em PHP não
  há equivalente maduro, e o Neuron não oferece nada nessa linha — daí tudo aqui ser escrito
  à mão.

### As duas famílias

O código já documenta a distinção em `Tools/Executor.php`: a trava de ordem "é a única que
não depende de o modelo obedecer — as outras três (descrição, parâmetro obrigatório, prompt)
são pedidos; esta é uma recusa."

**Persuasivos** (texto, em `Llm/PromptBuilder.php::guardrails()`): idioma, proibição de
inventar número e contato, proibição de prometer encaminhamento inexistente, aviso de busca
fraca, regra contra injeção indireta. O Neuron apenas transporta. Se o modelo desobedecer,
nada acontece.

> Caso especial: a **frase de recusa é fixa**, não gerada. Era a sentença mais produzida do
> sistema e escorregou em produção ("encarecesse" no lugar de "encaminhasse"). É um guardrail
> que funciona *removendo o modelo da equação* — texto que não passa pelo modelo não erra.

**Estruturais** (código que recusa, independente do que o modelo queira):

| Guardrail | Onde | O que faz |
|---|---|---|
| `UrlGuard` | `Tools/UrlGuard.php` | Allowlist de host + bloqueio de faixa de IP interna, checando o IP **resolvido**. Proteção contra SSRF. |
| Trava de ordem | `Tools/Executor.php` | Ferramenta com pré-requisito não roda se ele não rodou nesta conversa. |
| Teto de iterações | `Tools/Executor.php` | `agentes.max_iteracoes_tool` por turno. Antes o laço era do Neuron, com o limite dele — o campo da tela era um botão que mentia. |
| Validação de parâmetro | `Tools/Executor.php` | Obrigatoriedade, tipo, conversão. |
| Corte por limiar | `Rag/Retriever.php` | Trecho fraco nem entra no prompt — o guardrail age **antes** de o modelo ver. |
| Cerca do material | `Llm/PromptBuilder.php` | Trecho do RAG delimitado e sanitizado; ver seção 7. |
| Verificação de fontes | `Llm/PromptBuilder.php` | Citação de trecho inexistente é descartada em vez de exibida quebrada. |

Vários rodam **sem o Neuron ser chamado**: o corte por limiar acontece antes, e o modo
roteador e a FAQ curada devolvem resposta sem tocar no modelo. Resposta que não passa por
geração não tem o que alucinar.

### Observabilidade: aqui existe padrão de verdade

Diferente de guardrails, há um padrão consolidado: **OpenTelemetry (OTel)**, da CNCF, neutro
de fornecedor. Para IA existem convenções semânticas de GenAI (atributos `gen_ai.*`) e um
ecossistema dedicado (Langfuse, LangSmith, Helicone, Arize Phoenix).

A distinção que importa: **monitoramento** responde perguntas que você já sabia fazer;
**observabilidade** é responder perguntas *novas* sem publicar código novo. Os três pilares
são logs, métricas e traces — e o *trace* é o pilar que separa os dois conceitos.

### O que existe hoje

Implementado em 2026-09-09. Três peças:

| Peça | Onde | Papel |
|---|---|---|
| `Turno` | `app/Turno.php` | O contexto de um turno: um `trace_id` e o tempo de cada etapa. |
| `Log` | `app/Log.php` | Uma linha de JSON por evento, com `trace` e `conversa` carimbados. |
| `ObservadorNeuron` | `app/Llm/ObservadorNeuron.php` | Ouve o que acontece dentro da chamada ao modelo. |

**Um turno = um trace.** O id nasce na borda — quem conhece o canal, ou seja `api/chat.php`,
`api/publico.php` e o `Worker` — e acompanha tudo: a linha em `mensagens`, as linhas em
`ferramenta_execucoes`, cada evento de log e a linha de resumo em `turnos`.

A tabela **`turnos`** guarda uma linha por resposta, com o **caminho** tomado
(`roteador` | `faq` | `rag`) e o tempo quebrado em `ms_embedding`, `ms_busca`,
`ms_inferencia` e `ms_ferramentas`. Separar o caminho é o que permite ver quanto do
atendimento sai **sem custo de inferência** — roteador e FAQ não chamam o modelo.

`status` distingue três desfechos, e o terceiro importa: `ok`, `erro` e **`degradado`** — o
modelo falhou, mas a pessoa saiu com um caminho pelo menu. É a diferença entre o atendimento
cair e o atendimento se defender.

### De onde vem cada medida

- **Busca e embedding**: do `Retriever`, que já media e cujo resultado o `ChatService`
  guardava em `$tempoBusca` sem ninguém ler. Era campo morto; agora serve.
- **Ferramentas**: do `Executor`, que mede o trabalho de verdade — HTTP, banco, e-mail. O
  Neuron sabe quando o modelo *pediu* a ferramenta; só nós sabemos quanto ela levou.
- **Inferência**: do `EventBus` do Neuron, via `ObservadorNeuron`. Acumula, porque um turno
  com ferramenta tem **mais de uma** inferência: o modelo é chamado, pede a ferramenta, e é
  chamado de novo com o resultado.

> **Por que `setDefaultObserver` e não `observe`.** Os nós do agente emitem com um
> `workflowId` próprio, tirado do estado da execução (`Workflow\Node::emit()`), então um
> observer de escopo nulo nunca receberia esses eventos. Mas ao emitir num escopo não
> inicializado o `EventBus` registra ali o observer padrão — definir o padrão uma vez alcança
> todo escopo novo sem saber o id de nenhum.

### As duas armadilhas do estado global

Ambas tratadas no `finally` de `Worker::processar()`, e ambas só aparecem no worker CLI, que
é um processo longo atendendo vários jobs em sequência:

1. **Turno vazado.** Turno que morre por exceção antes do `finalizar()` deixaria o id
   pendurado, e o job seguinte gravaria tudo sob o trace do anterior — investigação apontando
   para a conversa errada é pior que investigação sem pista.
2. **Observers acumulados.** O `EventBus` guarda observers por escopo em propriedade
   estática, e cada execução do agente cria um escopo. Numa requisição web isso morre com o
   processo; no worker o mapa só cresce.

### Privacidade

**Conteúdo não entra em log nem em `turnos`.** Nem pergunta, nem resposta, nem trecho
recuperado. O texto vive em `mensagens`, sob a anonimização e o expurgo configurados em
`config`; duplicá-lo criaria uma segunda cópia fora do alcance dessas regras, que ninguém
lembraria de apagar. Só metadado: duração, contagem, id, status, causa. O corte por campo em
`Log::MAX_TEXTO` é rede de segurança para quando alguém esquecer disso, não permissão.

Pela mesma razão a linha de `turnos` **sobrevive ao expurgo**, como `metricas`: não tem dado
pessoal e tem valor longo. Mas cai junto com a conversa quando a conversa é apagada de fato.

### A tela

`admin/turnos.php`, em Sistema › Diagnóstico. Restrita a `admin`, como Logs.

Responde três perguntas, nesta ordem de urgência:

1. **O atendimento está saudável?** Mediana e p95 (a média não serve: uma única chamada que
   bateu no timeout de 40s desloca o dia inteiro), taxa de erro, quantos degradaram, e a
   proporção respondida **sem chamar o modelo** — subir esse número é a forma mais direta
   de baixar a conta sem piorar o atendimento.
2. **Onde o tempo está indo?** Média por etapa, em barras, separando rede até o fornecedor,
   busca local e endpoint externo lento.
3. **O que aconteceu neste atendimento?** A visão de um `trace_id` só: o resumo do turno, as
   mensagens e as ferramentas chamadas, com link para a conversa. É a razão de a tabela
   existir; as duas primeiras visões servem para chegar até aqui, porque ninguém abre um
   diagnóstico já sabendo qual turno investigar.

O percentil sai de `OFFSET` sobre o resultado ordenado — o SQLite embarcado no PHP de
hospedagem compartilhada não traz `percentile()`, e ordenar em PHP exigiria carregar a
coluna inteira na memória.

O detalhe de um trace fica **fora** do filtro de período: quem chega por um link não deveria
precisar acertar o período antes de ver o que procurava.

### Infraestrutura: o chão em que o sistema pisa

A observabilidade de turno diz onde o tempo de UM atendimento foi gasto. Ela
não responde se o worker está vivo, se falta extensão ou se o servidor web
encerra processos — e foi isso que a primeira instalação de produção, na
Hostinger, precisou saber em 11/09/2026: o WhatsApp só devolvia o aviso de turno
interrompido, o playground ficou bem mais lento que no ambiente local e uma
indexação de 300 KB demorou demais.

Três peças, com duas faces (`bin/diagnostico.php` e Sistema › Infraestrutura)
lendo a mesma classe, `DiagnosticoInfra` — como o `DiagnosticoProvedor` já fazia:

- **Batimento do worker** (`Jobs/Batimento`). Cada execução abre um registro
  ao começar e fecha ao terminar, num JSON com as últimas 50 em `storage/` —
  arquivo e não tabela, porque o worker roda a cada cinco minutos mesmo com a
  fila vazia e isso seria escrita constante no mesmo SQLite do chat. O
  desfecho diz o que houve: fechado pelo worker é `ok` ou `com falhas`;
  fechado pela função de desligamento é `fatal` ou `abortada`; **nunca
  fechado é processo morto de fora**, porque `register_shutdown_function` roda
  em erro fatal mas não quando o servidor mata o PHP.
- **Sonda** (`api/sonda.php`). Faz o caminho do kick — responde 202, libera a
  conexão e segue —, mas em vez de chamar o modelo marca "ainda vivo" a cada
  segundo. Mede em que segundo o servidor web encerra um processo em segundo
  plano. `liberar=0` reproduz o comportamento antigo, para comparar os dois no
  mesmo servidor.
- **Verificações** de ambiente, banco, worker e rede, cada uma existindo para
  confirmar ou descartar uma hipótese — o OPcache do site, a latência de
  escrita **no diretório do banco** (não no temporário, que num host
  compartilhado pode ser outro disco), e o custo de um **lote real** de
  embedding, do mesmo tamanho que a indexação usa (`WORKER_LOTE`) — é o que dá
  quanto custa indexar cada grupo de trechos.

> **O lote que o §6 prometia e o código não fazia.** O desenho da ingestão
> sempre disse "embed em LOTE", mas o `Ingestor` chamava `embedText()` um trecho
> por vez. Na Hostinger, com o plano gratuito do Gemini a ~1 s por chamada, um
> grupo de 25 trechos passava do orçamento de 20 s do worker, e um arquivo de
> 300 KB levava mais de uma hora. Desde 11/09/2026 cada grupo vai numa chamada:
> `ProviderFactory::embeddarVarios()`, com `batchEmbedContents` no Gemini — o
> Neuron só faz lote para a OpenAI. Os vetores saem idênticos aos individuais,
> então a troca não exigiu reindexar nada.

As duas faces não são redundantes: **o PHP da linha de comando não é o do
site.** OPcache, liberação de conexão e o comportamento do servidor web só
existem no processo web; a CLI diz, nesses itens, para olhar no painel.

> **A correção que veio junto.** O webhook e o kick liberavam a conexão só com
> `fastcgi_finish_request()`, que não existe no LiteSpeed — o servidor da
> Hostinger, onde o equivalente é `litespeed_finish_request()`. As duas
> chamadas passaram a usar `liberar_conexao()`, que tenta as duas e registra
> qual usou. Se isso resolve o WhatsApp é o que a sonda responde: se ela
> morrer mesmo com a conexão liberada, o limite é do servidor, e o caminho é
> tirar o trabalho longo do processo web.

### O que ainda falta

1. **Custo.** Os tokens são gravados, mas não há preço por modelo — `provedores.custo_*` foi
   removido em 2026-08-27 justamente por nunca ter sido preenchido. Com a tela de diagnóstico
   já no ar, agora há onde exibir: a coluna e a multiplicação passam a fazer sentido juntas.
2. **Tokens no streaming.** No caminho de stream o consumo vem no evento final do handler, e
   não há gancho confiável — `tokens_in`/`out` ficam nulos ali.

> Exemplo do que a falta de observabilidade custou: até 2026-09-09, `vetorDaPergunta()`
> embutia a pergunta com o provedor de **chat** do agente, enquanto os índices saíam do de
> **embedding**. A comparação entre espaços vetoriais diferentes não dá erro — dá nota sem
> sentido. E quando a chamada falhava de vez, o `catch` escrevia num `error_log` sem id de
> conversa e devolvia `null`: a FAQ curada simplesmente parava de responder, calada. Hoje
> essa falha sai como `{"ev":"embedding_pergunta_falhou","trace":...,"conversa_id":...}`.
