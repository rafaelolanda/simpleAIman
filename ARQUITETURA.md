# simpleAIman — Arquitetura

Plataforma de agentes de IA com RAG, ferramentas configuráveis e múltiplos canais. Um "Dify simplificado" em PHP, feito pra rodar em hospedagem compartilhada de baixo orçamento e ser clonado por cliente.

Primeiro case: assistente da URI (dúvidas de alunos e candidatos, simulação de mensalidade, captação de leads). O produto, porém, é genérico — nada específico de universidade entra no código.

---

## 1. Premissas

- **PHP 8.3+, sem framework.** Composer entra só como fornecedor de bibliotecas. `vendor/` vai **commitado** — deploy continua sendo `git pull`, sem build.
- **SQLite**, com o acesso vetorial isolado atrás de interface (troca por pgvector é substituir uma classe).
- **Document root isolado** em `public_html/`; `app/`, `bin/` e `database/` fora do alcance HTTP.
- **Uma instância por cliente.** Cada um tem seu `.env`, seu `.sqlite` e seus uploads, fora do controle de versão.
- **Multi-agente**: vários agentes por instância, cada um com prompt, modelo, bases e ferramentas próprios.

### Reaproveitado da base institucional (quase sem tocar)

`Database.php` (PDO SQLite, WAL, foreign_keys), `Auth.php` (login, bloqueio por tentativas, log de ações), `Mailer.php`, `Metrics.php`, shell do admin (partials, CSS, padrão de CRUD com CSRF e flash), `migrate.php` idempotente, helper de link de WhatsApp.

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
│   │   ├── ProviderFactory.php   monta config LLPhant a partir da tabela provedores
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
│   └── benchmark-sqlite.php      teste de concorrência no host real
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
                    modelo_chat, modelo_embedding, dimensoes,
                    suporta_tools, suporta_stream, ativo
```

> `driver` + `base_url` + `modelo` cobre Gemini, Groq, DeepSeek e OpenRouter pelo caminho
> OpenAI-compatible, sem classe nova por fornecedor.

### Agentes

```sql
agentes             slug, nome, descricao, provedor_id, modelo,
                    system_prompt, temperatura, max_tokens,
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
conversas           canal_id, agente_id, externo_id, titulo,
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
jobs                tipo, payload(JSON), status, progresso(JSON),
                    tentativas, proxima_execucao_em, lock_ate, erro
usuarios            + setor_id, atende, disponivel     -- Nível 2 do handoff
log_acoes  metricas
```

> **Sem `CHECK`** nas enumerações (`driver`, `tipo`, `status`). SQLite não permite ALTER de
> constraint e mudar o conjunto exigiria recriar a tabela. Validação fica no PHP.

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

---

## 10. Ordem de construção

| # | Etapa | Fecha funcionando com |
|---|---|---|
| 1 | **Esqueleto** — `public_html/`+`app/`, classes do cofre, `schema.sql`, `migrate.php`, admin logando | painel acessível, zero IA |
| 2 | **Provedores + LLPhant** — CRUD, `ProviderFactory`, chat cru sem RAG | conversa com Gemini ponta a ponta |
| 3 | **Ingestão** — jobs, worker retomável, leitores, chunker, embeddings | artefatos indexados com status no admin |
| 4 | **Retrieval** — `SqliteVectorStore`, FTS5, RRF + tela "testar busca" | calibrar top_k e limiar sem queimar token |
| 5 | **RAG no chat** — junta 2+4, citações, log de custo | agente que responde citando fonte |
| 6 | **FAQ + setores** — curadoria, curto-circuito, `contato_setor`, `abrir_chamado` | atendimento com encaminhamento |
| 7 | **Ferramentas** — registry, `http`, `tabela`, guardas, `depende_de` | simulação de mensalidade |
| 8 | **Leads** — captura, `lead_destinos`, integração CRM/RD | leads chegando no CRM |
| 9 | **Widget** — `embed.js`, token público, canal web | plugável em qualquer site |
| 10 | *(futuro)* WhatsApp Cloud API | — |

Parando na 8, já existe produto.

**Etapa 2 tem um teste obrigatório antes de seguir:** confirmar que o Gemini pelo caminho
OpenAI-compatible aguenta **tool calling com streaming**. É onde essa camada de compatibilidade
costuma falhar, e é exatamente o mais usado. Se falhar, decide-se ali entre driver nativo ou
modo não-streaming.

---

## 11. Armadilhas conhecidas

- `php -S` não processa `.htaccess` — URLs limpas só sob Apache/Laragon.
- SQLite não permite ALTER de `CHECK`; enumerações validam no PHP.
- Índice sobre coluna incremental não pode ficar no `schema.sql` (roda antes da migração).
- Coluna nova depois de instância no ar precisa de `garantir_colunas()` no `migrate.php` —
  `CREATE TABLE IF NOT EXISTS` não altera tabela existente.
- `$_FILES` de upload múltiplo vem transposto; remontar arquivo a arquivo.
- Curto-circuito na ordem certa: `empty()` ou `(!$x || $x['k'])`, nunca `$x['k'] !== null && $x`.
