<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus', UPDATE);

$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int)($_POST['items_id'] ?? 0);

if (!$items_id || !$itemtype) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

if (!in_array($itemtype, Transfer::getValidItemtypes(), true)) {
    Session::addMessageAfterRedirect('Tipo de ativo inválido.', false, ERROR);
    Html::back();
    exit;
}

$item = new $itemtype();
if (!$item->getFromDB($items_id)) {
    Session::addMessageAfterRedirect('Ativo não encontrado.', false, ERROR);
    Html::back();
    exit;
}
if (!$item->can($items_id, DELETE)) {
    Session::addMessageAfterRedirect('Sem permissão para excluir este ativo.', false, ERROR);
    Html::back();
    exit;
}

MaintenanceRecord::deleteAsset($itemtype, $items_id);

Session::addMessageAfterRedirect('Ativo removido com sucesso.', false, INFO);
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php');
