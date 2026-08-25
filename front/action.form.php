<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();

global $CFG_GLPI;

$action   = $_POST['action']   ?? '';
$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int)($_POST['items_id'] ?? 0);

// Observação avulsa: qualquer um com acesso à Manutenção pode usar.
// Manutenção/Baixa: exige direito de técnico.
if ($action === 'note') {
    Session::checkRight(MaintenanceRecord::RIGHT_VIEW, UPDATE);
} else {
    Session::checkRight(MaintenanceRecord::RIGHT_TECNICO, READ);
}

if (!$action || !$itemtype || !$items_id) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

$photos = [];
if (!empty($_FILES['photos']['name'][0])) {
    $files = [];
    foreach ($_FILES['photos'] as $field => $values) {
        foreach ($values as $idx => $value) {
            $files[$idx][$field] = $value;
        }
    }
    $photos = MaintenanceRecord::handlePhotoUpload($files);
}

if ($action === 'manutencao') {
    $description = trim($_POST['action_description'] ?? '');
    if (!$description) {
        Session::addMessageAfterRedirect('Descreva a manutenção realizada.', false, ERROR);
        Html::back();
        exit;
    }
    MaintenanceRecord::saveManutencaoRealizada($itemtype, $items_id, $description, $photos);
    Session::addMessageAfterRedirect('Manutenção realizada registrada com sucesso!', false, INFO);

} elseif ($action === 'baixa') {
    $motivo     = trim($_POST['baixa_motivo'] ?? '');
    $data_baixa = $_POST['baixa_data'] ?? '';
    if (!$motivo) {
        Session::addMessageAfterRedirect('Informe o motivo da baixa.', false, ERROR);
        Html::back();
        exit;
    }
    MaintenanceRecord::saveBaixa($itemtype, $items_id, $motivo, $data_baixa, $photos);
    Session::addMessageAfterRedirect('Baixa registrada com sucesso!', false, INFO);

} elseif ($action === 'note') {
    $note = trim($_POST['note_text'] ?? '');
    if (!$note) {
        Session::addMessageAfterRedirect('Escreva a observação.', false, ERROR);
        Html::back();
        exit;
    }
    MaintenanceRecord::saveNote($itemtype, $items_id, $note);
    Session::addMessageAfterRedirect('Observação registrada com sucesso!', false, INFO);
}

$view = $_POST['view_mode'] ?? 'list';
$raw_entity = $_POST['filter_entity'] ?? [];
if (is_string($raw_entity)) $raw_entity = [$raw_entity];
if (!is_array($raw_entity)) $raw_entity = [];
$filter_entities = array_values(array_filter(array_map('intval', $raw_entity)));
$filter_entity_recursive = !empty($_POST['filter_entity_recursive']);
$qs = 'view=' . urlencode($view);
if (!empty($filter_entities) && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    foreach ($filter_entities as $eid) $qs .= '&entity%5B%5D=' . $eid;
    if ($filter_entity_recursive) $qs .= '&entity_recursive=1';
} elseif ($filter_entity_recursive && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    $qs .= '&entity_recursive=1';
}
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?' . $qs);
