<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus', UPDATE);

$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int)($_POST['items_id'] ?? 0);

if (!$items_id || !$itemtype) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

MaintenanceRecord::deleteAsset($itemtype, $items_id);

Session::addMessageAfterRedirect('Ativo removido com sucesso.', false, INFO);
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php');
