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
 * O mesmo código alimenta o botão "testar" na tela de provedores do admin.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Llm\ProviderFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$slug = $argv[1] ?? null;

/** @var array{ok: int, falha: int} $placar */
$placar = ['ok' => 0, 'falha' => 0];

function titulo(string $t): void
{
    echo "\n" . $t . "\n" . str_repeat('-', strlen($t)) . "\n";
}

function ok(string $msg): void
{
    global $placar;
    $placar['ok']++;
    echo "  [OK]     {$msg}\n";
}

function falha(ErroAgente $e): void
{
    global $placar;
    $placar['falha']++;
    echo "  [FALHOU] {$e->paraLog()}\n";
    echo "           → {$e->sugestaoAdmin()}\n";
    // A mensagem pública aparece aqui só para conferência: é o que o visitante
    // veria no widget. Nenhum detalhe técnico pode vazar para ela.
    echo "           visitante veria: \"{$e->mensagemPublica()}\"\n";
}

// =====================================================================

try {
    $pdo = Database::connection();

    if ($slug !== null) {
        $stmt = $pdo->prepare('SELECT id FROM provedores WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            fwrite(STDERR, "Provedor '{$slug}' não encontrado.\n");
            exit(1);
        }

        $fabrica = ProviderFactory::porId((int) $id);
    } else {
        $fabrica = ProviderFactory::ativo();
    }
} catch (ErroAgente $e) {
    echo "\nNão foi possível carregar o provedor.\n";
    falha($e);
    exit(1);
}

echo "Provedor: {$fabrica->nome()}\n";
echo "Chat:     {$fabrica->modeloChat()}\n";
echo "Embed:    {$fabrica->modeloEmbedding()} ({$fabrica->dimensoes()} dim)\n";

$opcoes = ['max_tokens' => 2000, 'temperatura' => 0.2, 'reasoning_effort' => 'none'];

$ferramenta = static fn (array &$registro): Tool => Tool::make(
    'simular_mensalidade',
    'Calcula o valor da mensalidade de um curso. Use sempre que perguntarem preço.'
)
    ->addProperty(new ToolProperty('curso', PropertyType::STRING, 'Nome do curso', true))
    ->addProperty(new ToolProperty('turno', PropertyType::STRING, 'manha ou noite', true))
    ->setCallable(function (string $curso, string $turno) use (&$registro): string {
        $registro[] = ['curso' => $curso, 'turno' => $turno];
        return json_encode(['curso' => $curso, 'turno' => $turno, 'valor' => 1250.00]);
    });

// =====================================================================
titulo('1. Chat');
// =====================================================================

try {
    $r = Agent::make()
        ->setAiProvider($fabrica->chat($opcoes))
        ->chat(new UserMessage('Responda apenas: ok'))
        ->getMessage();

    $texto = trim((string) $r->getContent());

    // Modelo pensante com orçamento curto devolve 200 com conteúdo VAZIO.
    // Sem esta checagem o sintoma seria "o bot não respondeu", sem erro nenhum.
    if ($texto === '') {
        throw new ErroAgente('resposta_vazia', 'Provedor respondeu 200 com conteúdo vazio.');
    }

    ok("respondeu: \"{$texto}\"");
} catch (ErroAgente $e) {
    falha($e);
} catch (Throwable $e) {
    falha(ErroAgente::deProvedor($e, 'chat'));
}

// =====================================================================
titulo('2. Ciclo completo de ferramenta');
// =====================================================================

$registro = [];

try {
    $r = Agent::make()
        ->setAiProvider($fabrica->chat($opcoes))
        ->addTool($ferramenta($registro))
        ->chat(new UserMessage('Quanto custa o curso de Direito no turno da noite?'))
        ->getMessage();

    if ($registro === []) {
        throw new ErroAgente('ferramenta_falhou', 'O modelo não chamou a ferramenta.');
    }

    ok('ferramenta chamada com ' . json_encode($registro[0], JSON_UNESCAPED_UNICODE)
        . ' e resposta montada sobre o resultado');
} catch (ErroAgente $e) {
    falha($e);
} catch (Throwable $e) {
    falha(ErroAgente::deProvedor($e, 'ferramenta'));
}

// =====================================================================
titulo('3. Streaming com ferramenta');
// =====================================================================

$registro = [];

try {
    $handler = Agent::make()
        ->setAiProvider($fabrica->chat($opcoes))
        ->addTool($ferramenta($registro))
        ->stream(new UserMessage('Quanto custa Medicina no turno da manhã?'));

    $pedacos = 0;
    $texto = '';

    foreach ($handler->events() as $evento) {
        if (is_string($evento)) {
            $pedacos++;
            $texto .= $evento;
        } elseif (is_object($evento) && property_exists($evento, 'content') && is_string($evento->content)) {
            $pedacos++;
            $texto .= $evento->content;
        }
    }

    if ($pedacos < 2) {
        throw new ErroAgente('provedor_indisponivel', "Stream veio em {$pedacos} pedaço(s) — não é streaming real.");
    }

    ok("{$pedacos} pedaços recebidos" . ($registro !== [] ? ', com ferramenta executada no meio' : ''));
} catch (ErroAgente $e) {
    falha($e);
} catch (Throwable $e) {
    falha(ErroAgente::deProvedor($e, 'streaming'));
}

// =====================================================================
titulo('4. Embeddings (indexar vs consultar)');
// =====================================================================

try {
    $doc = $fabrica->embeddings(ProviderFactory::TAREFA_INDEXAR)->embedText('matrícula do curso de Direito');
    $qry = $fabrica->embeddings(ProviderFactory::TAREFA_CONSULTAR)->embedText('matrícula do curso de Direito');

    $esperado = $fabrica->dimensoes();

    if ($esperado > 0 && count($doc) !== $esperado) {
        throw new ErroAgente(
            'configuracao',
            'Provedor devolveu ' . count($doc) . " dimensões, mas `provedores.dimensoes` diz {$esperado}."
        );
    }

    // Se os vetores forem idênticos, o task_type foi ignorado — e usar o mesmo
    // espaço nos dois lados custa recall de graça.
    $iguais = true;
    for ($i = 0, $n = min(50, count($doc)); $i < $n; $i++) {
        if (abs($doc[$i] - $qry[$i]) > 1e-9) {
            $iguais = false;
            break;
        }
    }

    ok(count($doc) . ' dimensões · task_type ' . ($iguais ? 'IGNORADO (vetores idênticos)' : 'aplicado'));

    if ($iguais) {
        echo "           → o provedor aceitou mas não aplicou o task_type. Confira\n";
        echo "             `driver_embedding` — o caminho OpenAI-compatible do Gemini\n";
        echo "             não suporta; só o nativo (gemini_nativo) aplica.\n";
    }
} catch (ErroAgente $e) {
    falha($e);
} catch (Throwable $e) {
    falha(ErroAgente::deProvedor($e, 'embeddings'));
}

// =====================================================================

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passou · %d falhou\n", $placar['ok'], $placar['falha']);

exit($placar['falha'] > 0 ? 1 : 0);
