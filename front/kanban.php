<?php
/**
 * front/kanban.php - Kanban Externo (fora do GLPI) - Fullscreen
 * Abre em nova aba via botão em tecnico.php
 * Reusa mesma lógica de dados de tecnico.php mas com layout expandido
 */
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus_tecnico', READ);

// Limpeza automática de PDFs após 7 dias (1x/dia, mantém dados para regenerar)
try { Transfer::maybeRunCleanup(); } catch (\Throwable $e) {}

global $CFG_GLPI, $DB;

$filter_status = $_GET['status'] ?? '';
$filter_tech   = (int)($_GET['tech'] ?? 0);
$filter_date   = $_GET['date'] ?? '';
$filter_sort   = $_GET['sort'] ?? 'recent';
$filter_tipo   = $_GET['tipo'] ?? 'all'; // all, transfer, ticket
$filter_cat    = (int)($_GET['cat'] ?? 0);
$q = trim($_GET['q'] ?? '');
$q_norm = $q !== '' ? mb_strtolower($q, 'UTF-8') : '';
$q_ascii = '';
if ($q_norm !== '') { $q_ascii = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$q_norm); if($q_ascii===false) $q_ascii=$q_norm; $q_ascii=mb_strtolower($q_ascii,'UTF-8'); }
$transfers     = Transfer::getAll($filter_status);

// TicketJAL: busca chamados para timeline unificada (sem filtro de entidade para garantir que apareça)
$tickets = [];
$tjCatIds = [];
if ((new Plugin())->isActivated('ticketjal')) {
    try {
        $iterCat = $DB->request(['SELECT' => ['itilcategories_id'], 'FROM' => 'glpi_plugin_ticketjal_cards', 'WHERE' => ['type' => 'ticket', 'is_active' => 1, 'itilcategories_id' => ['>', 0]]]);
        foreach ($iterCat as $r) $tjCatIds[] = (int)$r['itilcategories_id'];
        $tjCatIds = array_values(array_unique($tjCatIds));
        $ticketWhere = ['glpi_tickets.is_deleted' => 0];
        if (!empty($tjCatIds)) {
            $ticketWhere['OR'] = [
                ['glpi_tickets.itilcategories_id' => $tjCatIds],
                ['glpi_tickets.content' => ['LIKE', '%Aberto via Central de Chamados%']]
            ];
        } else {
            $ticketWhere['glpi_tickets.content'] = ['LIKE', '%Aberto via Central de Chamados%'];
        }
        $ticketIter = $DB->request([
            'SELECT' => ['glpi_tickets.id','glpi_tickets.name','glpi_tickets.content','glpi_tickets.status','glpi_tickets.priority','glpi_tickets.date','glpi_tickets.date_mod','glpi_tickets.itilcategories_id','glpi_tickets.entities_id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => $ticketWhere,
            'ORDER' => ['glpi_tickets.date DESC'],
            'LIMIT' => 100,
        ]);
        $ticketsRaw = iterator_to_array($ticketIter);
        $catIdsNeeded = array_unique(array_column($ticketsRaw, 'itilcategories_id'));
        $catNamesMap = [];
        if ($catIdsNeeded) {
            foreach ($DB->request(['SELECT' => ['id','completename','name'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['id' => $catIdsNeeded]]) as $c) {
                $catNamesMap[(int)$c['id']] = $c['completename'] ?: $c['name'];
            }
        }
        $entIds = array_unique(array_column($ticketsRaw, 'entities_id'));
        $entNamesMap = [];
        if ($entIds) {
            foreach ($DB->request(['SELECT' => ['id','completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entIds]]) as $e) {
                $entNamesMap[(int)$e['id']] = $e['completename'];
            }
        }
        foreach ($ticketsRaw as $tk) {
            $tickets[] = [
                'id' => (int)$tk['id'],
                'name' => $tk['name'],
                'content' => $tk['content'],
                'status' => (int)$tk['status'],
                'priority' => (int)$tk['priority'],
                'date_creation' => $tk['date'] ?: $tk['date_mod'],
                'date_mod' => $tk['date_mod'],
                'itilcategories_id' => (int)$tk['itilcategories_id'],
                'category_name' => $catNamesMap[(int)$tk['itilcategories_id']] ?? ($tk['itilcategories_id'] ? 'Cat #' . $tk['itilcategories_id'] : 'Sem categoria'),
                'entities_id' => (int)$tk['entities_id'],
                'entity_name' => $entNamesMap[(int)$tk['entities_id']] ?? '',
            ];
        }
        if (!empty($tickets)) {
            try {
                $ticketIds = array_column($tickets, 'id');
                $assignedMap = [];
                foreach ($DB->request(['SELECT' => ['tickets_id','users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ticketIds, 'type' => 2]]) as $r) {
                    $assignedMap[(int)$r['tickets_id']] = (int)$r['users_id'];
                }
                $userNames = [];
                if (!empty($assignedMap)) {
                    $uids = array_values(array_unique($assignedMap));
                    foreach ($DB->request(['SELECT' => ['id','name','realname','firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $uids]]) as $u) {
                        $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                        if ($full === '') $full = $u['name'];
                        $userNames[(int)$u['id']] = $full;
                    }
                }
                foreach ($tickets as &$tk) {
                    $uid = $assignedMap[$tk['id']] ?? 0;
                    $tk['assigned_users_id'] = $uid;
                    $tk['assigned_name'] = $uid && isset($userNames[$uid]) ? $userNames[$uid] : '';
                }
                unset($tk);
            } catch (\Throwable $e2) { error_log('[assetmgrstatus] ticket assigned fetch: '.$e2->getMessage()); }
        }
    } catch (Throwable $e) { error_log('[assetmgrstatus] ticket fetch: '.$e->getMessage()); }
}
$tjCategoriesForFilter = [];
if (!empty($tjCatIds)) {
    foreach ($DB->request(['SELECT' => ['id','completename','name'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['id' => $tjCatIds]]) as $c) {
        $tjCategoriesForFilter[(int)$c['id']] = $c['completename'] ?: $c['name'];
    }
}

// Filtra por técnico se solicitado
if ($filter_tech) {
    $transfers = array_filter($transfers, fn($t) => (int)$t['users_id_tech'] === $filter_tech);
    $transfers = array_values($transfers);
}
if ($filter_date) {
    $transfers = array_filter($transfers, fn($t) => date('Y-m-d', strtotime($t['date_creation'])) === $filter_date);
    $transfers = array_values($transfers);
}
if ($q_norm !== '') {
    $transfers = array_values(array_filter($transfers, function($t) use ($q_norm,$q_ascii){
        $hay = ($t['origin_entity_name'] ?? '') . ' ' . ($t['entity_dest_name'] ?? '') . ' #' . $t['id'] . ' ' . ($t['reason'] ?? '');
        $hay_low = mb_strtolower($hay,'UTF-8');
        if(mb_strpos($hay_low,$q_norm)!==false) return true;
        $hay_ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$hay); if($hay_ascii===false) $hay_ascii=$hay;
        $hay_ascii=mb_strtolower($hay_ascii,'UTF-8');
        return mb_strpos($hay_ascii,$q_ascii)!==false;
    }));
}
if ($filter_date) {
    $tickets = array_values(array_filter($tickets, fn($tk) => date('Y-m-d', strtotime($tk['date_creation'])) === $filter_date));
}
if ($q_norm !== '') {
    $tickets = array_values(array_filter($tickets, function($tk) use ($q_norm,$q_ascii){
        $hay = ($tk['name'] ?? '') . ' ' . ($tk['category_name'] ?? '') . ' #' . $tk['id'] . ' ' . ($tk['content'] ?? '') . ' ' . ($tk['entity_name'] ?? '');
        $hay_low = mb_strtolower($hay,'UTF-8');
        if(mb_strpos($hay_low,$q_norm)!==false) return true;
        $hay_ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$hay); if($hay_ascii===false) $hay_ascii=$hay;
        $hay_ascii=mb_strtolower($hay_ascii,'UTF-8');
        return mb_strpos($hay_ascii,$q_ascii)!==false;
    }));
}
if ($filter_cat) {
    $tickets = array_values(array_filter($tickets, fn($tk) => (int)$tk['itilcategories_id'] === $filter_cat));
}
$combined = [];
if ($filter_tipo !== 'ticket') {
    foreach ($transfers as $t) $combined[] = ['type' => 'transfer', 'date' => $t['date_creation'], 'data' => $t];
}
if ($filter_tipo !== 'transfer') {
    foreach ($tickets as $tk) $combined[] = ['type' => 'ticket', 'date' => $tk['date_creation'], 'data' => $tk];
}
if ($filter_sort === 'old') {
    usort($combined, fn($a,$b) => strtotime($a['date']) <=> strtotime($b['date']));
} else {
    usort($combined, fn($a,$b) => strtotime($b['date']) <=> strtotime($a['date']));
}
$techs_in_transfers = [];
foreach (Transfer::getAll() as $t) {
    if ($t['users_id_tech'] && $t['tech_name'] && !isset($techs_in_transfers[$t['users_id_tech']])) {
        $techs_in_transfers[$t['users_id_tech']] = $t['tech_name'];
    }
}
$currentUserId = (int)Session::getLoginUserID();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kanban Externo — Técnico</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/tabler-icons.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#f1f5f9;font-family:'Inter',system-ui,-apple-system,sans-serif;color:#1e2333;height:100%}
.kanban-external{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.kanban-header{height:56px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 16px;flex-shrink:0;gap:12px}
.kanban-header-left{display:flex;align-items:center;gap:12px}
.kanban-header h1{margin:0;font-size:1.15rem;font-weight:800;display:flex;align-items:center;gap:8px}
.kanban-header h1 .ti{font-size:1.3rem;color:#38bdf8}
.kanban-header-actions{display:flex;align-items:center;gap:8px}
.kanban-btn{padding:8px 14px;border-radius:8px;border:none;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .15s}
.kanban-btn-secondary{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.18)}
.kanban-btn-secondary:hover{background:rgba(255,255,255,.2)}
.kanban-btn-primary{background:#2563eb;color:#fff}
.kanban-btn-primary:hover{background:#1d4ed8}
.kanban-filters{padding:12px 16px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;flex-direction:column;gap:10px;flex-shrink:0}
.kanban-filters.collapsed{display:none}
.kanban-toggle{padding:8px 16px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;flex-shrink:0}
.kanban-toggle-btn{padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;font-size:.82rem;font-weight:600;color:#374151;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.kanban-toggle-btn:hover{background:#f8fafc;border-color:#cbd5e1}
.kanban-toggle-btn.active{background:#eef2ff;border-color:#c7d2fe;color:#4f46e5}
.am-filters-bar{background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:10px}
.am-filter-group label{font-size:.70rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9ca3af;display:block;margin-bottom:6px}
.am-type-tabs{display:flex;gap:6px;flex-wrap:wrap;background:#f4f6fb;padding:4px;border-radius:10px}
.am-type-tab{padding:6px 12px;border-radius:8px;font-size:.8rem;font-weight:600;color:#6b7280;text-decoration:none;background:transparent;border:none;white-space:nowrap;cursor:pointer}
.am-type-tab:hover{background:#fff;color:#4f46e5}
.am-type-tab.active{background:#fff;color:#4f46e5;box-shadow:0 1px 6px rgba(79,70,229,.15);font-weight:700}
.am-type-count{background:#eef2ff;color:#4f46e5;border-radius:10px;padding:1px 6px;font-size:.68rem;font-weight:700;margin-left:4px}
.am-type-tab.active .am-type-count{background:#4f46e5;color:#fff}
.kanban-body{flex:1;overflow:auto;padding:16px;background:#f1f5f9}
.am-kanban{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;min-height:100%;align-items:start}
@media(max-width:1400px){.am-kanban{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.am-kanban{grid-template-columns:1fr}}
.am-kanban-column{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;display:flex;flex-direction:column;min-height:500px;box-shadow:0 1px 6px rgba(0,0,0,.04)}
.am-kanban-header{padding:12px 14px;font-weight:800;font-size:.88rem;color:#1e2333;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1.5px solid #e8eaf0;background:#fff;border-radius:14px 14px 0 0;flex-wrap:wrap}
.am-kanban-count{background:#eef2ff;color:#4f46e5;border-radius:20px;padding:2px 8px;font-size:.72rem;font-weight:700;white-space:nowrap}
.am-kanban-body{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1;min-height:300px;background:#f8fafc;border-radius:0 0 14px 14px}
.am-kanban-empty{text-align:center;color:#9ca3af;padding:24px 12px;font-size:.85rem;border:1.5px dashed #e8eaf0;border-radius:10px;background:#fff}
.am-tc-card{background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:all .15s;cursor:pointer}
.am-tc-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-1px)}
.am-tc-card-header{padding:12px 14px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px}
.am-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.70rem;font-weight:700;text-transform:uppercase;white-space:nowrap}
.am-badge-pendente{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.am-badge-manutencao{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.am-badge-pronto{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.am-badge-finalizado{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.am-badge-cancelada{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.am-tc-card-body{padding:10px 14px;display:flex;flex-direction:column;gap:6px;flex:1}
.am-tc-info-row{display:flex;align-items:center;gap:7px;font-size:.8rem;color:#374151}
.am-tc-info-row .ti{color:#9ca3af;font-size:.85rem;flex-shrink:0}
.am-tc-reason{font-size:.75rem;color:#6b7280;background:#f8f9fb;border:1px solid #f0f2f8;border-radius:8px;padding:7px 9px;line-height:1.4;white-space:normal;word-break:break-word}
.am-tc-card-footer{padding:10px 12px;display:flex;gap:8px;border-top:1px solid #f0f2f8;background:#fafbff;flex-wrap:wrap}
.am-btn{padding:7px 14px;border-radius:8px;border:none;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none;justify-content:center}
.am-btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
.am-btn-secondary:hover{background:#e5e7eb}
.am-kanban-hidden-pego{display:none}
.am-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;overflow-y:auto}
.am-modal-overlay.open{display:flex}
.am-modal{background:#fff;border-radius:16px;width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.3)}
.am-modal-header{padding:16px 20px;background:linear-gradient(135deg,#1e293b,#334155);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0}
.am-modal-title{display:flex;align-items:center;gap:10px;font-weight:700;font-size:1rem}
.am-modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.am-modal-body{padding:20px;overflow-y:auto;flex:1;background:#fff}
.am-modal-footer{padding:14px 20px;border-top:1px solid #f0f2f8;display:flex;justify-content:flex-end;gap:10px;background:#fafbff;flex-shrink:0}
.am-modal-footer .am-btn{flex:1}
#am-kanban-maximized-overlay .am-modal{width:96vw;max-width:1300px;height:90vh;max-height:90vh}
.am-tc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.am-input{width:100%;padding:8px 12px;border:1.5px solid #e8eaf0;border-radius:8px;font-size:.85rem}
.am-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
</style>
</head>
<body>
<div class="kanban-external">
  <div class="kanban-header">
    <div class="kanban-header-left">
      <h1><i class="ti ti-layout-kanban"></i> Kanban Externo — Técnico</h1>
      <span style="background:rgba(255,255,255,.15);border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700"><?= count($combined) ?> cards</span>
    </div>
    <div class="kanban-header-actions">
      <span id="kanban-clock" style="font-size:.78rem;color:#94a3b8"></span>
      <button class="kanban-btn kanban-btn-secondary" onclick="location.reload()"><i class="ti ti-refresh"></i> Atualizar</button>
      <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php<?= $_SERVER['QUERY_STRING'] ? '?'.htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="kanban-btn kanban-btn-secondary"><i class="ti ti-arrow-left"></i> Voltar ao GLPI</a>
      <button class="kanban-btn kanban-btn-secondary" onclick="window.close()"><i class="ti ti-x"></i> Fechar</button>
    </div>
  </div>

  <div class="kanban-toggle">
    <button type="button" id="kanban-filter-btn" class="kanban-toggle-btn" onclick="toggleKanbanFilters()"><i class="ti ti-filter"></i> Filtros <span id="kanban-filter-text">Expandir</span> <i id="kanban-filter-icon" class="ti ti-chevron-down"></i></button>
    <span style="font-size:.78rem;color:#64748b"><?= htmlspecialchars($filter_status ?: 'Todos status') ?> • <?= htmlspecialchars($filter_tipo) ?> • <?= $q ? 'Busca: '.htmlspecialchars(mb_strimwidth($q,0,22,'…')) : 'Sem busca' ?></span>
  </div>
  <div id="kanban-filters" class="kanban-filters collapsed" style="display:none">
    <!-- Filtro de status -->
    <div class="am-filters-bar">
        <div class="am-filter-group">
            <label>STATUS</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>" class="am-type-tab <?= $filter_status==='' ? 'active' : '' ?>">Todos</a>
                <?php foreach (Transfer::getStatusOptions() as $key => $label): ?>
                <a href="?<?= http_build_query(['status' => $key,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>" class="am-type-tab <?= $filter_status===$key ? 'active' : '' ?>"><span style="color:<?= Transfer::getStatusColor($key) ?>;font-weight:700;"><?= htmlspecialchars($label) ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="am-filters-bar">
        <div class="am-filter-group">
            <label>EXIBIR</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['tipo'=>'all','status'=>$filter_status,'cat'=>$filter_cat?:'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort,'q'=>$q]) ?>" class="am-type-tab <?= $filter_tipo==='all' ? 'active' : '' ?>">Todos (<?= count($transfers)+count($tickets) ?>)</a>
                <a href="?<?= http_build_query(['tipo'=>'transfer','status'=>$filter_status,'cat'=>$filter_cat?:'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort,'q'=>$q]) ?>" class="am-type-tab <?= $filter_tipo==='transfer' ? 'active' : '' ?>"><i class="ti ti-transfer"></i> Transferências (<?= count($transfers) ?>)</a>
                <a href="?<?= http_build_query(['tipo'=>'ticket','status'=>$filter_status,'cat'=>$filter_cat?:'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort,'q'=>$q]) ?>" class="am-type-tab <?= $filter_tipo==='ticket' ? 'active' : '' ?>"><i class="ti ti-ticket"></i> Chamados (<?= count($tickets) ?>)</a>
            </div>
        </div>
        <?php if (!empty($tjCategoriesForFilter)): ?>
        <div class="am-filter-group">
            <label>CATEGORIA ITIL</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['tipo'=>$filter_tipo,'status'=>$filter_status,'cat'=>'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort,'q'=>$q]) ?>" class="am-type-tab <?= !$filter_cat ? 'active' : '' ?>">Todas</a>
                <?php foreach ($tjCategoriesForFilter as $cid=>$cname): ?>
                <a href="?<?= http_build_query(['tipo'=>$filter_tipo,'status'=>$filter_status,'cat'=>$cid,'tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort,'q'=>$q]) ?>" class="am-type-tab <?= $filter_cat===$cid ? 'active' : '' ?>"><?= htmlspecialchars($cname) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="am-filters-bar">
        <div class="am-filter-group" style="flex:1">
            <label>PESQUISAR ENTIDADE</label>
            <div style="position:relative;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div style="position:relative;flex:1;max-width:380px;min-width:220px;">
                    <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.95rem;pointer-events:none;"></i>
                    <input type="text" id="kanban-entity-search" value="<?= htmlspecialchars($q) ?>" placeholder="Digite escola, URE, nº..." style="width:100%;padding:8px 34px 8px 32px;border:1.5px solid #e8eaf0;border-radius:10px;font-size:.85rem;background:#fff;" autocomplete="off" oninput="filterKanbanExternal(this.value)">
                </div>
                <span id="kanban-entity-count" style="font-size:.75rem;color:#9ca3af;white-space:nowrap;"></span>
                <?php if($q!==''): ?><a href="?<?= http_build_query(['status'=>$filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort]) ?>" class="am-type-tab" style="padding:6px 10px;font-size:.75rem;"><i class="ti ti-x"></i> Limpar filtro “<?= htmlspecialchars(mb_strimwidth($q,0,22,'…')) ?>”</a><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="am-filters-bar">
        <div class="am-filter-group">
            <label>DATA</label>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div class="am-type-tabs">
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => 'recent', 'q' => $q]) ?>" class="am-type-tab <?= $filter_sort !== 'old' && !$filter_date ? 'active' : '' ?>">Mais recente</a>
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => 'old', 'q' => $q]) ?>" class="am-type-tab <?= $filter_sort === 'old' && !$filter_date ? 'active' : '' ?>">Mais antigo</a>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="date" class="am-input" value="<?= htmlspecialchars($filter_date) ?>" title="Filtrar por dia" onchange="var u=new URL(window.location.href);u.searchParams.set('date',this.value);u.searchParams.delete('sort');window.location.href=u.href;" style="padding:7px 10px;margin-top:0;font-size:.82rem;width:auto;">
                    <?php if ($filter_date): ?>
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => $filter_sort, 'q' => $q]) ?>" class="am-type-tab active" title="Limpar filtro de data"><i class="ti ti-x"></i> Limpar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
  </div>

  <div class="kanban-body">
    <div class="am-kanban">
        <?php
            $kanbanStages = [
                'pendente'   => ['label'=>'PENDENTE', 'color'=>'#6b7280', 'desc'=>'Novos'],
                'emandamento'=> ['label'=>'Em Andamento', 'color'=>'#f59e0b', 'desc'=>'Só seus'],
                'retirada'   => ['label'=>'RETIRADA', 'color'=>'#10b981', 'desc'=>'Aguardando'],
                'concluido'  => ['label'=>'CONCLUÍDO', 'color'=>'#3b82f6', 'desc'=>'Finalizado'],
            ];
            foreach ($kanbanStages as $stageKey=>$stage):
                $sLabel = $stage['label'];
                $sColor = $stage['color'];
                $colCards = array_values(array_filter($combined, function($it) use ($stageKey, $currentUserId){
                    if ($stageKey==='pendente') {
                        if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_PENDENTE) return true;
                        if ($it['type']==='ticket' && (int)$it['data']['status']===1) return true;
                        return false;
                    }
                    if ($stageKey==='emandamento') {
                        if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_MANUTENCAO && (int)($it['data']['users_id_tech'] ?? 0)===$currentUserId) return true;
                        if ($it['type']==='ticket' && (int)$it['data']['status']===2 && (int)($it['data']['assigned_users_id'] ?? 0)===$currentUserId) return true;
                        return false;
                    }
                    if ($stageKey==='retirada') {
                        if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_PRONTO) return true;
                        return false;
                    }
                    if ($stageKey==='concluido') {
                        if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_FINALIZADO) return true;
                        if ($it['type']==='ticket' && in_array((int)$it['data']['status'], [5,6,3,4])) return true;
                        return false;
                    }
                    return false;
                }));
        ?>
        <div class="am-kanban-column" data-stage="<?= $stageKey ?>">
            <div class="am-kanban-header" style="border-top:4px solid <?= $sColor ?>">
                <span style="display:flex;align-items:center;gap:8px"><span><?= htmlspecialchars($sLabel) ?></span><small style="font-weight:600;color:#6b7280;font-size:.68rem;background:#f3f4f6;border-radius:20px;padding:2px 7px;"><?= htmlspecialchars($stage['desc']) ?></small></span>
                <span class="am-kanban-count"><?= count($colCards) ?></span>
            </div>
            <div class="am-kanban-body" id="kanban-body-<?= $stageKey ?>" data-stage="<?= $stageKey ?>" ondrop="amKanbanDrop(event)" ondragover="amKanbanDragOver(event)" ondragleave="amKanbanDragLeave(event)">
                <?php
                    $colCardsToRender = $colCards;
                    if ($stageKey==='emandamento') {
                        $colCardsToRender = array_values(array_filter($combined, function($it){
                            if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_MANUTENCAO) return true;
                            if ($it['type']==='ticket' && (int)$it['data']['status']===2) return true;
                            return false;
                        }));
                        $colCards = array_values(array_filter($colCardsToRender, function($it) use ($currentUserId){
                            if ($it['type']==='ticket') return (int)($it['data']['assigned_users_id'] ?? 0) === $currentUserId;
                            return (int)($it['data']['users_id_tech'] ?? 0) === $currentUserId;
                        }));
                        $colCardsToRender = array_values(array_filter($combined, function($it){
                            if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_MANUTENCAO) return true;
                            if ($it['type']==='ticket' && (int)$it['data']['status']===2) return true;
                            return false;
                        }));
                    }
                    foreach ($colCardsToRender as $item):
                        $isHiddenPego = ($stageKey==='emandamento' && (
                            ($item['type']==='transfer' && (int)($item['data']['users_id_tech'] ?? 0) !== $currentUserId) ||
                            ($item['type']==='ticket' && (int)($item['data']['assigned_users_id'] ?? 0) !== $currentUserId)
                        ));
                        $isTicket = $item['type']==='ticket';
                        $canDrag = !in_array($stageKey, ['retirada','concluido'], true) && !($isTicket && $stageKey==='retirada');
                        if ($item['type']==='transfer') {
                            $t = $item['data'];
                            $borderColor = '#f59e0b';
                        } else {
                            $tk = $item['data'];
                            $borderColor = '#2563eb';
                        }
                ?>
                <?php if ($item['type']==='transfer'): $t = $item['data']; ?>
                <div class="am-tc-card <?= ($stageKey==='emandamento' && $isHiddenPego) ? 'am-kanban-hidden-pego' : '' ?>" draggable="<?= $canDrag ? 'true' : 'false' ?>" ondragstart="amKanbanDragStart(event)" data-type="transfer" data-id="<?= $t['id'] ?>" data-date="<?= htmlspecialchars($t['date_creation']) ?>" data-status="<?= htmlspecialchars($t['status']) ?>" style="margin:0;<?= ($stageKey==='emandamento' && $isHiddenPego) ? 'display:none;' : '' ?>;border-left:4px solid <?= $borderColor ?>;cursor:pointer;" onclick="if(!event.target.closest('button,a')) amOpenCardModal('transfer', <?= (int)$t['id'] ?>)" data-mine="<?= ($stageKey==='emandamento' && !$isHiddenPego) ? '1' : '0' ?>">
                    <div class="am-tc-card-header" style="border-left:4px solid <?= $borderColor ?>;padding:14px;text-align:center;flex-direction:column;align-items:center;">
                        <span class="am-badge <?= Transfer::getStatusBadgeClass($t['status']) ?>" style="font-size:.65rem;"><?= htmlspecialchars($sLabel) ?></span>
                        <div style="font-size:.65rem;color:#9ca3af;font-weight:700;">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?> • <?= date('d/m H:i', strtotime($t['date_creation'])) ?></div>
                        <div style="font-weight:800;font-size:.95rem;color:#1e2333;text-align:center;word-break:break-word;"><?= htmlspecialchars($t['origin_entity_name']) ?></div>
                    </div>
                    <div class="am-tc-card-body" style="padding:10px 14px;">
                        <div class="am-tc-info-row"><i class="ti ti-box"></i><span><?= $t['items_count'] ?> ativo(s)</span></div>
                        <?php if ($t['reason']): ?><div class="am-tc-reason"><?= htmlspecialchars($t['reason']) ?></div><?php endif; ?>
                    </div>
                    <div class="am-tc-card-footer">
                        <?php if ($t['status']===Transfer::STATUS_PENDENTE): ?>
                        <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;padding:7px 10px;font-size:.8rem;" onclick="amOpenPegarModal(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>',<?= $t['items_count'] ?>)"><i class="ti ti-hand-grab"></i> Pegar</button>
                        <?php elseif ($t['status']===Transfer::STATUS_MANUTENCAO): ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_diario.php?id=<?= $t['id'] ?>" class="am-btn am-btn-secondary" style="flex:1;padding:7px 10px;font-size:.8rem;"><i class="ti ti-clipboard-text"></i> Diário</a>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.php?id=<?= $t['id'] ?>" class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;flex:1;padding:7px 10px;font-size:.8rem;"><i class="ti ti-check"></i> Pronto</a>
                        <?php elseif ($t['status']===Transfer::STATUS_PRONTO): ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/assinatura.php" class="am-btn am-btn-secondary" style="flex:1;padding:7px 10px;font-size:.8rem;background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="ti ti-signature"></i> Assinar</a>
                        <?php else: ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:7px 10px;font-size:.8rem;"><i class="ti ti-file-type-pdf"></i> PDF</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: $tk = $item['data']; $tkStatusColor = match((int)$tk['status']){1=>'#f59e0b',2=>'#3b82f6',5=>'#10b981',6=>'#111827',default=>'#6b7280'}; $tkStatusLabel = (class_exists('Ticket') && method_exists('Ticket','getStatus')) ? Ticket::getStatus($tk['status']) : $tk['status']; ?>
                <div class="am-tc-card <?= ($stageKey==='emandamento' && $isHiddenPego) ? 'am-kanban-hidden-pego' : '' ?>" draggable="<?= $canDrag ? 'true' : 'false' ?>" ondragstart="amKanbanDragStart(event)" data-type="ticket" data-id="<?= $tk['id'] ?>" style="margin:0;<?= ($stageKey==='emandamento' && $isHiddenPego) ? 'display:none;' : '' ?>;border-left:4px solid #2563eb;cursor:pointer;" onclick="if(!event.target.closest('button,a')) amOpenCardModal('ticket', <?= (int)$tk['id'] ?>)" data-mine="<?= $isHiddenPego ? '0' : '1' ?>">
                    <div class="am-tc-card-header" style="border-left:4px solid #2563eb;padding:14px;text-align:center;flex-direction:column;">
                        <span class="am-badge" style="background:<?= $tkStatusColor ?>;color:#fff;font-size:.65rem;"><?= htmlspecialchars($tkStatusLabel) ?></span>
                        <div style="font-size:.65rem;color:#2563eb;font-weight:700;">Chamado #<?= str_pad($tk['id'],6,'0',STR_PAD_LEFT) ?> • <?= htmlspecialchars($tk['category_name']) ?></div>
                        <div style="font-weight:800;font-size:.95rem;color:#1e2333;word-break:break-word;"><?= htmlspecialchars($tk['name']?:'Sem título') ?></div>
                    </div>
                    <div class="am-tc-card-body" style="padding:10px 14px;">
                        <?php if ($tk['entity_name']): ?><div class="am-tc-info-row"><i class="ti ti-building"></i><span><?= htmlspecialchars($tk['entity_name']) ?></span></div><?php endif; ?>
                        <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span><?= Html::convDateTime($tk['date_mod']?:$tk['date_creation']) ?></span></div>
                        <?php if (!empty($tk['assigned_name'])): ?><div class="am-tc-info-row"><i class="ti ti-user-check" style="color:#10b981;"></i><span style="color:#059669;font-weight:700;">Atribuído: <?= htmlspecialchars($tk['assigned_name']) ?></span></div><?php endif; ?>
                    </div>
                    <div class="am-tc-card-footer">
                        <?php if ((int)$tk['status']===1): ?>
                        <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;padding:7px 10px;font-size:.8rem;" onclick="amOpenPegarTicketModal(<?= (int)$tk['id'] ?>,'<?= htmlspecialchars(addslashes($tk['name']?:'Chamado #'.$tk['id'])) ?>')"><i class="ti ti-hand-grab"></i> Pegar</button>
                        <?php endif; ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/front/ticket.form.php?id=<?= (int)$tk['id'] ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:7px 10px;font-size:.8rem;"><i class="ti ti-external-link"></i> Abrir</a>
                    </div>
                </div>
                <?php endif; endforeach; ?>
                <?php if (empty($colCardsToRender)): ?><div class="am-kanban-empty">Nenhum card</div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Modais reutilizados (mesmos de tecnico.php) -->
<div id="am-modal-card-details" class="am-modal-overlay" onclick="if(event.target===this) amCloseCardModal()" style="z-index:10002;">
    <div class="am-modal" style="max-width:680px;width:95%;max-height:90vh;display:flex;flex-direction:column;" onclick="event.stopPropagation()">
        <div class="am-modal-header" id="am-card-details-header" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
            <div class="am-modal-title"><i class="ti ti-info-circle"></i><span id="am-card-details-title">Detalhes</span></div>
            <button class="am-modal-close" onclick="amCloseCardModal()"><i class="ti ti-x"></i></button>
        </div>
        <div class="am-modal-body" id="am-card-details-body" style="padding:20px;background:#fff;overflow-y:auto;flex:1;"></div>
        <div class="am-modal-footer" style="justify-content:space-between;">
            <button class="am-btn am-btn-secondary" onclick="amCloseCardModal()">Fechar</button>
            <a id="am-card-details-link" href="#" target="_blank" class="am-btn am-btn-primary" style="display:none;"><i class="ti ti-external-link"></i> Abrir no GLPI</a>
        </div>
    </div>
</div>
<div id="am-modal-pegar" class="am-modal-overlay" onclick="if(event.target===this) amClosePegarModal()" style="z-index:10003;">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-hand-grab"></i><span>Assumir Manutenção</span></div>
            <button class="am-modal-close" onclick="amClosePegarModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-pegar-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php" onsubmit="return amSubmitPegar(event)">
            <input type="hidden" name="action" value="pegar"><input type="hidden" name="transfer_id" id="am-pegar-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;text-align:center">
                <div style="width:56px;height:56px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;"><i class="ti ti-hand-grab" style="font-size:1.8rem;color:#fff;"></i></div>
                <div style="font-weight:700;color:#1e1b4b;">Assumir esta transferência?</div>
                <div id="am-pegar-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                <label style="display:flex;align-items:center;gap:8px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:10px;padding:10px 12px;margin-top:16px;cursor:pointer;font-size:.85rem"><input type="checkbox" id="am-pegar-agree" onchange="amTogglePegarBtn()" style="width:18px;height:18px;accent-color:#4f46e5"><span>Confirmo que estou assumindo esta manutenção</span></label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:12px">
                <button type="button" class="am-btn am-btn-secondary" onclick="amClosePegarModal()">Cancelar</button>
                <button type="submit" id="am-pegar-btn" class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;opacity:.4;cursor:not-allowed;" disabled><i class="ti ti-hand-grab"></i> Pegar</button>
            </div>
        </form>
    </div>
</div>
<div id="am-modal-pegar-ticket" class="am-modal-overlay" onclick="if(event.target===this) amClosePegarTicketModal()" style="z-index:10003;">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-hand-grab"></i><span>Assumir Chamado</span></div>
            <button class="am-modal-close" onclick="amClosePegarTicketModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-pegar-ticket-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php" onsubmit="return amSubmitPegarTicket(event)">
            <input type="hidden" name="action" value="pegar_ticket"><input type="hidden" name="tickets_id" id="am-pegar-ticket-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;text-align:center">
                <div style="font-weight:700;color:#1e1b4b;">Assumir este chamado?</div>
                <div id="am-pegar-ticket-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                <label style="display:flex;align-items:center;gap:8px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:10px;padding:10px 12px;margin-top:16px;cursor:pointer;font-size:.85rem"><input type="checkbox" id="am-pegar-ticket-agree" onchange="amTogglePegarTicketBtn()" style="width:18px;height:18px;accent-color:#4f46e5"><span>Confirmo que estou assumindo este chamado</span></label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:12px">
                <button type="button" class="am-btn am-btn-secondary" onclick="amClosePegarTicketModal()">Cancelar</button>
                <button type="submit" id="am-pegar-ticket-btn" class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;opacity:.4;cursor:not-allowed;" disabled>Pegar</button>
            </div>
        </form>
    </div>
</div>
<div id="am-kanban-confirm-modal" class="am-modal-overlay">
    <div class="am-modal" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);">
            <div class="am-modal-title"><i class="ti ti-arrows-move"></i><span>Mover no Kanban</span></div>
        </div>
        <div class="am-modal-body" style="padding:24px;text-align:center;">
            <div id="am-kanban-confirm-text" style="font-weight:700;color:#1e1b4b;">Mover card?</div>
            <div id="am-kanban-confirm-sub" style="font-size:.82rem;color:#6b7280;margin-top:6px;"></div>
        </div>
        <div class="am-modal-footer" style="justify-content:center;gap:12px">
            <button type="button" class="am-btn am-btn-secondary" onclick="amKanbanConfirmClose()">Cancelar</button>
            <button type="button" id="am-kanban-confirm-btn" class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amKanbanConfirmGo()">Confirmar</button>
        </div>
    </div>
</div>

<script>
function toggleKanbanFilters(){
  var c=document.getElementById('kanban-filters');
  var b=document.getElementById('kanban-filter-btn');
  var t=document.getElementById('kanban-filter-text');
  var i=document.getElementById('kanban-filter-icon');
  if(c.style.display==='none' || c.classList.contains('collapsed')){
    c.style.display='block'; c.classList.remove('collapsed'); c.classList.add('expanded');
    b.classList.add('active');
    if(t) t.textContent='Recolher';
    if(i){ i.classList.remove('ti-chevron-down'); i.classList.add('ti-chevron-up'); }
  } else {
    c.style.display='none'; c.classList.add('collapsed'); c.classList.remove('expanded');
    b.classList.remove('active');
    if(t) t.textContent='Expandir';
    if(i){ i.classList.remove('ti-chevron-up'); i.classList.add('ti-chevron-down'); }
  }
}
function filterKanbanExternal(q){
  q=(q||'').toLowerCase();
  try{ q=q.normalize('NFD').replace(/[\u0300-\u036f]/g,''); }catch(e){}
  var cards=document.querySelectorAll('.am-tc-card');
  var cnt=document.getElementById('kanban-entity-count');
  var visible=0;
  cards.forEach(function(card){
    var txt=(card.textContent||'').toLowerCase();
    try{ txt=txt.normalize('NFD').replace(/[\u0300-\u036f]/g,''); }catch(e){}
    var show=!q || txt.indexOf(q)!==-1;
    card.style.display=show?'':'none';
    if(show) visible++;
  });
  if(cnt) cnt.textContent=q? visible+' de '+cards.length+' exibido(s)':'';
  var url=new URL(window.location.href);
  if(q) url.searchParams.set('q', document.getElementById('kanban-entity-search').value.trim());
  else url.searchParams.delete('q');
  history.replaceState(null,'',url.toString());
}
// clock
setInterval(function(){ var el=document.getElementById('kanban-clock'); if(el) el.textContent=new Date().toLocaleTimeString('pt-BR'); },1000);
(function(){ var el=document.getElementById('kanban-clock'); if(el) el.textContent=new Date().toLocaleTimeString('pt-BR'); })();
// limita CONCLUÍDO a 15 no kanban externo
function kanbanLimitConcluido(){
    var body=document.getElementById('kanban-body-concluido');
    if(!body) return;
    var cards=Array.from(body.querySelectorAll('.am-tc-card'));
    if(cards.length<=15) return;
    var hidden=0;
    cards.forEach(function(c,idx){ if(idx>=15){ c.style.display='none'; c.dataset.hiddenByLimit='1'; hidden++; } });
    if(hidden>0 && !document.getElementById('kanban-concluido-more')){
        var more=document.createElement('div');
        more.id='kanban-concluido-more';
        more.style.cssText='text-align:center;padding:10px;';
        more.innerHTML='<div style="font-size:.82rem;font-weight:700;color:#3b82f6;">Mostrando 15 de '+cards.length+'</div><div style="font-size:.70rem;color:#9ca3af;margin-top:4px;">Use filtros ou maximize para ver todos</div>';
        body.appendChild(more);
        var cnt=document.querySelector('.am-kanban-column[data-stage="concluido"] .am-kanban-count');
        if(cnt){ cnt.textContent=cards.length; cnt.title=cards.length+' total — 15 visíveis'; }
    }
}
document.addEventListener('DOMContentLoaded',function(){ try{ kanbanLimitConcluido(); }catch(e){} });
// auto-atualizacao a cada 10s via hash (mesmo de tecnico.php)
var _kanbanCheckBase='<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/tecnico_check.php';
var _kanbanLastHash=null;
var _kanbanLastCount=document.querySelectorAll('.am-tc-card').length;
fetch(_kanbanCheckBase+window.location.search,{headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(function(r){ return r.json(); }).then(function(d){ _kanbanLastHash=d.hash; _kanbanLastCount=d.count; }).catch(function(){});
function kanbanCheckForUpdates(){
    if(document.querySelector('.am-modal-overlay.open')) return;
    fetch(_kanbanCheckBase+window.location.search,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); }).then(function(d){
        // atualiza relogio de refresh no header (se existir)
        if(d.hash!==_kanbanLastHash && _kanbanLastHash!==null){
            _kanbanLastHash=d.hash; _kanbanLastCount=d.count;
            // toast e reload suave do kanban
            var toast=document.createElement('div');
            toast.textContent='🔔 Nova atualização — recarregando kanban...';
            toast.style.cssText='position:fixed;top:16px;right:16px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:10px 16px;border-radius:10px;font-weight:700;font-size:.82rem;box-shadow:0 8px 24px rgba(0,0,0,.2);z-index:10000;opacity:0;transition:opacity .3s';
            document.body.appendChild(toast);
            requestAnimationFrame(function(){ toast.style.opacity='1'; });
            setTimeout(function(){
                // soft reload via fetch da pagina
                fetch(window.location.href,{headers:{'X-Requested-With':'XMLHttpRequest'}})
                  .then(function(r){ return r.text(); }).then(function(html){
                    try{
                        var parser=new DOMParser(); var doc=parser.parseFromString(html,'text/html');
                        var newKanban=doc.querySelector('.am-kanban');
                        var curKanban=document.querySelector('.am-kanban');
                        if(newKanban && curKanban){
                            curKanban.innerHTML=newKanban.innerHTML;
                            try{ kanbanLimitConcluido(); }catch(e){}
                            var newCount=curKanban.querySelectorAll('.am-tc-card').length;
                            if(newCount>_kanbanLastCount){
                                toast.textContent='🔔 '+ (newCount-_kanbanLastCount) +' novo(s) card(s)!';
                            }
                        } else {
                            location.reload();
                        }
                    }catch(e){ location.reload(); }
                    setTimeout(function(){ toast.style.opacity='0'; setTimeout(function(){ toast.remove(); },300); },2500);
                  }).catch(function(){ location.reload(); });
            },600);
        } else if(_kanbanLastHash===null){
            _kanbanLastHash=d.hash; _kanbanLastCount=d.count;
        }
      }).catch(function(){});
}
setInterval(kanbanCheckForUpdates,10000);
// Reusa funções de tecnico.php (copiadas)
function amGetCsrfToken(){
    try{
        var el = document.querySelector('#am-pegar-form input[name="_glpi_csrf_token"]') || document.querySelector('input[name="_glpi_csrf_token"]');
        if(el && el.value) return el.value;
        if(window.CFG_GLPI && window.CFG_GLPI.csrf_token) return window.CFG_GLPI.csrf_token;
    }catch(e){}
    return '';
}
function amOpenPegarModal(id, entity, count){
    document.getElementById('am-pegar-id').value=id;
    document.getElementById('am-pegar-info').textContent=count+' ativo(s) • '+entity;
    document.getElementById('am-pegar-agree').checked=false;
    amTogglePegarBtn();
    document.getElementById('am-modal-pegar').classList.add('open');
}
function amClosePegarModal(){ document.getElementById('am-modal-pegar').classList.remove('open'); }
function amTogglePegarBtn(){ var ok=document.getElementById('am-pegar-agree').checked; var b=document.getElementById('am-pegar-btn'); b.disabled=!ok; b.style.opacity=ok?'1':'.4'; b.style.cursor=ok?'pointer':'not-allowed'; }
function amOpenPegarTicketModal(id,name){
    document.getElementById('am-pegar-ticket-id').value=id;
    document.getElementById('am-pegar-ticket-info').textContent='Chamado #'+String(id).padStart(6,'0')+' • '+name;
    document.getElementById('am-pegar-ticket-agree').checked=false;
    amTogglePegarTicketBtn();
    document.getElementById('am-modal-pegar-ticket').classList.add('open');
}
function amClosePegarTicketModal(){ document.getElementById('am-modal-pegar-ticket').classList.remove('open'); }
function amTogglePegarTicketBtn(){ var ok=document.getElementById('am-pegar-ticket-agree').checked; var b=document.getElementById('am-pegar-ticket-btn'); b.disabled=!ok; b.style.opacity=ok?'1':'.4'; b.style.cursor=ok?'pointer':'not-allowed'; }
function amOpenCardModal(type,id){
    var modal=document.getElementById('am-modal-card-details');
    var titleEl=document.getElementById('am-card-details-title');
    var bodyEl=document.getElementById('am-card-details-body');
    var linkEl=document.getElementById('am-card-details-link');
    var headerEl=document.getElementById('am-card-details-header');
    if(!modal||!bodyEl) return;
    var isTicket=type==='ticket';
    titleEl.textContent=isTicket?'Chamado #'+String(id).padStart(6,'0'):'Transferência #'+String(id).padStart(4,'0');
    bodyEl.innerHTML='<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="ti ti-loader-2" style="animation:spin .8s linear infinite;font-size:1.5rem;display:block;margin-bottom:8px;"></i> Carregando...</div>';
    linkEl.style.display='none';
    if(headerEl) headerEl.style.background=isTicket?'linear-gradient(135deg,#2563eb,#3b82f6)':'linear-gradient(135deg,#d97706,#f59e0b)';
    modal.classList.add('open');
    var base='';
    try{
        var idx=window.location.pathname.indexOf('/plugins/assetmgrstatus/');
        if(idx!==-1) base=window.location.pathname.substring(0,idx)+'/plugins/assetmgrstatus/ajax/card_details.php';
        else base=window.location.origin+'/glpi/plugins/assetmgrstatus/ajax/card_details.php';
    }catch(e){ base='ajax/card_details.php'; }
    fetch(base+'?type='+encodeURIComponent(type)+'&id='+encodeURIComponent(id),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json().then(function(j){ return {ok:r.ok,json:j}; }); })
        .then(function(res){
            if(!res.ok||!res.json.success) throw new Error(res.json.message||'Falha ao carregar');
            var d=res.json.data;
            if(isTicket){
                var statusColor=d.status===1?'#f59e0b':d.status===2?'#3b82f6':d.status===5?'#10b981':d.status===6?'#111827':'#6b7280';
                var html='<div style="display:flex;flex-direction:column;gap:14px;">';
                html+='<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="am-badge" style="background:'+statusColor+';color:#fff;">'+d.status_label+'</span><span class="am-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><i class="ti ti-category"></i> '+d.category+'</span><span style="font-size:.82rem;color:#6b7280;"><i class="ti ti-building"></i> '+(d.entity||'—')+'</span></div>';
                html+='<h3 style="margin:0;font-size:1.05rem;font-weight:800;color:#1e2333;">'+d.name+'</h3>';
                html+='<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#f8f9fb;border:1px solid #e8eaf0;border-radius:10px;padding:12px;">';
                html+='<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Atribuído</div><div style="font-weight:700;color:'+(d.assigned?'#059669':'#9ca3af')+';">'+(d.assigned||'Sem técnico')+'</div></div>';
                html+='<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Prioridade</div><div>'+d.priority+'</div></div>';
                html+='</div>';
                if(d.content) html+='<div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:14px;"><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;">Descrição</div><div style="font-size:.88rem;color:#374151;line-height:1.6;white-space:pre-wrap;word-break:break-word;">'+d.content_html+'</div></div>';
                html+='</div>';
                bodyEl.innerHTML=html;
                linkEl.href=(window.CFG_GLPI&&window.CFG_GLPI.root_doc?window.CFG_GLPI.root_doc:'/glpi')+'/front/ticket.form.php?id='+d.id;
                linkEl.style.display='inline-flex';
                linkEl.innerHTML='<i class="ti ti-external-link"></i> Abrir Chamado no GLPI';
                if(d.status===1){
                    bodyEl.innerHTML+='<button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;margin-top:10px;width:100%;" onclick="amCloseCardModal(); setTimeout(function(){ amOpenPegarTicketModal('+d.id+', '+JSON.stringify(d.name)+') }, 80)"><i class="ti ti-hand-grab"></i> Pegar este chamado</button>';
                }
            } else {
                var html2='<div style="display:flex;flex-direction:column;gap:14px;">';
                html2+='<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="am-badge" style="background:'+d.status_color+';color:#fff;">'+d.status_label+'</span><span style="font-size:.82rem;color:#6b7280;">'+d.items_count+' ativo(s)</span></div>';
                html2+='<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#f8f9fb;border:1px solid #e8eaf0;border-radius:10px;padding:12px;">';
                html2+='<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Origem</div><div style="font-weight:700;">'+(d.origin||'—')+'</div></div>';
                html2+='<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Destino</div><div style="font-weight:700;">'+(d.dest||'—')+'</div></div>';
                html2+='</div>';
                if(d.reason) html2+='<div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:12px;"><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">Motivo</div><div style="font-size:.88rem;color:#374151;">'+d.reason+'</div></div>';
                if(d.items&&d.items.length){ html2+='<div><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;">Itens ('+d.items.length+')</div><div style="display:flex;flex-direction:column;gap:6px;">'; d.items.forEach(function(it){ var tp=it.type.replace('Glpi\\\\CustomAsset\\\\','').replace('Asset',''); html2+='<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #e8eaf0;border-radius:8px;padding:8px 10px;"><span style="font-weight:600;">'+it.name+'</span><span style="font-size:.75rem;color:#6b7280;">'+tp+'</span></div>'; }); html2+='</div></div>'; }
                html2+='</div>';
                if(d.status==='pendente'){ html2+='<button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;width:100%;margin-top:4px;" onclick="amCloseCardModal(); setTimeout(function(){ amOpenPegarModal('+d.id+', '+JSON.stringify(d.origin||'')+', '+d.items_count+') }, 80)"><i class="ti ti-hand-grab"></i> Pegar esta transferência</button>'; }
                bodyEl.innerHTML=html2;
                if(d.tickets_id){ linkEl.href='/glpi/front/ticket.form.php?id='+d.tickets_id; linkEl.style.display='inline-flex'; linkEl.innerHTML='<i class="ti ti-external-link"></i> Ver Chamado'; } else linkEl.style.display='none';
            }
        })
        .catch(function(err){ bodyEl.innerHTML='<div style="color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;">Erro: '+err.message+'</div>'; });
}
function amCloseCardModal(){ var m=document.getElementById('am-modal-card-details'); if(m) m.classList.remove('open'); }
function amKanbanDragStart(e){ var card=e.currentTarget; e.dataTransfer.setData('text/plain', JSON.stringify({type:card.dataset.type, id:card.dataset.id})); e.dataTransfer.effectAllowed='move'; card.style.opacity='0.5'; }
function amKanbanDragOver(e){ e.preventDefault(); var col=e.currentTarget; col.style.background='#eef2ff'; col.style.borderColor='#c7d2fe'; e.dataTransfer.dropEffect='move'; }
function amKanbanDragLeave(e){ var col=e.currentTarget; col.style.background='#fff'; col.style.borderColor='#e8eaf0'; }
var amKanbanPending=null;
function amKanbanDrop(e){
  e.preventDefault();
  var col=e.currentTarget;
  col.style.background='#fff'; col.style.borderColor='#e8eaf0';
  var raw=e.dataTransfer.getData('text/plain');
  var obj; try{ obj=JSON.parse(raw); }catch(err){ return; }
  if(obj.type==='ticket' && col.dataset.stage==='retirada'){ alert('Chamados não podem ir para RETIRADA'); return; }
  var to=col.dataset.stage;
  if(!to||!obj.type||!obj.id) return;
  amKanbanPending={type:obj.type,id:obj.id,to:to};
  document.getElementById('am-kanban-confirm-text').textContent='Mover '+(obj.type==='ticket'?'Chamado':'Transferência')+' #'+obj.id+' para '+to.toUpperCase()+'?';
  document.getElementById('am-kanban-confirm-sub').textContent=obj.type==='ticket'&&to==='emandamento'?'Você será vinculado como técnico.':'Mudança de etapa será registrada.';
  document.getElementById('am-kanban-confirm-modal').classList.add('open');
}
function amKanbanConfirmClose(){ document.getElementById('am-kanban-confirm-modal').classList.remove('open'); amKanbanPending=null; }
function amKanbanConfirmGo(){
  if(!amKanbanPending) return;
  var btn=document.getElementById('am-kanban-confirm-btn');
  var pending={type:amKanbanPending.type,id:amKanbanPending.id,to:amKanbanPending.to};
  if(btn){ btn.disabled=true; btn.innerHTML='<i class="ti ti-loader-2" style="animation:spin .8s linear infinite;display:inline-block;"></i> Movendo...'; }
  var fd=new FormData(); fd.append('type',pending.type); fd.append('id',pending.id); fd.append('to',pending.to);
  var csrf=amGetCsrfToken(); fd.append('_glpi_csrf_token',csrf);
  var h={'X-Requested-With':'XMLHttpRequest'}; if(csrf) h['X-Glpi-Csrf-Token']=csrf;
  fetch('<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/kanban_move.php',{method:'POST',body:fd,credentials:'same-origin',headers:h})
    .then(function(r){ return r.text().then(function(t){ var j=null; try{ j=JSON.parse(t);}catch(e){ j={success:false,message:t.slice(0,300)}; } return {ok:r.ok,j:j}; }); })
    .then(function(res){
      if(res.ok&&res.j.success){
        amKanbanConfirmClose();
        var card=document.querySelector('.am-tc-card[data-type="'+pending.type+'"][data-id="'+pending.id+'"]');
        var target=document.getElementById('kanban-body-'+pending.to);
        if(card&&target){ target.prepend(card); card.style.opacity='1'; }
        else setTimeout(function(){ location.reload(); },400);
      } else { alert((res.j&&res.j.message)||'Falha ao mover'); }
      if(btn){ btn.disabled=false; btn.innerHTML='Confirmar'; }
    }).catch(function(err){ alert('Erro: '+err.message); if(btn){ btn.disabled=false; btn.innerHTML='Confirmar'; } });
}
function amSubmitPegar(e){
    if(e) e.preventDefault();
    var id=parseInt(document.getElementById('am-pegar-id').value,10);
    var btn=document.getElementById('am-pegar-btn');
    if(btn){ btn.disabled=true; btn.innerHTML='Pegando...'; }
    var fd=new FormData(); fd.append('type','transfer'); fd.append('id',id); fd.append('to','emandamento');
    var csrf=amGetCsrfToken(); if(csrf) fd.append('_glpi_csrf_token',csrf);
    var h={'X-Requested-With':'XMLHttpRequest'}; if(csrf) h['X-Glpi-Csrf-Token']=csrf;
    fetch('<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/kanban_move.php',{method:'POST',body:fd,credentials:'same-origin',headers:h})
      .then(function(r){ return r.text().then(function(t){ try{ return {ok:r.ok,j:JSON.parse(t)} }catch(e){ return {ok:false,j:{success:false,message:t.slice(0,200)}} }) })
      .then(function(res){ if(res.ok&&res.j.success){ amClosePegarModal(); var card=document.querySelector('.am-tc-card[data-type="transfer"][data-id="'+id+'"]'); var target=document.getElementById('kanban-body-emandamento'); if(card&&target) target.prepend(card); else location.reload(); } else { alert(res.j.message||'Falha'); if(btn){ btn.disabled=false; btn.innerHTML='Pegar'; } } }).catch(function(err){ alert(err.message); if(btn){ btn.disabled=false; btn.innerHTML='Pegar'; } });
    return false;
}
function amSubmitPegarTicket(e){
    if(e) e.preventDefault();
    var id=parseInt(document.getElementById('am-pegar-ticket-id').value,10);
    var btn=document.getElementById('am-pegar-ticket-btn');
    if(btn){ btn.disabled=true; btn.innerHTML='Pegando...'; }
    var fd=new FormData(); fd.append('type','ticket'); fd.append('id',id); fd.append('to','emandamento');
    var csrf=amGetCsrfToken(); if(csrf) fd.append('_glpi_csrf_token',csrf);
    var h={'X-Requested-With':'XMLHttpRequest'}; if(csrf) h['X-Glpi-Csrf-Token']=csrf;
    fetch('<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/kanban_move.php',{method:'POST',body:fd,credentials:'same-origin',headers:h})
      .then(function(r){ return r.text().then(function(t){ try{ return {ok:r.ok,j:JSON.parse(t)} }catch(e){ return {ok:false,j:{success:false,message:t.slice(0,200)}} }) })
      .then(function(res){ if(res.ok&&res.j.success){ amClosePegarTicketModal(); var card=document.querySelector('.am-tc-card[data-type="ticket"][data-id="'+id+'"]'); var target=document.getElementById('kanban-body-emandamento'); if(card&&target) target.prepend(card); else location.reload(); } else { alert(res.j.message||'Falha'); if(btn){ btn.disabled=false; btn.innerHTML='Pegar'; } } }).catch(function(err){ alert(err.message); if(btn){ btn.disabled=false; btn.innerHTML='Pegar'; } });
    return false;
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ amClosePegarModal(); amClosePegarTicketModal(); amCloseCardModal(); amKanbanConfirmClose(); } });
</script>
</body>
</html>
