<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, UPDATE);

global $CFG_GLPI;

$itemtype = $_POST['itemtype'] ?? '';
$items_id = (int)($_POST['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

$ok = MaintenanceRecord::undoLastChange($itemtype, $items_id);

if ($ok) {
    Session::addMessageAfterRedirect('Status revertido com sucesso!', false, INFO);
} else {
    Session::addMessageAfterRedirect('Não foi possível reverter (prazo de 48h expirado ou não há alteração recente).', false, ERROR);
}

$view = $_POST['view_mode'] ?? 'grid';
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?view=' . urlencode($view));
