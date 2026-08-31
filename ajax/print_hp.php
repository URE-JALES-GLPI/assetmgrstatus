<?php
// Ajax handler para impressão na HP (compatibilidade — espelho de front/print_hp.form.php)
// Fix 403 GLPI: copia token do header/JSON para $_POST antes do include
if (isset($_SERVER['HTTP_X_GLPI_CSRF_TOKEN']) && !isset($_POST['_glpi_csrf_token']) && !isset($_GET['_glpi_csrf_token'])) {
    $_POST['_glpi_csrf_token'] = $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'];
    $_REQUEST['_glpi_csrf_token'] = $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw_pre = file_get_contents('php://input');
    if ($raw_pre) {
        $tmp_pre = json_decode($raw_pre, true);
        if (is_array($tmp_pre) && isset($tmp_pre['_glpi_csrf_token']) && !isset($_POST['_glpi_csrf_token'])) {
            $_POST['_glpi_csrf_token'] = $tmp_pre['_glpi_csrf_token'];
            $_REQUEST['_glpi_csrf_token'] = $tmp_pre['_glpi_csrf_token'];
        }
        $GLOBALS['_am_print_raw'] = $raw_pre;
    }
}
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    include('../../../inc/includes.php');
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') && isset($_GET['ping'])) {
        $printers = \GlpiPlugin\Assetmgrstatus\Transfer::getAvailablePrinters();
        $def = \GlpiPlugin\Assetmgrstatus\Transfer::getDefaultPrinter();
        $hp = \GlpiPlugin\Assetmgrstatus\Transfer::findHpPrinter();
        $cmds = \GlpiPlugin\Assetmgrstatus\Transfer::isPrintCommandAvailable();
        echo json_encode(['ok'=>true,'ping'=>'pong','user'=>Session::getLoginUserID(),'printers'=>$printers,'default'=>$def,'hp'=>$hp,'lp'=>$cmds['lp'],'lpr'=>$cmds['lpr']]);
        exit;
    }

    if (!Session::getLoginUserID()) {
        http_response_code(200);
        echo json_encode(['ok'=>false,'error'=>'Sessão expirada — faça login novamente no GLPI e recarregue a página']);
        exit;
    }

    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        http_response_code(200);
        echo json_encode(['ok'=>false,'error'=>'Sem permissão para impressão.']);
        exit;
    }

    $raw = $GLOBALS['_am_print_raw'] ?? file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;
    if (empty($data) && !empty($_GET)) $data = array_merge($data ?: [], $_GET);
    if (isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if ($tmp) $data = $tmp;
    }

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? $_GET['id'] ?? 0);
    $stage = trim((string)($data['stage'] ?? $_GET['stage'] ?? 'pronto'));
    if (!in_array($stage, ['transfer','pronto','final'], true)) $stage = 'pronto';
    $printer = trim((string)($data['printer'] ?? $_GET['printer'] ?? ''));
    $pdf_base64 = trim((string)($data['pdf_base64'] ?? ''));

    if (!$transfer_id) {
        echo json_encode(['ok'=>false,'error'=>'ID da transferência não informado']);
        exit;
    }

    $transfer = \GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id);
    if (!$transfer) {
        echo json_encode(['ok'=>false,'error'=>'Transferência não encontrada']);
        exit;
    }

    $res = \GlpiPlugin\Assetmgrstatus\Transfer::printOnServer($transfer_id, $stage, $printer ?: null, $pdf_base64 ?: null);
    if ($res['ok']) {
        echo json_encode(['ok'=>true,'printer'=>$res['printer'] ?? null,'request_id'=>$res['request_id'] ?? '','output'=>$res['output'] ?? '','audit'=>$res['audit'] ?? '']);
    } else {
        echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'Falha desconhecida','printer'=>$res['printer'] ?? null,'output'=>$res['output'] ?? '','audit'=>$res['audit'] ?? '','detail'=>$res['detail'] ?? '']);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] ajax print_hp fatal: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
