<?php

declare(strict_types=1);

/**
 * Aplica o schema e semeia os registros mínimos para o painel abrir.
 * Seguro de rodar quantas vezes quiser: tudo aqui é idempotente.
 *
 *   php database/migrate.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();

$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Não foi possível ler schema.sql\n");
    exit(1);
}

$pdo->exec($schema);
echo "Schema aplicado com sucesso.\n";

// ---------------------------------------------------------------
// Migração incremental
//
// "CREATE TABLE IF NOT EXISTS" não altera tabela que já existe: numa instância
// já em produção, coluna nova acrescentada ao schema.sql não chega sozinha.
// Toda coluna adicionada dali em diante precisa também de uma chamada a
// garantir_colunas() aqui — idempotente, convive com instalação nova.
//
//   garantir_colunas($pdo, 'agentes', ['campo_novo' => 'TEXT']);
//
// Índice sobre coluna criada por aqui NÃO pode ir no schema.sql: ele roda antes
// desta seção e quebraria a aplicação inteira do schema num banco existente.
// ---------------------------------------------------------------
function garantir_colunas(PDO $pdo, string $tabela, array $colunas): void
{
    $existentes = array_column($pdo->query("PRAGMA table_info({$tabela})")->fetchAll(PDO::FETCH_ASSOC), 'name');

    foreach ($colunas as $coluna => $definicao) {
        if (!in_array($coluna, $existentes, true)) {
            $pdo->exec("ALTER TABLE {$tabela} ADD COLUMN {$coluna} {$definicao}");
            echo "  + coluna {$tabela}.{$coluna} adicionada\n";
        }
    }
}

// (nenhuma coluna nova ainda — schema.sql é a fonte completa)

// Mudança de DEFAULT não alcança linha que já existe: `CREATE TABLE IF NOT
// EXISTS` não recria a tabela, e ALTER de DEFAULT no SQLite não reescreve os
// registros. O limiar padrão subiu de 0.30 para 0.70 (ver comentário no
// schema), então instância já no ar precisa do UPDATE — e só onde ninguém
// mexeu no valor, para não desfazer calibragem feita à mão.
garantir_colunas($pdo, 'admin_users', ['papel' => "TEXT NOT NULL DEFAULT 'admin'"]);
garantir_colunas($pdo, 'agentes', ['modo' => "TEXT NOT NULL DEFAULT 'ia'"]);
garantir_colunas($pdo, 'admin_users', ['visto_em' => 'TEXT']);
garantir_colunas($pdo, 'ferramentas', ['auth_nome' => 'TEXT', 'aviso_resposta' => 'TEXT']);
garantir_colunas($pdo, 'agentes', ['idioma' => "TEXT NOT NULL DEFAULT 'pt-BR'"]);
garantir_colunas($pdo, 'config', ['anonimizacao_conversas_dias' => 'INTEGER NOT NULL DEFAULT 0']);
garantir_colunas($pdo, 'canais', ['retencao_dias' => 'INTEGER NOT NULL DEFAULT 0']);
garantir_colunas($pdo, 'conversas', ['anonimizada_em' => 'TEXT', 'expurgada_em' => 'TEXT']);
garantir_colunas($pdo, 'provedores', [
    'custo_entrada_milhao' => 'REAL NOT NULL DEFAULT 0',
    'custo_saida_milhao' => 'REAL NOT NULL DEFAULT 0',
]);
garantir_colunas($pdo, 'mensagens', ['externo_id' => 'TEXT']);

// Índice ÚNICO PARCIAL sobre o id externo da mensagem.
//
// É ele — não o `if` do worker — que garante a resposta única: dois webhooks
// idênticos chegando ao mesmo tempo passam os dois pela verificação, e quem
// desempata é o banco, recusando o segundo INSERT antes de qualquer envio.
//
// Parcial (`WHERE externo_id IS NOT NULL`) porque o widget web não tem id
// externo: sem a cláusula, a segunda mensagem NULL violaria o índice e
// derrubaria o chat inteiro.
//
// Fica aqui e não no schema.sql: o schema roda antes do `garantir_colunas()`
// acima, e num banco já existente o índice apontaria para coluna inexistente
// — quebrando a aplicação do schema inteiro.
$pdo->exec(
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_mensagens_externo
     ON mensagens (externo_id) WHERE externo_id IS NOT NULL'
);

// O limiar deixou de ser seletor e passou a ser piso: 0.70 derrubava resposta
// correta de pergunta informal (medido). Ajusta quem ainda esta nos valores
// que ja foram padrao, sem tocar em calibragem feita a mao.
// Mesma medicao mostrou que 0.85 nunca disparava o curto-circuito da FAQ
// fora do caso de pergunta identica. Ver comentario no schema.
$faq = $pdo->prepare('UPDATE agentes SET limiar_faq_direto = 0.78, editado_em = :agora
                      WHERE limiar_faq_direto = 0.85');
$faq->execute(['agora' => now()]);

if ($faq->rowCount() > 0) {
    echo "  + limiar de resposta curada de {$faq->rowCount()} agente(s) ajustado de 0.85 para 0.78
";
}

$ajustados = $pdo->prepare('UPDATE agentes SET limiar_similaridade = 0.55, editado_em = :agora
                            WHERE limiar_similaridade IN (0.30, 0.70)');
$ajustados->execute(['agora' => now()]);

if ($ajustados->rowCount() > 0) {
    echo "  + limiar de similaridade de {$ajustados->rowCount()} agente(s) ajustado para 0.55 (piso)
";
}

// ---------------------------------------------------------------
// Semente: registros sem os quais o painel não abre
// ---------------------------------------------------------------
$agora = now();

if (!$pdo->query('SELECT 1 FROM config WHERE id = 1')->fetchColumn()) {
    $stmt = $pdo->prepare(
        'INSERT INTO config (id, nome_instancia, criado_em, editado_em)
         VALUES (1, :nome, :agora, :agora)'
    );
    $stmt->execute(['nome' => APP_NOME, 'agora' => $agora]);
    echo "Config criada.\n";
}

// Setor padrão: é para onde cai o encaminhamento quando nenhum setor casa com
// o pedido. Sem ele, o modelo ficaria sem saída válida e a tentação seria
// inventar um contato.
if (!$pdo->query('SELECT 1 FROM setores LIMIT 1')->fetchColumn()) {
    $stmt = $pdo->prepare(
        'INSERT INTO setores (slug, nome, descricao_llm, padrao, ativo, ordem, criado_em, editado_em, atualizado_em)
         VALUES (:slug, :nome, :descricao, 1, 1, 0, :agora, :agora, :agora)'
    );
    $stmt->execute([
        'slug' => 'atendimento-geral',
        'nome' => 'Atendimento geral',
        'descricao' => 'Setor padrão. Use quando o assunto não se encaixar em nenhum outro setor.',
        'agora' => $agora,
    ]);
    echo "Setor padrão criado (preencha os contatos no painel).\n";
}

// Provedor de exemplo: Gemini pelo caminho OpenAI-compatible. Fica INATIVO até
// alguém conferir a chave no .env — provedor ativo sem chave só produz erro
// confuso na primeira conversa.
if (!$pdo->query('SELECT 1 FROM provedores LIMIT 1')->fetchColumn()) {
    // Chat e embeddings pelo caminho NATIVO do Gemini.
    //
    // Chat nativo porque e' o unico que trata `thoughtSignature` no retorno da
    // ferramenta — a camada OpenAI-compatible entrega a assinatura em
    // `extra_content`, mas quem remonta a mensagem tende a descarta-la, e a
    // segunda volta volta 400. Embedding nativo porque o compat recusa
    // `task_type` (HTTP 400) e o nativo aplica de fato.
    //
    // As duas colunas de endpoint continuam existindo para provedores em que
    // chat e embedding moram em caminhos diferentes.
    $stmt = $pdo->prepare(
        'INSERT INTO provedores
            (slug, nome, driver, base_url, auth_ref, modelo_chat,
             base_url_embedding, driver_embedding, modelo_embedding, dimensoes,
             ativo, criado_em, editado_em)
         VALUES
            (:slug, :nome, :driver, :base_url, :auth_ref, :chat,
             :base_embed, :driver_embed, :embed, :dim,
             0, :agora, :agora)'
    );
    $stmt->execute([
        'slug' => 'gemini-flash',
        'nome' => 'Google Gemini Flash',
        'driver' => 'gemini',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'auth_ref' => 'GEMINI_API_KEY',
        // Versão FIXA de propósito: `gemini-flash-latest` devolveu 503 por
        // sobrecarga enquanto os modelos fixos respondiam, e um alias muda
        // comportamento e custo sem aviso.
        'chat' => 'gemini-3.7-flash',
        'base_embed' => 'https://generativelanguage.googleapis.com/v1beta/models/',
        'driver_embed' => 'gemini_nativo',
        'embed' => 'gemini-embedding-001',
        'dim' => 768,
        'agora' => $agora,
    ]);
    echo "Provedor de exemplo criado (Gemini Flash, inativo — confira a chave e ative).\n";
}

if (!$pdo->query('SELECT 1 FROM bases LIMIT 1')->fetchColumn()) {
    $provedorId = (int) $pdo->query("SELECT id FROM provedores WHERE slug = 'gemini-flash'")->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO bases (slug, nome, descricao, provedor_embedding_id, modelo_embedding, dimensoes, criado_em, editado_em)
         VALUES (:slug, :nome, :descricao, :provedor, :modelo, :dim, :agora, :agora)'
    );
    $stmt->execute([
        'slug' => 'base-geral',
        'nome' => 'Base geral',
        'descricao' => 'Base de conhecimento inicial.',
        'provedor' => $provedorId ?: null,
        'modelo' => 'gemini-embedding-001',
        'dim' => 768,
        'agora' => $agora,
    ]);
    echo "Base de conhecimento inicial criada.\n";
}

if (!$pdo->query('SELECT 1 FROM agentes LIMIT 1')->fetchColumn()) {
    $provedorId = (int) $pdo->query("SELECT id FROM provedores WHERE slug = 'gemini-flash'")->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO agentes
            (slug, nome, descricao, provedor_id, modelo, system_prompt, mensagem_abertura, token_publico, criado_em, editado_em)
         VALUES
            (:slug, :nome, :descricao, :provedor, :modelo, :prompt, :abertura, :token, :agora, :agora)'
    );
    $stmt->execute([
        'slug' => 'assistente',
        'nome' => 'Assistente',
        'descricao' => 'Agente inicial. Ajuste prompt, bases e ferramentas no painel.',
        'provedor' => $provedorId ?: null,
        'modelo' => 'gemini-3.7-flash',
        'prompt' => "Você é um assistente de atendimento. Responda apenas com base nas fontes fornecidas e nas ferramentas disponíveis.\n\n"
            . "Regras:\n"
            . "- Nunca invente valores, prazos, telefones ou e-mails. Esses dados só saem de ferramenta.\n"
            . "- Se não souber, diga que não sabe e ofereça encaminhamento para o setor responsável.\n"
            . "- Seja objetivo e cordial.",
        'abertura' => 'Olá! Como posso ajudar?',
        'token' => bin2hex(random_bytes(16)),
        'agora' => $agora,
    ]);

    $agenteId = (int) $pdo->lastInsertId();
    $baseId = (int) $pdo->query('SELECT id FROM bases LIMIT 1')->fetchColumn();

    if ($agenteId && $baseId) {
        $pdo->prepare('INSERT INTO agente_bases (agente_id, base_id) VALUES (:a, :b)')
            ->execute(['a' => $agenteId, 'b' => $baseId]);
    }

    $pdo->prepare('UPDATE config SET agente_padrao_id = :id, editado_em = :agora WHERE id = 1')
        ->execute(['id' => $agenteId, 'agora' => $agora]);

    echo "Agente inicial criado.\n";
}

if (!$pdo->query('SELECT 1 FROM canais LIMIT 1')->fetchColumn()) {
    $agenteId = (int) $pdo->query('SELECT id FROM agentes LIMIT 1')->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO canais (slug, tipo, nome, agente_id, criado_em, editado_em)
         VALUES (:slug, :tipo, :nome, :agente, :agora, :agora)'
    );
    $stmt->execute([
        'slug' => 'widget-web',
        'tipo' => 'web',
        'nome' => 'Widget web',
        'agente' => $agenteId ?: null,
        'agora' => $agora,
    ]);
    echo "Canal 'widget web' criado.\n";
}

// Ferramentas embutidas: a instalação nova nasce com elas VISÍVEIS na lista,
// e sem vínculo a agente nenhum. Antes disto, elas existiam só como código e
// como opção num <select> — quem instalava do zero não descobria que havia
// encaminhamento, transferência ou captura de lead disponíveis.
$criadas = \SimpleAIman\Tools\Catalogo::semear();

if ($criadas !== []) {
    echo 'Ferramentas embutidas criadas (' . implode(', ', $criadas) . ").
";
    echo "  Vincule as que quiser usar em Ferramentas > o agente.
";
}

if (!$pdo->query('SELECT 1 FROM admin_users LIMIT 1')->fetchColumn()) {
    $usuario = 'admin';
    $senha = bin2hex(random_bytes(6));

    // primeiro usuário é o dono do painel: só ele gerencia os demais (admin_master)
    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (usuario, senha_hash, nome, admin_master, criado_em, editado_em)
         VALUES (:usuario, :senha_hash, :nome, 1, :agora, :agora)'
    );
    $stmt->execute([
        'usuario' => $usuario,
        'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
        'nome' => 'Administrador',
        'agora' => $agora,
    ]);

    echo "Usuário admin criado:\n";
    echo "  usuario: {$usuario}\n";
    echo "  senha:   {$senha}\n";
    echo "  (anote agora — não será exibida novamente; troque após o primeiro login)\n";
}

echo "Migração concluída.\n";
