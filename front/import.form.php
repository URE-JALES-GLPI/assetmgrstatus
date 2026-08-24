<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, UPDATE);

global $CFG_GLPI, $DB;

$view = $_POST['view_mode'] ?? 'grid';

// Valida CSRF (GLPI já verifica via Html::header, mas garante)
if (!Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '')) {
    Session::addMessageAfterRedirect('Token inválido.', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?view=' . urlencode($view));
    exit;
}

$entity_id   = Session::getActiveEntity();
$entity_name = '';
try {
    $iter = $DB->request(['SELECT' => ['completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entity_id], 'LIMIT' => 1]);
    if ($iter->count() > 0) $entity_name = $iter->current()['completename'];
} catch (\Throwable $e) {}
if (!$entity_name) $entity_name = 'Entidade #' . $entity_id;

// Valida arquivo
if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    Session::addMessageAfterRedirect('Selecione um arquivo válido (CSV/XLSX/XLS) para importar.', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?view=' . urlencode($view));
    exit;
}

$allowed = ['csv', 'xlsx', 'xls'];
$fname   = $_FILES['import_file']['name'] ?? '';
$ext     = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
    Session::addMessageAfterRedirect('Formato inválido. Use CSV, XLSX ou XLS.', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?view=' . urlencode($view));
    exit;
}

// TODO: implementar lógica real de importação aqui.
// Por enquanto apenas confirma recebimento e entidade.
$size = (int)($_FILES['import_file']['size'] ?? 0);
Session::addMessageAfterRedirect(
    'Importação recebida para <strong>' . htmlspecialchars($entity_name) . '</strong> — arquivo: ' . htmlspecialchars($fname) . ' (' . round($size/1024, 1) . ' KB). Processamento em desenvolvimento.',
    false,
    INFO
);

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?view=' . urlencode($view));
