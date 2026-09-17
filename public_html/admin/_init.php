<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();
Auth::requireLogin();

$pdo = Database::connection();
$config = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch();

if (!$config) {
    http_response_code(500);
    exit('Configuração não encontrada. Rode: php database/migrate.php');
}

// admin_master é quem gerencia os usuários do painel; usado aqui e na sidebar
$stmt = $pdo->prepare('SELECT admin_master, papel FROM admin_users WHERE id = :id');
$stmt->execute(['id' => Auth::userId()]);
$souEu = $stmt->fetch() ?: ['admin_master' => 0, 'papel' => 'atendente'];

$ehAdminMaster = (bool) $souEu['admin_master'];
$meuPapel = Painel::papelValido($souEu['papel']);

// -------------------------------------------------------------------------
// Controle de acesso
//
// Aqui, e não no menu: esconder o link não protege nada, porque a pessoa
// digita o nome do arquivo na barra de endereço. Como toda tela autenticada
// passa por este arquivo, esta é a única porta que precisa ser guardada.
//
// Quem não pode ver é REDIRECIONADO para onde de fato trabalha, não recebe um
// 403 seco: um atendente que clicou num link antigo vê a fila, não um erro.
// -------------------------------------------------------------------------
$telaAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!Painel::podeVer($meuPapel, $telaAtual)) {
    flash_set('erro', 'Você não tem acesso a essa área.');
    redirect(Painel::inicioDe($meuPapel));
}

// -------------------------------------------------------------------------
// Banco atrasado não pode virar 500 mudo.
//
// Deploy é `git pull` e, quando há coluna nova, `php database/migrate.php`.
// Esquecer o segundo passo derrubava a tela com HTTP 500 e nada na página —
// aconteceu em 16/09/2026, ao salvar uma ferramenta, e o sintoma não sugeria
// migração. O handler só trata ESTE caso e devolve o resto ao PHP: capturar
// exceção demais esconderia erro de verdade.
//
// Custo zero no caminho feliz: nada é verificado por requisição; a checagem
// contra o schema.sql só roda depois de a consulta ter falhado.
// -------------------------------------------------------------------------
set_exception_handler(static function (Throwable $e): void {
    $mensagem = $e->getMessage();

    if (!$e instanceof PDOException
        || (!str_contains($mensagem, 'no such column') && !str_contains($mensagem, 'no such table'))) {
        \Log::erro('painel_excecao', ['erro' => $mensagem]);
        http_response_code(500);
        exit('Erro inesperado. Veja o log do servidor.');
    }

    require_once __DIR__ . '/../../app/DiagnosticoInfra.php';

    $faltando = DiagnosticoInfra::colunasFaltando();
    $lista = [];

    foreach ($faltando as $tabela => $colunas) {
        $lista[] = $tabela . ': ' . implode(', ', $colunas);
    }

    \Log::erro('banco_desatualizado', ['erro' => $mensagem, 'faltando' => $lista]);

    http_response_code(503);

    echo '<!doctype html><meta charset="utf-8"><title>Banco desatualizado</title>'
        . '<div style="font:16px/1.6 system-ui;max-width:640px;margin:12vh auto;padding:0 24px;color:#14151a">'
        . '<h1 style="font-size:1.4rem">O banco está atrasado em relação ao código</h1>'
        . '<p>O código foi atualizado, mas a migração ainda não rodou. Na pasta do projeto:</p>'
        . '<pre style="background:#f2f3f6;padding:12px 14px;border-radius:8px;overflow:auto">php database/migrate.php</pre>'
        . ($lista !== [] ? '<p>Faltando: <code>' . e(implode(' · ', $lista)) . '</code></p>' : '')
        . '<p style="color:#6b7280;font-size:.9rem">A migração é idempotente: rodar de novo não faz nada.</p>'
        . '</div>';

    exit;
});

// contadores do menu: a sidebar aparece em toda página do painel, então ficam aqui
//
// O crachá de chamados respeita o MESMO recorte da tela: quem atende vê os do
// setor dele. Um crachá com 12 levando a uma lista de 2 é pior que crachá
// nenhum — ele vira número repetido em reunião, sem ninguém conferir.
$meuSetorId = null;

if ($meuPapel === 'atendente') {
    $stmt = $pdo->prepare('SELECT setor_id FROM admin_users WHERE id = :id');
    $stmt->execute(['id' => Auth::userId()]);
    $valor = $stmt->fetchColumn();
    $meuSetorId = $valor !== false && $valor !== null ? (int) $valor : null;
}

$chamadosAbertos = $meuPapel === 'atendente'
    ? ($meuSetorId === null
        ? 0
        : (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'aberto' AND setor_id = " . $meuSetorId)->fetchColumn())
    : (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'aberto'")->fetchColumn();
$artefatosPendentes = (int) $pdo->query("SELECT COUNT(*) FROM artefatos WHERE status IN ('pendente','processando')")->fetchColumn();
$artefatosComErro = (int) $pdo->query("SELECT COUNT(*) FROM artefatos WHERE status = 'erro'")->fetchColumn();

// Fila do atendimento humano. Fica aqui, e não na tela, porque o crachá
// precisa aparecer em QUALQUER página do painel — um visitante esperando não
// pode depender de o atendente estar justamente na tela de atendimento.
$filaAtendimento = (int) $pdo->query("SELECT COUNT(*) FROM conversas WHERE modo = 'aguardando'")->fetchColumn();
