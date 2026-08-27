-- =====================================================================
-- simpleAIman — schema completo
--
-- Fonte única e completa do banco. Idempotente: pode rodar quantas vezes
-- quiser (database/migrate.php aplica este arquivo e depois semeia).
--
-- Convenções:
--   * Enumerações (tipo, status, driver, modo, efeito) NÃO usam CHECK.
--     SQLite não permite ALTER de constraint, então enumerar aqui viraria
--     dívida: mudar o conjunto exigiria recriar a tabela. Validação em PHP,
--     via valor_em() no helpers.php. Ver ARQUITETURA.md §4.
--   * Datas em TEXT no formato 'Y-m-d H:i:s' (ou 'Y-m-d' quando só dia).
--   * Coluna acrescentada aqui DEPOIS de existir instância no ar precisa
--     também de garantir_colunas() no migrate.php — CREATE TABLE IF NOT
--     EXISTS não altera tabela existente.
-- =====================================================================


-- =====================================================================
-- 1. Configuração, acesso e operação
-- =====================================================================

CREATE TABLE IF NOT EXISTS config (
    id                  INTEGER PRIMARY KEY CHECK (id = 1),
    nome_instancia      TEXT NOT NULL DEFAULT 'simpleAIman',
    agente_padrao_id    INTEGER,
    cor_primaria        TEXT NOT NULL DEFAULT '#2563eb',
    cor_secundaria      TEXT NOT NULL DEFAULT '#0f172a',
    logo                TEXT,

    -- Retencao do conteudo das conversas, em DOIS estagios. Ambos nascem em
    -- 0 = DESLIGADO: apagar dado de gente por padrao seria surpresa ruim.
    --
    -- Estagio 1, anonimizar: mascara CPF, e-mail e telefone dentro do texto e
    -- remove o IP. O que sobra continua servindo a curadoria — "quais cursos
    -- vcs tem" nao tem dado pessoal nenhum e e exatamente o insumo da lista de
    -- perguntas sem resposta.
    --
    -- Estagio 2, expurgar: apaga o conteudo das mensagens. Preserva conversa,
    -- fontes citadas e metricas, que nao tem dado pessoal e tem valor longo:
    -- quantas conversas, quais documentos respondem de fato, custo e latencia.
    anonimizacao_conversas_dias INTEGER NOT NULL DEFAULT 0,
    retencao_conversas_dias INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario             TEXT NOT NULL UNIQUE,
    senha_hash          TEXT NOT NULL,
    nome                TEXT,
    email               TEXT,
    admin_master        INTEGER NOT NULL DEFAULT 0,
    -- O que a pessoa ENXERGA no painel: admin | editor | atendente.
    -- Independente de `atende`, que diz se ela recebe transferências: um
    -- administrador pode atender também. Validado no PHP (Painel::PAPEIS),
    -- sem CHECK — SQLite não permite ALTER de constraint.
    papel               TEXT NOT NULL DEFAULT 'admin',
    -- Nível 2 do handoff: quem atende, de qual setor, e se está disponível agora.
    -- Nasce no schema mesmo sem a tela existir; enfiar depois obrigaria a mexer
    -- em toda conversa já gravada. Ver ARQUITETURA.md §9.
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    atende              INTEGER NOT NULL DEFAULT 0,
    disponivel          INTEGER NOT NULL DEFAULT 0,
    -- Presenca automatica: carimbada pelo painel a cada consulta. `disponivel`
    -- sozinho e INTENCAO, e intencao esquecida ligada faz o agente transferir
    -- para uma sala vazia. Disponivel de verdade = atende + disponivel +
    -- visto_em recente.
    visto_em            TEXT,
    reset_token_hash    TEXT,
    reset_expira        TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_tentativas (
    identificador       TEXT PRIMARY KEY,          -- usuario|ip
    tentativas          INTEGER NOT NULL DEFAULT 0,
    bloqueado_ate       TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_logs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id       INTEGER REFERENCES admin_users (id) ON DELETE SET NULL,
    acao                TEXT NOT NULL,
    detalhes            TEXT,
    ip                  TEXT,
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_logs_criado ON admin_logs (criado_em DESC);

CREATE TABLE IF NOT EXISTS metricas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL,
    referencia_id       INTEGER NOT NULL DEFAULT 0,
    data                TEXT NOT NULL,             -- Y-m-d
    contador            INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    UNIQUE (tipo, referencia_id, data)
);

CREATE INDEX IF NOT EXISTS idx_metricas_data ON metricas (data DESC);

-- Fila de trabalho. Um único worker (bin/worker.php) atende todos os tipos.
-- `progresso` guarda o ponto de retomada: o worker processa um lote, grava, e
-- sai limpo antes do max_execution_time. Ver ARQUITETURA.md §6.
CREATE TABLE IF NOT EXISTS jobs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL,             -- ingestao | reindex | entrega_lead | entrada_canal
    payload             TEXT,                      -- JSON
    progresso           TEXT,                      -- JSON: {offset, total, ...}
    status              TEXT NOT NULL DEFAULT 'pendente',  -- pendente|processando|ok|erro|pausado
    prioridade          INTEGER NOT NULL DEFAULT 0,
    tentativas          INTEGER NOT NULL DEFAULT 0,
    proxima_execucao_em TEXT,
    lock_ate            TEXT,
    erro                TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_fila ON jobs (status, proxima_execucao_em, prioridade DESC);


-- =====================================================================
-- 2. Setores — espinha de roteamento
--
-- Liga FAQ, ferramentas, chamados e atendentes. Serve três usos ao mesmo
-- tempo: encaminhar contato, destinar o e-mail do chamado ao responsável
-- certo, e pré-dispor o atendente do Nível 2.
--
-- Só canal institucional aqui: o bot fala com público externo.
-- =====================================================================

CREATE TABLE IF NOT EXISTS setores (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    -- Texto que o modelo lê para escolher o setor. É prompt, não documentação.
    descricao_llm       TEXT,
    email               TEXT,
    telefone            TEXT,
    whatsapp            TEXT,
    ramal               TEXT,
    horario_atendimento TEXT,
    local               TEXT,
    responsavel_nome    TEXT,
    padrao              INTEGER NOT NULL DEFAULT 0,   -- destino quando nenhum setor casa
    ativo               INTEGER NOT NULL DEFAULT 1,
    ordem               INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL,
    -- Contato desatualizado é pior que contato nenhum: o admin destaca o que
    -- não é revisado há muito tempo.
    atualizado_em       TEXT
);

CREATE INDEX IF NOT EXISTS idx_setores_ativo ON setores (ativo, ordem);


-- =====================================================================
-- 3. Provedores de LLM
--
-- driver + base_url + modelo cobre Gemini, Groq, DeepSeek e OpenRouter pelo
-- caminho OpenAI-compatible, sem classe nova por fornecedor.
-- A CHAVE nunca fica aqui: auth_ref guarda o NOME da variável no .env.
-- =====================================================================

CREATE TABLE IF NOT EXISTS provedores (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    driver              TEXT NOT NULL DEFAULT 'openai',   -- openai|anthropic|ollama|gemini
    base_url            TEXT,
    auth_ref            TEXT,                             -- nome da var no .env
    modelo_chat         TEXT,

    -- Embeddings têm endpoint e driver PRÓPRIOS, separados do chat.
    --
    -- Medido em 2026-08-24 contra o Gemini: a camada OpenAI-compatible recusa
    -- `task_type` com HTTP 400 ("Unknown name"), enquanto o endpoint nativo
    -- aceita e de fato aplica (RETRIEVAL_DOCUMENT e RETRIEVAL_QUERY produzem
    -- vetores diferentes). Como usar o tipo certo em cada lado é recall de
    -- graça, o chat vai pelo compat e o embedding pelo nativo — daí duas
    -- colunas em vez de uma. Provedor que faça tudo por um caminho só deixa
    -- estas em branco e cai no base_url/driver de cima.
    base_url_embedding  TEXT,
    driver_embedding    TEXT,                             -- openai|gemini_nativo|ollama

    modelo_embedding    TEXT,
    dimensoes           INTEGER,

    -- Preco por MILHAO de tokens, na moeda do painel. Fica no provedor
    -- porque muda por modelo e por fornecedor, e precisa ser editavel sem
    -- deploy: tabela de preco de LLM muda sozinha, sem avisar ninguem.
    -- Zero significa "nao calcular custo" — melhor campo vazio que numero
    -- inventado numa tela que alguem vai usar para decidir orcamento.
    custo_entrada_milhao REAL NOT NULL DEFAULT 0,
    custo_saida_milhao   REAL NOT NULL DEFAULT 0,

    suporta_tools       INTEGER NOT NULL DEFAULT 1,
    suporta_stream      INTEGER NOT NULL DEFAULT 1,
    ativo               INTEGER NOT NULL DEFAULT 1,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);


-- =====================================================================
-- 4. Agentes
-- =====================================================================

CREATE TABLE IF NOT EXISTS agentes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    descricao           TEXT,
    provedor_id         INTEGER REFERENCES provedores (id) ON DELETE SET NULL,
    modelo              TEXT,
    -- 'ia' usa o provedor; 'roteador' NAO chama provedor nenhuma vez: menu de
    -- setores, contato e fila. Serve a cliente que nao quer pagar LLM, e e
    -- tambem o caminho de degradacao quando o provedor cai.
    modo                TEXT NOT NULL DEFAULT 'ia',
    system_prompt       TEXT,
    mensagem_abertura   TEXT,

    -- Idioma da resposta. Parametro do AGENTE, nao regra fixa no codigo:
    -- um agente de atendimento a estudante estrangeiro ou um site bilingue
    -- precisam de comportamento diferente, e isso e configuracao, nao
    -- arquitetura. 'auto' responde no idioma de quem perguntou.
    idioma              TEXT NOT NULL DEFAULT 'pt-BR',   -- pt-BR|en|es|auto

    temperatura         REAL NOT NULL DEFAULT 0.3,

    -- CUIDADO com valor baixo: os Gemini 3.x são modelos "pensantes" e o
    -- raciocínio interno consome este orçamento ANTES de sobrar texto. Medido
    -- em 2026-08-24: com max_tokens = 20 a API devolve HTTP 200 com conteúdo
    -- VAZIO e finish_reason = length. Não é erro — é resposta em branco, e o
    -- sintoma no widget seria "o bot não respondeu", sem nada no log. O
    -- ChatService precisa tratar esse caso explicitamente.
    max_tokens          INTEGER NOT NULL DEFAULT 1024,

    -- Quanto o modelo pode "pensar" antes de responder: none|low|medium|high.
    -- Num agente de FAQ, `none` é mais barato e mais rápido; num que encadeia
    -- ferramentas, vale deixar pensar. Ignorado por provedor que não suporte.
    reasoning_effort    TEXT NOT NULL DEFAULT 'none',

    -- Parâmetros de recuperação ficam NO AGENTE, não globais: suporte quer
    -- recall alto, política interna quer precisão. É o botão que mais move
    -- qualidade na prática.
    top_k               INTEGER NOT NULL DEFAULT 5,

    -- Corte pela nota do COSSENO (não pela do RRF, que depende de quantas
    -- listas houve e não é comparável entre buscas).
    --
    -- Age como PISO, nao como seletor. Medido em 2026-08-24 com
    -- gemini-embedding-001: as notas sao comprimidas e dependem de como a
    -- pergunta foi escrita — o mesmo trecho correto pontua 0.777 na versao
    -- bem formada ("qual o valor da mensalidade de Direito?") e 0.683 na
    -- coloquial ("quais cursos vcs tem"), enquanto irrelevantes chegam a
    -- 0.66. As faixas se sobrepoem: nenhum corte absoluto separa bem, e um
    -- limiar de 0.70 derrubava resposta correta de pergunta informal.
    --
    -- A selecao fina e feita por margem RELATIVA ao melhor resultado da
    -- propria busca (ver Retriever::MARGEM_RELATIVA), que se ajusta a
    -- formulacao. Aqui fica so o piso que descarta busca sem casamento.
    limiar_similaridade REAL NOT NULL DEFAULT 0.55,
    -- Acima disto, a resposta curada sai sem passar pelo modelo.
    --
    -- 0.78 e nao 0.85: medido em 2026-08-25 com gemini-embedding-001, uma
    -- pergunta IDENTICA a FAQ pontua 0.906 e uma quase identica 0.903, mas
    -- variantes legitimas caem para 0.78-0.81 ("vcs tem medicina?" = 0.811,
    -- "qual o telefone da central?" = 0.783). Com 0.85 o curto-circuito nunca
    -- disparava fora do caso identico.
    --
    -- Mais importante: as faixas SE CRUZAM. Uma pergunta sem FAQ casou
    -- erradamente a 0.727, acima de uma parafrase correta a 0.682 — nenhum
    -- limiar acerta os dois. A escolha e assimetrica e por isso o corte fica
    -- onde os falsos positivos sao ZERO: responder texto curado a pergunta
    -- errada sai com autoridade total, enquanto deixar passar apenas cai no
    -- RAG, que responde bem. Errar de menos, nunca de mais.
    limiar_faq_direto   REAL NOT NULL DEFAULT 0.78,
    max_iteracoes_tool  INTEGER NOT NULL DEFAULT 5,
    usa_rag             INTEGER NOT NULL DEFAULT 1,
    usa_faq             INTEGER NOT NULL DEFAULT 1,
    captura_lead        INTEGER NOT NULL DEFAULT 0,
    lead_destino_id     INTEGER,                      -- ferramentas.id (0/NULL = só grava local)
    publico             INTEGER NOT NULL DEFAULT 0,
    token_publico       TEXT UNIQUE,
    ativo               INTEGER NOT NULL DEFAULT 1,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS agente_bases (
    agente_id           INTEGER NOT NULL REFERENCES agentes (id) ON DELETE CASCADE,
    base_id             INTEGER NOT NULL REFERENCES bases (id) ON DELETE CASCADE,
    PRIMARY KEY (agente_id, base_id)
);

CREATE TABLE IF NOT EXISTS agente_ferramentas (
    agente_id           INTEGER NOT NULL REFERENCES agentes (id) ON DELETE CASCADE,
    ferramenta_id       INTEGER NOT NULL REFERENCES ferramentas (id) ON DELETE CASCADE,
    config_override     TEXT,                         -- JSON
    ordem               INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (agente_id, ferramenta_id)
);


-- =====================================================================
-- 5. Conhecimento — bases, artefatos, chunks, embeddings
--
-- `dimensoes` e `modelo_embedding` ficam na BASE, não numa config global:
-- trocar de modelo invalida todos os vetores daquela base. O campo torna isso
-- explícito e permite reindexar uma base por vez, em vez de descobrir o
-- problema como resultado silenciosamente ruim.
-- =====================================================================

CREATE TABLE IF NOT EXISTS bases (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    descricao           TEXT,
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    provedor_embedding_id INTEGER REFERENCES provedores (id) ON DELETE SET NULL,
    modelo_embedding    TEXT,
    dimensoes           INTEGER,
    chunk_tamanho       INTEGER NOT NULL DEFAULT 800,
    chunk_sobreposicao  INTEGER NOT NULL DEFAULT 120,
    ativo               INTEGER NOT NULL DEFAULT 1,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS artefatos (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    base_id             INTEGER NOT NULL REFERENCES bases (id) ON DELETE CASCADE,
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    tipo                TEXT NOT NULL DEFAULT 'pdf',   -- pdf|docx|txt|md|html|url|manual
    titulo              TEXT NOT NULL,
    arquivo             TEXT,
    url                 TEXT,
    hash                TEXT,
    tamanho             INTEGER,
    status              TEXT NOT NULL DEFAULT 'pendente',  -- pendente|processando|ok|erro
    erro                TEXT,
    chunks_total        INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_artefatos_base ON artefatos (base_id, status);
CREATE INDEX IF NOT EXISTS idx_artefatos_hash ON artefatos (hash);

CREATE TABLE IF NOT EXISTS chunks (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    artefato_id         INTEGER NOT NULL REFERENCES artefatos (id) ON DELETE CASCADE,
    base_id             INTEGER NOT NULL REFERENCES bases (id) ON DELETE CASCADE,
    ordem               INTEGER NOT NULL DEFAULT 0,
    conteudo            TEXT NOT NULL,
    tokens              INTEGER,
    metadados           TEXT,                          -- JSON: {pagina, secao, ...}
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_chunks_base ON chunks (base_id);
CREATE INDEX IF NOT EXISTS idx_chunks_artefato ON chunks (artefato_id, ordem);

-- Metade lexical da busca híbrida (BM25), fundida com o cosseno por RRF.
-- Tabela de conteúdo externo: o texto vive em `chunks`, aqui fica só o índice.
CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5 (
    conteudo,
    content = 'chunks',
    content_rowid = 'id',
    tokenize = 'unicode61 remove_diacritics 2'
);

CREATE TRIGGER IF NOT EXISTS chunks_ai AFTER INSERT ON chunks BEGIN
    INSERT INTO chunks_fts (rowid, conteudo) VALUES (new.id, new.conteudo);
END;

CREATE TRIGGER IF NOT EXISTS chunks_ad AFTER DELETE ON chunks BEGIN
    INSERT INTO chunks_fts (chunks_fts, rowid, conteudo) VALUES ('delete', old.id, old.conteudo);
END;

CREATE TRIGGER IF NOT EXISTS chunks_au AFTER UPDATE ON chunks BEGIN
    INSERT INTO chunks_fts (chunks_fts, rowid, conteudo) VALUES ('delete', old.id, old.conteudo);
    INSERT INTO chunks_fts (rowid, conteudo) VALUES (new.id, new.conteudo);
END;

-- Vetor como BLOB de float32; `norma` pré-calculada para o cosseno não precisar
-- recalcular a magnitude a cada comparação. Acesso SEMPRE via a interface
-- VectorStore — é o que torna sqlite-vec/pgvector uma troca de classe.
CREATE TABLE IF NOT EXISTS embeddings (
    chunk_id            INTEGER PRIMARY KEY REFERENCES chunks (id) ON DELETE CASCADE,
    base_id             INTEGER NOT NULL,
    modelo              TEXT NOT NULL,
    dimensoes           INTEGER NOT NULL,
    vetor               BLOB NOT NULL,
    norma               REAL NOT NULL,
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_embeddings_base ON embeddings (base_id);


-- =====================================================================
-- 6. FAQ curada
--
-- Não é artefato: é par pergunta/resposta curado, embeddado pela PERGUNTA.
-- Match acima de agentes.limiar_faq_direto curto-circuita o RAG e devolve a
-- resposta curada — mais barato, mais previsível, e o cliente controla a
-- palavra final.
-- =====================================================================

CREATE TABLE IF NOT EXISTS faq_categorias (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    nome                TEXT NOT NULL,
    ordem               INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS faq (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    categoria_id        INTEGER REFERENCES faq_categorias (id) ON DELETE SET NULL,
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    base_id             INTEGER REFERENCES bases (id) ON DELETE SET NULL,
    pergunta            TEXT NOT NULL,
    resposta            TEXT NOT NULL,
    tags                TEXT,
    ativo               INTEGER NOT NULL DEFAULT 1,
    ordem               INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_faq_ativo ON faq (ativo, ordem);

CREATE VIRTUAL TABLE IF NOT EXISTS faq_fts USING fts5 (
    pergunta,
    resposta,
    content = 'faq',
    content_rowid = 'id',
    tokenize = 'unicode61 remove_diacritics 2'
);

CREATE TRIGGER IF NOT EXISTS faq_ai AFTER INSERT ON faq BEGIN
    INSERT INTO faq_fts (rowid, pergunta, resposta) VALUES (new.id, new.pergunta, new.resposta);
END;

CREATE TRIGGER IF NOT EXISTS faq_ad AFTER DELETE ON faq BEGIN
    INSERT INTO faq_fts (faq_fts, rowid, pergunta, resposta) VALUES ('delete', old.id, old.pergunta, old.resposta);
END;

CREATE TRIGGER IF NOT EXISTS faq_au AFTER UPDATE ON faq BEGIN
    INSERT INTO faq_fts (faq_fts, rowid, pergunta, resposta) VALUES ('delete', old.id, old.pergunta, old.resposta);
    INSERT INTO faq_fts (rowid, pergunta, resposta) VALUES (new.id, new.pergunta, new.resposta);
END;

CREATE TABLE IF NOT EXISTS faq_embeddings (
    faq_id              INTEGER PRIMARY KEY REFERENCES faq (id) ON DELETE CASCADE,
    modelo              TEXT NOT NULL,
    dimensoes           INTEGER NOT NULL,
    vetor               BLOB NOT NULL,
    norma               REAL NOT NULL,
    criado_em           TEXT NOT NULL
);


-- =====================================================================
-- 7. Ferramentas — o núcleo do produto
--
-- O LLM NUNCA monta URL nem corpo: preenche slots tipados e validados
-- (ferramenta_parametros), e o template é fixo, definido no admin.
-- Deixar o modelo concatenar URL equivale a entregar um cliente HTTP
-- arbitrário a quem conversa com o bot. Ver ARQUITETURA.md §7.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ferramentas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    -- Campo mais importante da tabela: é o texto que o modelo lê para decidir
    -- QUANDO chamar. É prompt, não documentação.
    descricao_llm       TEXT NOT NULL,
    tipo                TEXT NOT NULL DEFAULT 'http',  -- http|tabela|lead|handoff|contato_setor
    efeito              TEXT NOT NULL DEFAULT 'leitura',  -- leitura|escrita
    requer_confirmacao  INTEGER NOT NULL DEFAULT 0,
    -- Trava real de ordem: o orquestrador RECUSA a chamada se a ferramenta
    -- pré-requisito não rodou nesta conversa. Não é sugestão ao modelo.
    depende_de          INTEGER REFERENCES ferramentas (id) ON DELETE SET NULL,

    -- tipo = http
    metodo              TEXT DEFAULT 'GET',
    url_template        TEXT,
    headers             TEXT,                          -- JSON
    corpo_template      TEXT,                          -- JSON
    auth_tipo           TEXT DEFAULT 'none',           -- none|bearer|basic|header|query
    auth_ref            TEXT,                          -- nome da var no .env
    -- QUAL cabecalho (auth_tipo=header) ou QUAL parametro de query
    -- (auth_tipo=query) leva a chave. Sem isto os dois tipos apareciam no
    -- formulario e nao faziam nada: faltava a informacao, nao o codigo.
    auth_nome           TEXT,
    -- Frase anexada a resposta sempre que esta ferramenta for usada. Serve
    -- para avisar que o dado nao veio da base curada — busca na web, sistema
    -- de terceiro — e precisa ser conferido.
    aviso_resposta      TEXT,
    timeout_ms          INTEGER,
    retentativas        INTEGER NOT NULL DEFAULT 0,
    resposta_caminho    TEXT,                          -- ex.: "data.items"
    resposta_template   TEXT,

    -- tipo = tabela
    dataset_id          INTEGER REFERENCES datasets (id) ON DELETE SET NULL,
    formula             TEXT,

    ativo               INTEGER NOT NULL DEFAULT 1,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ferramenta_parametros (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    ferramenta_id       INTEGER NOT NULL REFERENCES ferramentas (id) ON DELETE CASCADE,
    nome                TEXT NOT NULL,
    tipo                TEXT NOT NULL DEFAULT 'string', -- string|number|boolean|enum
    descricao_llm       TEXT,
    obrigatorio         INTEGER NOT NULL DEFAULT 0,
    enum_valores        TEXT,                           -- JSON, para enum estático
    -- Enum dinâmico: nome da fonte no banco (ex.: 'setores', 'cursos'). As
    -- opções são montadas na hora da chamada, então criar um setor no admin
    -- o torna roteável na mesma hora, sem tocar em código nem no prompt.
    enum_fonte          TEXT,
    padrao              TEXT,
    exemplo             TEXT,
    ordem               INTEGER NOT NULL DEFAULT 0,
    UNIQUE (ferramenta_id, nome)
);

CREATE TABLE IF NOT EXISTS ferramenta_execucoes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    conversa_id         INTEGER REFERENCES conversas (id) ON DELETE CASCADE,
    mensagem_id         INTEGER REFERENCES mensagens (id) ON DELETE SET NULL,
    ferramenta_id       INTEGER REFERENCES ferramentas (id) ON DELETE SET NULL,
    params              TEXT,                           -- JSON
    status              TEXT NOT NULL DEFAULT 'ok',     -- ok|erro|recusado
    http_status         INTEGER,
    resposta            TEXT,
    duracao_ms          INTEGER,
    erro                TEXT,
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_execucoes_conversa ON ferramenta_execucoes (conversa_id);

-- Dados tabulares carregados por CSV no admin. É o que permite responder
-- "quanto custa o curso X" sem depender de API externa.
CREATE TABLE IF NOT EXISTS datasets (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    nome                TEXT NOT NULL,
    colunas             TEXT,                           -- JSON
    linhas_total        INTEGER NOT NULL DEFAULT 0,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS dataset_linhas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    dataset_id          INTEGER NOT NULL REFERENCES datasets (id) ON DELETE CASCADE,
    dados               TEXT NOT NULL                   -- JSON
);

CREATE INDEX IF NOT EXISTS idx_dataset_linhas ON dataset_linhas (dataset_id);


-- =====================================================================
-- 8. Canais e conversas
--
-- O widget web é o primeiro canal, não o caso especial. `externo_id` guarda
-- a identidade do canal (token de sessão no web, wa_id no WhatsApp).
-- =====================================================================

CREATE TABLE IF NOT EXISTS canais (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                TEXT NOT NULL UNIQUE,
    tipo                TEXT NOT NULL DEFAULT 'web',    -- web|whatsapp|api
    nome                TEXT NOT NULL,
    agente_id           INTEGER REFERENCES agentes (id) ON DELETE SET NULL,
    credenciais_ref     TEXT,                           -- nome da var no .env
    config              TEXT,                           -- JSON

    -- Sobrescreve a retencao global para este canal. Existe porque o WhatsApp
    -- e diferente do widget: ali `externo_id` e o TELEFONE, a pessoa volta
    -- semanas depois e espera continuidade. Apagar cedo demais faz o agente
    -- perder o contexto de uma conversa que, para ela, e a mesma. 0 = usa o
    -- valor global.
    retencao_dias       INTEGER NOT NULL DEFAULT 0,
    ativo               INTEGER NOT NULL DEFAULT 1,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS conversas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    canal_id            INTEGER REFERENCES canais (id) ON DELETE SET NULL,
    agente_id           INTEGER REFERENCES agentes (id) ON DELETE SET NULL,
    externo_id          TEXT,
    titulo              TEXT,
    -- modo = humano CALA O BOT: mensagem que chega é gravada mas não vai para
    -- a LLM. Um if, mas é a feature inteira do Nível 2.
    modo                TEXT NOT NULL DEFAULT 'bot',    -- bot|aguardando|humano|encerrada
    atendente_id        INTEGER REFERENCES admin_users (id) ON DELETE SET NULL,
    aguardando_desde    TEXT,
    -- Janela de 24h da Meta: fora dela só template aprovado. É regra de
    -- negócio, por isso mora no schema desde já.
    ultima_msg_usuario_em TEXT,
    ip                  TEXT,
    -- Marcas de controle da retencao. Servem para o job nao reprocessar
    -- eternamente as mesmas conversas antigas a cada rodada, e para a tela
    -- conseguir dizer que aquele historico foi tratado em vez de parecer
    -- que o texto simplesmente sumiu.
    anonimizada_em      TEXT,
    expurgada_em        TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_conversas_canal ON conversas (canal_id, externo_id);
CREATE INDEX IF NOT EXISTS idx_conversas_modo ON conversas (modo, aguardando_desde);

CREATE TABLE IF NOT EXISTS mensagens (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    conversa_id         INTEGER NOT NULL REFERENCES conversas (id) ON DELETE CASCADE,
    -- Sem autor_tipo, o bot ao retomar confunde a fala do atendente com a dele
    -- mesma e passa a se contradizer.
    autor_tipo          TEXT NOT NULL DEFAULT 'usuario', -- bot|usuario|atendente|sistema
    autor_id            INTEGER,
    conteudo            TEXT NOT NULL,
    tokens_in           INTEGER,
    tokens_out          INTEGER,
    custo               REAL,
    latencia_ms         INTEGER,
    feedback            INTEGER,                         -- 1 positivo, -1 negativo
    -- Id da mensagem no canal de origem (o `wamid`, no WhatsApp).
    --
    -- Existe para reconhecer reenvio: a Meta repete o webhook quando não
    -- recebe 200 depressa, e cada repetição viraria outra resposta para a
    -- mesma pergunta. Fica NULL no widget web, onde não há id externo — por
    -- isso o índice único é parcial, e mora no migrate.php.
    externo_id          TEXT,
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mensagens_conversa ON mensagens (conversa_id, id);

CREATE TABLE IF NOT EXISTS mensagem_fontes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    mensagem_id         INTEGER NOT NULL REFERENCES mensagens (id) ON DELETE CASCADE,
    tipo                TEXT NOT NULL,                   -- chunk|faq
    referencia_id       INTEGER NOT NULL,
    score               REAL
);

CREATE INDEX IF NOT EXISTS idx_fontes_mensagem ON mensagem_fontes (mensagem_id);


-- =====================================================================
-- 9. Leads e chamados
--
-- `leads` é a fonte da verdade e grava SEMPRE primeiro; `lead_destinos`
-- registra cada entrega com retry e dead letter próprios. Fire-and-forget
-- contra API de terceiro significa perder lead sem ninguém saber.
-- =====================================================================

CREATE TABLE IF NOT EXISTS leads (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    conversa_id         INTEGER REFERENCES conversas (id) ON DELETE SET NULL,
    agente_id           INTEGER REFERENCES agentes (id) ON DELETE SET NULL,
    nome                TEXT,
    email               TEXT,
    telefone            TEXT,
    campos_extra        TEXT,                            -- JSON
    origem              TEXT,
    consentimento_em    TEXT,
    criado_em           TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_leads_criado ON leads (criado_em DESC);

CREATE TABLE IF NOT EXISTS lead_destinos (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id             INTEGER NOT NULL REFERENCES leads (id) ON DELETE CASCADE,
    ferramenta_id       INTEGER REFERENCES ferramentas (id) ON DELETE SET NULL,
    -- 'descartado' é o estado de um lead que não tem o campo que o destino
    -- exige (o RD Station identifica contato por e-mail obrigatório, e um lead
    -- vindo do WhatsApp normalmente só tem telefone). Não é erro.
    status              TEXT NOT NULL DEFAULT 'pendente', -- pendente|ok|erro|descartado
    tentativas          INTEGER NOT NULL DEFAULT 0,
    proxima_tentativa_em TEXT,
    http_status         INTEGER,
    resposta            TEXT,
    erro                TEXT,
    idempotencia        TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_lead_destinos_fila ON lead_destinos (status, proxima_tentativa_em);

CREATE TABLE IF NOT EXISTS chamados (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    conversa_id         INTEGER REFERENCES conversas (id) ON DELETE SET NULL,
    setor_id            INTEGER REFERENCES setores (id) ON DELETE SET NULL,
    assunto             TEXT NOT NULL,
    descricao           TEXT,
    contato             TEXT,
    status              TEXT NOT NULL DEFAULT 'aberto',   -- aberto|respondido|fechado
    resposta            TEXT,
    email_enviado_em    TEXT,
    criado_em           TEXT NOT NULL,
    editado_em          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_chamados_status ON chamados (status, criado_em DESC);
