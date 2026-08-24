<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Stats;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus', READ);

global $CFG_GLPI, $DB;

$filter_type   = $_GET['type']   ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_comp   = $_GET['comp']   ?? []; // array: ['motherboard' => 'has', 'keyboard' => 'not', ...]
if (!is_array($filter_comp)) $filter_comp = [];
$filter_comp   = array_filter($filter_comp, fn($v) => in_array($v, ['has', 'not'], true));
$raw_fab = $_GET['fabricante'] ?? [];
if (is_string($raw_fab)) $raw_fab = $raw_fab !== '' ? [$raw_fab] : [];
if (!is_array($raw_fab)) $raw_fab = [];
$filter_fabricante = array_values(array_filter(array_map('intval', $raw_fab)));
$is_mobile_ua  = preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
$view_mode     = $_GET['view']   ?? ($is_mobile_ua ? 'grid' : 'list');
if ($is_mobile_ua && $view_mode === 'list') $view_mode = 'grid'; // força grade no mobile mesmo se view=list na URL
$page          = max(1, (int)($_GET['page'] ?? 1));

// Helper para montar querystring preservando o array comp[] e fabricante — lê direto de $_GET para evitar perda de filtro
function am_qs(array $overrides = []): string {
    $cur_type   = $_GET['type']   ?? '';
    $cur_search = $_GET['search'] ?? '';
    $cur_status = $_GET['status'] ?? '';
    $cur_comp   = $_GET['comp']   ?? [];
    if (!is_array($cur_comp)) $cur_comp = [];
    $cur_comp   = array_filter($cur_comp, fn($v) => in_array($v, ['has', 'not'], true));
    $cur_fab    = $_GET['fabricante'] ?? [];
    if (is_string($cur_fab)) $cur_fab = $cur_fab !== '' ? [$cur_fab] : [];
    if (!is_array($cur_fab)) $cur_fab = [];
    $cur_fab    = array_values(array_filter(array_map('intval', $cur_fab)));
    $is_mobile_qs = preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $cur_view   = $_GET['view']   ?? ($is_mobile_qs ? 'grid' : 'list');
    if ($is_mobile_qs && $cur_view === 'list') $cur_view = 'grid';
    $cur_page   = max(1, (int)($_GET['page'] ?? 1));

    // Usa override se a chave existir no array (permite '' para "Todos"), senão usa valor atual da URL
    $params = [
        'type'   => array_key_exists('type', $overrides)   ? $overrides['type']   : $cur_type,
        'search' => array_key_exists('search', $overrides) ? $overrides['search'] : $cur_search,
        'status' => array_key_exists('status', $overrides) ? $overrides['status'] : $cur_status,
        'view'   => array_key_exists('view', $overrides)   ? $overrides['view']   : $cur_view,
    ];
    $has_filter_override = array_key_exists('type', $overrides) || array_key_exists('search', $overrides) || array_key_exists('status', $overrides) || array_key_exists('comp', $overrides) || array_key_exists('fabricante', $overrides);
    $params['page'] = $has_filter_override ? 1 : (array_key_exists('page', $overrides) ? $overrides['page'] : $cur_page);
    $comp = array_key_exists('comp', $overrides) ? $overrides['comp'] : $cur_comp;
    if (!is_array($comp)) $comp = [];
    $fab  = array_key_exists('fabricante', $overrides) ? $overrides['fabricante'] : $cur_fab;
    if (is_string($fab)) $fab = $fab !== '' ? [$fab] : [];
    if (!is_array($fab)) $fab = [];
    $fab  = array_values(array_filter(array_map('intval', $fab)));
    // Se trocar de tipo e novo tipo não for Notebook, limpa fabricante (só faz sentido para Notebook)
    if (array_key_exists('type', $overrides) && $overrides['type'] !== 'Notebook') {
        $fab = [];
    }
    $qs = http_build_query($params);
    foreach ($comp as $k => $v) {
        $qs .= '&comp%5B' . urlencode($k) . '%5D=' . urlencode($v);
    }
    foreach ($fab as $fid) {
        $qs .= '&fabricante%5B%5D=' . urlencode($fid);
    }
    return $qs;
}

Html::header('Inventário de Ativos', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'maintenance');

// Força GRADE no mobile via JS (viewport) — além do UA no PHP
echo '<script>try{if(window.matchMedia&&window.matchMedia("(max-width: 768px)").matches){var p=new URLSearchParams(window.location.search);if(p.get("view")==="list"){p.set("view","grid");var u=window.location.pathname+(p.toString()?"?"+p.toString():"");window.location.replace(u);}}}catch(e){}</script>';

$paged        = MaintenanceRecord::getAssetsPaged($filter_type, $filter_search, $filter_status, $filter_comp, $filter_fabricante, $page);
$assets       = $paged['rows'];
$types        = MaintenanceRecord::getAssetTypes();
$status_opts  = MaintenanceRecord::getStatusOptions();
$fab_list     = ($filter_type === 'Notebook') ? MaintenanceRecord::getManufacturers('Notebook') : [];
$comp_list    = MaintenanceRecord::getComponents();
$entity_id    = Session::getActiveEntity();
$stats        = Stats::getAll($entity_id);
$type_counts  = Stats::getCountsByType($entity_id);
$form_action  = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.form.php';
$action_url   = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/action.form.php';
$can_tecnico  = Session::haveRight(MaintenanceRecord::RIGHT_TECNICO, READ);
$can_transfer = Session::haveRight('plugin_assetmgrstatus_transfer', CREATE) || Session::haveRight('plugin_assetmgrstatus_transfer', UPDATE)
    || Session::haveRight('plugin_assetmgrstatus', CREATE) || Session::haveRight('plugin_assetmgrstatus', UPDATE);
$entities_ure    = $can_transfer ? Transfer::getEntidades('ure') : [];
$entities_escola = $can_transfer ? Transfer::getEntidades('escola') : [];
$can_delete = Session::haveRight('plugin_assetmgrstatus_delete', DELETE) || Session::haveRight('plugin_assetmgrstatus', DELETE);
?>

<div class="container-fluid am-page">

    <div class="am-page-header">
        <div class="am-page-title">
            <i class="ti ti-clipboard-list"></i>
            <h2>Inventário de Ativos</h2>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/dashboard.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-dashboard"></i> Dashboard
            </a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_tecnico', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-tools"></i> Técnico
            </a>
            <?php endif; ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/reports.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-report"></i> Relatórios
            </a>
            <button id="am-theme-btn" onclick="amToggleTheme()"
                class="am-btn am-btn-secondary" style="padding:8px 12px;font-size:.82rem;" title="Alternar tema claro/escuro">
                <i class="ti ti-moon"></i>
            </button>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/export.php?format=excel&type=<?= urlencode($filter_type) ?>&status=<?= urlencode($filter_status) ?>"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-file-spreadsheet"></i> Excel
            </a>
            <div style="font-size:.85rem;color:#9ca3af;"><?= $paged['total'] ?> ativo(s)</div>
        </div>
    </div>

    <!-- Cards status em linha única centralizada (replica Dashboard) -->
    <div class="am-inv-dash-row">
        <?php foreach (MaintenanceRecord::getStatusOptions() as $key => $label):
            $count = $stats['by_status'][$key] ?? 0;
            $isActive = $filter_status === $key;
        ?>
        <a href="?<?= am_qs(['status' => $isActive ? '' : $key]) ?>" class="am-dash-card <?= $isActive ? 'am-dash-card-active' : '' ?>" style="min-width:150px;flex:0 0 auto;text-decoration:none;<?= $isActive ? 'border-color:#4f46e5;box-shadow:0 4px 16px rgba(79,70,229,.18);background:#eef2ff;' : '' ?>">
            <div class="am-dash-card-top"><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span></div>
            <div class="am-dash-number"><?= $count ?></div>
            <div class="am-dash-label">ativos</div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <div class="am-filters-bar">
        <div class="am-filter-group">
            <label>Tipo</label>
            <div class="am-type-tabs">
                <a href="?<?= am_qs(['type' => '']) ?>" class="am-type-tab <?= $filter_type==='' ? 'active' : '' ?>"><i class="ti ti-layout-grid"></i> Todos <span class="am-type-count"><?= $type_counts['total'] ?? 0 ?></span></a>
                <?php foreach ($types as $key => $def): ?>
                <a href="?<?= am_qs(['type' => $key]) ?>" class="am-type-tab <?= $filter_type===$key ? 'active' : '' ?>">
                    <i class="ti <?= $def['icon'] ?>"></i> <?= htmlspecialchars($def['label']) ?> <span class="am-type-count"><?= $type_counts[$key] ?? 0 ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="am-filter-group">
            <label>Status</label>
            <div class="am-type-tabs">
                <a href="?<?= am_qs(['status' => '']) ?>" class="am-type-tab <?= $filter_status==='' ? 'active' : '' ?>">Todos</a>
                <?php foreach ($status_opts as $key => $label): ?>
                <a href="?<?= am_qs(['status' => $key]) ?>" class="am-type-tab am-status-tab <?= $filter_status===$key ? 'active' : '' ?>">
                    <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($filter_type === 'Notebook'): ?>
        <div class="am-filter-group">
            <label>Fabricante</label>
            <div style="position:relative;">
                <button type="button" class="am-comp-filter-btn" onclick="amToggleFabPanel()">
                    <i class="ti ti-building-factory-2"></i> Filtrar por fabricante
                    <?php if (!empty($filter_fabricante)): ?>
                    <span class="am-comp-filter-count"><?= count($filter_fabricante) ?></span>
                    <?php endif; ?>
                </button>

                <div id="am-fab-panel" class="am-comp-panel">
                    <form method="GET" action="" id="am-fab-filter-form">
                        <input type="hidden" name="type"   value="Notebook">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($filter_search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                        <input type="hidden" name="view"   value="<?= $view_mode ?>">
                        <?php foreach ($filter_comp as $ck => $cv): ?>
                        <input type="hidden" name="comp[<?= htmlspecialchars($ck) ?>]" value="<?= htmlspecialchars($cv) ?>">
                        <?php endforeach; ?>

                        <div class="am-comp-panel-header">
                            <strong>Filtrar por fabricante</strong>
                            <small>Selecione um ou mais fabricantes para filtrar os Notebooks.</small>
                        </div>

                        <div class="am-comp-panel-list">
                            <?php if (empty($fab_list)): ?>
                            <div style="padding:16px;text-align:center;color:#9ca3af;font-size:.82rem;">Nenhum fabricante encontrado para Notebook.</div>
                            <?php else: foreach ($fab_list as $fid => $fname):
                                $checked = in_array((int)$fid, $filter_fabricante, true);
                            ?>
                            <div class="am-comp-panel-row">
                                <span class="am-comp-panel-label"><?= htmlspecialchars($fname) ?></span>
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                    <input type="checkbox" name="fabricante[]" value="<?= (int)$fid ?>" <?= $checked ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#4f46e5;">
                                </label>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="am-comp-panel-footer">
                            <button type="button" class="am-btn am-btn-secondary" style="padding:7px 14px;font-size:.8rem;" onclick="amClearFabFilters()">Limpar</button>
                            <button type="submit" class="am-btn am-btn-primary" style="padding:7px 14px;font-size:.8rem;">Aplicar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="am-filter-group">
            <label>Componentes</label>
            <div style="position:relative;">
                <button type="button" class="am-comp-filter-btn" onclick="amToggleCompPanel()">
                    <i class="ti ti-cpu"></i> Filtrar por componentes
                    <?php if (!empty($filter_comp)): ?>
                    <span class="am-comp-filter-count"><?= count($filter_comp) ?></span>
                    <?php endif; ?>
                </button>

                <div id="am-comp-panel" class="am-comp-panel">
                    <form method="GET" action="" id="am-comp-filter-form">
                        <input type="hidden" name="type"   value="<?= htmlspecialchars($filter_type) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($filter_search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                        <input type="hidden" name="view"   value="<?= $view_mode ?>">
                        <?php foreach ($filter_fabricante as $ffid): ?>
                        <input type="hidden" name="fabricante[]" value="<?= (int)$ffid ?>">
                        <?php endforeach; ?>

                        <div class="am-comp-panel-header">
                            <strong>Filtrar por componentes</strong>
                            <small>Para cada componente, escolha se quer ver "com problema", "funcionando" ou ignorar.</small>
                        </div>

                        <div class="am-comp-panel-list">
                            <?php foreach ($comp_list as $ckey => $clabel):
                                $current = $filter_comp[$ckey] ?? '';
                            ?>
                            <div class="am-comp-panel-row">
                                <span class="am-comp-panel-label"><?= htmlspecialchars($clabel) ?></span>
                                <div class="am-comp-3state" data-comp="<?= $ckey ?>">
                                    <button type="button" class="am-3state-btn <?= $current === '' ? 'active' : '' ?>" data-value="" title="Ignorar">—</button>
                                    <button type="button" class="am-3state-btn am-3state-has <?= $current === 'has' ? 'active' : '' ?>" data-value="has" title="Com problema">⚠️</button>
                                    <button type="button" class="am-3state-btn am-3state-not <?= $current === 'not' ? 'active' : '' ?>" data-value="not" title="Funcionando">✅</button>
                                </div>
                                <input type="hidden" name="comp[<?= $ckey ?>]" value="<?= htmlspecialchars($current) ?>" id="comp-input-<?= $ckey ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="am-comp-panel-footer">
                            <button type="button" class="am-btn am-btn-secondary" style="padding:7px 14px;font-size:.8rem;" onclick="amClearCompFilters()">Limpar</button>
                            <button type="submit" class="am-btn am-btn-primary" style="padding:7px 14px;font-size:.8rem;">Aplicar Filtros</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex:1;align-items:center;">
            <form method="GET" action="" id="am-search-form" style="flex:1;display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="type"   value="<?= htmlspecialchars($filter_type) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <?php foreach ($filter_comp as $ckey => $cval): ?>
                <input type="hidden" name="comp[<?= htmlspecialchars($ckey) ?>]" value="<?= htmlspecialchars($cval) ?>">
                <?php endforeach; ?>
                <?php foreach ($filter_fabricante as $ffid): ?>
                <input type="hidden" name="fabricante[]" value="<?= (int)$ffid ?>">
                <?php endforeach; ?>
                <input type="hidden" name="view"   value="<?= $view_mode ?>">
                <div class="am-filter-search">
                    <input type="text" name="search" placeholder="Buscar por nome, serial..." value="<?= htmlspecialchars($filter_search) ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('am-search-form').submit();}">
                    <button type="submit" class="am-search-btn" title="Pesquisar"><i class="ti ti-search"></i></button>
                </div>
            </form>
            <div class="am-view-toggle">
                <a href="?<?= am_qs(['view' => 'list']) ?>" class="am-view-btn <?= $view_mode==='list' ? 'active' : '' ?>" title="Lista"><i class="ti ti-list"></i></a>
                <a href="?<?= am_qs(['view' => 'grid']) ?>" class="am-view-btn <?= $view_mode==='grid' ? 'active' : '' ?>" title="Grade"><i class="ti ti-layout-grid"></i></a>
            </div>
        </div>
    </div>

    <!-- Barra de ação em massa -->
    <div id="am-bulk-bar" class="am-bulk-bar">
        <span id="am-bulk-count">0 selecionado(s)</span>
        <button class="am-btn am-btn-primary" style="padding:7px 16px;font-size:.82rem;" onclick="amOpenBulkModal()"><i class="ti ti-edit"></i> Alterar Status em Massa</button>
        <?php if ($can_transfer): ?>
        <button class="am-btn" style="background:#fff;color:#1e40af;padding:7px 16px;font-size:.82rem;border:1.5px solid #dbeafe;" onclick="amOpenTransferModalFromBulk()"><i class="ti ti-transfer"></i> Transferir</button>
        <?php endif; ?>
        <?php if ($can_delete): ?>
        <button class="am-btn am-btn-danger" style="padding:7px 16px;font-size:.82rem;" onclick="amOpenBulkDeleteModal()"><i class="ti ti-trash"></i> Excluir</button>
        <?php endif; ?>
        <button class="am-btn am-btn-secondary" style="padding:7px 16px;font-size:.82rem;" onclick="amClearSelection()"><i class="ti ti-x"></i> Limpar seleção</button>
    </div>
    <script>
    // Fallback imediato caso o script principal falhe (404)
    window.amCloseTransferModal = window.amCloseTransferModal || function(e){
        if(e && e.target !== document.getElementById('am-modal-transfer')) return;
        var m=document.getElementById('am-modal-transfer'); if(m) m.classList.remove('open'); document.body.style.overflow='';
    };
    window.amToggleTransferSubmit = window.amToggleTransferSubmit || function(){
        var cb=document.getElementById('am-tr-agree'); var b=document.getElementById('am-tr-submit'); if(!cb||!b) return; b.disabled=!cb.checked; b.style.opacity=cb.checked?'1':'.4'; b.style.cursor=cb.checked?'pointer':'not-allowed';
    };
    window.amSwitchTransferType = window.amSwitchTransferType || function(type){
        var ureS=document.getElementById('am-tr-ure-section'); var escS=document.getElementById('am-tr-escola-section');
        var ureL=document.getElementById('am-tr-type-ure-label'); var escL=document.getElementById('am-tr-type-escola-label');
        var ureSel=document.getElementById('am-tr-entity-ure'); var escSel=document.getElementById('am-tr-entity-escola');
        if(!ureS||!escS) return;
        if(type==='ure'){ ureS.style.display='block'; escS.style.display='none'; ureSel.name='entity_dest'; ureSel.disabled=false; ureSel.required=true; escSel.name='entity_dest_escola_disabled'; escSel.disabled=true; escSel.required=false; ureL.style.borderColor='#1e40af'; ureL.style.background='#eff6ff'; escL.style.borderColor='#e8eaf0'; escL.style.background='#f8f9fb'; }
        else { ureS.style.display='none'; escS.style.display='block'; escSel.name='entity_dest'; escSel.disabled=false; escSel.required=true; ureSel.name='entity_dest_ure_disabled'; ureSel.disabled=true; ureSel.required=false; escL.style.borderColor='#1e40af'; escL.style.background='#eff6ff'; ureL.style.borderColor='#e8eaf0'; ureL.style.background='#f8f9fb'; }
    };
    window.amOpenTransferModalFromBulk = window.amOpenTransferModalFromBulk || function(){
        try{
            console.log('fallback amOpenTransferModalFromBulk');
            var cbs=document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
            if(!cbs.length){alert('Selecione ao menos um ativo.');return;}
            var items=[],names=[];
            cbs.forEach(function(cb){
                var oserial=cb.dataset.otherserial||cb.dataset.serial||'';
                items.push({id:parseInt(cb.value),itemtype:cb.dataset.itemtype,name:cb.dataset.name,otherserial:oserial});
                names.push(cb.dataset.name + (oserial ? ' ('+oserial+')' : ''));
            });
            var inp=document.getElementById('am-tr-selected-assets');
            var lst=document.getElementById('am-tr-asset-list');
            if(inp) inp.value=JSON.stringify(items);
            if(lst) lst.innerHTML='<strong>'+items.length+' ativo(s) selecionado(s):</strong><br>'+names.join(', ');
            var ur=document.getElementById('am-tr-type-ure'); if(ur){ur.checked=true; window.amSwitchTransferType('ure');}
            var mod=document.getElementById('am-modal-transfer');
            if(mod){mod.classList.add('open'); document.body.style.overflow='hidden'; console.log('modal opened fallback');}
            else {console.error('am-modal-transfer missing'); alert('Modal Transferir não encontrado. Verifique permissão.');}
        }catch(e){console.error(e);alert('Erro: '+e.message);}
    };
    // Fallback para confirmação (caso assetmgrstatus.js não carregue)
    window.amConfirmTransfer = window.amConfirmTransfer || function(){
        var f=document.getElementById('am-transfer-form'); if(!f) return;
        if(!f.checkValidity()){ f.reportValidity(); return; }
        // tenta abrir segunda janela se existir, senão envia direto
        if(document.getElementById('am-modal-transfer-confirm')){ if(typeof window.amConfirmTransfer==='function' && window.amConfirmTransfer.toString().indexOf('am-tr-confirm-body')!==-1) return; }
        f.submit();
    };
    window.amCloseTransferConfirm = window.amCloseTransferConfirm || function(e){
        if(e && e.target !== document.getElementById('am-modal-transfer-confirm')) return;
        var m=document.getElementById('am-modal-transfer-confirm'); if(m) m.classList.remove('open');
    };
    </script>

    <!-- GRID -->
    <?php if ($view_mode === 'grid'): ?>
    <div class="am-asset-grid">
        <?php if (empty($assets)): ?>
        <div class="am-empty-state"><i class="ti ti-device-desktop-off"></i><p>Nenhum ativo encontrado.</p></div>
        <?php else: foreach ($assets as $asset):
            $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
            $badge_class   = MaintenanceRecord::getStatusBadgeClass($plugin_status);
            $status_label  = MaintenanceRecord::getStatusLabel($plugin_status);
            $alert60       = $asset['alert_60days'] ?? false;
        ?>
        <?php
        $is_transferred = !empty($asset['transfer_status']) && $asset['transfer_status'] === 'transferido';
        // Clique na linha/card seleciona checkbox; modal abre apenas via botão Status
        $card_onclick = $is_transferred ? '' : 'onclick="amHandleAssetClick(this, event)"';
        ?>
        <div class="am-asset-card am-skeleton <?= $alert60 ? 'am-card-alert' : '' ?> <?= $is_transferred ? 'am-card-locked-transfer' : '' ?>" style="transition:transform .15s ease;" data-asset-id="<?= (int)$asset['id'] ?>" <?= $card_onclick ?>>
            <?php if ($is_transferred): ?>
            <div class="am-transfer-lock-banner">
                <i class="ti ti-lock"></i> Em transferência — aguardando retorno do técnico
            </div>
            <?php endif; ?>
            <div class="am-card-checkbox" onclick="event.stopPropagation()">
                <input type="checkbox" class="am-bulk-checkbox" value="<?= (int)$asset['id'] ?>" data-itemtype="<?= htmlspecialchars($asset['itemtype']) ?>" data-name="<?= htmlspecialchars($asset['name']) ?>" data-otherserial="<?= htmlspecialchars($asset['otherserial'] ?? '') ?>" data-serial="<?= htmlspecialchars($asset['serial'] ?? '') ?>" onchange="amUpdateBulkBar()" <?= $is_transferred ? 'disabled title="Em transferência — não pode ser selecionado"' : '' ?>>
            </div>
            <?php if ($alert60):
                $days = $asset['days_since_maintenance'];
                $msg60 = $days === null
                    ? 'Nenhuma manutenção realizada registrada para este ativo. Recomenda-se verificação imediata.'
                    : 'Última manutenção há ' . (int)$days . ' dias — ' . max(0, (int)$days - 60) . ' dia(s) acima do limite de 60 dias.';
            ?>
            <div class="am-alert-60 am-alert-trigger" tabindex="0" onclick="event.stopPropagation()">
                <i class="ti ti-alert-triangle"></i> +60 dias sem manutenção
                <div class="am-alert-popup">
                    <strong style="display:block;margin-bottom:4px;"><i class="ti ti-alert-triangle"></i> Alerta +60 dias</strong>
                    <?= htmlspecialchars($msg60) ?>
                    <?php if ($days !== null): ?>
                    <span style="display:block;margin-top:6px;font-size:.72rem;opacity:.8;">Última manutenção: <?= (int)$days ?> dias atrás</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif (!empty($asset['expected_return_overdue'])):
                $retDays = abs((int)$asset['expected_return_days']);
                $retDate = !empty($asset['expected_return_date']) ? date('d/m/Y', strtotime($asset['expected_return_date'])) : '—';
            ?>
            <div class="am-alert-overdue am-alert-trigger" tabindex="0" onclick="event.stopPropagation()">
                <i class="ti ti-calendar-x"></i> Prazo de retorno vencido (<?= $retDays ?>d atrás)
                <div class="am-alert-popup">
                    <strong style="display:block;margin-bottom:4px;"><i class="ti ti-calendar-x"></i> Prazo vencido</strong>
                    Prazo de retorno previsto em <strong><?= htmlspecialchars($retDate) ?></strong> vencido há <strong><?= $retDays ?> dia(s)</strong>.<br>
                    <span style="font-size:.75rem;opacity:.85;">Ativo em Manutenção com devolução atrasada. Verifique com o responsável.</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="am-asset-card-header">
                <div class="am-asset-type-icon"><i class="ti <?= $asset['asset_icon'] ?>"></i></div>
                <span class="am-asset-type-label"><?= htmlspecialchars($asset['asset_type_label']) ?></span>
                <span class="am-badge <?= $badge_class ?>"><?= htmlspecialchars($status_label) ?></span>
            </div>
            <div class="am-asset-card-body">
                <h4 class="am-asset-name"><?= htmlspecialchars($asset['name']) ?></h4>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php if ($asset['serial']): ?><div class="am-asset-info"><i class="ti ti-barcode"></i> <?= htmlspecialchars($asset['serial']) ?></div><?php endif; ?>
                    <?php if ($asset['otherserial']): ?><div class="am-asset-info"><i class="ti ti-hash"></i> Nº <?= htmlspecialchars($asset['otherserial']) ?></div><?php endif; ?>
                </div>
                <?php if (!empty($asset['entity_name'])): ?><div class="am-asset-info am-asset-entity"><i class="ti ti-building"></i> <?= htmlspecialchars($asset['entity_name']) ?></div><?php endif; ?>
                <?php if (!empty($asset['manufacturer_name'])): ?><div class="am-asset-info" style="margin-top:4px;"><i class="ti ti-building-factory-2"></i> <?= htmlspecialchars($asset['manufacturer_name']) ?></div><?php endif; ?>
                <?php
                $show_comps_status = [MaintenanceRecord::STATUS_MANUTENCAO, MaintenanceRecord::STATUS_GARANTIA, MaintenanceRecord::STATUS_INATIVO, MaintenanceRecord::STATUS_INSERVIVEL];
                $last_comps = !empty($asset['last_components']) ? json_decode($asset['last_components'], true) : [];
                if (in_array($plugin_status, $show_comps_status) && !empty($last_comps)):
                ?>
                <div class="am-card-components">
                    <?php foreach ($last_comps as $ck => $cd): ?>
                    <span class="am-comp-chip-small" title="<?= htmlspecialchars($cd ?: '') ?>"><?= htmlspecialchars($comp_list[$ck] ?? $ck) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="am-asset-card-footer" style="display:flex;gap:6px;flex-wrap:wrap;<?= $is_transferred ? 'opacity:.4;pointer-events:none;' : '' ?>">
                <a href="<?= $CFG_GLPI['root_doc'] ?>/front/asset/asset.form.php?class=<?= htmlspecialchars($asset['asset_type_key']) ?>&id=<?= (int)$asset['id'] ?>" class="am-btn am-btn-secondary" style="padding:7px 10px;width:auto;" onclick="event.stopPropagation()" title="Ver ativo"><i class="ti ti-eye"></i></a>
                <button class="am-btn am-btn-note" style="padding:7px 10px;width:auto;" onclick="event.stopPropagation();amOpenNote(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')" title="Adicionar Observação"><i class="ti ti-note"></i></button>
                <?php if ($can_tecnico): ?>
                <button class="am-btn am-btn-green" style="flex:1;padding:7px 8px;font-size:.78rem;" onclick="event.stopPropagation();amOpenManutencao(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')"><i class="ti ti-tools"></i> Manutenção</button>
                <button class="am-btn am-btn-orange" style="flex:1;padding:7px 8px;font-size:.78rem;" onclick="event.stopPropagation();amOpenBaixa(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')"><i class="ti ti-package-off"></i> Baixa</button>
                <?php endif; ?>
                <button class="am-btn am-btn-primary" style="flex:1;padding:7px 8px;font-size:.78rem;" onclick="event.stopPropagation();amOpenModal(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>','<?= htmlspecialchars($asset['asset_type_label']) ?>','<?= $plugin_status ?>')"><i class="ti ti-edit"></i> Status</button>
                <?php if (!empty($asset['can_undo'])): ?>
                <button class="am-btn am-btn-undo" style="padding:7px 10px;width:auto;" onclick="event.stopPropagation();amConfirmUndo(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>')" title="Reverter Status (até 48h)"><i class="ti ti-arrow-back-up"></i></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- LISTA -->
    <?php else: ?>
    <div class="am-asset-list">
        <?php if (empty($assets)): ?>
        <div class="am-empty-state am-empty-small"><i class="ti ti-device-desktop-off"></i><p>Nenhum ativo encontrado.</p></div>
        <?php else: ?>
        <table class="am-list-table">
            <thead><tr><th style="width:36px;"><input type="checkbox" id="am-select-all" onchange="amToggleSelectAll(this)"></th><th>Tipo</th><th>Nome</th><th>Serial</th><th>Nº Ativo</th><th>Entidade</th><th>Status</th><th>Alerta</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assets as $asset):
                $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
                $alert60 = $asset['alert_60days'] ?? false;
            ?>
            <?php $is_transferred_row = !empty($asset['transfer_status']) && $asset['transfer_status'] === 'transferido'; ?>
            <tr class="am-list-row <?= $is_transferred_row ? 'am-row-locked-transfer' : '' ?>" data-asset-id="<?= (int)$asset['id'] ?>" <?= $is_transferred_row ? '' : 'onclick="amHandleAssetClick(this, event)"' ?>>
                <td onclick="event.stopPropagation()"><input type="checkbox" class="am-bulk-checkbox" value="<?= (int)$asset['id'] ?>" data-itemtype="<?= htmlspecialchars($asset['itemtype']) ?>" data-name="<?= htmlspecialchars($asset['name']) ?>" data-otherserial="<?= htmlspecialchars($asset['otherserial'] ?? '') ?>" data-serial="<?= htmlspecialchars($asset['serial'] ?? '') ?>" onchange="amUpdateBulkBar()" <?= $is_transferred_row ? 'disabled title="Em transferência — não pode ser selecionado"' : '' ?>></td>
                <td><div style="display:flex;align-items:center;gap:8px;"><span class="am-list-icon"><i class="ti <?= $asset['asset_icon'] ?>"></i></span><span style="font-size:.8rem;color:#9ca3af;font-weight:600;text-transform:uppercase;"><?= htmlspecialchars($asset['asset_type_label']) ?></span></div></td>
                <td><strong><?= htmlspecialchars($asset['name']) ?></strong><?php if (!empty($asset['manufacturer_name'])): ?><div style="font-size:.72rem;color:#6b7280;display:flex;align-items:center;gap:3px;margin-top:2px;"><i class="ti ti-building-factory-2" style="font-size:.75rem;"></i> <?= htmlspecialchars($asset['manufacturer_name']) ?></div><?php endif; ?></td>
                <td style="color:#9ca3af;font-size:.85rem;"><?= htmlspecialchars($asset['serial'] ?? '—') ?></td>
                <td style="color:#9ca3af;font-size:.85rem;"><?= htmlspecialchars($asset['otherserial'] ?? '—') ?></td>
                <td style="font-size:.82rem;color:#6366f1;"><?= htmlspecialchars($asset['entity_name'] ?? '—') ?></td>
                <td><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($plugin_status) ?>"><?= MaintenanceRecord::getStatusLabel($plugin_status) ?></span></td>
                <td>
                    <?php if ($alert60):
                        $days = $asset['days_since_maintenance'];
                        $msg60 = $days === null
                            ? 'Nenhuma manutenção realizada registrada para este ativo.'
                            : 'Última manutenção há ' . (int)$days . ' dias — ' . max(0, (int)$days - 60) . ' dia(s) acima do limite de 60 dias.';
                    ?>
                    <span class="am-alert-trigger" style="color:#dc2626;font-size:.8rem;font-weight:700;display:inline-flex;align-items:center;gap:4px;position:relative;" tabindex="0" onclick="event.stopPropagation()">
                        <i class="ti ti-alert-triangle"></i> +60d
                        <span class="am-alert-popup"><?= htmlspecialchars($msg60) ?></span>
                    </span>
                    <?php elseif (!empty($asset['expected_return_overdue'])):
                        $retDays = abs((int)$asset['expected_return_days']);
                        $retDate = !empty($asset['expected_return_date']) ? date('d/m/Y', strtotime($asset['expected_return_date'])) : '—';
                    ?>
                    <span class="am-alert-trigger" style="color:#d97706;font-size:.8rem;font-weight:700;display:inline-flex;align-items:center;gap:4px;position:relative;" tabindex="0" onclick="event.stopPropagation()">
                        <i class="ti ti-calendar-x"></i> Atraso <?= $retDays ?>d
                        <span class="am-alert-popup">Prazo previsto <strong><?= htmlspecialchars($retDate) ?></strong> vencido há <strong><?= $retDays ?> dia(s)</strong>. Ativo em manutenção atrasado.</span>
                    </span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:5px;flex-wrap:wrap;">
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/front/asset/asset.form.php?class=<?= htmlspecialchars($asset['asset_type_key']) ?>&id=<?= (int)$asset['id'] ?>" class="am-btn am-btn-secondary" style="padding:6px 10px;width:auto;" title="Ver"><i class="ti ti-eye"></i></a>
                    <?php if ($is_transferred_row): ?>
                    <span style="font-size:.72rem;color:#f97316;font-weight:700;display:flex;align-items:center;gap:4px;padding:0 4px;"><i class="ti ti-lock"></i> Em transferência</span>
                    <?php else: ?>
                    <button class="am-btn am-btn-note" style="padding:6px 10px;width:auto;" onclick="amOpenNote(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')" title="Adicionar Observação"><i class="ti ti-note"></i></button>
                    <?php if ($can_tecnico): ?>
                    <button class="am-btn am-btn-green" style="padding:6px 10px;width:auto;" onclick="amOpenManutencao(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')" title="Manutenção Realizada"><i class="ti ti-tools"></i></button>
                    <button class="am-btn am-btn-orange" style="padding:6px 10px;width:auto;" onclick="amOpenBaixa(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>')" title="Baixa"><i class="ti ti-package-off"></i></button>
                    <?php endif; ?>
                    <button class="am-btn am-btn-primary" style="padding:6px 10px;width:auto;" onclick="amOpenModal(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>','<?= htmlspecialchars(addslashes($asset['name'])) ?>','<?= htmlspecialchars($asset['asset_type_label']) ?>','<?= $plugin_status ?>')" title="Alterar Status"><i class="ti ti-edit"></i></button>
                    <?php if (!empty($asset['can_undo'])): ?>
                    <button class="am-btn am-btn-undo" style="padding:6px 10px;width:auto;" onclick="amConfirmUndo(<?= (int)$asset['id'] ?>,'<?= htmlspecialchars(addslashes($asset['itemtype'])) ?>')" title="Reverter Status (até 48h)"><i class="ti ti-arrow-back-up"></i></button>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($paged['pages'] > 1): ?>
    <div class="am-pagination">
        <div class="am-pagination-info"><?= $paged['total'] ?> ativo(s) — página <?= $paged['page'] ?> de <?= $paged['pages'] ?></div>
        <div class="am-pagination-pages">
            <a class="am-page-link <?= $paged['page'] <= 1 ? 'disabled' : '' ?>" href="?<?= am_qs(['page' => $paged['page'] - 1]) ?>">Anterior</a>
            <?php
            $pg_total = $paged['pages'];
            $pg_cur   = $paged['page'];
            $pg_window = $pg_total <= 10 ? range(1, $pg_total) : array_values(array_unique(array_merge(
                [1],
                range(max(2, $pg_cur - 2), min($pg_total - 1, $pg_cur + 2)),
                [$pg_total]
            )));
            $pg_last = 0;
            foreach ($pg_window as $pg_n):
                if ($pg_n - $pg_last > 1): ?><span class="am-page-link disabled" style="background:transparent;box-shadow:none;">…</span><?php endif;
                $pg_last = $pg_n;
            ?>
            <a class="am-page-link <?= $pg_n === $pg_cur ? 'active' : '' ?>" href="?<?= am_qs(['page' => $pg_n]) ?>"><?= $pg_n ?></a>
            <?php endforeach; ?>
            <a class="am-page-link <?= $paged['page'] >= $paged['pages'] ? 'disabled' : '' ?>" href="?<?= am_qs(['page' => $paged['page'] + 1]) ?>">Próxima</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Status -->
<div id="am-modal-overlay" class="am-modal-overlay" onclick="amCloseModal(event)">
    <div class="am-modal" onclick="event.stopPropagation()">
        <div class="am-modal-header"><div class="am-modal-title"><i class="ti ti-edit"></i><span id="am-modal-asset-name">Alterar Status</span></div><button class="am-modal-close" onclick="amCloseModal()"><i class="ti ti-x"></i></button></div>
        <form id="am-maintenance-form" method="POST" action="<?= $form_action ?>" enctype="multipart/form-data">
            <input type="hidden" name="itemtype" id="am-f-itemtype">
            <input type="hidden" name="items_id" id="am-f-items-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">

                <!-- Foto + timeline + quem viu -->
                <div class="am-modal-meta-bar">
                    <img id="am-modal-asset-photo" src="" alt="" style="display:none;width:64px;height:64px;object-fit:cover;border-radius:10px;border:1.5px solid #e8eaf0;flex-shrink:0;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">Histórico de status</div>
                        <div id="am-modal-timeline" style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;min-height:18px;">
                            <span style="color:#9ca3af;font-size:.75rem;">Carregando...</span>
                        </div>
                    </div>
                    <div style="min-width:140px;">
                        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;"><i class="ti ti-eye" style="font-size:.8rem;"></i> Visualizações recentes</div>
                        <div id="am-modal-viewers" style="display:flex;flex-direction:column;gap:3px;min-height:18px;">
                            <span style="color:#9ca3af;font-size:.75rem;">Carregando...</span>
                        </div>
                    </div>
                </div>

                <div class="am-form-section">
                    <label class="am-form-label">Status do Ativo <span class="am-required">*</span></label>
                    <div class="am-status-grid">
                        <?php foreach ($status_opts as $key => $label): ?>
                        <label class="am-status-option" for="st_<?= $key ?>">
                            <input type="radio" name="status" id="st_<?= $key ?>" value="<?= $key ?>" required>
                            <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="am-form-section"><label class="am-form-label" for="am-reason">Motivo <span class="am-required">*</span></label><textarea id="am-reason" name="reason" class="am-textarea" required placeholder="Ex: Bateria carregando apenas até 70%"></textarea></div>

                <div class="am-form-section" id="am-expected-return-section" style="display:none;">
                    <label class="am-form-label" for="am-expected-return"><i class="ti ti-calendar-event"></i> Prazo de Retorno Previsto <small style="font-weight:400;text-transform:none;">(opcional)</small></label>
                    <input type="date" id="am-expected-return" name="expected_return_date" class="am-input" style="max-width:220px;">
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Componentes Afetados</label>
                    <div class="am-components-list">
                        <?php foreach ($comp_list as $comp_key => $comp_label): ?>
                        <div class="am-component-item" id="comp-item-<?= $comp_key ?>">
                            <label class="am-comp-checkbox-label"><input type="checkbox" name="comp_check[]" value="<?= $comp_key ?>" onchange="amToggleCompField(this,'<?= $comp_key ?>')"><span><?= htmlspecialchars($comp_label) ?></span></label>
                            <div class="am-comp-field" id="comp-field-<?= $comp_key ?>" style="display:none"><input type="text" name="comp_desc[<?= $comp_key ?>]" class="am-input" placeholder="<?= htmlspecialchars($comp_label) ?>: descreva..."></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Fotos <small style="font-weight:400;text-transform:none;">(máx. 3)</small></label>
                    <div class="am-upload-area" id="am-upload-area"><i class="ti ti-photo-up"></i><p>Arraste ou <span class="am-upload-link" onclick="document.getElementById('am-photos').click()">clique</span></p><small>JPG, PNG, JPEG</small><input type="file" id="am-photos" name="photos[]" accept=".jpg,.jpeg,.png" multiple onchange="amHandlePhotos(this)" style="display:none"></div>
                    <div id="am-photo-previews" class="am-photo-previews"></div>
                </div>
                <input type="hidden" name="users_id_tech" value="<?= Session::getLoginUserID() ?>">

                <!-- Histórico recente do ativo -->
                <div class="am-form-section">
                    <label class="am-form-label"><i class="ti ti-history"></i> Últimos Registros</label>
                    <div id="am-modal-history" style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:12px;min-height:60px;">
                        <div style="text-align:center;color:#9ca3af;font-size:.82rem;">Selecione um ativo para ver o histórico.</div>
                    </div>
                </div>
            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-danger" onclick="amConfirmDelete()"><i class="ti ti-trash"></i> Apagar</button>
                <div style="flex:1"></div>
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" class="am-btn am-btn-primary"><i class="ti ti-device-floppy"></i> Salvar</button>
            </div>
        </form>
        <form id="am-delete-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/delete.form.php" style="display:none">
            <input type="hidden" name="itemtype" id="am-del-itemtype">
            <input type="hidden" name="items_id" id="am-del-items-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
        </form>
    </div>
</div>

<form id="am-undo-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/undo.form.php" style="display:none">
    <input type="hidden" name="itemtype" id="am-undo-itemtype">
    <input type="hidden" name="items_id" id="am-undo-items-id">
    <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
</form>

<!-- Modal de confirmação de reversão com diff visual -->
<div id="am-modal-undo-confirm" class="am-modal-overlay" onclick="amCloseUndoConfirm(event)">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:540px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#4b5563,#374151);">
            <div class="am-modal-title"><i class="ti ti-arrow-back-up"></i><span>Reverter Status</span></div>
            <button class="am-modal-close" onclick="amCloseUndoConfirm()"><i class="ti ti-x"></i></button>
        </div>
        <div class="am-modal-body" id="am-undo-confirm-body" style="padding:20px 24px;">
            <div style="text-align:center;color:#9ca3af;">Carregando...</div>
        </div>
        <div style="padding:0 24px 16px;">
            <label class="am-agree-check" id="am-undo-agree-label">
                <input type="checkbox" id="am-undo-agree" onchange="amToggleUndoBtn()">
                <span>Confirmo e concordo com a <strong>REVERSÃO</strong></span>
            </label>
        </div>
        <div class="am-modal-footer">
            <button type="button" class="am-btn am-btn-secondary" onclick="amCloseUndoConfirm()"><i class="ti ti-x"></i> Cancelar</button>
            <button type="button" id="am-undo-confirm-btn" class="am-btn" style="background:linear-gradient(135deg,#4b5563,#374151);color:#fff;opacity:.4;cursor:not-allowed;" disabled onclick="document.getElementById('am-undo-form').submit()"><i class="ti ti-arrow-back-up"></i> Confirmar Reversão</button>
        </div>
    </div>
</div>

<!-- Modal Manutenção Realizada -->
<div id="am-modal-manutencao" class="am-modal-overlay" onclick="amCloseManutencao(event)">
    <div class="am-modal" onclick="event.stopPropagation()">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#059669,#10b981);">
            <div class="am-modal-title"><i class="ti ti-tools"></i><span id="am-manut-title">Registrar Manutenção Realizada</span></div>
            <button class="am-modal-close" onclick="amCloseManutencao()"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" action="<?= $action_url ?>" enctype="multipart/form-data">
            <input type="hidden" name="action"   value="manutencao">
            <input type="hidden" name="itemtype" id="am-manut-itemtype">
            <input type="hidden" name="items_id" id="am-manut-items-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">
                <div class="am-form-section">
                    <label class="am-form-label">O que foi feito? <span class="am-required">*</span></label>
                    <textarea name="action_description" class="am-textarea" required placeholder="Ex: Substituição da bateria, formatação do sistema, troca de HD..."></textarea>
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Fotos <small style="font-weight:400;text-transform:none;">(máx. 3)</small></label>
                    <div class="am-upload-area" onclick="document.getElementById('am-manut-photos').click()"><i class="ti ti-photo-up"></i><p>Clique para selecionar</p><small>JPG, PNG, JPEG</small></div>
                    <input type="file" id="am-manut-photos" name="photos[]" accept=".jpg,.jpeg,.png" multiple style="display:none">
                </div>
            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseManutencao()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" class="am-btn" style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;"><i class="ti ti-device-floppy"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Baixa -->
<div id="am-modal-baixa" class="am-modal-overlay" onclick="amCloseBaixa(event)">
    <div class="am-modal" onclick="event.stopPropagation()">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
            <div class="am-modal-title"><i class="ti ti-package-off"></i><span id="am-baixa-title">Registrar Baixa</span></div>
            <button class="am-modal-close" onclick="amCloseBaixa()"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" action="<?= $action_url ?>" enctype="multipart/form-data">
            <input type="hidden" name="action"   value="baixa">
            <input type="hidden" name="itemtype" id="am-baixa-itemtype">
            <input type="hidden" name="items_id" id="am-baixa-items-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">
                <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;color:#dc2626;">
                    <i class="ti ti-alert-triangle"></i> <strong>Atenção:</strong> Esta ação registrará a baixa do ativo e alterará o status para <strong>Inservível</strong>.
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Motivo da Baixa <span class="am-required">*</span></label>
                    <textarea name="baixa_motivo" class="am-textarea" required placeholder="Ex: Equipamento com dano irreparável, obsoleto, perda total..."></textarea>
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Data da Baixa</label>
                    <input type="date" name="baixa_data" class="am-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Fotos <small style="font-weight:400;text-transform:none;">(máx. 3)</small></label>
                    <div class="am-upload-area" onclick="document.getElementById('am-baixa-photos').click()"><i class="ti ti-photo-up"></i><p>Clique para selecionar</p><small>JPG, PNG, JPEG</small></div>
                    <input type="file" id="am-baixa-photos" name="photos[]" accept=".jpg,.jpeg,.png" multiple style="display:none">
                </div>
            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseBaixa()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" class="am-btn" style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;"><i class="ti ti-package-off"></i> Confirmar Baixa</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Observação Avulsa -->
<div id="am-modal-note" class="am-modal-overlay" onclick="amCloseNote(event)">
    <div class="am-modal" onclick="event.stopPropagation()">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="am-modal-title"><i class="ti ti-note"></i><span id="am-note-title">Adicionar Observação</span></div>
            <button class="am-modal-close" onclick="amCloseNote()"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" action="<?= $action_url ?>">
            <input type="hidden" name="action"   value="note">
            <input type="hidden" name="itemtype" id="am-note-itemtype">
            <input type="hidden" name="items_id" id="am-note-items-id">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">
                <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#92400e;">
                    <i class="ti ti-info-circle"></i> A observação é registrada no histórico, mas <strong>não altera o status</strong> do ativo.
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Observação <span class="am-required">*</span></label>
                    <textarea name="note_text" class="am-textarea" required placeholder="Ex: Equipamento entregue para o setor X temporariamente, aguardando peça de reposição..."></textarea>
                </div>
            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseNote()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" class="am-btn" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;"><i class="ti ti-device-floppy"></i> Salvar Observação</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bulk Status -->
<div id="am-modal-bulk" class="am-modal-overlay" onclick="amCloseBulkModal(event)">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:680px;">
        <div class="am-modal-header">
            <div class="am-modal-title"><i class="ti ti-checks"></i><span>Alterar Status em Massa</span></div>
            <button class="am-modal-close" onclick="amCloseBulkModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-bulk-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/bulk.form.php" novalidate>
            <input type="hidden" name="selected_assets" id="am-bulk-selected-assets">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode) ?>">
            <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
            <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>">
            <input type="hidden" name="filter_search" value="<?= htmlspecialchars($filter_search) ?>">
            <?php foreach ($filter_comp as $ck => $cv): ?>
            <input type="hidden" name="filter_comp[<?= htmlspecialchars($ck) ?>]" value="<?= htmlspecialchars($cv) ?>">
            <?php endforeach; ?>
            <?php foreach ($filter_fabricante as $ffid): ?>
            <input type="hidden" name="filter_fabricante[]" value="<?= (int)$ffid ?>">
            <?php endforeach; ?>
            <div class="am-modal-body">

                <div id="am-bulk-asset-list" style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px 14px;margin-bottom:18px;font-size:.85rem;color:#4b5563;max-height:100px;overflow-y:auto;"></div>

                <div class="am-form-section">
                    <label class="am-form-label">Novo Status <span class="am-required">*</span></label>
                    <div class="am-status-grid">
                        <?php foreach ($status_opts as $key => $label): ?>
                        <label class="am-status-option" for="bulk_st_<?= $key ?>">
                            <input type="radio" name="status" id="bulk_st_<?= $key ?>" value="<?= $key ?>" required>
                            <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="am-form-section">
                    <label class="am-form-label" for="am-bulk-reason">Motivo <span class="am-required">*</span></label>
                    <textarea id="am-bulk-reason" name="reason" class="am-textarea" required placeholder="Motivo aplicado a todos os ativos selecionados"></textarea>
                </div>

                <div class="am-form-section">
                    <label class="am-form-label">Componentes Afetados <small style="font-weight:400;text-transform:none;">(opcional — aplicado a todos)</small></label>
                    <div class="am-components-list">
                        <?php foreach ($comp_list as $comp_key => $comp_label): ?>
                        <div class="am-component-item" id="bulk-comp-item-<?= $comp_key ?>">
                            <label class="am-comp-checkbox-label">
                                <input type="checkbox" name="bulk_comp_check[]" value="<?= $comp_key ?>" onchange="amToggleBulkCompField(this,'<?= $comp_key ?>')">
                                <span><?= htmlspecialchars($comp_label) ?></span>
                            </label>
                            <div class="am-comp-field" id="bulk-comp-field-<?= $comp_key ?>" style="display:none">
                                <input type="text" name="bulk_comp_desc[<?= $comp_key ?>]" class="am-input" placeholder="<?= htmlspecialchars($comp_label) ?>: descreva...">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseBulkModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="button" class="am-btn am-btn-primary" onclick="amConfirmBulk()"><i class="ti ti-checks"></i> Revisar e Confirmar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de confirmação final (sim/não) -->
<div id="am-modal-bulk-confirm" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:440px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#1e1b4b,#4f46e5);">
            <div class="am-modal-title"><i class="ti ti-alert-triangle"></i><span>Confirmar Ação em Massa</span></div>
        </div>
        <div class="am-modal-body" style="padding:24px 28px;">
            <div id="am-bulk-confirm-body"></div>
        </div>
        <div style="padding:0 28px 16px;">
            <label class="am-agree-check" id="am-bulk-agree-label">
                <input type="checkbox" id="am-bulk-agree" onchange="amToggleBulkConfirmBtn()">
                <span>Confirmo e concordo com a <strong>AÇÃO EM MASSA</strong></span>
            </label>
        </div>
        <div class="am-modal-footer" style="justify-content:center;gap:16px;">
            <button type="button" class="am-btn am-btn-secondary" style="min-width:130px;" onclick="amCloseBulkConfirm()">
                <i class="ti ti-x"></i> Não, cancelar
            </button>
            <button type="button" id="am-bulk-confirm-btn" class="am-btn am-btn-primary" style="min-width:130px;opacity:.4;cursor:not-allowed;" disabled onclick="document.getElementById('am-bulk-form').submit()">
                <i class="ti ti-check"></i> Sim, confirmar
            </button>
        </div>
    </div>
</div>

<!-- Modal de Transferência (reuso na aba Inventário) -->
<?php if ($can_transfer): ?>
<div id="am-modal-transfer" class="am-modal-overlay" onclick="amCloseTransferModal(event)">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:580px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#0f172a,#1e40af);">
            <div class="am-modal-title"><i class="ti ti-transfer"></i><span>Nova Transferência</span></div>
            <button class="am-modal-close" onclick="amCloseTransferModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-transfer-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer.form.php" novalidate>
            <input type="hidden" name="selected_assets" id="am-tr-selected-assets">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">
                <div id="am-tr-asset-list" style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px 14px;margin-bottom:18px;font-size:.85rem;color:#4b5563;max-height:100px;overflow-y:auto;"></div>
                <div class="am-form-section">
                    <label class="am-form-label">Tipo de Destino <span class="am-required">*</span></label>
                    <div style="display:flex;gap:12px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#f0f7ff;border:2px solid #bfdbfe;border-radius:10px;padding:10px 18px;flex:1;transition:all .15s;" id="am-tr-type-ure-label">
                            <input type="radio" name="transfer_type" value="ure" id="am-tr-type-ure" onchange="amSwitchTransferType('ure')" checked style="accent-color:#1e40af;">
                            <span><strong>URE</strong><br><small style="color:#6b7280;">Entidade principal</small></span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#f8f9fb;border:2px solid #e8eaf0;border-radius:10px;padding:10px 18px;flex:1;transition:all .15s;" id="am-tr-type-escola-label">
                            <input type="radio" name="transfer_type" value="escola" id="am-tr-type-escola" onchange="amSwitchTransferType('escola')" style="accent-color:#1e40af;">
                            <span><strong>Escola</strong><br><small style="color:#6b7280;">Unidade de ensino</small></span>
                        </label>
                    </div>
                </div>
                <div class="am-form-section" id="am-tr-ure-section">
                    <label class="am-form-label">URE de Destino <span class="am-required">*</span></label>
                    <select name="entity_dest" id="am-tr-entity-ure" class="am-input" required>
                        <option value="0" selected>Unidade Regional de Ensino de Jales</option>
                    </select>
                </div>
                <div class="am-form-section" id="am-tr-escola-section" style="display:none;">
                    <label class="am-form-label">Escola de Destino <span class="am-required">*</span></label>
                    <select name="entity_dest_escola" id="am-tr-entity-escola" class="am-input">
                        <option value="">Selecione a escola...</option>
                        <?php foreach ($entities_escola as $ent): ?>
                        <option value="<?= (int)$ent['id'] ?>"><?= htmlspecialchars($ent['completename']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Motivo da Transferência <span class="am-required">*</span></label>
                    <textarea name="reason" class="am-textarea" required placeholder="Descreva o motivo da transferência..."></textarea>
                </div>
                <div class="am-form-section">
                    <label class="am-form-label">Categoria do Chamado <span class="am-required">*</span></label>
                    <select name="ticket_category" class="am-input" required>
                        <option value="">Selecione a categoria...</option>
                        <?php
                        try {
                            $tcats = $DB->request([
                                'FROM'  => 'glpi_itilcategories',
                                'ORDER' => ['completename ASC'],
                            ]);
                            $hasCat = false;
                            foreach ($tcats as $tcat) {
                                $hasCat = true;
                                echo '<option value="' . (int)$tcat['id'] . '">' . htmlspecialchars($tcat['completename']) . '</option>';
                            }
                            if (!$hasCat) {
                                echo '<option value="" disabled>Nenhuma categoria cadastrada — crie em Configuração > Categorias de chamado</option>';
                            }
                        } catch (\Throwable $e) {
                            echo '<option value="" disabled>Erro ao carregar categorias: ' . htmlspecialchars($e->getMessage()) . '</option>';
                        }
                        ?>
                    </select>
                    <small style="display:block;margin-top:6px;color:#6b7280;font-size:.75rem;">
                        <i class="ti ti-ticket"></i> Um chamado será aberto automaticamente no GLPI com todas as informações da transferência.
                    </small>
                </div>
            </div>
            <div class="am-modal-footer" style="position:sticky;bottom:0;z-index:2;">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseTransferModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="button" id="am-tr-submit" class="am-btn" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;" onclick="amConfirmTransfer()">
                    <i class="ti ti-checks"></i> Revisar e Confirmar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal de confirmação de Transferência (igual ao de Ação em Massa) -->
<div id="am-modal-transfer-confirm" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:520px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#1e40af,#3b82f6);">
            <div class="am-modal-title"><i class="ti ti-transfer"></i><span>Confirmar Transferência</span></div>
        </div>
        <div class="am-modal-body" style="padding:24px 28px;">
            <div id="am-tr-confirm-body"></div>
            <div style="margin-top:16px;">
                <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.06em;margin-bottom:8px;"><i class="ti ti-list"></i> Equipamentos — desmarque para remover</div>
                <div id="am-tr-confirm-list" style="max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px;background:#f8f9fb;"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:.78rem;">
                    <span style="color:#9ca3af;"><span id="am-tr-confirm-count">0</span> selecionado(s)</span>
                    <button type="button" style="background:transparent;border:none;color:#4f46e5;font-weight:700;font-size:.78rem;cursor:pointer;" onclick="amTransferConfirmToggleAll(true)">Marcar todos</button>
                    <span style="color:#e5e7eb;">|</span>
                    <button type="button" style="background:transparent;border:none;color:#6b7280;font-weight:700;font-size:.78rem;cursor:pointer;" onclick="amTransferConfirmToggleAll(false)">Desmarcar todos</button>
                </div>
            </div>
        </div>
        <div style="padding:0 28px 16px;">
            <label class="am-agree-check" id="am-tr-confirm-agree-label">
                <input type="checkbox" id="am-tr-confirm-agree" onchange="amToggleTransferConfirmBtn()">
                <span>Confirmo que as informações estão corretas e <strong>autorizo o envio</strong></span>
            </label>
        </div>
        <div class="am-modal-footer" style="justify-content:center;gap:16px;">
            <button type="button" class="am-btn am-btn-secondary" style="min-width:130px;" onclick="amCloseTransferConfirm()">
                <i class="ti ti-x"></i> Não, cancelar
            </button>
            <button type="button" id="am-tr-confirm-btn" class="am-btn" style="min-width:130px;background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;opacity:.4;cursor:not-allowed;" disabled onclick="amSubmitTransferConfirmed()">
                <i class="ti ti-check"></i> Sim, confirmar
            </button>
        </div>
    </div>
</div>

<!-- Modal Excluir em Massa (GLPI + Plugin) -->
<?php if ($can_delete): ?>
<div id="am-modal-bulk-delete" class="am-modal-overlay" onclick="amCloseBulkDeleteModal(event)">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:520px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
            <div class="am-modal-title"><i class="ti ti-trash"></i><span>Excluir Ativos</span></div>
            <button class="am-modal-close" onclick="amCloseBulkDeleteModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-bulk-delete-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/bulk_delete.form.php">
            <input type="hidden" name="selected_assets" id="am-bulk-delete-selected-assets">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode) ?>">
            <div class="am-modal-body" style="padding:24px;">
                <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;">
                    <i class="ti ti-alert-triangle" style="color:#dc2626;font-size:1.4rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;color:#991b1b;font-size:.95rem;">Ação irreversível!</div>
                        <div style="font-size:.82rem;color:#7f1d1d;margin-top:4px;line-height:1.4;">Os ativos selecionados serão <strong>excluídos do GLPI</strong> (is_deleted) e <strong>removidos do plugin</strong>. Esta ação não pode ser desfeita.</div>
                    </div>
                </div>
                <div id="am-bulk-delete-asset-list" style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;color:#4b5563;max-height:120px;overflow-y:auto;"></div>
                <label class="am-agree-check" style="background:#fef2f2;border-color:#fecaca;">
                    <input type="checkbox" id="am-bulk-delete-agree" onchange="amToggleBulkDeleteBtn()">
                    <span>Confirmo que quero <strong>EXCLUIR</strong> os ativos selecionados do <strong>GLPI e do Plugin</strong></span>
                </label>
            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseBulkDeleteModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-bulk-delete-btn" class="am-btn am-btn-danger" style="opacity:.4;cursor:not-allowed;" disabled><i class="ti ti-trash"></i> Confirmar Exclusão</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Injeta filtros em todos os forms de ação dos modais
document.addEventListener('DOMContentLoaded', function() {
    var filters = {
        'view_mode':     '<?= $view_mode ?>',
        'filter_type':   '<?= htmlspecialchars($filter_type) ?>',
        'filter_status': '<?= htmlspecialchars($filter_status) ?>',
        'filter_search': '<?= htmlspecialchars($filter_search) ?>',
    };
    // Seleciona todos os forms que fazem POST para o plugin (exceto busca)
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(form) {
        var action = form.getAttribute('action') || '';
        if (action.indexOf('assetmgrstatus') === -1) return;
        if (form.id === 'am-search-form') return;
        Object.entries(filters).forEach(function([name, value]) {
            if (!form.querySelector('[name="' + name + '"]')) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = name;
                input.value = value;
                form.appendChild(input);
            }
        });
    });
});

// Clique na linha/card seleciona/desseleciona o checkbox
function amHandleAssetClick(el, e) {
    // Ignora cliques em elementos interativos e no próprio alerta
    if (e.target.closest('a, button, input, .am-btn, .am-card-checkbox, .am-alert-trigger, .am-alert-popup')) return;
    if (el.classList.contains('am-card-locked-transfer') || el.classList.contains('am-row-locked-transfer')) return;
    var cb = el.querySelector('.am-bulk-checkbox');
    if (!cb || cb.disabled) return;
    cb.checked = !cb.checked;
    cb.dispatchEvent(new Event('change', {bubbles: true}));
    amUpdateBulkBar();
}

function amUpdateBulkBar() {
    // Garante que desabilitados (em transferência) não fiquem marcados
    document.querySelectorAll('.am-bulk-checkbox:disabled:checked').forEach(function(cb){ cb.checked=false; });
    var checkboxes = document.querySelectorAll('.am-bulk-checkbox:not(:disabled)');
    var checked    = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
    var hasSelection = checked.length > 0;

    document.querySelectorAll('.am-bulk-checkbox').forEach(function(cb) {
        var card = cb.closest('.am-asset-card');
        var row  = cb.closest('.am-list-row');
        if (card) card.classList.toggle('am-card-selected', cb.checked && !cb.disabled);
        if (row)  row.classList.toggle('am-row-selected', cb.checked && !cb.disabled);
    });

    // Aplica has-selection para desfocar não selecionados (suporta ambas as classes)
    document.querySelectorAll('.am-asset-grid, .am-assets-grid').forEach(function(g){
        g.classList.toggle('has-selection', hasSelection);
    });
    var table = document.querySelector('.am-list-table');
    if (table) table.classList.toggle('has-selection', hasSelection);

    var bar   = document.getElementById('am-bulk-bar');
    var count = document.getElementById('am-bulk-count');
    if (bar) {
        if (hasSelection) { bar.classList.add('open'); bar.style.display='flex'; }
        else { bar.classList.remove('open'); bar.style.display='none'; }
        if (count) count.textContent = checked.length + ' ativo(s) selecionado(s)';
        var page=document.querySelector('.am-page'); if(page) page.style.paddingBottom = hasSelection ? '90px' : '';
    }
}
function amToggleSelectAll(master) {
    document.querySelectorAll('.am-bulk-checkbox:not(:disabled)').forEach(function(cb) { cb.checked = master.checked; });
    document.querySelectorAll('.am-bulk-checkbox:disabled').forEach(function(cb){ cb.checked=false; });
    amUpdateBulkBar();
}
function amClearSelection() {
    document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)').forEach(function(cb) { cb.checked = false; });
    var master = document.getElementById('am-select-all');
    if (master) master.checked = false;
    amUpdateBulkBar();
}
window.amOpenTransferModalFromBulk = function() {
    try {
        console.log('amOpenTransferModalFromBulk clicked');
        var checkboxes = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
        console.log('checked', checkboxes.length);
        if (checkboxes.length === 0) { alert('Selecione ao menos um ativo.'); return; }
        var items = [], names = [];
        checkboxes.forEach(function(cb){
            var oserial = cb.dataset.otherserial || cb.dataset.serial || '';
            items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: oserial});
            names.push(cb.dataset.name + (oserial ? ' ('+oserial+')' : ''));
        });
        var input = document.getElementById('am-tr-selected-assets');
        var list  = document.getElementById('am-tr-asset-list');
        if (input) input.value = JSON.stringify(items); else console.warn('am-tr-selected-assets not found');
        if (list) list.innerHTML = '<strong>' + items.length + ' ativo(s) selecionado(s):</strong><br>' + names.join(', '); else console.warn('am-tr-asset-list not found');
        var ureRadio = document.getElementById('am-tr-type-ure');
        if (ureRadio) { ureRadio.checked = true; if (typeof amSwitchTransferType === 'function') amSwitchTransferType('ure'); }
        var modal = document.getElementById('am-modal-transfer');
        if (modal) { modal.classList.add('open'); document.body.style.overflow = 'hidden'; console.log('modal opened'); } else { console.error('am-modal-transfer not found'); alert('Modal não encontrado. Verifique se tem permissão de transferência.'); }
    } catch(e){ console.error('amOpenTransferModalFromBulk error', e); alert('Erro ao abrir transferência: ' + e.message); }
};
function amCloseTransferModal(e) {
    if (e && e.target !== document.getElementById('am-modal-transfer')) return;
    var m = document.getElementById('am-modal-transfer');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
}
function amToggleTransferSubmit() {
    var cb = document.getElementById('am-tr-agree');
    var btn = document.getElementById('am-tr-submit');
    if (!cb || !btn) return;
    btn.disabled = !cb.checked;
    btn.style.opacity = cb.checked ? '1' : '.4';
    btn.style.cursor = cb.checked ? 'pointer' : 'not-allowed';
}
function amSwitchTransferType(type) {
    var ureSection = document.getElementById('am-tr-ure-section');
    var escolaSection = document.getElementById('am-tr-escola-section');
    var ureLabel = document.getElementById('am-tr-type-ure-label');
    var escolaLabel = document.getElementById('am-tr-type-escola-label');
    var ureSelect = document.getElementById('am-tr-entity-ure');
    var escolaSelect = document.getElementById('am-tr-entity-escola');
    if (!ureSection || !escolaSection) return;
    if (type === 'ure') {
        ureSection.style.display = 'block';
        escolaSection.style.display = 'none';
        ureSelect.name = 'entity_dest';
        ureSelect.disabled = false;
        ureSelect.required = true;
        escolaSelect.name = 'entity_dest_escola_disabled';
        escolaSelect.disabled = true;
        escolaSelect.required = false;
        ureLabel.style.borderColor = '#1e40af';
        ureLabel.style.background = '#eff6ff';
        escolaLabel.style.borderColor = '#e8eaf0';
        escolaLabel.style.background = '#f8f9fb';
    } else {
        ureSection.style.display = 'none';
        escolaSection.style.display = 'block';
        escolaSelect.name = 'entity_dest';
        escolaSelect.disabled = false;
        escolaSelect.required = true;
        ureSelect.name = 'entity_dest_ure_disabled';
        ureSelect.disabled = true;
        ureSelect.required = false;
        escolaLabel.style.borderColor = '#1e40af';
        escolaLabel.style.background = '#eff6ff';
        ureLabel.style.borderColor = '#e8eaf0';
        ureLabel.style.background = '#f8f9fb';
    }
}
window.amOpenBulkDeleteModal = function() {
    var cbs = document.querySelectorAll('.am-bulk-checkbox:checked');
    if (cbs.length === 0) return;
    var items=[], names=[];
    cbs.forEach(function(cb){ items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype}); names.push(cb.dataset.name); });
    var inp = document.getElementById('am-bulk-delete-selected-assets');
    var lst = document.getElementById('am-bulk-delete-asset-list');
    if (inp) inp.value = JSON.stringify(items);
    if (lst) lst.innerHTML = '<strong>' + items.length + ' ativo(s) selecionado(s) para exclusão:</strong><br>' + names.join(', ');
    var ag = document.getElementById('am-bulk-delete-agree');
    if (ag) ag.checked = false;
    if (typeof window.amToggleBulkDeleteBtn === 'function') window.amToggleBulkDeleteBtn();
    var mod = document.getElementById('am-modal-bulk-delete');
    if (mod) { mod.classList.add('open'); document.body.style.overflow = 'hidden'; }
};
window.amCloseBulkDeleteModal = function(e) {
    if (e && e.target !== document.getElementById('am-modal-bulk-delete')) return;
    var m = document.getElementById('am-modal-bulk-delete');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
};
window.amToggleBulkDeleteBtn = function() {
    var cb = document.getElementById('am-bulk-delete-agree');
    var btn = document.getElementById('am-bulk-delete-btn');
    if (!cb || !btn) return;
    btn.disabled = !cb.checked;
    btn.style.opacity = cb.checked ? '1' : '.4';
    btn.style.cursor = cb.checked ? 'pointer' : 'not-allowed';
};
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        ['am-modal-transfer','am-modal-transfer-confirm','am-modal-bulk','am-modal-bulk-confirm','am-modal-bulk-delete','am-modal-overlay','am-modal-manutencao','am-modal-baixa','am-modal-undo-confirm','am-modal-note'].forEach(function(id){
            var el=document.getElementById(id); if(el) el.classList.remove('open');
        });
        document.body.style.overflow='';
    }
});
document.addEventListener('DOMContentLoaded', function(){
    // Fallback: garante clique no Transferir mesmo se inline onclick falhar
    var trBtn = document.querySelector('#am-bulk-bar button[onclick*="Transfer"]');
    if (trBtn) {
        trBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (typeof window.amOpenTransferModalFromBulk === 'function') window.amOpenTransferModalFromBulk();
            else console.error('amOpenTransferModalFromBulk not found');
        });
    }
    console.log('amOpenTransferModalFromBulk', typeof window.amOpenTransferModalFromBulk, 'modal', !!document.getElementById('am-modal-transfer'));
});
// Fallback JS: garante que ao clicar em tabs de tipo/status o outro filtro não se perca (caso PHP falhe)
document.addEventListener('click', function(e){
  var tab = e.target.closest('.am-type-tab');
  if (!tab || !tab.href) return;
  try {
    var url = new URL(tab.href, window.location.origin);
    var cur = new URLSearchParams(window.location.search);
    // Se o link não contém o param mas a URL atual tem, preserva (evita perda de filtro)
    // Nota: has() retorna true mesmo para "?type=" vazio, então só preserva se realmente ausente
    if (!url.searchParams.has('type') && cur.has('type') && cur.get('type')) {
      url.searchParams.set('type', cur.get('type'));
    }
    if (!url.searchParams.has('status') && cur.has('status') && cur.get('status')) {
      url.searchParams.set('status', cur.get('status'));
    }
    if (tab.href !== url.toString()) tab.href = url.toString();
  } catch(err){}
});
</script>
<?php Html::footer(); ?>