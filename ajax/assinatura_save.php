<?php
// Endpoint assinatura — aceita JSON ou FormData, sempre retorna JSON (nunca HTML 403)
ini_set('display_errors', '0');
error_reporting(E_ALL);

// NÃO envia header antes do include — copia do padrão diario_save.php que funciona
try {
    include('../../../inc/includes.php');
} catch (Throwable $e) {
    // Se falhar include, ainda tenta retornar JSON
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Falha ao carregar GLPI: ' . $e->getMessage()]);
    exit;
}
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

try {
    // Garante colunas (auto-migração)
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema();
        }
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] assinatura_save schema: ' . $e->getMessage());
    }

    // Ping diagnóstico — GET ?ping=1 deve retornar JSON mesmo sem permissão completa
    if (($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') && isset($_GET['ping'])) {
        echo json_encode(['ok' => true, 'ping' => 'pong', 'user' => Session::getLoginUserID(), 'hasAssinatura' => Session::haveRight('plugin_assetmgrstatus_assinatura', READ), 'hasTecnico' => Session::haveRight('plugin_assetmgrstatus_tecnico', READ), 'hasBasic' => Session::haveRight('plugin_assetmgrstatus', READ)]);
        exit;
    }

    // NÃO usa Session::checkLoginUser() — ele imprime HTML 403. Retorna JSON.
    $uid = Session::getLoginUserID();
    if (!$uid) {
        echo json_encode(['ok' => false, 'error' => 'Sessão expirada — faça login novamente no GLPI e recarregue a página (F5). Se persistir, limpe cookies.']);
        exit;
    }

    // Permissão ampliada (igual front/assinatura.php)
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok' => false, 'error' => 'Sem permissão (Assinatura). Perfil #' . (int)($_SESSION['glpiactiveprofile']['id'] ?? 0) . ' precisa de "Assinatura de Termos" (ou ao menos Acesso à Manutenção) em Administração > Perfis > Manutenção. Faça logout/login após alterar.']);
        exit;
    }

    // Lê payload: suporta JSON (application/json) e FormData (multipart/form-data / x-www-form-urlencoded)
    $data = null;
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    // Tenta JSON primeiro se content-type indicar json ou body parecer json
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data) || empty($data)) {
        // Fallback FormData / POST
        if (!empty($_POST)) $data = $_POST;
    }
    // Caso cliente enviou JSON dentro de campo payload (legado)
    if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data)) $data = [];

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');

    // FormData multipart pode truncar base64 com + sendo espaço — corrige se necessário
    if ($image !== '' && strpos($image, ' ') !== false && strpos($image, 'data:image/') === 0) {
        $image = str_replace(' ', '+', $image);
    }

    if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
        echo json_encode(['ok' => false, 'error' => 'Dados incompletos (transfer_id/doc/image obrigatórios). Recebido: transfer=' . $transfer_id . ' doc_type=' . $doc_type . ' doc_len=' . strlen($doc_number) . ' img_len=' . strlen($image)]);
        exit;
    }

    $ok = false;
    $errDetail = '';
    try {
        $ok = \GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinatura($transfer_id, $doc_type, $doc_number, $nome, $image);
        if (!$ok && \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error) $errDetail = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error;
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] salvarAssinatura exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['ok' => false, 'error' => 'Erro interno ao salvar: ' . $e->getMessage()]);
        exit;
    }

    if ($ok) {
        echo json_encode(['ok' => true]);
    } else {
        $err = 'Falha ao salvar assinatura. Verifique se o termo existe, não está cancelado/já assinado e se o documento está correto (CPF 11 dígitos, RG 5-12).';
        if ($errDetail) $err .= ' Detalhe: ' . $errDetail;
        global $DB;
        if (isset($DB) && $DB->error()) {
            $dbErr = $DB->error();
            if (stripos($dbErr, 'Unknown column') !== false || stripos($dbErr, 'assinatura') !== false) {
                $err .= ' (colunas de assinatura ainda não criadas — atualize o plugin para 2.0.2 em Configurar > Plugins > Verificar/Atualizar)';
            }
        }
        echo json_encode(['ok' => false, 'error' => $err]);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] assinatura_save fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}
