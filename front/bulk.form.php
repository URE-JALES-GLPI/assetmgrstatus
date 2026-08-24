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
foreach ($items as $item) {
    $items_id = (int)($item['id'] ?? 0);
    $itemtype = $item['itemtype'] ?? '';
    if (!$items_id || !$itemtype) continue;
    MaintenanceRecord::saveRecord($itemtype, $items_id, $status, $reason, $components, [], Session::getLoginUserID());
    $count++;
}

Session::addMessageAfterRedirect("Status alterado em massa para $count ativo(s)!", false, INFO);
$view = $_POST['view_mode'] ?? $_POST['view'] ?? 'grid';
$filter_type = $_POST['filter_type'] ?? '';
$filter_status = $_POST['filter_status'] ?? '';
$filter_search = $_POST['filter_search'] ?? '';
$filter_comp = $_POST['filter_comp'] ?? [];
$filter_fabricante = $_POST['filter_fabricante'] ?? [];
$qs = http_build_query(['view' => $view, 'type' => $filter_type, 'status' => $filter_status, 'search' => $filter_search]);
foreach ((array)$filter_comp as $k => $v) { $qs .= '&comp%5B' . urlencode($k) . '%5D=' . urlencode($v); }
foreach ((array)$filter_fabricante as $fid) { $qs .= '&fabricante%5B%5D=' . urlencode($fid); }
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?' . $qs);
