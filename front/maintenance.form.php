<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, UPDATE);

global $CFG_GLPI;

$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int)($_POST['items_id'] ?? 0);
$status   = $_POST['status']   ?? '';
$reason   = trim($_POST['reason'] ?? '');
$expected_return_date = trim($_POST['expected_return_date'] ?? '');
$users_id_tech = (int)($_POST['users_id_tech'] ?? Session::getLoginUserID());
$view_mode     = $_POST['view_mode']     ?? 'list';
$filter_type   = $_POST['filter_type']   ?? '';
$filter_status = $_POST['filter_status'] ?? '';
$filter_search = $_POST['filter_search'] ?? '';
$raw_entity = $_POST['filter_entity'] ?? [];
if (is_string($raw_entity)) $raw_entity = [$raw_entity];
if (!is_array($raw_entity)) $raw_entity = [];
$filter_entities = array_values(array_filter(array_map('intval', $raw_entity)));
$filter_entity_recursive = !empty($_POST['filter_entity_recursive']);

if (!$itemtype || !$items_id || !$status || !$reason) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

// Componentes
$comp_checks = $_POST['comp_check'] ?? [];
$comp_descs  = $_POST['comp_desc']  ?? [];
$components  = [];
foreach ($comp_checks as $ck) {
    $components[$ck] = trim($comp_descs[$ck] ?? '');
}

// Fotos
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

$ok = MaintenanceRecord::saveRecord(
    $itemtype,
    $items_id,
    $status,
    $reason,
    $components,
    $photos,
    $users_id_tech,
    ($status === MaintenanceRecord::STATUS_MANUTENCAO && $expected_return_date) ? $expected_return_date : null
);

if ($ok) {
    Session::addMessageAfterRedirect('Status atualizado com sucesso!', false, INFO);
} else {
    Session::addMessageAfterRedirect('Edição bloqueada: ativo em transferência e aguardando retorno do técnico.', false, ERROR);
    Html::back();
    exit;
}
$qs = http_build_query(['view'=>$view_mode,'type'=>$filter_type,'status'=>$filter_status,'search'=>$filter_search]);
if (!empty($filter_entities) && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    foreach ($filter_entities as $eid) $qs .= '&entity%5B%5D=' . $eid;
    if ($filter_entity_recursive) $qs .= '&entity_recursive=1';
} elseif ($filter_entity_recursive && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    $qs .= '&entity_recursive=1';
}
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?' . $qs);
