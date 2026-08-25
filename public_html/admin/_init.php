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

// contadores do menu: a sidebar aparece em toda página do painel, então ficam aqui
$chamadosAbertos = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'aberto'")->fetchColumn();
$artefatosPendentes = (int) $pdo->query("SELECT COUNT(*) FROM artefatos WHERE status IN ('pendente','processando')")->fetchColumn();
$artefatosComErro = (int) $pdo->query("SELECT COUNT(*) FROM artefatos WHERE status = 'erro'")->fetchColumn();

// Fila do atendimento humano. Fica aqui, e não na tela, porque o crachá
// precisa aparecer em QUALQUER página do painel — um visitante esperando não
// pode depender de o atendente estar justamente na tela de atendimento.
$filaAtendimento = (int) $pdo->query("SELECT COUNT(*) FROM conversas WHERE modo = 'aguardando'")->fetchColumn();
