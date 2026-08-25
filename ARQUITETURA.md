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

## 9. Handoff (Nível 1, com o schema do Nível 2 pronto)

**Agora:** `contato_setor` devolve telefone/e-mail/WhatsApp/horário do setor certo;
`abrir_chamado` registra e dispara e-mail ao responsável. Funciona 24/7, sem ninguém de plantão.

**Depois (Nível 2):** `conversas.modo = humano` **cala o bot** — mensagem que chega é gravada
mas não vai pra LLM. Atendente assume pelo painel vendo o histórico completo, inclusive as
ferramentas que rodaram.

- **Chat com humano usa polling de 3–5s, nunca SSE.** Atendimento de 10min segura um processo
  PHP esse tempo todo; cinco visitantes esperando derrubam o servidor.
- Ninguém disponível → cai automaticamente no Nível 1.
- Abandono após N minutos → converte em chamado e avisa.
- Volta pro bot preservando contexto — é pra isso que existe `mensagens.autor_tipo`.

### O WhatsApp torna o Nível 2 obrigatório

No widget web o Nível 1 basta: a pessoa fecha a página e recebe retorno por e-mail depois.
**No WhatsApp, Instagram e Direct, não.** A conversa continua aberta na mão dela e a resposta
é esperada *naquela thread* — "alguém vai te retornar por e-mail" ali é resposta errada.

Por isso o canal da Meta (etapa 10) depende do Nível 2 pronto, e não o contrário. O atendente
precisa de:

- **histórico completo da conversa**, incluindo o que o bot respondeu e quais ferramentas
  rodaram — ele entra no meio e precisa saber o que já foi dito;
- **responder dentro da mesma thread**, o que exige o driver de saída do canal;
- **atenção à janela de 24h** da Meta: fora dela só template aprovado, e o painel precisa
  avisar antes de o atendente escrever uma resposta que não será entregue
  (`conversas.ultima_msg_usuario_em` existe para isso);
- **saber de qual canal veio**, porque o tom e o formato mudam entre widget e WhatsApp.

O que já está pronto no schema e no código para isso: `conversas.modo`, `atendente_id`,
`aguardando_desde`, `ultima_msg_usuario_em`, `mensagens.autor_tipo`, `admin_users.atende` e
`disponivel`, a tabela `canais`, e `ChatService::botDeveResponder()`, que cala o bot quando a
conversa está em modo humano — já implementado e testado.

Falta a interface: leitura de histórico, tela de atendimento (assumir, responder, devolver ao
bot), endpoint de polling e o driver de saída por canal.

> **A tela de leitura de conversas vale antes disso.** Mesmo só com o widget web, ler o que as
> pessoas perguntaram é a melhor fonte para saber o que colocar na FAQ — e é o insumo direto
> da etapa 6.

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
| 9 | **Widget** — `embed.js`, token público, canal web | plugável em qualquer site |
| 10 | *(futuro)* WhatsApp Cloud API | — |

Parando na 8, já existe produto.

**O teste obrigatório da etapa 2 foi feito e passou** (2026-08-24): o Gemini pelo caminho
OpenAI-compatible **aguenta tool calling com streaming** — o modelo pediu a ferramenta com os
argumentos corretos, entregues em deltas ao longo do stream. O `ChatService` pode nascer com
pipeline único (stream e completo), como planejado.

---

## 11. Armadilhas conhecidas

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
