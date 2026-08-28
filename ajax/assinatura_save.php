<?php
// Garante resposta JSON mesmo em caso de erro PHP
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

try {
    include('../../../inc/includes.php');

    // Tenta garantir que as colunas de assinatura existam (caso o plugin ainda esteja na 2.0.1 no banco)
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) {
                @plugin_assetmgrstatus_schema();
            }
        }
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] assinatura_save schema check: ' . $e->getMessage());
    }

    if (!Session::checkLoginUser()) {
        throw new Exception('Sessão expirada — faça login novamente');
    }

    if (!Session::haveRight('plugin_assetmgrstatus_assinatura', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
        echo json_encode(['ok' => false, 'error' => 'Sem permissão (Assinatura)']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;
    // Fallback: se veio como form-encoded com JSON string
    if (isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if ($tmp) $data = $tmp;
    }

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');

    if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
        echo json_encode(['ok' => false, 'error' => 'Dados incompletos (transfer_id/doc/image obrigatórios)']);
        exit;
    }

    // Delegado à classe — já faz validação de RG/CPF e colunas
    $ok = false;
    $errDetail = '';
    try {
        $ok = \GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinatura($transfer_id, $doc_type, $doc_number, $nome, $image);
        if (!$ok && \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error) {
            $errDetail = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error;
        }
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] salvarAssinatura exception: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Erro interno ao salvar: ' . $e->getMessage()]);
        exit;
    }

    if ($ok) {
        echo json_encode(['ok' => true]);
    } else {
        $err = 'Falha ao salvar assinatura. Verifique se o termo existe, não está cancelado/já assinado e se o documento está correto (CPF 11 dígitos, RG 5-12).';
        if ($errDetail) $err .= ' Detalhe: ' . $errDetail;
        // Se a coluna não existe, a mensagem do DB costuma ser "Unknown column"
        global $DB;
        if (isset($DB) && $DB->error()) {
            $dbErr = $DB->error();
            if (stripos($dbErr, 'Unknown column') !== false || stripos($dbErr, 'assinatura') !== false) {
                $err .= ' (colunas de assinatura ainda não criadas — atualize o plugin para 2.0.2 em Configurar > Plugins)';
            }
        }
        echo json_encode(['ok' => false, 'error' => $err]);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] assinatura_save fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // Garante JSON mesmo em fatal
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}

