<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI;

$transfer_id = (int)($_POST['transfer_id'] ?? 0);
$items_post  = $_POST['items'] ?? [];

if (!$transfer_id || empty($items_post)) {
    Session::addMessageAfterRedirect('Dados inválidos.', false, ERROR);
    Html::back();
    exit;
}

$final_items = [];
foreach ($items_post as $items_id => $data) {
    $status = $data['status'] ?? '';
    if (!$status) continue;
    $components = [];
    foreach ($data['comp_check'] ?? [] as $ckey) {
        $components[$ckey] = trim($data['comp_desc'][$ckey] ?? '');
    }
    $final_items[(int)$items_id] = [
        'status'     => $status,
        'reason'     => trim($data['reason'] ?? ''),
        'components' => $components,
    ];
}

$ok = Transfer::marcarPronto($transfer_id, $final_items);

if ($ok) {
    Session::addMessageAfterRedirect('Transferência marcada como Pronta!', false, INFO);
    if (Transfer::$last_ticket_error !== '') {
        Session::addMessageAfterRedirect(Transfer::$last_ticket_error, false, WARNING);
    }
    $pdf_url = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/transfer_pdf.php?id=' . $transfer_id . '&stage=pronto';
    ?>
    <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>
    <script>
        window.open('<?= $pdf_url ?>', '_blank');
        setTimeout(function(){ window.location.href = '<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php'; }, 300);
    </script>
    </body></html>
    <?php
    exit;
}

Session::addMessageAfterRedirect('Erro ao marcar como pronto.', false, ERROR);
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php');
