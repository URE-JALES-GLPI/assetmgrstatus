<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus_tecnico', READ);

global $CFG_GLPI;

$action      = $_POST['action']      ?? '';
$transfer_id = (int)($_POST['transfer_id'] ?? 0);

if (!$action || !$transfer_id) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

if ($action === 'pegar') {
    $ok = Transfer::pegar($transfer_id);
    if ($ok) {
        Session::addMessageAfterRedirect('Você assumiu esta transferência! Status alterado para Em Manutenção.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Não foi possível assumir — transferência já foi assumida ou inválida.', false, ERROR);
    }
    if (Transfer::$last_ticket_error !== '') {
        Session::addMessageAfterRedirect(Transfer::$last_ticket_error, false, WARNING);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php');

} elseif ($action === 'finalizar') {
    $ok = Transfer::finalizar($transfer_id);
    if ($ok) {
        Session::addMessageAfterRedirect('Transferência finalizada com sucesso! Status aplicados no inventário.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Não foi possível finalizar — transferência não está no status Pronto.', false, ERROR);
    }
    if (Transfer::$last_ticket_error !== '') {
        Session::addMessageAfterRedirect(Transfer::$last_ticket_error, false, WARNING);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php');

} elseif ($action === 'cancelar') {
    $motivo = trim($_POST['motivo'] ?? '');
    $ok = Transfer::cancelar($transfer_id, $motivo);
    if ($ok) {
        Session::addMessageAfterRedirect('Transferência cancelada. Ativos liberados e chamado notificado.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Não foi possível cancelar — transferência já finalizada ou inválida.', false, ERROR);
    }
    if (Transfer::$last_ticket_error !== '') {
        Session::addMessageAfterRedirect(Transfer::$last_ticket_error, false, WARNING);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php');

} else {
    Session::addMessageAfterRedirect('Ação inválida.', false, ERROR);
    Html::back();
}