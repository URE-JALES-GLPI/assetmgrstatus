<?php
// Endpoint Kanban Move — sempre retorna JSON (nunca HTML), evita "Unexpected token '<'"
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$incOk = false;
try {
    include('../../../inc/includes.php');
    $incOk = true;
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Falha ao carregar GLPI: '.$e->getMessage()]);
    exit;
}
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

// Ping diagnóstico — GET ?ping=1 retorna JSON mesmo sem permissão completa (ajuda debug 403)
if (($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') && isset($_GET['ping'])) {
    $uid = Session::getLoginUserID();
    $rights = [
        'tecnico'   => Session::haveRight('plugin_assetmgrstatus_tecnico', READ),
        'admin'     => Session::haveRight('plugin_assetmgrstatus_admin', READ),
        'assinatura'=> Session::haveRight('plugin_assetmgrstatus_assinatura', READ),
        'basic'     => Session::haveRight('plugin_assetmgrstatus', READ),
    ];
    echo json_encode(['success'=>true,'ping'=>'pong','user'=>$uid,'rights'=>$rights,'profile'=>($_SESSION['glpiactiveprofile']['id']??0),'csrf'=> (isset($_SESSION['_glpi_csrf_token']) ? 'set' : 'unset')]);
    exit;
}

// NÃO usa Session::checkLoginUser() — ele imprime HTML 403 quando sessão expira
$uid = Session::getLoginUserID();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Sessão expirada — faça login novamente no GLPI e recarregue a página (F5). Se persistir, limpe cookies.']);
    exit;
}

// Permissão — aceita tecnico OU admin (permite admin assumir também). Mensagem detalhada evita 403 HTML genérico.
$hasTecnico = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
$hasAdmin   = Session::haveRight('plugin_assetmgrstatus_admin', READ);
$hasBasic   = Session::haveRight('plugin_assetmgrstatus', READ);
if (!$hasTecnico && !$hasAdmin) {
    // Dá dica como assinatura_save faz
    $pid = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Sem permissão (Painel Técnico). Perfil #'.$pid.' precisa de "Acesso ao Painel Técnico" em Administração > Perfis > Manutenção (campo Acesso ao Painel Técnico). Faça logout/login após alterar. Seu perfil atual tem basic='.($hasBasic?1:0).' tecnico='.($hasTecnico?1:0).' admin='.($hasAdmin?1:0)]);
    exit;
}

// Validação CSRF: deixa o GLPI (CheckCsrfListener) validar via header X-Glpi-Csrf-Token.
// Não bloqueia aqui — apenas loga se token presente/ausente para debug, evitando 403 duplo após consumo do token.
// O JS já envia token via FormData _glpi_csrf_token + header X-Glpi-Csrf-Token (GLPI 11 exige header).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokDbg = $_POST['_glpi_csrf_token'] ?? $_GET['_glpi_csrf_token'] ?? ($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');
    // Log silencioso para diagnóstico (não bloqueia)
    // error_log('[assetmgrstatus] kanban_move csrf dbg token_len='.strlen($tokDbg).' hasSession='. (isset($_SESSION['glpicsrftokens'])?count($_SESSION['glpicsrftokens']):'0'));
}

// Lê parâmetros: suporta FormData (POST), x-www-form-urlencoded e JSON (application/json)
$type = $_POST['type'] ?? $_GET['type'] ?? '';
$id   = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$to   = $_POST['to'] ?? $_GET['to'] ?? '';

// Se ainda vazio e body é JSON (fetch com JSON), tenta php://input
if ((!$type || !$id || !$to) && empty($_POST) && empty($_GET['type'])) {
    $raw = @file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && !empty($j)) {
            if (isset($j['payload']) && is_string($j['payload'])) {
                $tmp = json_decode($j['payload'], true);
                if (is_array($tmp)) $j = $tmp;
            }
            $type = $j['type'] ?? $type;
            $id   = (int)($j['id'] ?? $id);
            $to   = $j['to'] ?? $to;
        }
    }
}
// Corrige FormData que pode truncar token com + -> espaço (menos relevante aqui mas mantém padrão)
if (isset($_POST['_glpi_csrf_token']) && strpos($_POST['_glpi_csrf_token'],' ')!==false) {
    $_POST['_glpi_csrf_token'] = str_replace(' ','+',$_POST['_glpi_csrf_token']);
}

if (!$type || !$id || !$to) {
    echo json_encode(['success'=>false,'message'=>'Parâmetros inválidos (type/id/to obrigatórios). Recebido: type='.$type.' id='.$id.' to='.$to]);
    exit;
}

try {
    if ($type === 'transfer') {
        // Mapeia coluna kanban para status GLPI
        $map = [
            'pendente'   => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE,
            'emandamento'=> \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_MANUTENCAO,
            'retirada'   => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO,
            'concluido'  => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_FINALIZADO,
        ];
        $newStatus = $map[$to] ?? null;
        if (!$newStatus) throw new Exception('Status destino inválido');

        $transfer = \GlpiPlugin\Assetmgrstatus\Transfer::getById($id);
        if (!$transfer) throw new Exception('Transferência não encontrada');

        // Ações conforme destino
        if ($to === 'emandamento' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE) {
            // Pegar
            $ok = \GlpiPlugin\Assetmgrstatus\Transfer::pegar($id);
            if (!$ok) throw new Exception(\GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error ?: 'Falha ao pegar — transferência já foi assumida ou inválida');
        } elseif ($to === 'retirada' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_MANUTENCAO) {
            // Marcar como pronto (sem finalizar, apenas pronto para retirada)
            global $DB;
            $DB->update('glpi_plugin_assetmgrstatus_transfers', ['status' => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO, 'date_pronto' => date('Y-m-d H:i:s')], ['id' => $id]);
            \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($id, \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO, 'Movido via Kanban para RETIRADA por '. \GlpiPlugin\Assetmgrstatus\Transfer::getUserName(Session::getLoginUserID()));
        } elseif ($to === 'concluido' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO) {
            throw new Exception('Transferência em RETIRADA só pode ser concluída via aba Assinatura após assinatura');
        } elseif ($to === 'pendente' && $transfer['status'] !== \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE) {
            throw new Exception('Não é permitido voltar para Pendente via Kanban');
        } else {
            throw new Exception('Transição não permitida de ' . $transfer['status'] . ' para ' . $to);
        }

        echo json_encode(['success'=>true]);
        exit;
    } elseif ($type === 'ticket') {
        // Chamados: Pendente (1) -> Pego (2) -> Concluído (5/6)
        $map = [
            'pendente'   => 1, // Novo
            'emandamento'=> 2, // Em andamento
            'concluido'  => 5, // Solucionado
        ];
        if ($to === 'retirada') {
            echo json_encode(['success'=>false,'message'=>'Chamados não podem ir para Retirada (apenas Transferências)']);
            exit;
        }
        $newStatus = $map[$to] ?? null;
        if (!$newStatus) throw new Exception('Status destino inválido para chamado');

        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) throw new Exception('Chamado não encontrado');

        // Ao mover para Em Andamento, primeiro garante atribuição correta (mesmo fix de Transfer::assignTicket)
        if ($to === 'emandamento') {
            $uid = Session::getLoginUserID();
            $currentStatus = (int)($ticket->fields['status'] ?? 0);
            // Usa helper robusto de atribuição para garantir campo Atribuído
            \GlpiPlugin\Assetmgrstatus\Transfer::assignTicket($id, $uid);
            // Também tenta atribuir via campo direto se existir
            if (isset($ticket->fields['users_id_tech'])) {
                try { $ticket->update(['id'=>$id,'users_id_tech'=>$uid]); } catch(\Throwable $e){}
            }
            $errAssign = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error;
            if ($errAssign !== '' && strpos($errAssign,'Falha ao atribuir')!==false) {
                throw new Exception($errAssign);
            }
            // Atualiza status para Em Atendimento se ainda Novo
            if ($currentStatus === 1) {
                $okS = $ticket->update(['id'=>$id,'status'=>$newStatus]);
                if (!$okS) {
                    // Tenta via Transfer::setTicketStatus como fallback (cria solução se necessário)
                    \GlpiPlugin\Assetmgrstatus\Transfer::setTicketStatus($id, $newStatus);
                    if (\GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error !== '' && strpos(\GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error,'Falha')!==false) {
                        // Ainda falhou, tenta não bloquear completamente — loga mas segue
                        error_log('[assetmgrstatus] kanban_move ticket status fallback: '. \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error);
                    }
                }
            }
            // Adiciona acompanhamento de quem pegou (não falha se não conseguir)
            try {
                \GlpiPlugin\Assetmgrstatus\Transfer::addTicketFollowup($id, "🔧 Chamado #".str_pad($id,6,'0',STR_PAD_LEFT)." assumido pelo técnico ".\GlpiPlugin\Assetmgrstatus\Transfer::getUserName($uid)." em ".date('d/m/Y H:i')." via Kanban.");
            } catch(\Throwable $e){}
            echo json_encode(['success'=>true]);
            exit;
        }

        $update = ['id' => $id, 'status' => $newStatus];
        $ok = $ticket->update($update);
        if (!$ok) {
            // Fallback via Transfer helper para Solucionado
            if ($newStatus === 5) {
                \GlpiPlugin\Assetmgrstatus\Transfer::setTicketStatus($id, $newStatus);
                $fallbackOk = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error === '';
                if ($fallbackOk) { echo json_encode(['success'=>true]); exit; }
            }
            $glpiErr = '';
            try { $glpiErr = $ticket->getError(); } catch(Throwable $e) {}
            throw new Exception('Falha ao atualizar chamado: ' . ($glpiErr ?: \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error ?: 'erro desconhecido'));
        }

        echo json_encode(['success'=>true]);
        exit;
    } else {
        throw new Exception('Tipo inválido: '.$type);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Tipo inválido']);
