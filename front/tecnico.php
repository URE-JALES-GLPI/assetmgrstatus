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
.am-kanban{display:flex;gap:16px;overflow-x:auto;padding-bottom:16px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;}
.am-kanban-column{flex:0 0 340px;min-width:340px;max-width:340px;background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:14px;display:flex;flex-direction:column;max-height:75vh;scroll-snap-align:start;}
.am-kanban-header{padding:14px 16px;font-weight:800;font-size:.9rem;color:#1e2333;display:flex;align-items:center;justify-content:space-between;border-bottom:1.5px solid #e8eaf0;background:#fff;border-radius:14px 14px 0 0;position:sticky;top:0;z-index:1;}
.am-kanban-count{background:#eef2ff;color:#4f46e5;border-radius:20px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.am-kanban-body{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1;}
.am-kanban-empty{text-align:center;color:#9ca3af;padding:24px 12px;font-size:.85rem;border:1.5px dashed #e8eaf0;border-radius:10px;background:#fff;}
.am-kanban .am-tc-card{margin:0;flex-shrink:0;}
@media(max-width:768px){.am-kanban{gap:12px;padding-bottom:12px;}.am-kanban-column{flex:0 0 300px;min-width:300px;max-width:300px;}}
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
            <div style="display:flex;background:#f4f6fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:3px;gap:3px;">
                <button id="am-view-grid-btn" class="am-view-btn" onclick="amSetView('grid')" title="Grade" style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:7px;border:none;background:transparent;color:#9ca3af;cursor:pointer;"><i class="ti ti-layout-grid"></i></button>
                <button id="am-view-kanban-btn" class="am-view-btn active" onclick="amSetView('kanban')" title="Kanban" style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:7px;border:none;background:#fff;color:#4f46e5;box-shadow:0 2px 6px rgba(79,70,229,.15);cursor:pointer;"><i class="ti ti-layout-kanban"></i></button>
            </div>
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

    // Paginação sobre timeline unificada
    $tc_page     = max(1, (int)($_GET['page'] ?? 1));
    $tc_per_page = 12;
    $tc_total    = count($combined);
    $tc_pages    = max(1, (int)ceil($tc_total / $tc_per_page));
    $tc_page     = min($tc_page, $tc_pages);
    $combined_page = array_slice($combined, ($tc_page - 1) * $tc_per_page, $tc_per_page);

    // Monta lista de técnicos únicos que já pegaram algum card
    $techs_in_transfers = [];
    foreach (Transfer::getAll() as $t) {
        if ($t['users_id_tech'] && $t['tech_name'] && !isset($techs_in_transfers[$t['users_id_tech']])) {
            $techs_in_transfers[$t['users_id_tech']] = $t['tech_name'];
        }
    }
    ?>

    <?php if (!empty($techs_in_transfers)): ?>
    <div class="am-filters-bar" style="margin-bottom:16px;">
        <div class="am-filter-group">
            <label>TÉCNICO</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['status' => $filter_status,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>"
                   class="am-type-tab <?= !$filter_tech ? 'active' : '' ?>">
                    Todos
                </a>
                <?php foreach ($techs_in_transfers as $uid => $uname): ?>
                <a href="?<?= http_build_query(['status' => $filter_status, 'tech' => $uid,'tipo'=>$filter_tipo,'cat'=>$filter_cat?:'','date' => $filter_date, 'sort' => $filter_sort, 'q' => $q]) ?>"
                   class="am-type-tab <?= $filter_tech === $uid ? 'active' : '' ?>">
                    <i class="ti ti-user-check"></i> <?= htmlspecialchars($uname) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
        <div class="am-tc-card">
            <div class="am-tc-card-header" style="border-left:4px solid <?= $status_color ?>;">
                <div>
                    <div style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                        Transferência #<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?>
                    </div>
                    <div style="font-weight:800;font-size:1rem;color:#1e2333;">
                        <?= htmlspecialchars($t['origin_entity_name']) ?>
                    </div>
                </div>
                <span class="am-badge <?= Transfer::getStatusBadgeClass($t['status']) ?>"><?= $status_label ?></span>
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
        <div class="am-tc-card" style="border-left:4px solid <?= $tkStatusColor ?>;">
            <div class="am-tc-card-header" style="border-left:4px solid <?= $tkStatusColor ?>;background:#f8f9ff;">
                <div>
                    <div style="font-size:.72rem;color:#4f46e5;font-weight:700;text-transform:uppercase;display:flex;align-items:center;gap:6px;">
                        <i class="ti ti-ticket" style="font-size:.9rem;"></i> Chamado #<?= str_pad($tk['id'], 6, '0', STR_PAD_LEFT) ?> • <?= htmlspecialchars($tk['category_name']) ?>
                    </div>
                    <div style="font-weight:800;font-size:1rem;color:#1e2333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;" title="<?= htmlspecialchars($tk['name']) ?>">
                        <?= htmlspecialchars($tk['name'] ?: 'Sem título') ?>
                    </div>
                </div>
                <span class="am-badge" style="background:<?= $tkStatusColor ?>;color:#fff;"><?= htmlspecialchars($tkStatusLabel) ?></span>
            </div>
            <div class="am-tc-card-body">
                <div class="am-tc-info-row"><i class="ti ti-category" style="color:#4f46e5;"></i><span style="color:#4f46e5;font-weight:700;"><?= htmlspecialchars($tk['category_name']) ?></span></div>
                <?php if ($tk['entity_name']): ?><div class="am-tc-info-row"><i class="ti ti-building"></i><span><?= htmlspecialchars($tk['entity_name']) ?></span></div><?php endif; ?>
                <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span><?= Html::convDateTime($tk['date_mod'] ?: $tk['date_creation']) ?></span></div>
                <div class="am-tc-info-row"><i class="ti ti-clock"></i><span><?= date('d/m/Y H:i', strtotime($tk['date_creation'])) ?></span></div>
                <?php if ($tkContentShort): ?><div class="am-tc-reason" style="background:#f8f9ff;border-color:#e0e7ff;"><?= htmlspecialchars($tkContentShort) ?></div><?php endif; ?>
            </div>
            <div class="am-tc-card-footer" style="justify-content:space-between;">
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
                $kanbanStages = [
                    'pendente'   => ['label'=>'PENDENTE', 'color'=>Transfer::getStatusColor(Transfer::STATUS_PENDENTE), 'status'=>Transfer::STATUS_PENDENTE],
                    'pego'       => ['label'=>'PEGO', 'color'=>Transfer::getStatusColor(Transfer::STATUS_MANUTENCAO), 'status'=>Transfer::STATUS_MANUTENCAO],
                    'concluido'  => ['label'=>'CONCLUÍDO', 'color'=>Transfer::getStatusColor(Transfer::STATUS_PRONTO), 'status'=>Transfer::STATUS_PRONTO],
                    'aguardando' => ['label'=>'AGUARDANDO PEGAR', 'color'=>'#f59e0b', 'status'=>Transfer::STATUS_PRONTO, 'desc'=>'Assinar finaliza'],
                    'finalizado' => ['label'=>'FINALIZADO', 'color'=>Transfer::getStatusColor(Transfer::STATUS_FINALIZADO), 'status'=>Transfer::STATUS_FINALIZADO],
                ];
                foreach ($kanbanStages as $stageKey=>$stage):
                    $sKey = $stage['status'];
                    $sLabel = $stage['label'];
                    $sColor = $stage['color'];
                    // Para AGUARDANDO PEGAR, mostra os mesmos PRONTO mas com ação de assinatura
                    $colCards = array_values(array_filter($combined_page, function($it) use ($stageKey, $sKey){
                        if ($it['type']!=='transfer') return false;
                        if ($it['data']['status']!==$sKey) return false;
                        // Diferencia CONCLUÍDO vs AGUARDANDO pela assinatura
                        if ($stageKey==='concluido' && !empty($it['data']['assinatura_image'])) return false;
                        if ($stageKey==='aguardando' && empty($it['data']['assinatura_image'])) return false;
                        // Para PENDENTE/PEGO/FINALIZADO mostra direto
                        if (in_array($stageKey, ['pendente','pego','finalizado'])) return true;
                        return true;
                    }));
                    if ($filter_tipo==='ticket') continue;
                    if ($stageKey==='aguardando' && $filter_status!=='' && $filter_status!==Transfer::STATUS_PRONTO) continue;
            ?>
            <div class="am-kanban-column">
                <div class="am-kanban-header" style="border-top:4px solid <?= $sColor ?>;">
                    <span><?= htmlspecialchars($sLabel) ?><?php if (!empty($stage['desc'])): ?><small style="font-weight:400;color:#9ca3af;font-size:.7rem;margin-left:6px;"><?= htmlspecialchars($stage['desc']) ?></small><?php endif; ?></span><span class="am-kanban-count"><?= count($colCards) ?></span>
                </div>
                <div class="am-kanban-body">
                    <?php foreach ($colCards as $item): $t = $item['data'];
                        $endForElapsed = $t['date_finalizado'] ?? $t['date_cancelado'] ?? null;
                        $isTerminal = in_array($t['status'], [Transfer::STATUS_FINALIZADO, Transfer::STATUS_CANCELADA], true);
                        $elapsed = Transfer::getElapsedTime($t['date_creation'], $isTerminal ? $endForElapsed : null);
                        $status_color = Transfer::getStatusColor($t['status']);
                    ?>
                    <div class="am-tc-card" style="margin:0;">
                        <div class="am-tc-card-header" style="border-left:4px solid <?= $status_color ?>;padding:12px 14px;">
                            <div style="min-width:0;flex:1;">
                                <div style="font-size:.65rem;color:#9ca3af;font-weight:700;">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?> • <?= date('d/m H:i', strtotime($t['date_creation'])) ?></div>
                                <div style="font-weight:800;font-size:.9rem;color:#1e2333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($t['origin_entity_name']) ?></div>
                            </div>
                            <span class="am-badge <?= Transfer::getStatusBadgeClass($t['status']) ?>" style="font-size:.65rem;"><?= htmlspecialchars($sLabel) ?></span>
                        </div>
                        <div class="am-tc-card-body" style="padding:10px 14px;">
                            <div class="am-tc-info-row" style="font-size:.78rem;"><i class="ti ti-box"></i><span><?= $t['items_count'] ?> ativo(s)</span></div>
                            <?php if ($t['reason']): ?><div class="am-tc-reason" style="font-size:.75rem;padding:6px 8px;"><?= htmlspecialchars(mb_strimwidth($t['reason'],0,60,'…')) ?></div><?php endif; ?>
                        </div>
                        <div class="am-tc-card-footer" style="padding:8px 12px;">
                            <?php if ($t['status']===Transfer::STATUS_PENDENTE): ?>
                            <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;" onclick="amOpenPegarModal(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['origin_entity_name'])) ?>',<?= $t['items_count'] ?>)"><i class="ti ti-hand-grab"></i> Pegar</button>
                            <?php elseif ($t['status']===Transfer::STATUS_MANUTENCAO): ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_diario.php?id=<?= $t['id'] ?>" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;">Diário</a>
                            <?php elseif ($t['status']===Transfer::STATUS_PRONTO): ?>
                            <button class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;flex:1;padding:6px 10px;font-size:.75rem;" onclick="amOpenFinalizarModal(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['entity_dest_name'])) ?>')"><i class="ti ti-flag-check"></i> Finalizar</button>
                            <?php else: ?>
                            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= $t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="flex:1;padding:6px 10px;font-size:.75rem;"><i class="ti ti-file-type-pdf"></i> PDF</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($colCards)): ?><div class="am-kanban-empty">Nenhum card</div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($filter_tipo!=='transfer'): 
            $ticketsForKanban = array_values(array_filter($combined_page, fn($it)=>$it['type']==='ticket'));
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

    <?php if ($tc_pages > 1): ?>
    <div class="am-pagination">
        <div class="am-pagination-info"><?= $tc_total ?> card(s) — página <?= $tc_page ?> de <?= $tc_pages ?> (<?= count($transfers) ?> transf. + <?= count($tickets) ?> chamados)</div>
        <div class="am-pagination-pages">
            <?php
            $tc_qs = fn($p) => http_build_query([
                'status' => $filter_status,
                'tech'   => $filter_tech ?: '',
                'date'   => $filter_date,
                'sort'   => $filter_sort,
                'q'      => $q,
                'tipo'   => $filter_tipo,
                'cat'    => $filter_cat ?: '',
                'page'   => $p,
            ]);
            $tc_window = $tc_pages <= 10 ? range(1, $tc_pages) : array_values(array_unique(array_merge(
                [1],
                range(max(2, $tc_page - 2), min($tc_pages - 1, $tc_page + 2)),
                [$tc_pages]
            )));
            $tc_last = 0;
            foreach ($tc_window as $tc_n):
                if ($tc_n - $tc_last > 1): ?><span class="am-page-link disabled" style="background:transparent;box-shadow:none;">…</span><?php endif;
                $tc_last = $tc_n;
            ?>
            <a class="am-page-link <?= $tc_n === $tc_page ? 'active' : '' ?>" href="?<?= $tc_qs($tc_n) ?>"><?= $tc_n ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<!-- Modal Pegar -->
<div id="am-modal-pegar" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:460px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-hand-grab"></i><span>Assumir Manutenção</span></div>
        </div>
        <form method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php">
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
    document.body.style.overflow = '';
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
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    amClosePegarModal(); amCloseFinalizarModal(); amCloseCancelarModal();
});

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
    var v=new URLSearchParams(window.location.search).get('view') || localStorage.getItem('am_tec_view') || 'kanban';
    amSetView(v==='kanban'?'kanban':'grid');
  }catch(e){}
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("am-theme-btn");
    var dark = localStorage.getItem("am_theme") === "dark";
    btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
});
</script>
<?php Html::footer(); ?>
