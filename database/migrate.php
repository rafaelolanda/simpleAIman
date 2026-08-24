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

// (nenhuma ainda — schema.sql é a fonte completa)

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
    // Chat pelo caminho OpenAI-compatible; embeddings pelo NATIVO.
    // Motivo medido: o compat recusa `task_type` (HTTP 400) e o nativo aplica.
    // Ver comentário em schema.sql, tabela provedores.
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
        'driver' => 'openai',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'auth_ref' => 'GEMINI_API_KEY',
        // Versão FIXA de propósito: `gemini-flash-latest` devolveu 503 por
        // sobrecarga enquanto os modelos fixos respondiam, e um alias muda
        // comportamento e custo sem aviso.
        'chat' => 'gemini-3.7-flash',
        'base_embed' => 'https://generativelanguage.googleapis.com/v1beta/',
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
