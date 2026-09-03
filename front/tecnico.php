<?php

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
        // Enriquecer com Atribuído (glpi_tickets_users type=2) para exibir técnico responsável
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

Html::header('Técnico', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'tecnico');
?>

<style>
@keyframes amSpin{to{transform:rotate(360deg)}}
.am-kanban{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;overflow:visible;padding-bottom:0;width:100%;}
.am-kanban-column{min-width:0;max-width:none;width:auto;background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:14px;display:flex;flex-direction:column;max-height:calc(100vh - 150px);min-height:420px;}
.am-page{padding-bottom:0 !important;}
.am-kanban-body{min-height:220px;}
#am-kanban-view{margin-bottom:0;}
.am-page > .am-filters-bar:last-of-type{margin-bottom:12px;}
.am-kanban-header{padding:12px 14px;font-weight:800;font-size:.9rem;color:#1e2333;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;border-bottom:1.5px solid #e8eaf0;background:#fff;border-radius:14px 14px 0 0;position:sticky;top:0;z-index:1;overflow:hidden;}
.am-kanban-count{background:#eef2ff;color:#4f46e5;border-radius:20px;padding:2px 8px;font-size:.72rem;font-weight:700;white-space:nowrap;line-height:1.3;display:inline-flex;align-items:center;flex-shrink:0;}
.am-kanban-body{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1;}
/* Overlay maximizado - altura auto sem retangulo branco em baixo */
#am-kanban-maximized-overlay{padding:16px !important;align-items:center !important;justify-content:center !important;background:rgba(15,23,42,.88) !important;}
#am-kanban-maximized-overlay .am-modal{width:96vw !important;max-width:1400px !important;height:auto !important;max-height:90vh !important;margin:auto !important;border-radius:14px !important;overflow:hidden !important;display:flex !important;flex-direction:column !important;}
#am-kanban-maximized-overlay .am-modal-body{padding:16px !important;flex:1 1 auto !important;overflow-y:auto !important;max-height:calc(90vh - 110px) !important;}
#am-kanban-maximized-overlay .am-modal-header{border-radius:14px 14px 0 0 !important;}
#am-kanban-maximized-overlay .am-modal-footer{border-radius:0 0 14px 14px !important;background:#f8f9fb !important;border-top:1.5px solid #e8eaf0 !important;}
#am-max-grid{grid-template-columns:repeat(auto-fit,minmax(340px,1fr)) !important;gap:16px !important;align-content:start !important;}
.am-kanban-empty{text-align:center;color:#9ca3af;padding:24px 12px;font-size:.85rem;border:1.5px dashed #e8eaf0;border-radius:10px;background:#fff;}
.am-kanban .am-tc-card{margin:0;flex-shrink:0;}
@media(max-width:1280px){.am-kanban{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.am-kanban{display:flex;gap:12px;padding-bottom:12px;overflow-x:auto;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;grid-template-columns:none;}.am-kanban-column{flex:0 0 300px;min-width:300px;max-width:300px;scroll-snap-align:start;}}
</style>
<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Inventário</a>
        <i class="ti ti-chevron-right"></i>
        <span>Técnico</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-tools"></i><h2>Painel do Técnico</h2></div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/dashboard.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-dashboard"></i> Dashboard
            </a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/reports.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-report"></i> Relatórios
            </a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_assinatura', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/assinatura.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-signature"></i> Assinatura
            </a>
            <?php endif; ?>
            <a href="http://10.180.152.27/glpi/plugins/cadastroativos/Cadastro" target="_blank"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-plus"></i> Cadastrar
            </a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/kanban.php<?= $_SERVER['QUERY_STRING'] ? '?'.htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" target="_blank"
               class="am-btn" style="padding:8px 14px;font-size:.82rem;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border:1px solid #1e293b;box-shadow:0 2px 8px rgba(15,23,42,.2);"
               title="Abrir Kanban em página externa (tela cheia, sem menu GLPI)">
                <i class="ti ti-external-link"></i> Kanban Externo
            </a>

            <button id="am-refresh-btn" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;" onclick="amManualRefresh(this)" title="Atualizar agora">
                <i class="ti ti-refresh"></i> Atualizar
            </button>
            <span id="am-refresh-time" style="font-size:.72rem;color:#9ca3af;white-space:nowrap;"></span>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-arrow-left"></i> Inventário
            </a>
        </div>
    </div>

    <style>
    .tec-filter-toggle{margin-bottom:12px;display:flex;align-items:center;gap:8px}
    .tec-filter-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#fff;border:1.5px solid #e8eaf0;border-radius:8px;font-size:.85rem;font-weight:700;color:#374151;cursor:pointer;transition:all .15s}
    .tec-filter-btn:hover{background:#f8fafc;border-color:#cbd5e1}
    .tec-filter-btn.active{background:#eef2ff;border-color:#c7d2fe;color:#4f46e5}
    .tec-filters-collapsible.collapsed{display:none}
    .tec-filters-collapsible.expanded{display:block;animation:tecFadeIn .2s ease}
    @keyframes tecFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    </style>
    <?php
    $tec_has_active = ($filter_status!=='' || $filter_tech || $filter_date!=='' || $filter_sort!=='recent' || $filter_tipo!=='all' || $filter_cat || $q!=='');
    $tec_active_count = ($filter_status!==''?1:0)+($filter_tech?1:0)+($filter_date!==''?1:0)+($filter_sort!=='recent'?1:0)+($filter_tipo!=='all'?1:0)+($filter_cat?1:0)+($q!==''?1:0);
    ?>
    <div class="tec-filter-toggle">
        <button type="button" id="tec-filter-btn" class="tec-filter-btn" onclick="toggleTecFilters()">
            <i class="ti ti-filter"></i> Filtros <?php if($tec_has_active) echo "<span class='am-comp-filter-count' style='margin-left:4px'>$tec_active_count</span>"; ?> <span id="tec-filter-text">Expandir</span> <i id="tec-filter-icon" class="ti ti-chevron-down" style="margin-left:4px"></i>
        </button>
        <?php if($tec_has_active): ?><small style="color:#6b7280;font-size:.78rem"><i class="ti ti-info-circle"></i> <?= $tec_active_count ?> filtro(s) ativo(s)</small><?php endif; ?>
    </div>
    <div id="tec-filters-collapsible" class="tec-filters-collapsible collapsed" style="display:none">
    <!-- Filtro de status -->
    <div class="am-filters-bar" style="margin-bottom:20px;">
        <div class="am-filter-group">
            <label>STATUS</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>"
                   class="am-type-tab <?= $filter_status==='' ? 'active' : '' ?>">Todos</a>
                <?php foreach (Transfer::getStatusOptions() as $key => $label): ?>
                <a href="?<?= http_build_query(['status' => $key,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>"
                   class="am-type-tab <?= $filter_status===$key ? 'active' : '' ?>">
                    <span style="color:<?= Transfer::getStatusColor($key) ?>;font-weight:700;"><?= htmlspecialchars($label) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Filtro Tipo (Transferência / Chamado) + Categoria ITIL -->
    <div class="am-filters-bar" style="margin-bottom:16px;">
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

    <!-- Pesquisar entidade (filtra ao digitar) -->
    <div class="am-filters-bar" style="margin-bottom:16px;">
        <div class="am-filter-group" style="flex:1;min-width:260px;">
            <label>PESQUISAR ENTIDADE</label>
            <div style="position:relative;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div style="position:relative;flex:1;max-width:380px;min-width:220px;">
                    <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.95rem;pointer-events:none;"></i>
                    <input type="text" id="am-entity-search-tec" value="<?= htmlspecialchars($q) ?>" placeholder="Digite escola, URE, nº..." style="width:100%;padding:8px 34px 8px 32px;border:1.5px solid #e8eaf0;border-radius:10px;font-size:.85rem;background:#fff;" autocomplete="off">
                    <button type="button" id="am-entity-clear-tec" title="Limpar" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:#f3f4f6;border:none;border-radius:6px;padding:4px 6px;cursor:pointer;display:<?= $q!==''?'flex':'none' ?>;align-items:center;justify-content:center;"><i class="ti ti-x" style="font-size:.85rem;color:#6b7280;"></i></button>
                </div>
                <span id="am-entity-count-tec" style="font-size:.75rem;color:#9ca3af;white-space:nowrap;"></span>
                <?php if($q!==''): ?><a href="?<?= http_build_query(['status'=>$filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech'=>$filter_tech?:'','date'=>$filter_date,'sort'=>$filter_sort]) ?>" class="am-type-tab" style="padding:6px 10px;font-size:.75rem;"><i class="ti ti-x"></i> Limpar filtro “<?= htmlspecialchars(mb_strimwidth($q,0,22,'…')) ?>”</a><?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // Filtra por técnico se solicitado
    if ($filter_tech) {
        $transfers = array_filter($transfers, fn($t) => (int)$t['users_id_tech'] === $filter_tech);
        $transfers = array_values($transfers);
    }

    // Filtra por dia específico (data de criação)
    if ($filter_date) {
        $transfers = array_filter($transfers, fn($t) => date('Y-m-d', strtotime($t['date_creation'])) === $filter_date);
        $transfers = array_values($transfers);
    }

    // Filtra por entidade (busca em origem/destino/id/motivo)
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

    // Filtra tickets por data, entidade, categoria
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

    // Monta timeline unificada (transferências + chamados) ordenada por criação
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

    // Sem paginação — mostra todos os cards (kanban já mostra tudo, grid também)
    $tc_total    = count($combined);
    $tc_pages    = 1;
    $tc_page     = 1;
    $tc_per_page = $tc_total ?: 1;
    $combined_page = $combined;

    // Filtro TÉCNICO removido do topo a pedido — agora PEGO mostra só do usuário com botão "Mostrar todos" no Kanban
    $techs_in_transfers = [];
    foreach (Transfer::getAll() as $t) {
        if ($t['users_id_tech'] && $t['tech_name'] && !isset($techs_in_transfers[$t['users_id_tech']])) {
            $techs_in_transfers[$t['users_id_tech']] = $t['tech_name'];
        }
    }
    ?>

    <!-- Filtro de data e ordenação -->
    <div class="am-filters-bar" style="margin-bottom:16px;">
        <div class="am-filter-group">
            <label>DATA</label>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div class="am-type-tabs">
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => 'recent', 'q' => $q]) ?>"
                       class="am-type-tab <?= $filter_sort !== 'old' && !$filter_date ? 'active' : '' ?>">
                        Mais recente
                    </a>
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => 'old', 'q' => $q]) ?>"
                       class="am-type-tab <?= $filter_sort === 'old' && !$filter_date ? 'active' : '' ?>">
                        Mais antigo
                    </a>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="date" class="am-input" value="<?= htmlspecialchars($filter_date) ?>"
                           title="Filtrar por dia"
                           onchange="var u=new URL(window.location.href);u.searchParams.set('date',this.value);u.searchParams.delete('sort');window.location.href=u.href;"
                           style="padding:7px 10px;margin-top:0;font-size:.82rem;width:auto;">
                    <?php if ($filter_date): ?>
                    <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','tech' => $filter_tech ?: '', 'sort' => $filter_sort, 'q' => $q]) ?>"
                       class="am-type-tab active" title="Limpar filtro de data">
                        <i class="ti ti-x"></i> Limpar
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </div>
    <script>
    function toggleTecFilters(){
      var c=document.getElementById('tec-filters-collapsible');
      var b=document.getElementById('tec-filter-btn');
      var t=document.getElementById('tec-filter-text');
      var icon=document.getElementById('tec-filter-icon');
      if(!c || !b){ console.error('tec-filters-collapsible not found'); return; }
      var isHidden = c.classList.contains('collapsed') || window.getComputedStyle(c).display === 'none' || c.style.display === 'none';
      if(isHidden){
        c.style.display='block'; c.classList.remove('collapsed'); c.classList.add('expanded');
        b.classList.add('active');
        if(t) t.textContent='Recolher';
        if(icon){ icon.classList.remove('ti-chevron-down'); icon.classList.add('ti-chevron-up'); }
      } else {
        c.style.display='none'; c.classList.add('collapsed'); c.classList.remove('expanded');
        b.classList.remove('active');
        if(t) t.textContent='Expandir';
        if(icon){ icon.classList.remove('ti-chevron-up'); icon.classList.add('ti-chevron-down'); }
      }
    }
    document.addEventListener('DOMContentLoaded', function(){
      var btn=document.getElementById('tec-filter-btn');
      if(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); toggleTecFilters(); }); console.log('tec-filter-toggle ready'); }
    });
    window.toggleTecFilters = toggleTecFilters;
    </script>

    <?php if (empty($combined_page)): ?>
    <div class="am-empty-state"><i class="ti ti-clipboard-off"></i><p>Nenhum card encontrado (transferência ou chamado) para os filtros atuais.</p></div>
    <?php else: ?>
    <div class="am-tc-grid" id="am-grid-view" style="display:none;">
        <?php foreach ($combined_page as $item):
            if ($item['type'] === 'transfer'):
                $t = $item['data'];
                $endForElapsed = $t['date_finalizado'] ?? $t['date_cancelado'] ?? null;
                $isTerminal = in_array($t['status'], [Transfer::STATUS_FINALIZADO, Transfer::STATUS_CANCELADA], true);
                $elapsed      = Transfer::getElapsedTime($t['date_creation'], $isTerminal ? $endForElapsed : null);
                $status_color = Transfer::getStatusColor($t['status']);
                $status_label = Transfer::getStatusOptions()[$t['status']] ?? $t['status'];
        ?>
        <div class="am-tc-card" style="cursor:pointer;" onclick="if(!event.target.closest('button,a,details,summary')) amOpenCardModal('transfer', <?= (int)$t['id'] ?>)">
            <div class="am-tc-card-header" style="border-left:4px solid <?= $status_color ?>;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;padding:14px 18px 12px;">
                <span class="am-badge <?= Transfer::getStatusBadgeClass($t['status']) ?>" style="font-size:.70rem;"><?= $status_label ?></span>
                <div style="display:flex;flex-direction:column;align-items:center;gap:2px;width:100%;min-width:0;">
                    <div style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;text-align:center;white-space:normal;word-break:break-word;">
                        Transferência #<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?>
                    </div>
                    <div style="font-weight:800;font-size:1rem;color:#1e2333;text-align:center;white-space:normal;word-break:break-word;overflow-wrap:anywhere;">
                        <?= htmlspecialchars($t['origin_entity_name']) ?>
                    </div>
                </div>
            </div>

            <div class="am-tc-card-body">
                <div class="am-tc-info-row"><i class="ti ti-box"></i><span><?= $t['items_count'] ?> ativo(s)</span></div>
                <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span><?= date('d/m/Y H:i', strtotime($t['date_creation'])) ?></span></div>
                <div class="am-tc-info-row">
                    <i class="ti ti-clock"></i>
                    <span class="am-tc-timer" data-start="<?= $t['date_creation'] ?>"
                          data-end="<?= $t['date_finalizado'] ?? $t['date_cancelado'] ?? '' ?>">
                        <?= $elapsed['label'] ?>
                    </span>
                </div>
                <?php if ($t['tech_name']): ?>
                <div class="am-tc-info-row"><i class="ti ti-user-check"></i><span>Téc: <?= htmlspecialchars($t['tech_name']) ?></span></div>
                <?php endif; ?>
                <?php if ($t['creator_name']): ?>
                <div class="am-tc-info-row"><i class="ti ti-user"></i><span>Por: <?= htmlspecialchars($t['creator_name']) ?></span></div>
                <?php endif; ?>
                <?php if ((int)$t['tickets_id'] > 0): ?>
                <div class="am-tc-info-row"><i class="ti ti-ticket"></i><a href="<?= $CFG_GLPI['root_doc'] ?>/front/ticket.form.php?id=<?= (int)$t['tickets_id'] ?>" target="_blank" style="color:#4f46e5;font-weight:600;">Chamado #<?= (int)$t['tickets_id'] ?></a></div>
                <?php endif; ?>
                <?php if ($t['reason']): ?>
                <div class="am-tc-reason"><?= htmlspecialchars(mb_substr($t['reason'], 0, 90)) ?><?= strlen($t['reason']) > 90 ? '...' : '' ?></div>
                <?php endif; ?>
            </div>

            <!-- Barra de progresso dos itens concluídos -->
            <?php
            $tc_total = (int)($t['items_count'] ?? 0);
            $tc_done  = (int)($t['items_done'] ?? 0);
            $tc_pct   = (int)($t['progress_pct'] ?? ($tc_total ? round($tc_done/$tc_total*100) : 0));
            if ($t['status'] === Transfer::STATUS_CANCELADA) { $tc_bar = 'linear-gradient(90deg,#ef4444,#dc2626)'; $tc_label_class = 'cancel'; }
            elseif ($t['status'] === Transfer::STATUS_FINALIZADO) { $tc_bar = 'linear-gradient(90deg,#6b7280,#9ca3af)'; $tc_label_class = 'done'; }
            elseif ($tc_pct === 100) { $tc_bar = 'linear-gradient(90deg,#10b981,#059669)'; $tc_label_class = 'done'; }
            elseif ($tc_pct > 0) { $tc_bar = 'linear-gradient(90deg,#10b981,#34d399)'; $tc_label_class = 'partial'; }
            else { $tc_bar = 'linear-gradient(90deg,#e5e7eb,#d1d5db)'; $tc_label_class = ''; }
            ?>
            <div class="am-tc-progress">
                <div class="am-tc-progress-head">
                    <span><i class="ti ti-progress-check"></i> Progresso</span>
                    <span class="am-tc-progress-label <?= $tc_label_class ?>"><?= $tc_done ?>/<?= $tc_total ?> • <?= $tc_pct ?>%</span>
                </div>
                <div class="am-tc-progress-track" role="progressbar" aria-valuenow="<?= $tc_pct ?>" aria-valuemin="0" aria-valuemax="100" title="<?= $tc_done ?> de <?= $tc_total ?> itens concluídos (<?= $tc_pct ?>%)">
                    <div class="am-tc-progress-fill" style="width:<?= $tc_pct ?>%;background:<?= $tc_bar ?>;"></div>
                </div>
            </div>

            <!-- Tempos por etapa -->
            <div class="am-tc-times">
                <?php
                $cancelEnd = $t['date_cancelado'] ?? null;
                $finalEnd  = $t['date_finalizado'] ?? null;
                $stages = [
                    ['label' => 'Pendente',   'from' => $t['date_pending'],    'to' => $t['date_manutencao'] ?: $cancelEnd],
                    ['label' => 'Manutenção', 'from' => $t['date_manutencao'], 'to' => $t['date_pronto'] ?: $cancelEnd],
                    ['label' => 'Pronto',     'from' => $t['date_pronto'],     'to' => $finalEnd ?: $cancelEnd],
                ];
                foreach ($stages as $stage):
                    if (!$stage['from']) continue;
                    $st = Transfer::getElapsedTime($stage['from'], $stage['to'] ?: null);
                ?>
                <div class="am-tc-time-item">
                    <span class="am-tc-time-label"><?= $stage['label'] ?></span>
                    <span class="am-tc-time-val"><?= $st['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Ações -->
            <div class="am-tc-card-footer">
                <?php if ($t['status'] === Transfer::STATUS_PENDENTE): ?>
                    <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;"
                        onclick="amOpenPegarModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>', <?= $t['items_count'] ?>)">
                        <i class="ti ti-hand-grab"></i> Pegar
                    </button>
                    <button class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;color:#dc2626;border-color:#fecaca;"
                        title="Cancelar transferência"
                        onclick="amOpenCancelarModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>', <?= $t['items_count'] ?>)">
                        <i class="ti ti-x"></i>
                    </button>

                <?php elseif ($t['status'] === Transfer::STATUS_MANUTENCAO): ?>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=transfer"
                       target="_blank" class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;" title="PDF de Retirada">
                        <i class="ti ti-file-type-pdf"></i>
                    </a>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_diario.php?id=<?= $t['id'] ?>"
                       class="am-btn am-btn-secondary" style="flex:1;">
                        <i class="ti ti-clipboard-text"></i> Diário
                    </a>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.php?id=<?= $t['id'] ?>"
                       class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;flex:1;">
                        <i class="ti ti-check"></i> Pronto
                    </a>
                    <button class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;color:#dc2626;border-color:#fecaca;"
                        title="Cancelar transferência"
                        onclick="amOpenCancelarModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>', <?= $t['items_count'] ?>)">
                        <i class="ti ti-x"></i>
                    </button>

                <?php elseif ($t['status'] === Transfer::STATUS_PRONTO): ?>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=pronto"
                       target="_blank" class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;" title="PDF de Devolução">
                        <i class="ti ti-file-type-pdf"></i>
                    </a>
                    <button class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;flex:1;"
                        onclick="amOpenFinalizarModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['entity_dest_name'])) ?>')">
                        <i class="ti ti-flag-check"></i> Finalizar
                    </button>

                <?php elseif ($t['status'] === Transfer::STATUS_FINALIZADO): ?>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=pronto"
                       target="_blank" class="am-btn am-btn-secondary" style="flex:1;">
                        <i class="ti ti-file-type-pdf"></i> PDF Final
                    </a>
                <?php endif; ?>
            </div>

            <?php
            $timeline = Transfer::getTimeline((int)$t['id']);
            if (!empty($timeline)):
            ?>
            <details class="am-tc-timeline">
                <summary><i class="ti ti-history"></i> Histórico <span style="font-weight:400;color:#9ca3af;">(<?= count($timeline) ?>)</span></summary>
                <div style="display:flex;flex-direction:column;gap:10px;padding:10px 14px 14px;">
                    <?php foreach ($timeline as $tl): ?>
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <span style="min-width:10px;min-height:10px;width:10px;height:10px;border-radius:50%;background:<?= Transfer::getStatusColor($tl['status']) ?>;margin-top:5px;"></span>
                        <div>
                            <div style="font-size:.8rem;color:#374151;"><?= htmlspecialchars($tl['note'] ?: (Transfer::getStatusOptions()[$tl['status']] ?? $tl['status'])) ?></div>
                            <div style="font-size:.7rem;color:#9ca3af;">
                                <?= Transfer::getStatusOptions()[$tl['status']] ?? $tl['status'] ?> •
                                <?= htmlspecialchars($tl['user_name']) ?> •
                                <?= date('d/m/Y H:i', strtotime($tl['date_creation'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php else:
                $tk = $item['data'];
                $tkStatusColor = match((int)$tk['status']){1=>'#f59e0b',2=>'#3b82f6',3=>'#f59e0b',4=>'#6b7280',5=>'#10b981',6=>'#111827',default=>'#9ca3af'};
                $tkStatusLabel = (class_exists('Ticket') && method_exists('Ticket','getStatus')) ? Ticket::getStatus($tk['status']) : ('Status '.$tk['status']);
                $tkPrioLabel = (class_exists('Ticket') && method_exists('Ticket','getPriorityName')) ? Ticket::getPriorityName($tk['priority']) : $tk['priority'];
                $tkContentShort = trim(strip_tags($tk['content'] ?? ''));
                if (mb_strlen($tkContentShort) > 120) $tkContentShort = mb_substr($tkContentShort,0,120).'…';
                $tkCatColor = '#4f46e5';
        ?>
        <div class="am-tc-card" style="border-left:4px solid #2563eb;cursor:pointer;" onclick="if(!event.target.closest('button,a')) amOpenCardModal('ticket', <?= (int)$tk['id'] ?>)">
            <div class="am-tc-card-header" style="border-left:4px solid #2563eb;background:#f8f9ff;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;padding:14px 18px 12px;">
                <span class="am-badge" style="background:<?= $tkStatusColor ?>;color:#fff;font-size:.70rem;"><?= htmlspecialchars($tkStatusLabel) ?></span>
                <div style="display:flex;flex-direction:column;align-items:center;gap:2px;width:100%;min-width:0;">
                    <div style="font-size:.72rem;color:#4f46e5;font-weight:700;text-transform:uppercase;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;white-space:normal;word-break:break-word;flex-wrap:wrap;">
                        <i class="ti ti-ticket" style="font-size:.9rem;"></i> Chamado #<?= str_pad($tk['id'], 6, '0', STR_PAD_LEFT) ?> • <?= htmlspecialchars($tk['category_name']) ?>
                    </div>
                    <div style="font-weight:800;font-size:1rem;color:#1e2333;text-align:center;white-space:normal;word-break:break-word;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:100%;" title="<?= htmlspecialchars($tk['name']) ?>">
                        <?= htmlspecialchars($tk['name'] ?: 'Sem título') ?>
                    </div>
                </div>
            </div>
            <div class="am-tc-card-body">
                <div class="am-tc-info-row"><i class="ti ti-category" style="color:#4f46e5;"></i><span style="color:#4f46e5;font-weight:700;"><?= htmlspecialchars($tk['category_name']) ?></span></div>
                <?php if ($tk['entity_name']): ?><div class="am-tc-info-row"><i class="ti ti-building"></i><span><?= htmlspecialchars($tk['entity_name']) ?></span></div><?php endif; ?>
                <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span><?= Html::convDateTime($tk['date_mod'] ?: $tk['date_creation']) ?></span></div>
                <div class="am-tc-info-row"><i class="ti ti-clock"></i><span><?= date('d/m/Y H:i', strtotime($tk['date_creation'])) ?></span></div>
                <?php if (!empty($tk['assigned_name'])): ?><div class="am-tc-info-row"><i class="ti ti-user-check" style="color:#10b981;"></i><span style="color:#059669;font-weight:700;">Atribuído: <?= htmlspecialchars($tk['assigned_name']) ?></span></div><?php elseif ((int)$tk['status'] === 1): ?><div class="am-tc-info-row"><i class="ti ti-user-question" style="color:#9ca3af;"></i><span style="color:#9ca3af;">Sem técnico atribuído</span></div><?php endif; ?>
                <?php if ($tkContentShort): ?><div class="am-tc-reason" style="background:#f8f9ff;border-color:#e0e7ff;"><?= htmlspecialchars($tkContentShort) ?></div><?php endif; ?>
            </div>
            <div class="am-tc-card-footer" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <?php if ((int)$tk['status'] === 1): ?>
                <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;" onclick="amOpenPegarTicketModal(<?= (int)$tk['id'] ?>, '<?= htmlspecialchars(addslashes($tk['name'] ?: 'Chamado #'.$tk['id'])) ?>')">
                    <i class="ti ti-hand-grab"></i> Pegar
                </button>
                <?php endif; ?>
                <a href="<?= $CFG_GLPI['root_doc'] ?>/front/ticket.form.php?id=<?= (int)$tk['id'] ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;">
                    <i class="ti ti-external-link"></i> Abrir Chamado
                </a>
                <span style="font-size:.72rem;color:#9ca3af;display:flex;align-items:center;gap:4px;"><i class="ti ti-flag" style="color:#f59e0b;"></i> Prioridade: <?= htmlspecialchars($tkPrioLabel) ?></span>
            </div>
        </div>
        <?php endif; endforeach; ?>
    </div>

    <!-- Kanban View (por etapa) - Trello style, colunas verticais lado a lado -->
    <div id="am-kanban-view">
        <div class="am-kanban">
            <?php
                $currentUserId = (int)Session::getLoginUserID();
                // Kanban 4 etapas Trello: PENDENTE (cinza) -> Em Andamento (laranja) -> RETIRADA (verde) -> CONCLUIDO (azul)
                $kanbanStages = [
                    'pendente'   => ['label'=>'PENDENTE', 'color'=>'#6b7280', 'desc'=>'Novos'],
                    'emandamento'=> ['label'=>'Em Andamento', 'color'=>'#f59e0b', 'desc'=>'Só seus'],
                    'retirada'   => ['label'=>'RETIRADA', 'color'=>'#10b981', 'desc'=>'Aguardando pegar'],
                    'concluido'  => ['label'=>'CONCLUÍDO', 'color'=>'#3b82f6', 'desc'=>'Assinado'],
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
                            // Chamados NÃO vão para Retirada (só Transferências)
                            return false;
                        }
                        if ($stageKey==='concluido') {
                            if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_FINALIZADO) return true;
                            if ($it['type']==='ticket' && in_array((int)$it['data']['status'], [5,6])) return true;
                            if ($it['type']==='ticket' && in_array((int)$it['data']['status'], [3,4])) return true;
                            return false;
                        }
                        return false;
                    }));
                    // Filtro tipo: se for só transferência, esconde chamados e vice-versa (já filtrado acima, mas mantém)
                    if ($filter_tipo==='ticket' && $stageKey!=='pendente' && $stageKey!=='pego') {
                        // Para tipo ticket, mostra só colunas que têm ticket mapeado; já filtrado, mas mantém
                    }
                    if ($filter_tipo==='transfer' && $stageKey==='concluido' && empty($colCards)) {
                        // mantém coluna vazia para mostrar estrutura
                    }
            ?>
            <div class="am-kanban-column" data-stage="<?= $stageKey ?>">
                <div class="am-kanban-header" style="border-top:4px solid <?= $sColor ?>;cursor:pointer;flex-wrap:wrap;" onclick="if(event.target.closest('button')) return; amKanbanMaximize('<?= $stageKey ?>')" title="Clique para maximizar">
                    <span style="display:flex;align-items:center;gap:8px;flex:1;min-width:140px;"><span><?= htmlspecialchars($sLabel) ?></span><?php if (!empty($stage['desc'])): ?><small style="font-weight:600;color:#6b7280;font-size:.68rem;background:#f3f4f6;border-radius:20px;padding:2px 7px;letter-spacing:.02em;white-space:nowrap;"><?= htmlspecialchars($stage['desc']) ?></small><?php endif; ?><i class="ti ti-maximize" style="font-size:.85rem;color:#9ca3af;opacity:.7;flex-shrink:0;"></i></span>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end;max-width:100%;">
                        <span class="am-kanban-count" id="am-kanban-count-<?= $stageKey ?>"><?= count($colCards) ?></span>
                        <button onclick="amKanbanMaximize('<?= $stageKey ?>')" title="Maximizar <?= htmlspecialchars($sLabel) ?>" style="background:#fff;border:1px solid #e8eaf0;border-radius:6px;padding:4px 6px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;"><i class="ti ti-maximize" style="font-size:.8rem;"></i></button>
                        <?php if ($stageKey==='emandamento'):
                            $allEmAndamento = array_values(array_filter($combined, function($it){
                                if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_MANUTENCAO) return true;
                                if ($it['type']==='ticket' && (int)$it['data']['status']===2) return true;
                                return false;
                            }));
                            $allEmAndamentoCount = count($allEmAndamento);
                            $mineEmAndamentoCount = count($colCards);
                            if ($allEmAndamentoCount > $mineEmAndamentoCount): ?>
                        <button onclick="event.stopPropagation(); amTogglePegoTodos(this)" data-show="0" data-total="<?= $allEmAndamentoCount ?>" data-mine="<?= $mineEmAndamentoCount ?>" style="font-size:.65rem;padding:3px 8px;border-radius:20px;border:1px solid #e8eaf0;background:#fff;color:#4f46e5;cursor:pointer;white-space:nowrap;">Mostrar todos (<?= $allEmAndamentoCount ?>)</button>
                        <?php endif; endif; ?>
                    </div>
                </div>
                <div class="am-kanban-body" id="am-kanban-body-<?= $stageKey ?>" data-stage="<?= $stageKey ?>" ondrop="amKanbanDrop(event)" ondragover="amKanbanDragOver(event)" ondragleave="amKanbanDragLeave(event)">
                    <?php
                        $colCardsToRender = $colCards;
                        if ($stageKey==='emandamento') {
                            $colCardsToRender = $allEmAndamento ?? array_values(array_filter($combined, function($it){
                                if ($it['type']==='transfer' && $it['data']['status']===Transfer::STATUS_MANUTENCAO) return true;
                                if ($it['type']==='ticket' && (int)$it['data']['status']===2) return true;
                                return false;
                            }));
                            $colCards = array_values(array_filter($colCardsToRender, function($it) use ($currentUserId){
                                if ($it['type']==='ticket') {
                                    return (int)($it['data']['assigned_users_id'] ?? 0) === $currentUserId;
                                }
                                return (int)($it['data']['users_id_tech'] ?? 0) === $currentUserId;
                            }));
                            // Para o toggle, mantém todos em ToRender mas esconde os não seus
                            $colCardsToRender = $allEmAndamento;
                        }
                        foreach ($colCardsToRender as $item):
                            $isHiddenPego = ($stageKey==='emandamento' && (
                                ($item['type']==='transfer' && (int)($item['data']['users_id_tech'] ?? 0) !== $currentUserId) ||
                                ($item['type']==='ticket' && (int)($item['data']['assigned_users_id'] ?? 0) !== $currentUserId)
                            ));
                            $isTicket = $item['type']==='ticket';
                            // Retirada e Concluido nao arrastam - Concluido so via Assinatura
                            $canDrag = !in_array($stageKey, ['retirada','concluido'], true) && !($isTicket && $stageKey==='retirada');
                            if ($item['type']==='transfer') {
                                $t = $item['data'];
                                $endForElapsed = $t['date_finalizado'] ?? $t['date_cancelado'] ?? null;
                                $isTerminal = in_array($t['status'], [Transfer::STATUS_FINALIZADO, Transfer::STATUS_CANCELADA], true);
                                $elapsed = Transfer::getElapsedTime($t['date_creation'], $isTerminal ? $endForElapsed : null);
                                $borderColor = '#f59e0b';
                            } else {
                                $tk = $item['data'];
                                $borderColor = '#2563eb';
                            }
                    ?>
                    <?php if ($item['type']==='transfer'): $t = $item['data']; $status_color = '#f59e0b'; ?>
                    <div class="am-tc-card <?= ($stageKey==='emandamento' && $isHiddenPego) ? 'am-kanban-hidden-pego' : '' ?>" draggable="<?= $canDrag ? 'true' : 'false' ?>" ondragstart="amKanbanDragStart(event)" data-type="transfer" data-id="<?= $t['id'] ?>" data-date="<?= htmlspecialchars($t['date_creation']) ?>" data-status="<?= htmlspecialchars($t['status']) ?>" style="margin:0;<?= ($stageKey==='emandamento' && $isHiddenPego) ? 'display:none;' : '' ?>;border-left:4px solid <?= $borderColor ?>;<?= $canDrag ? 'cursor:pointer;' : 'opacity:.6;' ?>;cursor:pointer;" onclick="if(!event.target.closest('button,a')) amOpenCardModal('transfer', <?= (int)$t['id'] ?>)" data-mine="<?= ($stageKey==='emandamento' && !$isHiddenPego) ? '1' : '0' ?>">
                        <div class="am-tc-card-header" style="border-left:4px solid <?= $borderColor ?>;padding:12px 14px;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;">
                            <span class="am-badge <?= Transfer::getStatusBadgeClass($t['status']) ?>" style="font-size:.65rem;white-space:nowrap;"><?= htmlspecialchars($sLabel) ?></span>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;width:100%;min-width:0;">
                                <div style="font-size:.65rem;color:#9ca3af;font-weight:700;white-space:normal;word-break:break-word;line-height:1.2;text-align:center;">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?> • <?= date('d/m H:i', strtotime($t['date_creation'])) ?></div>
                                <div style="font-weight:800;font-size:.9rem;color:#1e2333;white-space:normal;word-break:break-word;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.25;text-align:center;"><?= htmlspecialchars($t['origin_entity_name']) ?></div>
                            </div>
                        </div>
                        <div class="am-tc-card-body" style="padding:10px 14px;">
                            <div class="am-tc-info-row" style="font-size:.78rem;white-space:normal;word-break:break-word;"><i class="ti ti-box"></i><span><?= $t['items_count'] ?> ativo(s)</span></div>
                            <?php if ($t['reason']): ?><div class="am-tc-reason" style="font-size:.75rem;padding:6px 8px;white-space:normal;word-break:break-word;overflow-wrap:anywhere;line-height:1.4;"><?= htmlspecialchars($t['reason']) ?></div><?php endif; ?>
                        </div>
                        <div class="am-tc-card-footer" style="padding:8px 12px;flex-wrap:wrap;gap:6px;">
                            <?php if ($t['status']===Transfer::STATUS_PENDENTE): ?>
                            <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;" onclick="amOpenPegarModal(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>',<?= $t['items_count'] ?>)"><i class="ti ti-hand-grab"></i> Pegar</button>
                            <span style="font-size:.68rem;color:#9ca3af;display:flex;align-items:center;gap:4px;"><i class="ti ti-arrows-move"></i> ou arraste</span>
                            <?php elseif ($t['status']===Transfer::STATUS_MANUTENCAO): ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_diario.php?id=<?= $t['id'] ?>" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-clipboard-text"></i> Diário</a>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.php?id=<?= $t['id'] ?>" class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-check"></i> Pronto</a>
                            <?php elseif ($t['status']===Transfer::STATUS_PRONTO): ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/assinatura.php" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="ti ti-signature"></i> Assinar</a>
                            <?php else: ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-file-type-pdf"></i> PDF</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: $tk = $item['data']; $tkStatusColor = match((int)$tk['status']){1=>'#f59e0b',2=>'#ef4444',3=>'#f59e0b',4=>'#6b7280',5=>'#10b981',6=>'#111827',default=>'#ef4444'}; $tkStatusLabel = (class_exists('Ticket') && method_exists('Ticket','getStatus')) ? Ticket::getStatus($tk['status']) : $tk['status']; $tkContentShort = trim(strip_tags($tk['content'] ?? '')); if (mb_strlen($tkContentShort)>60) $tkContentShort=mb_substr($tkContentShort,0,60).'…'; $canDragTicket = !in_array($stageKey, ['retirada','concluido'], true); $isTicketPendente = (int)$tk['status']===1; ?>
                    <div class="am-tc-card <?= ($stageKey==='emandamento' && $isHiddenPego) ? 'am-kanban-hidden-pego' : '' ?>" draggable="<?= $canDragTicket ? 'true' : 'false' ?>" ondragstart="amKanbanDragStart(event)" data-type="ticket" data-id="<?= $tk['id'] ?>" style="margin:0;<?= ($stageKey==='emandamento' && $isHiddenPego) ? 'display:none;' : '' ?>;border-left:4px solid #2563eb;<?= $canDragTicket ? 'cursor:pointer;' : 'opacity:.6;cursor:not-allowed;' ?>;cursor:pointer;" onclick="if(!event.target.closest('button,a')) amOpenCardModal('ticket', <?= (int)$tk['id'] ?>)" data-mine="<?= $isHiddenPego ? '0' : '1' ?>" data-status="<?= (int)$tk['status'] ?>" data-date="<?= htmlspecialchars($tk['date_creation']) ?>">
                        <div class="am-tc-card-header" style="border-left:4px solid #2563eb;padding:12px 14px;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;">
                            <span class="am-badge" style="background:<?= $tkStatusColor ?>;color:#fff;font-size:.65rem;white-space:nowrap;"><?= htmlspecialchars($tkStatusLabel) ?></span>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;width:100%;min-width:0;">
                                <div style="font-size:.65rem;color:#2563eb;font-weight:700;white-space:normal;word-break:break-word;line-height:1.2;text-align:center;">Chamado #<?= str_pad($tk['id'],6,'0',STR_PAD_LEFT) ?> • <?= htmlspecialchars($tk['category_name']) ?></div>
                                <div style="font-weight:800;font-size:.9rem;color:#1e2333;white-space:normal;word-break:break-word;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.25;text-align:center;"><?= htmlspecialchars($tk['name']?:'Sem título') ?></div>
                            </div>
                        </div>
                        <div class="am-tc-card-body" style="padding:10px 14px;">
                            <?php if ($tk['entity_name']): ?><div class="am-tc-info-row" style="font-size:.78rem;white-space:normal;word-break:break-word;"><i class="ti ti-building"></i><span><?= htmlspecialchars($tk['entity_name']) ?></span></div><?php endif; ?>
                            <div class="am-tc-info-row" style="font-size:.78rem;white-space:normal;word-break:break-word;"><i class="ti ti-calendar"></i><span><?= Html::convDateTime($tk['date_mod']?:$tk['date_creation']) ?></span></div>
                            <?php if (!empty($tk['assigned_name'])): ?><div class="am-tc-info-row" style="font-size:.78rem;white-space:normal;word-break:break-word;"><i class="ti ti-user-check" style="color:#10b981;"></i><span style="color:#059669;font-weight:700;">Atribuído: <?= htmlspecialchars($tk['assigned_name']) ?></span></div><?php elseif ((int)$tk['status']===1): ?><div class="am-tc-info-row" style="font-size:.78rem;"><i class="ti ti-user-question" style="color:#9ca3af;"></i><span style="color:#9ca3af;">Sem técnico</span></div><?php endif; ?>
                            <?php
                                $tkContentFull = trim(strip_tags($tk['content'] ?? ''));
                                // mostra completo com clamp 3 linhas, sem corte php de 60
                            ?>
                            <?php if ($tkContentFull): ?><div class="am-tc-reason" style="font-size:.75rem;padding:6px 8px;background:#fef2f2;border-color:#fecaca;white-space:normal;word-break:break-word;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;"><?= htmlspecialchars($tkContentFull) ?></div><?php endif; ?>
                        </div>
                        <div class="am-tc-card-footer" style="padding:8px 12px;flex-wrap:wrap;gap:6px;">
                            <?php if ($isTicketPendente): ?>
                            <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;" onclick="amOpenPegarTicketModal(<?= (int)$tk['id'] ?>,'<?= htmlspecialchars(addslashes($tk['name']?:'Chamado #'.$tk['id'])) ?>')"><i class="ti ti-hand-grab"></i> Pegar</button>
                            <?php endif; ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/front/ticket.form.php?id=<?= (int)$tk['id'] ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-external-link"></i> Abrir</a>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                    <?php
                        $emptyCheck = ($stageKey==='emandamento') ? empty($colCardsToRender) : empty($colCards);
                        if ($emptyCheck): ?><div class="am-kanban-empty">Nenhum card</div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php // Chamados já estão no kanban unificado acima (borda vermelha), remove seção separada
        if (false && $filter_tipo!=='transfer'): 
            $ticketsForKanban = array_values(array_filter($combined, fn($it)=>$it['type']==='ticket'));
        ?>
        <h3 style="margin:20px 0 12px;font-size:1rem;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:8px;"><i class="ti ti-ticket" style="color:#4f46e5;"></i> Chamados <span style="font-weight:400;color:#9ca3af;font-size:.85rem;">por categoria</span></h3>
        <div class="am-kanban" id="am-kanban-tickets">
            <?php
                $catsInPage = [];
                foreach ($ticketsForKanban as $it) $catsInPage[$it['data']['itilcategories_id']] = $it['data']['category_name'];
                if (empty($catsInPage)) $catsInPage = $tjCategoriesForFilter;
                foreach ($catsInPage as $cid=>$cname):
                    $colTickets = array_values(array_filter($ticketsForKanban, fn($it)=>(int)$it['data']['itilcategories_id']===(int)$cid));
                    if ($filter_cat && (int)$filter_cat !== (int)$cid) continue;
            ?>
            <div class="am-kanban-column">
                <div class="am-kanban-header" style="border-top:4px solid #4f46e5;">
                    <span><?= htmlspecialchars($cname ?: 'Cat #'.$cid) ?></span><span class="am-kanban-count"><?= count($colTickets) ?></span>
                </div>
                <div class="am-kanban-body">
                    <?php foreach ($colTickets as $item): $tk=$item['data']; $tkStatusColor = match((int)$tk['status']){1=>'#f59e0b',2=>'#3b82f6',3=>'#f59e0b',4=>'#6b7280',5=>'#10b981',6=>'#111827',default=>'#9ca3af'}; $tkStatusLabel = (class_exists('Ticket') && method_exists('Ticket','getStatus')) ? Ticket::getStatus($tk['status']) : $tk['status']; ?>
                    <div class="am-tc-card" style="margin:0;border-left:4px solid <?= $tkStatusColor ?>;">
                        <div class="am-tc-card-header" style="border-left:4px solid <?= $tkStatusColor ?>;padding:10px 12px;">
                            <div style="min-width:0;flex:1;">
                                <div style="font-size:.65rem;color:#4f46e5;font-weight:700;">#<?= str_pad($tk['id'],6,'0',STR_PAD_LEFT) ?> • <?= htmlspecialchars($tk['category_name']) ?></div>
                                <div style="font-weight:700;font-size:.85rem;color:#1e2333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($tk['name']?:'Sem título') ?></div>
                            </div>
                            <span class="am-badge" style="background:<?= $tkStatusColor ?>;color:#fff;font-size:.65rem;"><?= htmlspecialchars($tkStatusLabel) ?></span>
                        </div>
                        <div class="am-tc-card-body" style="padding:10px 12px;">
                            <?php if ($tk['entity_name']): ?><div class="am-tc-info-row" style="font-size:.75rem;"><i class="ti ti-building"></i><span><?= htmlspecialchars($tk['entity_name']) ?></span></div><?php endif; ?>
                            <div class="am-tc-info-row" style="font-size:.75rem;"><i class="ti ti-calendar"></i><span><?= Html::convDateTime($tk['date_mod']?:$tk['date_creation']) ?></span></div>
                        </div>
                        <div class="am-tc-card-footer" style="padding:8px 12px;">
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/front/ticket.form.php?id=<?= (int)$tk['id'] ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-external-link"></i> Abrir</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($colTickets)): ?><div class="am-kanban-empty">Nenhum chamado</div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</div>

<!-- Maximized Kanban Overlay -->
<div id="am-kanban-maximized-overlay" class="am-modal-overlay" style="z-index:10001;align-items:stretch;overflow-y:auto;padding:8px;" onclick="if(event.target===this) amKanbanMaximizeClose()">
    <div class="am-modal" style="max-width:98vw;width:98vw;margin:4px auto;max-height:96vh;height:96vh;display:flex;flex-direction:column;" onclick="event.stopPropagation()">
        <div class="am-modal-header" id="am-maximized-header" style="background:linear-gradient(135deg,#1e293b,#334155);">
            <div class="am-modal-title"><i class="ti ti-maximize"></i><span id="am-maximized-title">PENDENTE</span> <span id="am-maximized-count" class="am-kanban-count" style="background:#fff;color:#1e293b;margin-left:8px;"></span></div>
            <button class="am-modal-close" onclick="amKanbanMaximizeClose()" style="background:rgba(255,255,255,.18);"><i class="ti ti-x"></i></button>
        </div>
        <div class="am-modal-body" style="padding:18px;background:#f8f9fb;overflow-y:auto;flex:1;">
            <div class="am-filters-bar" style="margin-bottom:16px;padding:16px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div style="flex:1;min-width:220px;">
                        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;display:block;">Pesquisar</label>
                        <div style="position:relative;">
                            <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.95rem;"></i>
                            <input type="text" id="am-max-search" placeholder="Buscar por nome, entidade, #id, categoria..." style="width:100%;padding:9px 12px 9px 32px;border:1.5px solid #e8eaf0;border-radius:10px;font-size:.85rem;background:#fff;box-sizing:border-box;" oninput="amMaxFilter()">
                        </div>
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;display:block;">Ano</label>
                        <select id="am-max-year" onchange="amMaxFilter()" style="padding:9px 12px;border:1.5px solid #e8eaf0;border-radius:10px;font-size:.85rem;background:#fff;min-width:140px;">
                            <option value="">Todos os anos</option>
                            <option value="2026">2026</option>
                            <option value="2025">2025</option>
                            <option value="2024">2024</option>
                            <option value="2023">2023</option>
                            <option value="2022">2022</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;display:block;">Data específica</label>
                        <input type="date" id="am-max-date" onchange="amMaxFilter()" style="padding:8px 12px;border:1.5px solid #e8eaf0;border-radius:10px;font-size:.85rem;background:#fff;">
                    </div>
                    <button class="am-btn am-btn-secondary" style="padding:9px 14px;" onclick="amMaxClearFilters()"><i class="ti ti-x"></i> Limpar filtros</button>
                    <span id="am-max-filter-count" style="font-size:.75rem;color:#6b7280;align-self:center;margin-left:4px;"></span>
                </div>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Ordenar:</span>
                    <select id="am-max-sort" onchange="amMaxFilter()" style="padding:6px 10px;border:1px solid #e8eaf0;border-radius:8px;font-size:.8rem;background:#fff;">
                        <option value="recent">Mais recente</option>
                        <option value="old">Mais antigo</option>
                    </select>
                    <span style="font-size:.72rem;color:#9ca3af;margin-left:auto;">Dica: arraste cards entre colunas ou use o botão Pegar</span>
                </div>
            </div>
            <div id="am-max-grid" class="am-tc-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;"></div>
            <div id="am-max-pagination" style="display:flex;justify-content:center;gap:8px;margin-top:18px;align-items:center;flex-wrap:wrap;"></div>
            <div id="am-max-empty" style="display:none;text-align:center;padding:48px 20px;color:#9ca3af;background:#fff;border:1.5px dashed #e8eaf0;border-radius:12px;margin-top:12px;">
                <i class="ti ti-search-off" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.5;"></i>
                Nenhum card encontrado para os filtros atuais.
            </div>
        </div>
        <div class="am-modal-footer" style="justify-content:space-between;">
            <span id="am-max-footer-info" style="font-size:.78rem;color:#6b7280;"></span>
            <button type="button" class="am-btn am-btn-secondary" onclick="amKanbanMaximizeClose()"><i class="ti ti-x"></i> Fechar</button>
        </div>
    </div>
</div>

<!-- Modal Detalhes do Card -->
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

<!-- Modal Pegar -->
<div id="am-modal-pegar" class="am-modal-overlay" onclick="if(event.target===this) amClosePegarModal()" style="z-index:10003;">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-hand-grab"></i><span>Assumir Manutenção</span></div>
            <button class="am-modal-close" onclick="amClosePegarModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-pegar-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php" onsubmit="return amSubmitPegar(event)">
            <input type="hidden" name="action" value="pegar">
            <input type="hidden" name="transfer_id" id="am-pegar-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:56px;height:56px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <i class="ti ti-hand-grab" style="font-size:1.8rem;color:#fff;"></i>
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:#1e1b4b;">Assumir esta transferência?</div>
                    <div id="am-pegar-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                </div>
                <label class="am-agree-check">
                    <input type="checkbox" id="am-pegar-agree" onchange="amTogglePegarBtn()">
                    <span>Confirmo que estou assumindo esta manutenção e me responsabilizo pelo atendimento desta transferência.</span>
                </label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:16px;">
                <button type="button" class="am-btn am-btn-secondary" style="min-width:120px;" onclick="amClosePegarModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-pegar-btn" class="am-btn" style="min-width:120px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                    <i class="ti ti-hand-grab"></i> Pegar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Finalizar -->
<div id="am-modal-finalizar" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);">
            <div class="am-modal-title"><i class="ti ti-flag-check"></i><span>Finalizar Transferência</span></div>
        </div>
        <form method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php">
            <input type="hidden" name="action" value="finalizar">
            <input type="hidden" name="transfer_id" id="am-finalizar-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:56px;height:56px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <i class="ti ti-flag-check" style="font-size:1.8rem;color:#fff;"></i>
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:#1e1b4b;">Finalizar esta transferência?</div>
                    <div id="am-finalizar-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                    <div style="font-size:.82rem;color:#ef4444;margin-top:8px;background:#fef2f2;border-radius:8px;padding:8px 12px;">
                        ⚠️ Os status definidos na etapa Pronto serão aplicados <strong>definitivamente</strong> no inventário.
                    </div>
                </div>
                <label class="am-agree-check">
                    <input type="checkbox" id="am-finalizar-agree" onchange="amToggleFinalizarBtn()">
                    <span>Confirmo que todas as informações foram revisadas e autorizo a finalização da transferência e a aplicação definitiva dos novos status dos ativos.</span>
                </label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:16px;">
                <button type="button" class="am-btn am-btn-secondary" style="min-width:120px;" onclick="amCloseFinalizarModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-finalizar-btn" class="am-btn" style="min-width:120px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                    <i class="ti ti-flag-check"></i> Confirmar Finalização
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Cancelar — mesmo padrão Pegar/Finalizar -->
<div id="am-modal-cancelar" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
            <div class="am-modal-title"><i class="ti ti-trash"></i><span>Cancelar Transferência</span></div>
        </div>
        <form method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php">
            <input type="hidden" name="action" value="cancelar">
            <input type="hidden" name="transfer_id" id="am-cancelar-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:56px;height:56px;background:linear-gradient(135deg,#dc2626,#ef4444);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <i class="ti ti-alert-triangle" style="font-size:1.8rem;color:#fff;"></i>
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:#1e1b4b;">Cancelar esta transferência?</div>
                    <div id="am-cancelar-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                    <div style="font-size:.82rem;color:#991b1b;margin-top:10px;background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:8px 12px;text-align:left;">
                        <i class="ti ti-info-circle"></i> Os ativos serão <strong>liberados</strong> e o chamado receberá um aviso de cancelamento. Esta ação não pode ser desfeita.
                    </div>
                </div>
                <div class="am-form-section" style="margin-bottom:16px;">
                    <label class="am-form-label">Motivo do cancelamento <span class="am-required">*</span></label>
                    <textarea id="am-cancelar-motivo" name="motivo" class="am-textarea" required placeholder="Descreva o motivo do cancelamento..." rows="3" oninput="amToggleCancelarBtn()"></textarea>
                </div>
                <label class="am-agree-check">
                    <input type="checkbox" id="am-cancelar-agree" onchange="amToggleCancelarBtn()">
                    <span>Confirmo o <strong>cancelamento</strong> desta transferência e estou ciente de que os ativos serão liberados.</span>
                </label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:16px;">
                <button type="button" class="am-btn am-btn-secondary" style="min-width:120px;" onclick="amCloseCancelarModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-cancelar-btn" class="am-btn" style="min-width:120px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                    <i class="ti ti-trash"></i> Confirmar Cancelamento
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Pegar Chamado -->
<div id="am-modal-pegar-ticket" class="am-modal-overlay" onclick="if(event.target===this) amClosePegarTicketModal()" style="z-index:10003;">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-hand-grab"></i><span>Assumir Chamado</span></div>
            <button class="am-modal-close" onclick="amClosePegarTicketModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-pegar-ticket-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php" onsubmit="return amSubmitPegarTicket(event)">
            <input type="hidden" name="action" value="pegar_ticket">
            <input type="hidden" name="tickets_id" id="am-pegar-ticket-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body" style="padding:24px;">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:56px;height:56px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <i class="ti ti-ticket" style="font-size:1.8rem;color:#fff;"></i>
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:#1e1b4b;">Assumir este chamado?</div>
                    <div id="am-pegar-ticket-info" style="font-size:.85rem;color:#6b7280;margin-top:6px;"></div>
                    <div style="font-size:.82rem;color:#92400e;margin-top:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:8px 12px;text-align:left;">
                        <i class="ti ti-info-circle"></i> Você será atribuído em <strong>Atribuído</strong> no GLPI e o status mudará para <strong>Em Andamento</strong>.
                    </div>
                </div>
                <label class="am-agree-check">
                    <input type="checkbox" id="am-pegar-ticket-agree" onchange="amTogglePegarTicketBtn()">
                    <span>Confirmo que estou assumindo este chamado e me responsabilizo pelo atendimento.</span>
                </label>
            </div>
            <div class="am-modal-footer" style="justify-content:center;gap:16px;">
                <button type="button" class="am-btn am-btn-secondary" style="min-width:120px;" onclick="amClosePegarTicketModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-pegar-ticket-btn" class="am-btn" style="min-width:120px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                    <i class="ti ti-hand-grab"></i> Pegar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Confirmar Mover Kanban -->
<div id="am-kanban-confirm-modal" class="am-modal-overlay">
    <div class="am-modal" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);">
            <div class="am-modal-title"><i class="ti ti-arrows-move"></i><span>Mover no Kanban</span></div>
        </div>
        <div class="am-modal-body" style="padding:24px;text-align:center;">
            <div style="width:56px;height:56px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="ti ti-arrows-move" style="font-size:1.6rem;color:#fff;"></i>
            </div>
            <div id="am-kanban-confirm-text" style="font-size:1rem;font-weight:700;color:#1e1b4b;">Mover card?</div>
            <div id="am-kanban-confirm-sub" style="font-size:.82rem;color:#6b7280;margin-top:6px;"></div>
            <div style="margin-top:12px;background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#6b7280;">Ao confirmar, o status será atualizado e o técnico será vinculado ao chamado (quando aplicável).</div>
        </div>
        <div class="am-modal-footer" style="justify-content:center;gap:16px;">
            <button type="button" class="am-btn am-btn-secondary" style="min-width:120px;" onclick="amKanbanConfirmClose()"><i class="ti ti-x"></i> Cancelar</button>
            <button type="button" id="am-kanban-confirm-btn" class="am-btn" style="min-width:120px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amKanbanConfirmGo()"><i class="ti ti-arrows-move"></i> Confirmar</button>
        </div>
    </div>
</div>

<script>
function amOpenCancelarModal(id, entity, count) {
    document.getElementById('am-cancelar-id').value = id;
    var info = '#' + String(id).padStart(4, '0');
    if (count) info += ' • ' + count + ' ativo(s)';
    if (entity) info += ' • ' + entity;
    var infoEl = document.getElementById('am-cancelar-info');
    if (infoEl) infoEl.textContent = info;
    var mot = document.getElementById('am-cancelar-motivo');
    if (mot) mot.value = '';
    var agree = document.getElementById('am-cancelar-agree');
    if (agree) agree.checked = false;
    amToggleCancelarBtn();
    var mod = document.getElementById('am-modal-cancelar');
    if (mod) { mod.classList.add('open'); document.body.style.overflow = 'hidden'; }
    if (mot) setTimeout(function(){ mot.focus(); }, 80);
}
function amCloseCancelarModal() {
    var mod = document.getElementById('am-modal-cancelar');
    if (mod) mod.classList.remove('open');
    document.body.style.overflow = '';
}
function amToggleCancelarBtn() {
    var motEl = document.getElementById('am-cancelar-motivo');
    var agreeEl = document.getElementById('am-cancelar-agree');
    var btn = document.getElementById('am-cancelar-btn');
    if (!motEl || !agreeEl || !btn) return;
    var ok = motEl.value.trim().length > 0 && agreeEl.checked;
    btn.disabled = !ok; btn.style.opacity = ok ? '1' : '.4'; btn.style.cursor = ok ? 'pointer' : 'not-allowed';
}
function amOpenPegarModal(id, entity, count) {
    document.getElementById('am-pegar-id').value = id;
    document.getElementById('am-pegar-info').textContent = count + ' ativo(s) • ' + entity;
    document.getElementById('am-pegar-agree').checked = false;
    amTogglePegarBtn();
    document.getElementById('am-modal-pegar').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function amClosePegarModal() {
    document.getElementById('am-modal-pegar').classList.remove('open');
    if(!document.querySelector('.am-modal-overlay.open')) document.body.style.overflow = '';
}
function amTogglePegarBtn() {
    var ok = document.getElementById('am-pegar-agree').checked;
    var b  = document.getElementById('am-pegar-btn');
    b.disabled = !ok; b.style.opacity = ok?'1':'.4'; b.style.cursor = ok?'pointer':'not-allowed';
}
function amOpenFinalizarModal(id, entity) {
    document.getElementById('am-finalizar-id').value = id;
    document.getElementById('am-finalizar-info').textContent = 'Destino: ' + entity;
    document.getElementById('am-finalizar-agree').checked = false;
    amToggleFinalizarBtn();
    document.getElementById('am-modal-finalizar').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function amCloseFinalizarModal() {
    document.getElementById('am-modal-finalizar').classList.remove('open');
    document.body.style.overflow = '';
}
function amToggleFinalizarBtn() {
    var ok = document.getElementById('am-finalizar-agree').checked;
    var b  = document.getElementById('am-finalizar-btn');
    b.disabled = !ok; b.style.opacity = ok?'1':'.4'; b.style.cursor = ok?'pointer':'not-allowed';
}
function amOpenPegarTicketModal(id, name) {
    document.getElementById('am-pegar-ticket-id').value = id;
    document.getElementById('am-pegar-ticket-info').textContent = 'Chamado #' + String(id).padStart(6,'0') + ' • ' + name;
    document.getElementById('am-pegar-ticket-agree').checked = false;
    amTogglePegarTicketBtn();
    document.getElementById('am-modal-pegar-ticket').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function amClosePegarTicketModal() {
    var m = document.getElementById('am-modal-pegar-ticket');
    if (m) m.classList.remove('open');
    if(!document.querySelector('.am-modal-overlay.open')) document.body.style.overflow = '';
}
function amTogglePegarTicketBtn() {
    var ok = document.getElementById('am-pegar-ticket-agree').checked;
    var b  = document.getElementById('am-pegar-ticket-btn');
    if (!b) return;
    b.disabled = !ok; b.style.opacity = ok?'1':'.4'; b.style.cursor = ok?'pointer':'not-allowed';
}
function amGetCsrfToken(){
    try{
        var el = document.querySelector('#am-pegar-form input[name="_glpi_csrf_token"]') || document.querySelector('#am-pegar-ticket-form input[name="_glpi_csrf_token"]') || document.querySelector('input[name="_glpi_csrf_token"]');
        if(el && el.value) return el.value;
        if(window.CFG_GLPI && window.CFG_GLPI.csrf_token) return window.CFG_GLPI.csrf_token;
        if(window.glpi_csrf_token) return window.glpi_csrf_token;
        var m=document.querySelector('meta[name="glpi_csrf_token"]'); if(m && m.content) return m.content;
    }catch(e){}
    return '';
}
function amParseKanbanResponse(r){
    // Lê como texto e tenta JSON; se vier HTML (ex: 403 página GLPI), extrai mensagem útil
    return r.text().then(function(t){
        var j=null; try{ j=JSON.parse(t); }catch(e){
            var msg = (t||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,400);
            if(!msg) msg = 'Resposta inválida do servidor (HTTP '+r.status+')';
            // Detecta sessão expirada vs permissão
            if(r.status===401 || /login|entrar|autentica/i.test((t||'').slice(0,2000))) msg='Sessão expirada — faça login no GLPI e recarregue a página (F5).';
            else if(r.status===403) msg = msg.slice(0,250) || 'Sem permissão (403) — verifique perfil em Administração > Perfis > Manutenção';
            j={success:false, message: msg, _raw: t.slice(0,500), _status:r.status};
        }
        return {ok:r.ok, status:r.status, j:j};
    });
}
function amSubmitPegar(e){
    if(e) e.preventDefault();
    var idEl = document.getElementById('am-pegar-id');
    var btn = document.getElementById('am-pegar-btn');
    var id = idEl ? parseInt(idEl.value,10) : 0;
    if(!id){ alert('ID inválido'); return false; }
    if(btn){ btn.disabled=true; btn.innerHTML='<i class="ti ti-loader-2" style="animation:amSpin .8s linear infinite;display:inline-block;"></i> Pegando...'; }
    var fd=new FormData(); fd.append('type','transfer'); fd.append('id', id); fd.append('to','emandamento');
    var csrf=amGetCsrfToken(); if(csrf) fd.append('_glpi_csrf_token', csrf);
    var h={'X-Requested-With':'XMLHttpRequest'}; if(csrf) h['X-Glpi-Csrf-Token']=csrf;
    fetch((window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '') + '/plugins/assetmgrstatus/ajax/kanban_move.php', {method:'POST', body:fd, credentials:'same-origin', headers:h})
        .then(amParseKanbanResponse)
        .then(function(res){
            if(res.ok && res.j.success){
                amClosePegarModal();
                amCloseCardModal();
                if(window.amKanbanToast) amKanbanToast('Transferência #'+String(id).padStart(4,'0')+' assumida! Movendo para Em Andamento…');
                // Move DOM sem F5
                try{
                    var card=document.querySelector('.am-tc-card[data-type=\"transfer\"][data-id=\"'+id+'\"]');
                    var target=document.getElementById('am-kanban-body-emandamento');
                    var source=document.getElementById('am-kanban-body-pendente');
                    if(card && target){
                        // remove empty msg
                        var empty=target.querySelector('.am-kanban-empty'); if(empty) empty.style.display='none';
                        card.style.display=''; card.classList.remove('am-kanban-hidden-pego'); card.setAttribute('data-mine','1');
                        card.setAttribute('data-status','em_manutencao');
                        // atualiza badge e footer sem F5
                        try{
                            var bdg=card.querySelector('.am-badge'); if(bdg){ bdg.textContent='Em Andamento'; bdg.className='am-badge am-badge-garantia'; }
                            var foot=card.querySelector('.am-tc-card-footer');
                            if(foot){
                                var rd=(window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '/glpi');
                                foot.innerHTML='<a href="'+rd+'/plugins/assetmgrstatus/front/tecnico_diario.php?id='+id+'" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-clipboard-text"></i> Diário</a><a href="'+rd+'/plugins/assetmgrstatus/front/tecnico_pronto.php?id='+id+'" class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-check"></i> Pronto</a>';
                            }
                        }catch(e){}
                        card.style.opacity='0'; target.prepend(card);
                        setTimeout(function(){ card.style.transition='opacity .3s'; card.style.opacity='1'; }, 30);
                        // atualiza contadores
                        var cntPend=document.getElementById('am-kanban-count-pendente');
                        var cntEm=document.getElementById('am-kanban-count-emandamento');
                        if(cntPend && source) cntPend.textContent = source.querySelectorAll('.am-tc-card').length;
                        if(cntEm) cntEm.textContent = target.querySelectorAll('.am-tc-card:not(.am-kanban-hidden-pego)').length;
                        // também atualiza grid view se existir
                        var gridCard=document.querySelector('#am-grid-view .am-tc-card[onclick*=\"transfer.*'+id+'\"]');
                        if(gridCard){ gridCard.style.opacity='.6'; gridCard.style.pointerEvents='none'; }
                        // limite concluido recalculo
                        try{ if(typeof amLimitConcluido==='function') amLimitConcluido(); }catch(e){}
                        // atualiza hash para evitar softRefresh conflito
                        try{ if(window._amLastHash!==undefined) _amLastHash=null; }catch(e){}
                    } else {
                        // fallback: reload suave sem F5 percebido
                        setTimeout(function(){ window.location.reload(); }, 500);
                    }
                }catch(err){ setTimeout(function(){ window.location.reload(); }, 600); }
            } else {
                var msg = (res.j && res.j.message) ? res.j.message : ('Falha ao pegar transferência (HTTP '+res.status+')');
                alert(msg);
                if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-hand-grab\"></i> Pegar'; }
            }
        }).catch(function(err){ alert('Erro: '+(err.message||err)); if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-hand-grab\"></i> Pegar'; } });
    return false;
}
function amSubmitPegarTicket(e){
    if(e) e.preventDefault();
    var idEl = document.getElementById('am-pegar-ticket-id');
    var btn = document.getElementById('am-pegar-ticket-btn');
    var id = idEl ? parseInt(idEl.value,10) : 0;
    if(!id){ alert('ID inválido'); return false; }
    if(btn){ btn.disabled=true; btn.innerHTML='<i class=\"ti ti-loader-2\" style=\"animation:amSpin .8s linear infinite;display:inline-block;\"></i> Pegando...'; }
    var fd=new FormData(); fd.append('type','ticket'); fd.append('id', id); fd.append('to','emandamento');
    var csrf=amGetCsrfToken(); if(csrf) fd.append('_glpi_csrf_token', csrf);
    var h2={'X-Requested-With':'XMLHttpRequest'}; if(csrf) h2['X-Glpi-Csrf-Token']=csrf;
    fetch((window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '') + '/plugins/assetmgrstatus/ajax/kanban_move.php', {method:'POST', body:fd, credentials:'same-origin', headers:h2})
        .then(amParseKanbanResponse)
        .then(function(res){
            if(res.ok && res.j.success){
                amClosePegarTicketModal();
                amCloseCardModal();
                if(window.amKanbanToast) amKanbanToast('Chamado #'+String(id).padStart(6,'0')+' assumido! Movendo para Em Andamento…');
                try{
                    var card=document.querySelector('.am-tc-card[data-type=\"ticket\"][data-id=\"'+id+'\"]');
                    var target=document.getElementById('am-kanban-body-emandamento');
                    var source=document.getElementById('am-kanban-body-pendente');
                    if(card && target){
                        var empty=target.querySelector('.am-kanban-empty'); if(empty) empty.style.display='none';
                        card.style.display=''; card.classList.remove('am-kanban-hidden-pego');
                        card.setAttribute('data-mine','1');
                        card.setAttribute('data-status','2');
                        try{
                            var bdg2=card.querySelector('.am-badge'); if(bdg2){ bdg2.textContent='Em Andamento'; bdg2.style.background='#3b82f6'; }
                            var foot2=card.querySelector('.am-tc-card-footer');
                            if(foot2){
                                var rd2=(window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '/glpi');
                                foot2.innerHTML='<a href="'+rd2+'/front/ticket.form.php?id='+id+'" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-external-link"></i> Abrir</a>';
                            }
                            var semTec=card.querySelector('.ti-user-question');
                            if(semTec){
                                var row=semTec.closest('.am-tc-info-row');
                                if(row) row.innerHTML='<i class="ti ti-user-check" style="color:#10b981;"></i><span style="color:#059669;font-weight:700;">Atribuído: você</span>';
                            }
                        }catch(e){}
                        card.style.opacity='0'; target.prepend(card);
                        setTimeout(function(){ card.style.transition='opacity .3s'; card.style.opacity='1'; }, 30);
                        var cntPend=document.getElementById('am-kanban-count-pendente');
                        var cntEm=document.getElementById('am-kanban-count-emandamento');
                        if(cntPend && source) cntPend.textContent = source.querySelectorAll('.am-tc-card').length;
                        if(cntEm) cntEm.textContent = target.querySelectorAll('.am-tc-card:not(.am-kanban-hidden-pego)').length;
                        try{ if(typeof amLimitConcluido==='function') amLimitConcluido(); }catch(e){}
                        try{ if(window._amLastHash!==undefined) _amLastHash=null; }catch(e){}
                    } else {
                        setTimeout(function(){ window.location.reload(); }, 500);
                    }
                }catch(err){ setTimeout(function(){ window.location.reload(); }, 600); }
            } else {
                var msg = (res.j && res.j.message) ? res.j.message : ('Falha ao pegar chamado (HTTP '+res.status+')');
                alert(msg);
                if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-hand-grab\"></i> Pegar'; }
            }
        }).catch(function(err){ alert('Erro: '+(err.message||err)); if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-hand-grab\"></i> Pegar'; } });
    return false;
}
function amOpenCardModal(type, id) {
    var modal = document.getElementById('am-modal-card-details');
    var titleEl = document.getElementById('am-card-details-title');
    var bodyEl = document.getElementById('am-card-details-body');
    var linkEl = document.getElementById('am-card-details-link');
    var headerEl = document.getElementById('am-card-details-header');
    if (!modal || !bodyEl) return;
    var isTicket = type==='ticket';
    titleEl.textContent = isTicket ? 'Chamado #' + String(id).padStart(6,'0') : 'Transferência #' + String(id).padStart(4,'0');
    bodyEl.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="ti ti-loader-2" style="animation:amSpin .8s linear infinite;font-size:1.5rem;display:block;margin-bottom:8px;"></i> Carregando...</div>';
    linkEl.style.display='none';
    if (headerEl) headerEl.style.background = isTicket ? 'linear-gradient(135deg,#2563eb,#3b82f6)' : 'linear-gradient(135deg,#d97706,#f59e0b)';
    modal.classList.add('open');
    document.body.style.overflow='hidden';
    var url = (window._amCardDetailsBase || '') + '?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id);
    // base detecta via location
    var base = '';
    try {
        var idx = window.location.pathname.indexOf('/plugins/assetmgrstatus/');
        if (idx!==-1) base = window.location.pathname.substring(0, idx) + '/plugins/assetmgrstatus/ajax/card_details.php';
        else base = window.location.origin + '/glpi/plugins/assetmgrstatus/ajax/card_details.php';
    } catch(e){ base = 'ajax/card_details.php'; }
    fetch(base + '?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id), {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, json:j}; }); })
        .then(function(res){
            if (!res.ok || !res.json.success) throw new Error(res.json.message || 'Falha ao carregar');
            var d = res.json.data;
            if (isTicket) {
                var statusColor = d.status===1?'#f59e0b':d.status===2?'#3b82f6':d.status===5?'#10b981':d.status===6?'#111827':'#6b7280';
                var html = '<div style="display:flex;flex-direction:column;gap:14px;">';
                html += '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><span class="am-badge" style="background:'+statusColor+';color:#fff;">'+d.status_label+'</span><span class="am-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><i class="ti ti-category"></i> '+d.category+'</span><span style="font-size:.82rem;color:#6b7280;"><i class="ti ti-building"></i> '+(d.entity||'—')+'</span></div>';
                html += '<h3 style="margin:0;font-size:1.05rem;font-weight:800;color:#1e2333;">'+d.name+'</h3>';
                html += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#f8f9fb;border:1px solid #e8eaf0;border-radius:10px;padding:12px;">';
                html += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Atribuído</div><div style="font-weight:700;color:'+(d.assigned?'#059669':'#9ca3af')+';">'+(d.assigned||'Sem técnico')+'</div></div>';
                html += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Prioridade</div><div>'+d.priority+'</div></div>';
                html += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Criado</div><div>'+(d.date||'').replace(' ',' às ')+'</div></div>';
                html += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Atualizado</div><div>'+(d.date_mod||'').replace(' ',' às ')+'</div></div>';
                html += '</div>';
                if (d.content) html += '<div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:14px;"><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;">Descrição</div><div style="font-size:.88rem;color:#374151;line-height:1.6;white-space:pre-wrap;word-break:break-word;">'+d.content_html+'</div></div>';
                html += '</div>';
                bodyEl.innerHTML = html;
                linkEl.href = (window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '/glpi') + '/front/ticket.form.php?id=' + d.id;
                // fallback usa origin
                try{ var root = document.querySelector('a[href*="ticket.form.php"]'); if(root) linkEl.href = root.href.split('?')[0] + '?id=' + d.id; }catch(e){}
                linkEl.style.display='inline-flex';
                linkEl.innerHTML = '<i class="ti ti-external-link"></i> Abrir Chamado no GLPI';
                // adiciona botão Pegar se status Novo — usa timeout para evitar conflito de modais sobrepostos
                if (d.status===1) {
                    var pegarBtn = '<button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;margin-top:10px;width:100%;" onclick="amCloseCardModal(); setTimeout(function(){ amOpenPegarTicketModal('+d.id+', '+JSON.stringify(d.name)+') }, 80)"><i class="ti ti-hand-grab"></i> Pegar este chamado</button>';
                    bodyEl.innerHTML += pegarBtn;
                }
            } else {
                var html2 = '<div style="display:flex;flex-direction:column;gap:14px;">';
                html2 += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="am-badge" style="background:'+d.status_color+';color:#fff;">'+d.status_label+'</span><span style="font-size:.82rem;color:#6b7280;">'+d.items_count+' ativo(s)</span></div>';
                html2 += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#f8f9fb;border:1px solid #e8eaf0;border-radius:10px;padding:12px;">';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Origem</div><div style="font-weight:700;">'+(d.origin||'—')+'</div></div>';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Destino</div><div style="font-weight:700;">'+(d.dest||'—')+'</div></div>';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Técnico</div><div>'+(d.tech||'—')+'</div></div>';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Criado por</div><div>'+(d.creator||'—')+'</div></div>';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Criado em</div><div>'+(d.date_creation||'').replace(' ',' às ')+'</div></div>';
                html2 += '<div><div style="font-size:.70rem;font-weight:700;text-transform:uppercase;color:#9ca3af;">Chamado</div><div>'+(d.tickets_id ? ('#'+String(d.tickets_id).padStart(6,'0')) : '—')+'</div></div>';
                html2 += '</div>';
                if (d.reason) html2 += '<div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:12px;"><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">Motivo</div><div style="font-size:.88rem;color:#374151;">'+d.reason+'</div></div>';
                if (d.items && d.items.length) {
                    html2 += '<div><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;">Itens ('+d.items.length+')</div><div style="display:flex;flex-direction:column;gap:6px;">';
                    d.items.forEach(function(it){ var tp = it.type.replace('Glpi\\\\CustomAsset\\\\','').replace('Asset',''); html2 += '<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #e8eaf0;border-radius:8px;padding:8px 10px;"><span style="font-weight:600;">'+it.name+'</span><span style="font-size:.75rem;color:#6b7280;">'+tp+(it.final_status?' • '+it.final_status:'')+'</span></div>'; });
                    html2 += '</div></div>';
                }
                if (d.timeline && d.timeline.length) {
                    html2 += '<div><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;">Histórico</div><div style="display:flex;flex-direction:column;gap:8px;">';
                    d.timeline.forEach(function(tl){ html2 += '<div style="display:flex;gap:8px;align-items:flex-start;"><span style="width:8px;height:8px;border-radius:50%;background:'+(tl.status?"#4f46e5":"#9ca3af")+';margin-top:6px;flex-shrink:0;"></span><div><div style="font-size:.82rem;color:#374151;">'+(tl.note||tl.status)+'</div><div style="font-size:.72rem;color:#9ca3af;">'+(tl.status||'')+' • '+(tl.user_name||'')+' • '+(tl.date_creation||'')+'</div></div></div>'; });
                    html2 += '</div></div>';
                }
                html2 += '</div>';
                // Botão Pegar para transferência pendente dentro do modal de detalhes
                if (d.status === 'pendente') {
                    html2 += '<button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;width:100%;margin-top:4px;" onclick="amCloseCardModal(); setTimeout(function(){ amOpenPegarModal('+d.id+', '+JSON.stringify(d.origin||'')+', '+d.items_count+') }, 80)"><i class="ti ti-hand-grab"></i> Pegar esta transferência</button>';
                }
                bodyEl.innerHTML = html2;
                if (d.tickets_id) {
                    linkEl.href = (window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '/glpi') + '/front/ticket.form.php?id=' + d.tickets_id;
                    try{ var root2 = document.querySelector('a[href*="ticket.form.php"]'); if(root2) linkEl.href = root2.href.split('?')[0] + '?id=' + d.tickets_id; }catch(e){}
                    linkEl.style.display='inline-flex';
                    linkEl.innerHTML = '<i class="ti ti-external-link"></i> Ver Chamado';
                } else {
                    linkEl.style.display='none';
                }
            }
        })
        .catch(function(err){ bodyEl.innerHTML = '<div style="color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;">Erro: '+err.message+'</div>'; });
}
function amCloseCardModal(){ var m=document.getElementById('am-modal-card-details'); if(m) m.classList.remove('open'); if(!document.querySelector('.am-modal-overlay.open')) document.body.style.overflow=''; }

// ---- Maximizar Kanban por categoria ----
var _amMaxStage = null;
var _amMaxCards = []; // {el, date, text, year, month}
var _amMaxPage = 1;
var _amMaxPerPage = 10;
function amKanbanMaximize(stage) {
    _amMaxStage = stage;
    var col = document.querySelector('.am-kanban-column[data-stage="'+stage+'"]');
    if (!col) return;
    var headerColor = col.querySelector('.am-kanban-header') ? col.querySelector('.am-kanban-header').style.borderTopColor || col.querySelector('.am-kanban-header').style.borderTop : '#1e293b';
    var labelEl = col.querySelector('.am-kanban-header span span') || col.querySelector('.am-kanban-header span');
    var label = labelEl ? labelEl.textContent.trim() : stage.toUpperCase();
    // pega todos cards da coluna (incluindo hidden por limite)
    var body = document.getElementById('am-kanban-body-'+stage);
    if (!body) return;
    var cards = Array.from(body.querySelectorAll('.am-tc-card'));
    // fallback: se vazio, tenta buscar direto da coluna
    if (cards.length===0) cards = Array.from(col.querySelectorAll('.am-tc-card'));
    document.getElementById('am-maximized-title').textContent = label;
    document.getElementById('am-maximized-count').textContent = cards.length;
    var header = document.getElementById('am-maximized-header');
    if (header) header.style.borderTop = '4px solid ' + (col.querySelector('.am-kanban-header').style.borderTopColor || '#334155');
    // prepara dataset para filtro sem recriar DOM toda vez
    _amMaxCards = cards.map(function(c){
        var clone = c.cloneNode(true);
        // remove drag handlers duplicados, mantém botão Pegar funcional
        clone.removeAttribute('draggable');
        clone.style.opacity='1';
        clone.style.cursor='default';
        clone.style.display='';
        // garante que clone não tenha classe hidden
        clone.classList.remove('am-kanban-hidden-pego');
        var dateStr = c.dataset.date || c.getAttribute('data-date') || '';
        if (!dateStr) {
            // tenta extrair de .am-tc-timer ou texto
            var timer = c.querySelector('.am-tc-timer');
            if (timer) dateStr = timer.dataset.start || '';
            if (!dateStr) {
                var timeEl = c.querySelector('.am-tc-info-row');
                dateStr = '';
            }
        }
        var d = null; var y=''; var m='';
        if (dateStr) {
            // tenta Date, fallback para substring yyyy-mm-dd
            d = new Date(dateStr.replace(' ', 'T'));
            if (d && !isNaN(d)) { y = String(d.getFullYear()); m = String(d.getMonth()+1).padStart(2,'0'); }
            if (!y && dateStr.length>=4) { y = dateStr.substr(0,4); if (dateStr.length>=7) m = dateStr.substr(5,2); try{ d = new Date(dateStr.replace(' ','T')); }catch(e){} }
        }
        var txt = (c.textContent || '').toLowerCase();
        // normaliza para busca
        try { txt = txt.normalize('NFD').replace(/[\u0300-\u036f]/g,''); } catch(e){}
        return { el: clone, dateStr: dateStr, year: y, month: m, dateObj: d, text: txt, raw: c };
    });
    _amMaxPage = 1;
    document.getElementById('am-max-search').value = '';
    document.getElementById('am-max-year').value = '';
    document.getElementById('am-max-date').value = '';
    document.getElementById('am-max-sort').value = 'recent';
    document.getElementById('am-kanban-maximized-overlay').classList.add('open');
    document.body.style.overflow='hidden';
    amMaxFilter();
}
function amKanbanMaximizeClose() {
    var o = document.getElementById('am-kanban-maximized-overlay');
    if (o) o.classList.remove('open');
    document.body.style.overflow='';
    _amMaxStage = null;
}
function amMaxClearFilters() {
    document.getElementById('am-max-search').value='';
    document.getElementById('am-max-year').value='';
    document.getElementById('am-max-date').value='';
    document.getElementById('am-max-sort').value='recent';
    amMaxFilter();
}
function amMaxFilter() {
    if (!_amMaxStage) return;
    var q = (document.getElementById('am-max-search').value || '').toLowerCase();
    try { q = q.normalize('NFD').replace(/[\u0300-\u036f]/g,''); } catch(e){}
    var year = document.getElementById('am-max-year').value || '';
    var dateVal = document.getElementById('am-max-date').value || '';
    var sort = document.getElementById('am-max-sort').value || 'recent';
    var filtered = _amMaxCards.filter(function(item){
        if (q && item.text.indexOf(q)===-1) return false;
        if (year && item.year !== year) return false;
        if (dateVal) {
            // dateVal yyyy-mm-dd
            var itemDate = item.dateStr ? item.dateStr.substring(0,10) : '';
            if (itemDate !== dateVal) return false;
        }
        return true;
    });
    // ordena
    filtered.sort(function(a,b){
        var ta = a.dateObj ? a.dateObj.getTime() : 0;
        var tb = b.dateObj ? b.dateObj.getTime() : 0;
        return sort==='old' ? ta - tb : tb - ta;
    });
    var grid = document.getElementById('am-max-grid');
    var empty = document.getElementById('am-max-empty');
    var pagEl = document.getElementById('am-max-pagination');
    var countEl = document.getElementById('am-max-filter-count');
    var footerInfo = document.getElementById('am-max-footer-info');
    if (!grid) return;
    grid.innerHTML='';
    if (filtered.length===0) {
        empty.style.display='block';
        pagEl.innerHTML='';
        countEl.textContent='';
        footerInfo.textContent='0 de '+_amMaxCards.length+' cards';
        return;
    }
    empty.style.display='none';
    // paginação: 10 por página, mas Concluído já naturalmente limita 10 por página; para outros também 10
    var perPage = _amMaxPerPage;
    var totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
    if (_amMaxPage > totalPages) _amMaxPage = totalPages;
    if (_amMaxPage < 1) _amMaxPage = 1;
    var start = (_amMaxPage - 1) * perPage;
    var pageItems = filtered.slice(start, start + perPage);
    pageItems.forEach(function(item){ grid.appendChild(item.el); });
    // paginação UI
    var pagHtml = '';
    if (totalPages > 1) {
        pagHtml += '<button class="am-btn am-btn-secondary" style="padding:6px 12px;min-width:80px;" ' + (_amMaxPage<=1 ? 'disabled' : '') + ' onclick="amMaxGoPage('+(_amMaxPage-1)+')">‹ Anterior</button>';
        pagHtml += '<span style="font-size:.82rem;color:#6b7280;">Página '+_amMaxPage+' de '+totalPages+'</span>';
        pagHtml += '<button class="am-btn am-btn-secondary" style="padding:6px 12px;min-width:80px;" ' + (_amMaxPage>=totalPages ? 'disabled' : '') + ' onclick="amMaxGoPage('+(_amMaxPage+1)+')">Próxima ›</button>';
    }
    pagEl.innerHTML = pagHtml;
    countEl.textContent = filtered.length + ' de ' + _amMaxCards.length;
    footerInfo.textContent = 'Exibindo '+(start+1)+'–'+Math.min(start+perPage, filtered.length)+' de '+filtered.length+' · Total na coluna: '+_amMaxCards.length;
}
function amMaxGoPage(p) { _amMaxPage = p; amMaxFilter(); window.scrollTo({top:0,behavior:'smooth'}); var overlay = document.getElementById('am-kanban-maximized-overlay'); if(overlay) overlay.scrollTop=0; var body = overlay ? overlay.querySelector('.am-modal-body') : null; if(body) body.scrollTop=0; }
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    amClosePegarModal(); amCloseFinalizarModal(); amCloseCancelarModal(); amClosePegarTicketModal(); amKanbanMaximizeClose(); amCloseCardModal();
});
// limita CONCLUÍDO a 15 no kanban normal
function amLimitConcluido() {
    var body = document.getElementById('am-kanban-body-concluido');
    if (!body) return;
    var cards = Array.from(body.querySelectorAll('.am-tc-card'));
    if (cards.length <= 15) return;
    var hidden = 0;
    cards.forEach(function(c, idx){
        if (idx >= 15) { c.style.display='none'; c.dataset.hiddenByLimit='1'; hidden++; }
    });
    if (hidden>0 && !document.getElementById('am-concluido-more')) {
        var more = document.createElement('div');
        more.id='am-concluido-more';
        more.style.cssText='text-align:center;padding:10px;';
        more.innerHTML='<button onclick="amKanbanMaximize(\'concluido\')" style="background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:8px 14px;font-size:.82rem;font-weight:700;color:#3b82f6;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.06);"><i class="ti ti-eye"></i> Ver todos ('+cards.length+') — maximizado com filtros</button><div style="font-size:.70rem;color:#9ca3af;margin-top:6px;">Mostrando 15 de '+cards.length+' · clique para ver com filtro por ano</div>';
        body.appendChild(more);
        var cnt = document.getElementById('am-kanban-count-concluido');
        if (cnt) { cnt.textContent = cards.length; cnt.title = cards.length+' total — 15 visíveis, clique no cabeçalho para maximizar e filtrar por 2025 etc'; }
    }
}
document.addEventListener('DOMContentLoaded', function(){ try{ amLimitConcluido(); }catch(e){} });


// Cronômetro em tempo real (para em finalizados)
function amUpdateTimers() {
    document.querySelectorAll('.am-tc-timer').forEach(function(el) {
        var end = el.dataset.end;
        if (end) return; // já finalizado, não atualiza
        var start = new Date(el.dataset.start).getTime();
        var diff  = Math.floor((Date.now() - start) / 1000);
        var d = Math.floor(diff/86400), h = Math.floor((diff%86400)/3600),
            m = Math.floor((diff%3600)/60), s = diff%60;
        el.textContent = (d>0?d+'d ':'')+h+'h '+m+'m '+s+'s';
    });
}
setInterval(amUpdateTimers, 1000);
amUpdateTimers();

// ---- Atualização suave via AJAX — só quando houver novidade (hash) ----
var _amCheckBase = '<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/tecnico_check.php';
var _amLastHash = null;
var _amLastCount = document.querySelectorAll('.am-tc-card').length;
// Inicializa hash na carga
fetch(_amCheckBase + window.location.search, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); })
    .then(function(d){ _amLastHash = d.hash; _amLastCount = d.count; })
    .catch(function(){});

function amSoftRefresh() {
    var modalOpen = document.querySelector('.am-modal-overlay.open');
    if (modalOpen) return; // não atualiza com modal aberto

    fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var newDoc = parser.parseFromString(html, 'text/html');
            var newGrid = newDoc.querySelector('.am-tc-grid');
            var newEmpty = newDoc.querySelector('.am-empty-state');
            var container = document.querySelector('.am-tc-grid') || document.querySelector('.am-empty-state');
            if (!container || !container.parentNode) return;

            var newContent = newGrid || newEmpty;
            if (!newContent) return;

            // Conta cards atuais vs novos pra saber se mudou algo
            var oldCount = document.querySelectorAll('.am-tc-card').length;
            var newCount = newContent.querySelectorAll ? newContent.querySelectorAll('.am-tc-card').length : 0;

            // Fade out suave, troca conteúdo, fade in
            container.style.transition = 'opacity .25s ease';
            container.style.opacity = '0.3';
            setTimeout(function() {
                container.parentNode.replaceChild(newContent, container);
                newContent.style.opacity = '0.3';
                newContent.style.transition = 'opacity .25s ease';
                requestAnimationFrame(function() {
                    newContent.style.opacity = '1';
                });
                amUpdateTimers();

                // Notifica se chegou card novo
                if (newCount > oldCount) {
                    amShowNewCardToast();
                } else if (newCount !== oldCount || newContent.innerHTML !== container.innerHTML) {
                    // Mudança de status (ex: Pendente → Manutenção) — atualiza silencioso
                }
            }, 200);
        })
        .catch(function() { /* falha silenciosa, tenta de novo no próximo ciclo */ });
}

function amShowNewCardToast() {
    var toast = document.createElement('div');
    toast.textContent = '🔔 Nova transferência recebida!';
    toast.style.cssText = 'position:fixed;top:20px;right:20px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:12px 20px;border-radius:10px;font-weight:700;font-size:.88rem;box-shadow:0 8px 24px rgba(0,0,0,.2);z-index:9999;opacity:0;transition:opacity .3s ease;';
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.opacity = '1'; });
    setTimeout(function() {
        toast.style.opacity = '0';
        setTimeout(function() { toast.remove(); }, 300);
    }, 4000);
}

function amCheckForUpdates() {
    if (document.querySelector('.am-modal-overlay.open')) return;
    fetch(_amCheckBase + window.location.search, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
            amUpdateRefreshTime();
            if (_amLastHash === null) { _amLastHash = d.hash; _amLastCount = d.count; return; }
            if (d.hash !== _amLastHash) {
                // Houve novidade — atualiza hash e dispara refresh suave
                var prevCount = _amLastCount;
                _amLastHash = d.hash;
                _amLastCount = d.count;
                // Se aumentou contagem, o toast será exibido dentro do amSoftRefresh
                // Mas já podemos antecipar se quiser: if (d.count > prevCount) ...
                amSoftRefresh();
            }
        }).catch(function(){});
}
function amUpdateRefreshTime(){
    var el=document.getElementById('am-refresh-time');
    if(el) el.textContent='Atualizado '+new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
}
function amManualRefresh(btn){
    if(!btn) btn=document.getElementById('am-refresh-btn');
    var icon=btn ? btn.querySelector('.ti') : null;
    var origClass=icon ? icon.className : '';
    if(icon){ icon.className='ti ti-loader-2'; icon.style.display='inline-block'; icon.style.animation='amSpin 0.8s linear infinite'; }
    if(btn) btn.disabled=true;
    // Força refresh visual imediato
    amSoftRefresh();
    setTimeout(function(){
        fetch(_amCheckBase + window.location.search, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){ _amLastHash=d.hash; _amLastCount=d.count; })
            .catch(function(){})
            .finally(function(){
                if(icon){ icon.className=origClass||'ti ti-refresh'; icon.style.animation=''; }
                if(btn) btn.disabled=false;
                amUpdateRefreshTime();
            });
    }, 900);
}
setInterval(amCheckForUpdates, 10000);
amUpdateRefreshTime();

// --- Pesquisar entidade (filtra ao digitar, sem F5) ---
function amNormStrTec(s){ try{ return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }catch(e){ return (s||'').toLowerCase(); } }
function amFilterTecEntity(){
    var qEl=document.getElementById('am-entity-search-tec');
    var cntEl=document.getElementById('am-entity-count-tec');
    var clearBtn=document.getElementById('am-entity-clear-tec');
    if(!qEl) return;
    var q=amNormStrTec(qEl.value.trim());
    var cards=document.querySelectorAll('.am-tc-card');
    var visible=0;
    cards.forEach(function(c){
        var txt=amNormStrTec(c.textContent);
        var show=!q || txt.indexOf(q)!==-1;
        c.style.display = show ? '' : 'none';
        if(show) visible++;
    });
    var grid=document.querySelector('.am-tc-grid');
    var noRes=document.getElementById('am-entity-nores-tec');
    if(q && visible===0 && grid){
        if(!noRes){
            noRes=document.createElement('div');
            noRes.id='am-entity-nores-tec';
            noRes.style.cssText='grid-column:1/-1;text-align:center;color:#9ca3af;padding:18px;font-size:.85rem;background:#fff;border:1.5px dashed #e8eaf0;border-radius:12px;';
            noRes.innerHTML='<i class="ti ti-search-off" style="font-size:1.4rem;display:block;margin-bottom:6px;"></i>Nenhuma entidade encontrada para “'+ qEl.value.replace(/[&<>"\']/g,function(m){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[m]}) +'”';
            grid.appendChild(noRes);
        } else { noRes.style.display='block'; }
    } else if(noRes) noRes.style.display='none';
    if(cntEl) cntEl.textContent = q ? visible + ' de ' + cards.length + ' exibido(s)' : '';
    if(clearBtn) clearBtn.style.display = q ? 'flex' : 'none';
    try{
        var url=new URL(window.location.href);
        if(q) url.searchParams.set('q', qEl.value.trim());
        else url.searchParams.delete('q');
        url.searchParams.delete('page');
        history.replaceState(null,'',url.toString());
    }catch(e){}
}
function amInitTecEntitySearch(){
    var sEl=document.getElementById('am-entity-search-tec');
    if(!sEl) return;
    sEl.addEventListener('input', function(){ clearTimeout(window._amTecEntT); window._amTecEntT=setTimeout(amFilterTecEntity, 150); });
    sEl.addEventListener('keydown', function(e){ if(e.key==='Escape'){ sEl.value=''; amFilterTecEntity(); }});
    var cBtn=document.getElementById('am-entity-clear-tec');
    if(cBtn) cBtn.addEventListener('click', function(){ sEl.value=''; amFilterTecEntity(); sEl.focus(); });
    if(sEl.value.trim()!=='') setTimeout(amFilterTecEntity, 60);
}
// re-aplica filtro após soft refresh
var _amOrigSoftRefresh = amSoftRefresh;
amSoftRefresh = function(){
    var r=_amOrigSoftRefresh.apply(this, arguments);
    setTimeout(function(){ try{ var se=document.getElementById('am-entity-search-tec'); if(se && se.value.trim()!=='') amFilterTecEntity(); }catch(e){} }, 350);
    return r;
};
document.addEventListener('DOMContentLoaded', function(){ try{ amInitTecEntitySearch(); }catch(e){} });
</script>

<script>
function amSetView(view){
  try{
    localStorage.setItem('am_tec_view', view);
    var grid=document.getElementById('am-grid-view');
    var kanban=document.getElementById('am-kanban-view');
    var gridBtn=document.getElementById('am-view-grid-btn');
    var kanBtn=document.getElementById('am-view-kanban-btn');
    if(view==='kanban'){
      if(grid) grid.style.display='none';
      if(kanban) kanban.style.display='block';
      if(gridBtn){gridBtn.style.background='transparent';gridBtn.style.color='#9ca3af';gridBtn.style.boxShadow='none';gridBtn.classList.remove('active');}
      if(kanBtn){kanBtn.style.background='#fff';kanBtn.style.color='#4f46e5';kanBtn.style.boxShadow='0 2px 6px rgba(79,70,229,.15)';kanBtn.classList.add('active');}
    } else {
      if(grid) grid.style.display='grid';
      if(kanban) kanban.style.display='none';
      if(kanBtn){kanBtn.style.background='transparent';kanBtn.style.color='#9ca3af';kanBtn.style.boxShadow='none';kanBtn.classList.remove('active');}
      if(gridBtn){gridBtn.style.background='#fff';gridBtn.style.color='#4f46e5';gridBtn.style.boxShadow='0 2px 6px rgba(79,70,229,.15)';gridBtn.classList.add('active');}
    }
    var url=new URL(window.location.href);
    url.searchParams.set('view',view);
    history.replaceState(null,'',url.toString());
  }catch(e){}
}
document.addEventListener('DOMContentLoaded', function(){
  try{
    var v=new URLSearchParams(window.location.search).get('view') || 'kanban';
    amSetView(v==='kanban'?'kanban':'grid');
    // força kanban como padrão visual tipo Trello
    document.getElementById('am-grid-view').style.display='none';
    document.getElementById('am-kanban-view').style.display='block';
  }catch(e){}
});
function amTogglePegoTodos(btn){
  try{
    var showAll = btn.dataset.show === '0';
    document.querySelectorAll('.am-kanban-hidden-pego').forEach(function(el){ el.style.display = showAll ? 'block' : 'none'; });
    btn.dataset.show = showAll ? '1' : '0';
    btn.textContent = showAll ? 'Mostrar só meus' : 'Mostrar todos ('+ (btn.dataset.count||'') +')';
    var col = btn.closest('.am-kanban-column');
    var countEl = col ? col.querySelector('.am-kanban-count') : null;
    if(countEl){
      var all = parseInt(btn.dataset.count||'0');
      var mine = col.querySelectorAll('.am-tc-card:not(.am-kanban-hidden-pego)').length;
      countEl.textContent = showAll ? all : mine;
    }
  }catch(e){}
}
function amKanbanDragStart(e){
  var card = e.currentTarget;
  e.dataTransfer.setData('text/plain', JSON.stringify({type: card.dataset.type, id: card.dataset.id}));
  e.dataTransfer.effectAllowed = 'move';
  card.style.opacity = '0.5';
}
function amKanbanDragEnd(e){ e.currentTarget.style.opacity = '1'; }
function amKanbanDragOver(e){
  e.preventDefault();
  var col = e.currentTarget;
  // Não permite soltar chamados em Retirada
  var data = e.dataTransfer.getData('text/plain');
  try{ var obj=JSON.parse(data); if(obj.type==='ticket' && col.dataset.stage==='retirada'){ col.style.background='#fef2f2'; col.style.borderColor='#fecaca'; return; } }catch(err){}
  col.style.background='#eef2ff';
  col.style.borderColor='#c7d2fe';
  e.dataTransfer.dropEffect='move';
}
function amKanbanDragLeave(e){
  var col=e.currentTarget;
  col.style.background='#f8f9fb';
  col.style.borderColor='#e8eaf0';
}
var amKanbanPending = null;
function amKanbanDrop(e){
  e.preventDefault();
  var col=e.currentTarget;
  col.style.background='#f8f9fb';
  col.style.borderColor='#e8eaf0';
  var raw=e.dataTransfer.getData('text/plain');
  var obj; try{ obj=JSON.parse(raw); }catch(err){ return; }
  if(obj.type==='ticket' && col.dataset.stage==='retirada'){
    if(window.amKanbanToast) amKanbanToast('Chamados não podem ir para RETIRADA', 'error');
    else alert('Chamados não podem ir para RETIRADA (apenas Transferências)');
    return;
  }
  // Bloqueia arraste de Retirada/Concluido (so via Assinatura)
  try{
    var cardEl = document.querySelector('.am-tc-card[data-type="'+obj.type+'"][data-id="'+obj.id+'"]');
    var from = cardEl ? (cardEl.closest('.am-kanban-column') ? cardEl.closest('.am-kanban-column').dataset.stage : (cardEl.closest('.am-kanban-body') ? cardEl.closest('.am-kanban-body').dataset.stage : '')) : '';
    if(obj.type==='transfer' && from==='retirada' && col.dataset.stage==='concluido'){
      if(window.amKanbanToast) amKanbanToast('Retirada só conclui via aba Assinatura', 'error');
      else alert('Transferência em RETIRADA só pode ir para CONCLUÍDO após assinatura na aba Assinatura');
      return;
    }
    if(from==='retirada' || from==='concluido'){
      if(window.amKanbanToast) amKanbanToast('Card em '+from.toUpperCase()+' não pode ser arrastado', 'error');
      else alert('Card em '+from+' não pode ser movido por arraste');
      return;
    }
  }catch(err){}
  var to = col.dataset.stage;
  if(!to || !obj.type || !obj.id) return;
  amKanbanPending = {type: obj.type, id: obj.id, to: to};
  var label = to==='pendente'?'PENDENTE':to==='emandamento'?'Em Andamento':to==='retirada'?'RETIRADA':'CONCLUÍDO';
  var typeLabel = obj.type==='ticket' ? 'Chamado' : 'Transferência';
  document.getElementById('am-kanban-confirm-text').textContent = 'Mover ' + typeLabel + ' #' + obj.id + ' para ' + label + '?';
  document.getElementById('am-kanban-confirm-sub').textContent = obj.type==='ticket' && to==='emandamento' ? 'Você será vinculado como técnico no chamado padrão GLPI.' : 'A mudança de etapa será registrada no histórico.';
  amKanbanConfirmOpen();
}
function amKanbanConfirmOpen(){ var m=document.getElementById('am-kanban-confirm-modal'); if(m){ m.classList.add('open'); document.body.style.overflow='hidden'; } }
function amKanbanConfirmClose(){ var m=document.getElementById('am-kanban-confirm-modal'); if(m) m.classList.remove('open'); document.body.style.overflow=''; amKanbanPending=null; }
function amKanbanConfirmGo(){
  if(!amKanbanPending) return;
  var btn=document.getElementById('am-kanban-confirm-btn');
  var pending = {type: amKanbanPending.type, id: amKanbanPending.id, to: amKanbanPending.to};
  if(btn){ btn.disabled=true; btn.innerHTML='<i class=\"ti ti-loader-2\" style=\"animation:amSpin .8s linear infinite;display:inline-block;\"></i> Movendo...'; }
  var fd=new FormData();
  fd.append('type', pending.type);
  fd.append('id', pending.id);
  fd.append('to', pending.to);
  var csrf3=amGetCsrfToken() || '<?= Session::getNewCSRFToken() ?>';
  fd.append('_glpi_csrf_token', csrf3);
  var h3={'X-Requested-With':'XMLHttpRequest'}; if(csrf3) h3['X-Glpi-Csrf-Token']=csrf3;
  fetch('<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/kanban_move.php', {method:'POST', body:fd, credentials:'same-origin', headers:h3})
    .then(amParseKanbanResponse)
    .then(function(res){
      if(res.ok && res.j.success){
        amKanbanConfirmClose();
        if(window.amKanbanToast) amKanbanToast('Card movido para '+(pending.to==='pendente'?'PENDENTE':pending.to==='emandamento'?'Em Andamento':pending.to==='retirada'?'RETIRADA':'CONCLUÍDO')+'!');
        // Move DOM sem F5
        try{
          var card=document.querySelector('.am-tc-card[data-type=\"'+pending.type+'\"][data-id=\"'+pending.id+'\"]');
          var target=document.getElementById('am-kanban-body-'+pending.to);
          var allBodies=document.querySelectorAll('.am-kanban-body');
          var source=null; allBodies.forEach(function(b){ if(b.contains(card)) source=b; });
          if(card && target){
            var empty=target.querySelector('.am-kanban-empty'); if(empty) empty.style.display='none';
            // Para Em Andamento: se for outro técnico, marca como hidden se necessário
            var currentUid=0; try{ currentUid=parseInt('<?= (int)Session::getLoginUserID() ?>',10)||0; }catch(e){}
            // Se movendo para emandamento e ainda não é do usuário, mas drag pegou, então garantimos visível (pois acabou de pegar)
            // Remove hidden e atualiza data-mine
            card.classList.remove('am-kanban-hidden-pego');
            card.style.display='';
            if(pending.to==='emandamento') card.setAttribute('data-mine','1');
            card.style.opacity='0'; target.prepend(card);
            setTimeout(function(){ card.style.transition='opacity .25s'; card.style.opacity='1'; }, 20);
            // Atualiza contadores
            var stages=['pendente','emandamento','retirada','concluido'];
            stages.forEach(function(s){
              var b=document.getElementById('am-kanban-body-'+s);
              var c=document.getElementById('am-kanban-count-'+s);
              if(b && c){
                var visible=b.querySelectorAll('.am-tc-card:not(.am-kanban-hidden-pego):not([style*=\"display: none\"])').length;
                // fallback conta todos se não houver hidden
                if(visible===0) visible=b.querySelectorAll('.am-tc-card').length;
                c.textContent=visible;
              }
            });
            // Se foi para concluido e excede 10, aplica limite
            try{ if(pending.to==='concluido' && typeof amLimitConcluido==='function') amLimitConcluido(); }catch(e){}
            // Se source ficou vazia, mostra empty
            if(source && source.querySelectorAll('.am-tc-card').length===0){
              var em=source.querySelector('.am-kanban-empty'); if(em) em.style.display='block';
              else { var ne=document.createElement('div'); ne.className='am-kanban-empty'; ne.textContent='Nenhum card'; source.appendChild(ne); }
            }
            try{ if(window._amLastHash!==undefined) _amLastHash=null; }catch(e){}
          } else {
            setTimeout(function(){ window.location.reload(); }, 400);
          }
        }catch(err){ setTimeout(function(){ window.location.reload(); }, 400); }
        if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-arrows-move\"></i> Confirmar'; }
      }
      else { var msg=(res.j && res.j.message)?res.j.message:('Falha ao mover (HTTP '+res.status+')'); alert(msg); amKanbanConfirmClose(); if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-arrows-move\"></i> Confirmar'; } }
    }).catch(function(err){ alert('Erro: '+(err.message||err)); amKanbanConfirmClose(); if(btn){ btn.disabled=false; btn.innerHTML='<i class=\"ti ti-arrows-move\"></i> Confirmar'; } });
}
function amKanbanToast(msg, type){
  var c=document.createElement('div');
  c.textContent=msg;
  c.style.cssText='position:fixed;top:20px;right:20px;background:'+(type==='error'?'linear-gradient(135deg,#dc2626,#ef4444)':'linear-gradient(135deg,#4f46e5,#7c3aed)')+';color:#fff;padding:12px 18px;border-radius:10px;font-weight:700;font-size:.85rem;box-shadow:0 8px 24px rgba(0,0,0,.2);z-index:10002;';
  document.body.appendChild(c);
  setTimeout(function(){ c.style.opacity='0'; c.style.transition='opacity .3s'; setTimeout(function(){ c.remove(); },300); }, 3000);
}
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.am-kanban-column .am-tc-card').forEach(function(card){
    // Respeita draggable=false de Retirada/Concluido (bloqueia arraste desses)
    if(card.getAttribute('draggable') !== 'false'){
      card.setAttribute('draggable','true');
    }
    card.addEventListener('dragstart', amKanbanDragStart);
    card.addEventListener('dragend', amKanbanDragEnd);
  });
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("am-theme-btn");
    if(!btn) return;
    var dark = localStorage.getItem("am_theme") === "dark";
    btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
});
</script>
<?php Html::footer(); ?>
