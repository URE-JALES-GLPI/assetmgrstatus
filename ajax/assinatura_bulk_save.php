<?php
// Endpoint assinatura em lote — assina N transferências da mesma entidade com mesma assinatura
// Aceita JSON ou FormData, sempre retorna JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    include('../../../inc/includes.php');
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Falha ao carregar GLPI: ' . $e->getMessage()]);
    exit;
}
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

try {
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema();
        }
    } catch (Throwable $e) { error_log('[assetmgrstatus] bulk schema: ' . $e->getMessage()); }

    if (($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') && isset($_GET['ping'])) {
        echo json_encode(['ok' => true, 'ping' => 'pong', 'user' => Session::getLoginUserID()]);
        exit;
    }

    $uid = Session::getLoginUserID();
    if (!$uid) {
        echo json_encode(['ok' => false, 'error' => 'Sessão expirada — faça login novamente']);
        exit;
    }
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok' => false, 'error' => 'Sem permissão (Assinatura).']);
        exit;
    }

    $data = null;
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data) || empty($data)) {
        if (!empty($_POST)) $data = $_POST;
    }
    if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data)) $data = [];

    // transfer_ids pode vir como array, JSON string, ou csv
    $transfer_ids = $data['transfer_ids'] ?? $data['transferIds'] ?? $data['ids'] ?? $data['transfer_id'] ?? $data['id'] ?? [];
    if (is_string($transfer_ids)) {
        $s = trim($transfer_ids);
        if ($s !== '' && $s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) $transfer_ids = $decoded;
            else $transfer_ids = array_map('trim', explode(',', trim($s, '[]')));
        } else {
            $transfer_ids = $s === '' ? [] : array_map('trim', explode(',', $s));
        }
    }
    if (!is_array($transfer_ids)) $transfer_ids = [$transfer_ids];
    $transfer_ids = array_values(array_unique(array_filter(array_map(function($v){ return (int)trim((string)$v); }, $transfer_ids), fn($v) => $v > 0)));

    // Compat: se veio transfer_id único mas tem mais em lista
    if (empty($transfer_ids) && !empty($data['transfer_id'])) {
        $transfer_ids = [(int)$data['transfer_id']];
    }

    if (empty($transfer_ids)) {
        echo json_encode(['ok' => false, 'error' => 'Nenhuma transferência selecionada (transfer_ids obrigatório)']);
        exit;
    }
    if (count($transfer_ids) < 2) {
        echo json_encode(['ok' => false, 'error' => 'Selecione pelo menos 2 transferências da mesma entidade para assinatura em lote']);
        exit;
    }

    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');
    $tec_doc_type   = strtoupper(trim($data['tec_doc_type'] ?? $data['tec_document_type'] ?? ''));
    $tec_doc_number = trim($data['tec_doc_number'] ?? $data['tec_document'] ?? '');
    $tec_nome       = trim($data['tec_nome'] ?? $data['tec_name'] ?? '');
    $tec_image      = trim($data['tec_image'] ?? $data['assinatura_tecnico_image'] ?? '');

    if ($image !== '' && strpos($image, ' ') !== false && strpos($image, 'data:image/') === 0) $image = str_replace(' ', '+', $image);
    if ($tec_image !== '' && strpos($tec_image, ' ') !== false && strpos($tec_image, 'data:image/') === 0) $tec_image = str_replace(' ', '+', $tec_image);

    // Verifica se cada transferência já tem recebedor (para permitir salvar só técnico se todas já tiverem)
    $hasRecAlreadyAll = true;
    $needsTecAny = false;
    foreach ($transfer_ids as $tid) {
        try { $tr = \GlpiPlugin\Assetmgrstatus\Transfer::getById($tid); if($tr){ if(empty($tr['assinatura_image'])) $hasRecAlreadyAll = false; if(empty($tr['assinatura_tecnico_image'])) $needsTecAny = true; } else { $hasRecAlreadyAll = false; } } catch(Throwable $e){ $hasRecAlreadyAll = false; }
    }
    if (!$hasRecAlreadyAll) {
        if (!$doc_type || !$doc_number || !$image) {
            echo json_encode(['ok' => false, 'error' => 'Dados recebedor incompletos (doc_type/doc_number/image obrigatórios).']);
            exit;
        }
    } else {
        if (empty($doc_type) || empty($image)) {
            try { $ex = \GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_ids[0]); if($ex){ if(empty($doc_type)) $doc_type = $ex['assinatura_document_type'] ?? 'RG'; if(empty($doc_number)) $doc_number = $ex['assinatura_document'] ?? '0'; if(empty($nome)) $nome = $ex['assinatura_nome'] ?? ''; if(empty($image)) $image = $ex['assinatura_image'] ?? ''; } } catch(Throwable $e){}
        }
    }
    $tecProvided = $tec_doc_type !== '' || $tec_doc_number !== '' || $tec_image !== '' || $tec_nome !== '';
    if ($hasRecAlreadyAll && $needsTecAny && !$tecProvided) {
        echo json_encode(['ok'=>false,'error'=>'Selecione o técnico responsável (assinatura do técnico obrigatória).']);
        exit;
    }
    if ($tecProvided && (!$tec_doc_type || !$tec_doc_number || !$tec_image)) {
        echo json_encode(['ok'=>false,'error'=>'Dados técnico incompletos (doc_type/doc_number/image)']);
        exit;
    }

    $res = \GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinaturaBulk(
        $transfer_ids, $doc_type, $doc_number, $nome, $image,
        $tec_doc_type !== '' ? $tec_doc_type : null,
        $tec_doc_number !== '' ? $tec_doc_number : null,
        $tec_nome !== '' ? $tec_nome : null,
        $tec_image !== '' ? $tec_image : null
    );

    if ($res['ok']) {
        echo json_encode(['ok' => true, 'results' => $res['results']]);
    } else {
        $err = $res['error'] ?? 'Falha ao salvar em lote';
        if (!empty($res['results'])) {
            echo json_encode(['ok' => false, 'error' => $err, 'results' => $res['results']]);
        } else {
            echo json_encode(['ok' => false, 'error' => $err]);
        }
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] bulk fatal: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
