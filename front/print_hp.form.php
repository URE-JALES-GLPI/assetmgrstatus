<?php
// Front handler para impressão na HP (CUPS do servidor Ubuntu)
// Envia o PDF assinado para fila de impressão via lp/lpr
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

try {
    include('../../../inc/includes.php');

    if (isset($_GET['ping'])) {
        $printers = \GlpiPlugin\Assetmgrstatus\Transfer::getAvailablePrinters();
        $def = \GlpiPlugin\Assetmgrstatus\Transfer::getDefaultPrinter();
        $hp = \GlpiPlugin\Assetmgrstatus\Transfer::findHpPrinter();
        $cmds = \GlpiPlugin\Assetmgrstatus\Transfer::isPrintCommandAvailable();
        echo json_encode(['ok'=>true,'ping'=>'pong','user'=>Session::getLoginUserID(),'printers'=>$printers,'default'=>$def,'hp'=>$hp,'lp'=>$cmds['lp'],'lpr'=>$cmds['lpr']]);
        exit;
    }

    if (!Session::getLoginUserID()) {
        echo json_encode(['ok'=>false,'error'=>'Sessão expirada — faça login novamente']);
        exit;
    }
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok'=>false,'error'=>'Sem permissão para impressão. Ative em Administração > Perfis > Manutenção']);
        exit;
    }

    // Aceita JSON ou form-encoded
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;
    if (isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if ($tmp) $data = $tmp;
    }

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? $_GET['id'] ?? 0);
    $stage = trim((string)($data['stage'] ?? $_GET['stage'] ?? 'pronto'));
    if (!in_array($stage, ['transfer','pronto','final'], true)) $stage = 'pronto';
    $printer = trim((string)($data['printer'] ?? $_GET['printer'] ?? ''));

    if (!$transfer_id) {
        echo json_encode(['ok'=>false,'error'=>'ID da transferência não informado']);
        exit;
    }

    $transfer = \GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id);
    if (!$transfer) {
        echo json_encode(['ok'=>false,'error'=>'Transferência não encontrada']);
        exit;
    }
    // Exige que esteja assinada? O botão só aparece para assinados, mas permitimos imprimir mesmo se não assinado (avisa)
    // Se quiser restringir a assinados, descomente:
    // if (empty($transfer['assinatura_image'])) {
    //     echo json_encode(['ok'=>false,'error'=>'Termo ainda não assinado — não há PDF assinado para imprimir']);
    //     exit;
    // }

    $res = \GlpiPlugin\Assetmgrstatus\Transfer::printOnServer($transfer_id, $stage, $printer ?: null);
    if ($res['ok']) {
        echo json_encode(['ok'=>true,'printer'=>$res['printer'] ?? null,'request_id'=>$res['request_id'] ?? '','output'=>$res['output'] ?? '']);
    } else {
        echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'Falha desconhecida','printer'=>$res['printer'] ?? null,'output'=>$res['output'] ?? '']);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] print_hp.form fatal: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
