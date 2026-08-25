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
│   ├── Llm/
│   │   ├── ProviderFactory.php   monta o provider Neuron a partir da tabela provedores
│   │   ├── ChatService.php       orquestração; modos stream e completo
│   │   ├── PromptBuilder.php     system + contexto + fontes + guardrails
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
provedores          slug, driver(openai|anthropic|ollama|gemini), base_url,
                    auth_ref,                 -- nome da var no .env, NUNCA a chave
                    modelo_chat,
                    base_url_embedding, driver_embedding,   -- embeddings por outro caminho
                    modelo_embedding, dimensoes,
                    suporta_tools, suporta_stream, ativo
```

> `driver` + `base_url` + `modelo` cobre Gemini, Groq, DeepSeek e OpenRouter pelo caminho
> OpenAI-compatible, sem classe nova por fornecedor.

> **Embeddings têm endpoint próprio.** Medido em 2026-08-24: a camada OpenAI-compatible do
> Gemini **recusa `task_type`** (HTTP 400, "Unknown name"), enquanto o endpoint nativo aceita
> e de fato aplica — `RETRIEVAL_DOCUMENT` e `RETRIEVAL_QUERY` produzem vetores diferentes.
> Como usar o tipo certo de cada lado é recall de graça, o chat vai pelo compat e o embedding
> pelo nativo. Provedor que faça tudo por um caminho só deixa as duas colunas em branco.

### Agentes

```sql
agentes             slug, nome, descricao, provedor_id, modelo,
                    system_prompt, temperatura, max_tokens, reasoning_effort,
                    top_k, limiar_similaridade, max_iteracoes_tool,
                    usa_rag, usa_faq, captura_lead, lead_destino_id,
                    publico, token_publico, ativo
agente_bases        agente_id, base_id
agente_ferramentas  agente_id, ferramenta_id, config_override(JSON)
```

> `top_k` e `limiar_similaridade` ficam **no agente**, não globais: suporte quer recall alto,
> política interna quer precisão. É o botão que mais move qualidade na prática.

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
  ├─ RECUPERAÇÃO
  │    embed da pergunta (task_type = RETRIEVAL_QUERY)
  │    FTS5/BM25 + cosseno → fusão RRF → top_k → corte por limiar
  │    match de FAQ acima do limiar alto? → responde a resposta curada e encerra
  │
  ├─ PROMPT
  │    system do agente + guardrails + trechos citados + histórico + schema das ferramentas
  │
  ├─ LOOP DE FERRAMENTAS  (máx. max_iteracoes_tool, orçamento de tokens, timeout total)
  │    modelo pede ferramenta → valida params → checa depende_de → executa → devolve
  │    resultado marcado como DADO EXTERNO, nunca como instrução
  │
  ├─ RESPOSTA
  │    stream (web) ou completa (WhatsApp) — mesmo pipeline, transportes diferentes
  │
  └─ grava mensagem + fontes + execuções + tokens/custo/latência
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
- **`429` pausa o job**, não marca erro. Backoff respeitando `Retry-After`.
- **Nunca segurar transação durante chamada HTTP.** Chama a API fora, grava dentro,
  transação curta. É o bug nº 1 desse tipo de app.
- **Leitura de PDF página a página** e teto de upload configurável — parser guloso derruba
  o processo em host com 128–256MB.

---

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
- SQLite não permite ALTER de `CHECK`; enumerações validam no PHP.
- Índice sobre coluna incremental não pode ficar no `schema.sql` (roda antes da migração).
- Coluna nova depois de instância no ar precisa de `garantir_colunas()` no `migrate.php` —
  `CREATE TABLE IF NOT EXISTS` não altera tabela existente.
- `$_FILES` de upload múltiplo vem transposto; remontar arquivo a arquivo.
- Curto-circuito na ordem certa: `empty()` ou `(!$x || $x['k'])`, nunca `$x['k'] !== null && $x`.
