<?php

declare(strict_types=1);

/**
 * Exercita um provedor de ponta a ponta e diz o que funciona.
 *
 *   php bin/testar-provedor.php            # o provedor ativo
 *   php bin/testar-provedor.php gemini-flash
 *
 * Existe porque diagnosticar isso na mão custa caro: uma chave sem permissão
 * de projeto devolve 403 em TODOS os modelos, e o sintoma no chat é apenas
 * "não consegui responder". Aqui a causa aparece em segundos, com a sugestão
 * do que fazer a respeito.
 *
 * A lógica vive em SimpleAIman\Llm\DiagnosticoProvedor — a mesma classe que
 * alimenta o botão "testar" na tela de provedores do admin.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use SimpleAIman\Llm\DiagnosticoProvedor;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Llm\ProviderFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$slug = $argv[1] ?? null;

try {
    if ($slug !== null) {
        $stmt = Database::connection()->prepare('SELECT id FROM provedores WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            fwrite(STDERR, "Provedor '{$slug}' não encontrado.\n");
            exit(1);
        }

        $fabrica = ProviderFactory::porId((int) $id);
    } else {
        // Sem argumento, testa o padrão de CHAT. Para o de embedding, passe
        // o slug dele: os dois universos são linhas diferentes da tabela.
        $fabrica = ProviderFactory::padraoChat();
    }
} catch (ErroAgente $e) {
    fwrite(STDERR, "Não foi possível carregar o provedor.\n  " . $e->paraLog() . "\n  → " . $e->sugestaoAdmin() . "\n");
    exit(1);
}

echo "Provedor: {$fabrica->nome()}\n";
echo "Chat:     {$fabrica->modeloChat()}\n";
echo "Embed:    {$fabrica->modeloEmbedding()} ({$fabrica->dimensoes()} dim)\n";

$falhas = 0;

foreach ((new DiagnosticoProvedor($fabrica))->executar() as $r) {
    echo "\n" . $r['item'] . "\n" . str_repeat('-', mb_strlen($r['item'])) . "\n";

    if ($r['ok']) {
        echo "  [OK]     {$r['detalhe']}\n";
        continue;
    }

    $falhas++;
    echo "  [FALHOU] {$r['detalhe']}\n";
    echo "           → {$r['sugestao']}\n";
    echo "           visitante veria: \"{$r['publica']}\"\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passou · %d falhou\n", 4 - $falhas, $falhas);

exit($falhas > 0 ? 1 : 0);
