<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, UPDATE);

global $CFG_GLPI;

$status      = $_POST['status']          ?? '';
$reason      = trim($_POST['reason']     ?? '');
$selected    = $_POST['selected_assets'] ?? '';
$comp_checks = $_POST['bulk_comp_check'] ?? [];
$comp_descs  = $_POST['bulk_comp_desc']  ?? [];

if (!$status || !$reason || !$selected) {
    Session::addMessageAfterRedirect('Dados inválidos para alteração em massa.', false, ERROR);
    Html::back();
    exit;
}

$items = json_decode($selected, true);
if (!is_array($items) || empty($items)) {
    Session::addMessageAfterRedirect('Nenhum ativo selecionado.', false, ERROR);
    Html::back();
    exit;
}

$components = [];
foreach ($comp_checks as $ck) {
    $components[$ck] = trim($comp_descs[$ck] ?? '');
}

$count = 0;
$blocked = 0;
foreach ($items as $item) {
    $items_id = (int)($item['id'] ?? 0);
    $itemtype = $item['itemtype'] ?? '';
    if (!$items_id || !$itemtype) continue;
    $ok = MaintenanceRecord::saveRecord($itemtype, $items_id, $status, $reason, $components, [], Session::getLoginUserID());
    if ($ok) $count++;
    else $blocked++;
}

if ($blocked > 0) {
    Session::addMessageAfterRedirect("Status alterado em massa para $count ativo(s)! $blocked bloqueado(s) por transferência.", false, WARNING);
} else {
    Session::addMessageAfterRedirect("Status alterado em massa para $count ativo(s)!", false, INFO);
}
$view = $_POST['view_mode'] ?? $_POST['view'] ?? 'list';
$filter_type = $_POST['filter_type'] ?? '';
$filter_status = $_POST['filter_status'] ?? '';
$filter_search = $_POST['filter_search'] ?? '';
$filter_comp = $_POST['filter_comp'] ?? [];
$filter_fabricante = $_POST['filter_fabricante'] ?? [];
$raw_entity = $_POST['filter_entity'] ?? [];
if (is_string($raw_entity)) $raw_entity = [$raw_entity];
if (!is_array($raw_entity)) $raw_entity = [];
$filter_entities = array_values(array_filter(array_map('intval', $raw_entity)));
$qs = http_build_query(['view' => $view, 'type' => $filter_type, 'status' => $filter_status, 'search' => $filter_search]);
foreach ((array)$filter_comp as $k => $v) { $qs .= '&comp%5B' . urlencode($k) . '%5D=' . urlencode($v); }
foreach ((array)$filter_fabricante as $fid) { $qs .= '&fabricante%5B%5D=' . urlencode($fid); }
if (!empty($filter_entities) && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    foreach ($filter_entities as $eid) $qs .= '&entity%5B%5D=' . $eid;
}
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?' . $qs);
