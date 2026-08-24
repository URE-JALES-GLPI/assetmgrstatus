<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();

// Permissão específica de exclusão (ou DELETE no principal)
$can_delete = Session::haveRight('plugin_assetmgrstatus_delete', DELETE)
    || Session::haveRight('plugin_assetmgrstatus', DELETE);
if (!$can_delete) {
    Session::addMessageAfterRedirect('Sem permissão para excluir ativos.', false, ERROR);
    Html::back();
    exit;
}

global $CFG_GLPI;

$selected = $_POST['selected_assets'] ?? '';

if (!$selected) {
    Session::addMessageAfterRedirect('Nenhum ativo selecionado para exclusão.', false, ERROR);
    Html::back();
    exit;
}

$items = json_decode($selected, true);
if (!is_array($items) || empty($items)) {
    Session::addMessageAfterRedirect('Nenhum ativo selecionado.', false, ERROR);
    Html::back();
    exit;
}

$count = 0;
$errors = 0;
foreach ($items as $item) {
    $items_id = (int)($item['id'] ?? 0);
    $itemtype = $item['itemtype'] ?? '';
    if (!$items_id || !$itemtype) { $errors++; continue; }
    if (!in_array($itemtype, Transfer::getValidItemtypes(), true)) { $errors++; continue; }

    $obj = new $itemtype();
    if (!$obj->getFromDB($items_id)) { $errors++; continue; }
    if (!$obj->can($items_id, DELETE)) { $errors++; continue; }

    MaintenanceRecord::deleteAsset($itemtype, $items_id);
    $count++;
}

if ($count > 0) {
    $msg = "$count ativo(s) excluído(s) com sucesso (GLPI + Plugin).";
    if ($errors > 0) $msg .= " $errors falha(s) por permissão ou ativo não encontrado.";
    Session::addMessageAfterRedirect($msg, false, INFO);
} else {
    Session::addMessageAfterRedirect('Nenhum ativo pôde ser excluído. Verifique permissões.', false, ERROR);
}

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
