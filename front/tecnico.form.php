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
    // Suporte a pendências: itens que ficaram pendentes geram nova transferência
    $pending_ids = $_POST['pending_items'] ?? [];
    if (is_string($pending_ids)) {
        $pending_ids = json_decode($pending_ids, true) ?? [];
    }
    if (!is_array($pending_ids)) $pending_ids = [];
    // Normaliza para ints, remove duplicados
    $pending_ids = array_values(array_unique(array_map('intval', $pending_ids)));
    $pending_reason = trim($_POST['pending_reason'] ?? '');

    $ok = Transfer::finalizar($transfer_id, $pending_ids, $pending_reason);
    if ($ok) {
        if (Transfer::$last_pending_transfer_id > 0) {
            $newId = Transfer::$last_pending_transfer_id;
            $pendCount = count($pending_ids);
            Session::addMessageAfterRedirect('Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' finalizada parcialmente! ' . $pendCount . ' equipamento(s) pendente(s) movido(s) para a nova Transferência #' . str_pad($newId, 4, '0', STR_PAD_LEFT) . ' (novo chamado criado automaticamente quando possível). Status dos demais aplicados no inventário.', false, INFO);
            // Se criou novo chamado, informa
            if (Transfer::$last_ticket_error !== '') {
                // Pode ser warning do novo chamado mas não falha principal
                Session::addMessageAfterRedirect(Transfer::$last_ticket_error, false, WARNING);
            } else {
                // Busca novo ticket id para informar
                $newTransfer = Transfer::getById($newId);
                if ($newTransfer && (int)$newTransfer['tickets_id'] > 0) {
                    Session::addMessageAfterRedirect('Novo chamado para pendências: #' . (int)$newTransfer['tickets_id'] . ' (Transferência #' . str_pad($newId, 4, '0', STR_PAD_LEFT) . ')', false, INFO);
                }
            }
        } else {
            Session::addMessageAfterRedirect('Transferência finalizada com sucesso! Status aplicados no inventário.', false, INFO);
        }
    } else {
        $err = Transfer::$last_ticket_error !== '' ? Transfer::$last_ticket_error : 'Não foi possível finalizar — transferência não está no status Pronto ou validação de pendências falhou (mantidas ao menos 1 para finalizar).';
        Session::addMessageAfterRedirect($err, false, ERROR);
    }
    if (Transfer::$last_ticket_error !== '' && Transfer::$last_pending_transfer_id === 0) {
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