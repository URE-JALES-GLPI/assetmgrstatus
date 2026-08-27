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

$return_to = $_POST['return_to'] ?? '';
if ($return_to === 'dashboard') {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/dashboard.php');
    exit;
}

$view = $_POST['view_mode'] ?? 'list';
$raw_entity = $_POST['filter_entity'] ?? [];
if (is_string($raw_entity)) $raw_entity = [$raw_entity];
if (!is_array($raw_entity)) $raw_entity = [];
$filter_entities = array_values(array_filter(array_map('intval', $raw_entity)));
$qs = 'view=' . urlencode($view);
if (!empty($filter_entities) && Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    foreach ($filter_entities as $eid) $qs .= '&entity%5B%5D=' . $eid;
}
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php?' . $qs);
