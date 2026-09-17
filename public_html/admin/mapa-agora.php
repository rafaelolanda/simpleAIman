<?php

declare(strict_types=1);

/**
 * Panorama do momento: o que está acontecendo agora e quem está com cada conversa.
 *
 * Os outros dois mapas descrevem CONFIGURAÇÃO — coisas que mudam quando alguém
 * mexe no painel. Este descreve MOVIMENTO: quem entrou, por onde, há quanto
 * tempo espera e quem responde. Por isso ele é o único que se atualiza sozinho,
 * e o único cujo desenho muda sem ninguém configurar nada.
 *
 * ## O que ele responde que as listas não respondem
 *
 * A tela de Atendimento mostra a fila de quem espera; a de Conversas mostra o
 * histórico. Nenhuma das duas mostra, de uma olhada, a distribuição: três
 * conversas no WhatsApp com o bot, uma esperando há seis minutos e nenhum
 * atendente disponível. É a diferença entre "há uma fila" e "a fila não tem
 * para quem ir".
 *
 * ## Janela curta de propósito
 *
 * Conversa sem mensagem há mais de JANELA_MIN minutos não é "agora" — é
 * histórico, e vive na tela de Conversas. Misturar as duas encheria o desenho
 * com gente que já foi embora, que é o defeito clássico de painel "ao vivo".
 */

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../app/Mapa.php';

$paginaAtual = 'mapa-agora.php';
$tituloPagina = 'Agora';

/** Minutos sem mensagem depois dos quais a conversa deixa de ser "agora". */
const JANELA_MIN = 30;

/** Teto de conversas desenhadas: acima disso o desenho deixa de ser legível. */
const MAX_CONVERSAS = 14;

/** Minutos de espera na fila a partir dos quais o caso vira alerta. */
const ESPERA_ALERTA_MIN = 3;

$limite = date('Y-m-d H:i:s', time() - JANELA_MIN * 60);

$conversas = $pdo->prepare(
    "SELECT c.id, c.modo, c.aguardando_desde, c.editado_em, c.atendente_id,
            COALESCE(ca.tipo, 'web') AS canal_tipo,
            COALESCE(ca.nome, 'Widget/API') AS canal_nome,
            a.nome AS agente,
            u.nome AS atendente_nome, u.usuario AS atendente_usuario,
            (SELECT MAX(criado_em) FROM mensagens m WHERE m.conversa_id = c.id) AS ultima,
            (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS mensagens
       FROM conversas c
       LEFT JOIN canais ca ON ca.id = c.canal_id
       LEFT JOIN agentes a ON a.id = c.agente_id
       LEFT JOIN admin_users u ON u.id = c.atendente_id
      WHERE c.modo IN ('bot', 'aguardando', 'humano')
        AND COALESCE((SELECT MAX(criado_em) FROM mensagens m WHERE m.conversa_id = c.id), c.editado_em) >= :limite
      ORDER BY c.modo = 'aguardando' DESC, ultima DESC
      LIMIT :max"
);
$conversas->bindValue('limite', $limite);
$conversas->bindValue('max', MAX_CONVERSAS, PDO::PARAM_INT);
$conversas->execute();
$conversas = $conversas->fetchAll(PDO::FETCH_ASSOC);

$totalAtivas = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversas c
      WHERE c.modo IN ('bot', 'aguardando', 'humano')
        AND COALESCE((SELECT MAX(criado_em) FROM mensagens m WHERE m.conversa_id = c.id), c.editado_em) >= '" . $limite . "'"
)->fetchColumn();

$umaHora = date('Y-m-d H:i:s', time() - 3600);

$turnos = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) AS erros,
            SUM(CASE WHEN status = 'degradado' THEN 1 ELSE 0 END) AS degradados,
            AVG(ms_total) AS media
       FROM turnos WHERE criado_em >= '" . $umaHora . "'"
)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'erros' => 0, 'degradados' => 0, 'media' => 0];

$pessoas = $pdo->query(
    'SELECT id, nome, usuario, atende, disponivel, visto_em FROM admin_users ORDER BY nome, usuario'
)->fetchAll(PDO::FETCH_ASSOC);

$presente = static fn (array $u): bool => (int) $u['atende'] === 1
    && (int) $u['disponivel'] === 1
    && $u['visto_em'] !== null
    && strtotime((string) $u['visto_em']) > time() - PRESENCA_JANELA_SEG;

$disponiveis = array_values(array_filter($pessoas, $presente));

$minutos = static function (?string $quando): int {
    if ($quando === null) {
        return 0;
    }

    return max(0, (int) floor((time() - strtotime($quando)) / 60));
};

$hams = static function (int $min): string {
    if ($min < 1) {
        return 'agora';
    }

    return $min < 60 ? 'há ' . $min . ' min' : 'há ' . (int) floor($min / 60) . 'h';
};

// -------------------------------------------------------------------------
// Diagnóstico do momento
// -------------------------------------------------------------------------
$alertas = [];
$nosComAlerta = [];
$aguardando = 0;
$comHumano = 0;

foreach ($conversas as $c) {
    $id = (int) $c['id'];

    if ($c['modo'] === 'aguardando') {
        $aguardando++;
        $espera = $minutos((string) ($c['aguardando_desde'] ?? $c['editado_em']));

        if ($espera >= ESPERA_ALERTA_MIN) {
            $nosComAlerta['conversa-' . $id] = true;
            $alertas[] = [
                'grave' => true,
                'texto' => 'A conversa #' . $id . ' espera atendimento ' . $hams($espera) . '. '
                    . ($disponiveis === []
                        ? 'Não há ninguém disponível para receber.'
                        : 'Há ' . count($disponiveis) . ' pessoa(s) disponível(is).'),
                'onde' => 'atendimento.php?c=' . $id,
                'rotulo' => 'Abrir',
            ];
        }
    }

    if ($c['modo'] === 'humano') {
        $comHumano++;
        $parada = $minutos((string) $c['ultima']);

        if ($parada >= 10) {
            $nosComAlerta['conversa-' . $id] = true;
            $alertas[] = [
                'grave' => false,
                'texto' => 'A conversa #' . $id . ' está com '
                    . (string) ($c['atendente_nome'] ?: $c['atendente_usuario'] ?: 'um atendente')
                    . ' e sem nenhuma fala ' . $hams($parada) . '.',
                'onde' => 'atendimento.php?c=' . $id,
                'rotulo' => 'Abrir',
            ];
        }
    }
}

if ($aguardando > 0 && $disponiveis === []) {
    $alertas[] = [
        'grave' => true,
        'texto' => 'Ninguém está disponível para atender, e ' . $aguardando . ' conversa(s) esperam. '
            . 'Passado o tempo de espera, o bot devolve a conversa sozinho.',
        'onde' => 'mapa-atendimento.php',
        'rotulo' => 'Ver atendentes',
    ];
}

if ((int) $turnos['erros'] > 0) {
    $alertas[] = [
        'grave' => true,
        'texto' => (int) $turnos['erros'] . ' turno(s) falharam na última hora. O detalhe de cada um diz a causa.',
        'onde' => 'turnos.php?status=erro',
        'rotulo' => 'Diagnóstico',
    ];
}

if ((int) $turnos['degradados'] > 0) {
    $alertas[] = [
        'grave' => false,
        'texto' => (int) $turnos['degradados'] . ' turno(s) caíram no menu de setores na última hora — o modelo '
            . 'falhou e o atendimento se defendeu.',
        'onde' => 'turnos.php',
        'rotulo' => 'Diagnóstico',
    ];
}

if ($totalAtivas > count($conversas)) {
    $alertas[] = [
        'grave' => false,
        'texto' => 'Há ' . $totalAtivas . ' conversas ativas e o desenho mostra as ' . count($conversas)
            . ' mais recentes, para continuar legível.',
        'onde' => 'conversas.php',
        'rotulo' => 'Ver todas',
    ];
}

// -------------------------------------------------------------------------
// Desenho: canal → conversa → quem responde
// -------------------------------------------------------------------------
$mapa = new Mapa([20, 290, 560]);

$porCanal = [];

foreach ($conversas as $c) {
    $chave = (string) $c['canal_tipo'];
    $porCanal[$chave] = ($porCanal[$chave] ?? 0) + 1;
}

$colCanais = [];

foreach ($porCanal as $tipo => $quantas) {
    $colCanais[] = [
        'chave' => 'canal-' . $tipo,
        'titulo' => match ($tipo) {
            'whatsapp' => 'WhatsApp',
            'api' => 'API',
            default => 'Site',
        },
        'sub' => $quantas . ' conversa(s) ativa(s)',
        'href' => 'conversas.php',
        'inativo' => false,
        'classe' => 'col-canal',
    ];
}

$colConversas = [];
$colQuem = [];
$quemVisto = [];

foreach ($conversas as $c) {
    $id = (int) $c['id'];
    $espera = $minutos((string) ($c['aguardando_desde'] ?? $c['editado_em']));

    $estado = match ($c['modo']) {
        'aguardando' => 'esperando ' . $hams($espera),
        'humano' => 'com atendente',
        default => 'com o robô',
    };

    $colConversas[] = [
        'chave' => 'conversa-' . $id,
        'titulo' => '#' . $id . ' · ' . $hams($minutos((string) $c['ultima'])),
        'sub' => $estado,
        'sub2' => (int) $c['mensagens'] . ' mensagens · ' . (string) ($c['agente'] ?? 'sem agente'),
        'href' => 'conversas.php?ver=' . $id,
        'inativo' => false,
        'classe' => match ($c['modo']) {
            'aguardando' => 'col-base',
            'humano' => 'col-agente',
            default => 'col-ferr',
        },
        'canal' => 'canal-' . (string) $c['canal_tipo'],
        'modo' => (string) $c['modo'],
        'atendente_id' => (int) ($c['atendente_id'] ?? 0),
        'atendente' => (string) ($c['atendente_nome'] ?: $c['atendente_usuario'] ?: ''),
        'agente' => (string) ($c['agente'] ?? ''),
    ];
}

// Terceira coluna: quem responde. Uma caixa por responsável, não por conversa —
// é o que deixa ver duas conversas na mão da mesma pessoa.
foreach ($colConversas as $c) {
    if ($c['modo'] === 'humano' && $c['atendente_id'] > 0) {
        $chave = 'quem-atendente-' . $c['atendente_id'];

        if (!isset($quemVisto[$chave])) {
            $quemVisto[$chave] = true;
            $colQuem[] = [
                'chave' => $chave,
                'titulo' => $c['atendente'] !== '' ? $c['atendente'] : 'Atendente',
                'sub' => 'atendendo',
                'href' => 'atendimento.php',
                'inativo' => false,
                'classe' => 'col-agente',
            ];
        }

        continue;
    }

    if ($c['modo'] === 'aguardando') {
        if (!isset($quemVisto['quem-fila'])) {
            $quemVisto['quem-fila'] = true;
            $colQuem[] = [
                'chave' => 'quem-fila',
                'titulo' => 'Fila de espera',
                'sub' => count($disponiveis) . ' pessoa(s) disponível(is)',
                'href' => 'atendimento.php',
                'inativo' => $disponiveis === [],
                'classe' => 'col-base',
            ];
        }

        continue;
    }

    $chave = 'quem-bot-' . md5($c['agente']);

    if (!isset($quemVisto[$chave])) {
        $quemVisto[$chave] = true;
        $colQuem[] = [
            'chave' => $chave,
            'titulo' => $c['agente'] !== '' ? $c['agente'] : 'Agente',
            'sub' => 'respondendo sozinho',
            'href' => 'mapa.php',
            'inativo' => false,
            'classe' => 'col-prov',
        ];
    }
}

$mapa->coluna(0, $colCanais);
$mapa->coluna(1, $colConversas);
$mapa->coluna(2, $colQuem);
$mapa->montar();

foreach ($nosComAlerta as $chave => $_) {
    $mapa->alertar((string) $chave);
}

foreach ($colConversas as $c) {
    $mapa->ligar($c['canal'], $c['chave']);

    $destino = match (true) {
        $c['modo'] === 'humano' && $c['atendente_id'] > 0 => 'quem-atendente-' . $c['atendente_id'],
        $c['modo'] === 'aguardando' => 'quem-fila',
        default => 'quem-bot-' . md5($c['agente']),
    };

    $mapa->ligar($c['chave'], $destino, $c['modo'] === 'bot');
}

$graves = count(array_filter($alertas, static fn (array $a): bool => $a['grave']));

$resumo = [
    ['rotulo' => 'Conversas ativas', 'valor' => $totalAtivas, 'de' => null],
    ['rotulo' => 'Esperando atendente', 'valor' => $aguardando, 'de' => null],
    ['rotulo' => 'Com atendente', 'valor' => $comHumano, 'de' => null],
    ['rotulo' => 'Atendentes disponíveis', 'valor' => count($disponiveis), 'de' => null],
    ['rotulo' => 'Turnos na última hora', 'valor' => (int) $turnos['total'], 'de' => null],
    ['rotulo' => 'Falhas na última hora', 'valor' => (int) $turnos['erros'], 'de' => null],
];

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Agora</h1>
        <p>
            O que está em movimento nos últimos <?= JANELA_MIN ?> minutos: por onde entrou, há quanto tempo,
            e quem está com a conversa. A tela se atualiza sozinha a cada 30 segundos.
        </p>
    </div>
</div>

<div class="panel">
    <div class="mapa-resumo">
        <?php foreach ($resumo as $item): ?>
            <div>
                <strong><?= (int) $item['valor'] ?></strong>
                <span><?= e($item['rotulo']) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ((int) $turnos['total'] > 0): ?>
            <div>
                <strong><?= (int) round((float) $turnos['media']) ?><span>ms</span></strong>
                <span>Tempo médio de resposta</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($alertas !== []): ?>
    <div class="panel">
        <h2 class="card-title">
            <?= count($alertas) ?> ponto<?= count($alertas) > 1 ? 's' : '' ?> de atenção
            <?php if ($graves > 0): ?><span class="tag tag-alerta"><?= $graves ?> agora</span><?php endif; ?>
        </h2>
        <ul class="mapa-alertas">
            <?php foreach ($alertas as $alerta): ?>
                <li class="<?= $alerta['grave'] ? 'grave' : '' ?>">
                    <?= e($alerta['texto']) ?>
                    <a href="<?= e($alerta['onde']) ?>"><?= e($alerta['rotulo']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="panel">
    <?php if ($conversas === []): ?>
        <div class="empty-state">
            Nenhuma conversa em movimento nos últimos <?= JANELA_MIN ?> minutos. O histórico está em
            <a href="conversas.php">Conversas</a>.
        </div>
    <?php else: ?>
        <div class="mapa-legenda">
            <span><i class="mapa-chip col-canal"></i> Por onde entrou</span>
            <span><i class="mapa-chip col-ferr"></i> Com o robô</span>
            <span><i class="mapa-chip col-base"></i> Esperando atendente</span>
            <span><i class="mapa-chip col-agente"></i> Com uma pessoa</span>
            <span><i class="mapa-chip col-alerta"></i> Esperando demais</span>
        </div>

        <div class="mapa-rolagem">
            <?= $mapa->svg('Conversas ativas agora, por canal, e quem está respondendo cada uma') ?>
        </div>

        <p class="page-sub" style="margin-top:.6rem;">
            Cada caixa do meio é uma conversa: o tempo é o da última mensagem. À direita, quem responde —
            uma caixa por responsável, e não por conversa, para que duas conversas na mão da mesma pessoa
            apareçam como duas linhas chegando nela.
        </p>
    <?php endif; ?>
</div>

<?php // Atualização automática: é a única tela do painel que envelhece sozinha.
      // Só recarrega com a aba visível — recarregar em segundo plano gastaria
      // consulta para ninguém, e o pior caso é o painel aberto num monitor
      // esquecido. ?>
<script>
(function () {
    setInterval(function () {
        if (!document.hidden) { location.reload(); }
    }, 30000);
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
