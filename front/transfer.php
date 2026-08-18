<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_transfer', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI, $DB;

$can_transfer = Session::haveRight('plugin_assetmgrstatus_transfer', CREATE) || Session::haveRight('plugin_assetmgrstatus_transfer', UPDATE)
    || Session::haveRight('plugin_assetmgrstatus', CREATE) || Session::haveRight('plugin_assetmgrstatus', UPDATE);

$filter_type   = $_GET['type']   ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$view_mode     = $_GET['view']   ?? 'list';

function tr_qs(array $overrides = []): string {
    global $filter_type, $filter_search, $filter_status, $view_mode;
    $params = [
        'type'   => $overrides['type']   ?? $filter_type,
        'search' => $overrides['search'] ?? $filter_search,
        'status' => $overrides['status'] ?? $filter_status,
        'view'   => $overrides['view']   ?? $view_mode,
    ];
    return http_build_query($params);
}

$assets      = MaintenanceRecord::getAssets($filter_type, $filter_search, $filter_status);
$status_opts = MaintenanceRecord::getStatusOptions();
$types       = MaintenanceRecord::getAssetTypes();

// Entidades para o modal
$entities_ure    = Transfer::getEntidades('ure');
$entities_escola = Transfer::getEntidades('escola');

Html::header('Transferência', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'transfer');
?>

<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Manutenção</a>
        <i class="ti ti-chevron-right"></i>
        <span>Transferência</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-transfer"></i><h2>Transferência de Ativos</h2></div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-arrow-left"></i> Manutenção</a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_tecnico', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-tools"></i> Técnico</a>
            <?php endif; ?>
            <span style="font-size:.85rem;color:#9ca3af;"><?= count($assets) ?> ativo(s)</span>
        </div>
    </div>

    <!-- Filtros -->
    <div class="am-filters-bar">
        <div class="am-filter-group">
            <label>TIPO</label>
            <div class="am-type-tabs">
                <a href="?<?= tr_qs(['type'=>'']) ?>" class="am-type-tab <?= $filter_type==='' ? 'active' : '' ?>"><i class="ti ti-layout-grid"></i> Todos</a>
                <?php foreach ($types as $key => $def): ?>
                <a href="?<?= tr_qs(['type'=>$key]) ?>" class="am-type-tab <?= $filter_type===$key ? 'active' : '' ?>"><i class="ti <?= $def['icon'] ?>"></i> <?= htmlspecialchars($def['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="am-filter-group">
            <label>STATUS</label>
            <div class="am-type-tabs">
                <a href="?<?= tr_qs(['status'=>'']) ?>" class="am-type-tab <?= $filter_status==='' ? 'active' : '' ?>">Todos</a>
                <?php foreach ($status_opts as $key => $label): ?>
                <a href="?<?= tr_qs(['status'=>$key]) ?>" class="am-type-tab <?= $filter_status===$key ? 'active' : '' ?>">
                    <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex:1;align-items:center;">
            <form method="GET" action="" style="flex:1;display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="type"   value="<?= htmlspecialchars($filter_type) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <input type="hidden" name="view"   value="<?= $view_mode ?>">
                <div class="am-filter-search">
                    <input type="text" name="search" placeholder="Buscar por nome, serial..." value="<?= htmlspecialchars($filter_search) ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();this.closest('form').submit();}">
                    <button type="submit" class="am-search-btn"><i class="ti ti-search"></i></button>
                </div>
            </form>
            <div class="am-view-toggle">
                <a href="?<?= tr_qs(['view'=>'list']) ?>" class="am-view-btn <?= $view_mode==='list'?'active':'' ?>" title="Lista"><i class="ti ti-list"></i></a>
                <a href="?<?= tr_qs(['view'=>'grid']) ?>" class="am-view-btn <?= $view_mode==='grid'?'active':'' ?>" title="Grade"><i class="ti ti-layout-grid"></i></a>
            </div>
        </div>
    </div>

    <!-- Bulk bar -->
    <?php if ($can_transfer): ?>
    <div id="am-transfer-bulk-bar" class="am-bulk-bar">
        <span id="am-transfer-bulk-count" style="color:#fff;font-size:.85rem;font-weight:600;"></span>
        <button class="am-btn" style="background:#fff;color:#1e40af;padding:7px 18px;font-size:.85rem;" onclick="amOpenTransferModal()">
            <i class="ti ti-transfer"></i> Transferir Selecionados
        </button>
        <button class="am-btn am-btn-secondary" style="padding:7px 14px;font-size:.85rem;" onclick="amClearTransferSelection()">
            <i class="ti ti-x"></i> Limpar
        </button>
    </div>
    <?php endif; ?>

    <!-- Listagem -->
    <?php if (empty($assets)): ?>
    <div class="am-empty-state"><i class="ti ti-inbox"></i><p>Nenhum ativo encontrado.</p></div>
    <?php elseif ($view_mode === 'grid'): ?>
    <div class="am-assets-grid">
        <?php foreach ($assets as $asset):
            $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
            $locked = !empty($asset['transfer_status']);
        ?>
        <div class="am-asset-card <?= $locked ? 'am-card-transferred' : '' ?>">
            <?php if (!$locked): ?>
            <div class="am-card-checkbox" onclick="event.stopPropagation()">
                <input type="checkbox" class="am-tr-checkbox" value="<?= (int)$asset['id'] ?>"
                    data-itemtype="<?= htmlspecialchars($asset['itemtype']) ?>"
                    data-name="<?= htmlspecialchars($asset['name']) ?>"
                    onchange="amUpdateTransferBar()">
            </div>
            <?php else: ?>
            <div style="background:#f97316;color:#fff;font-size:.72rem;font-weight:700;padding:4px 14px;display:flex;align-items:center;gap:5px;">
                <i class="ti ti-transfer"></i> Em transferência — somente técnicos podem editar
            </div>
            <?php endif; ?>
            <div class="am-asset-card-header">
                <div class="am-asset-type-icon"><i class="ti <?= $asset['asset_icon'] ?>"></i></div>
                <div>
                    <span class="am-asset-type-label"><?= htmlspecialchars($asset['asset_type_label']) ?></span>
                    <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($plugin_status) ?>"><?= MaintenanceRecord::getStatusLabel($plugin_status) ?></span>
                </div>
            </div>
            <div class="am-asset-card-body">
                <div class="am-asset-name"><?= htmlspecialchars($asset['name']) ?></div>
                <div class="am-asset-info"><i class="ti ti-barcode"></i> <?= htmlspecialchars($asset['serial'] ?? '—') ?></div>
                <div class="am-asset-entity"><i class="ti ti-building"></i> <?= htmlspecialchars($asset['entity_name'] ?? '—') ?></div>
            </div>
            <div class="am-asset-card-footer">
                <a href="<?= $CFG_GLPI['root_doc'] ?>/front/asset/asset.form.php?class=<?= htmlspecialchars($asset['asset_type_key']) ?>&id=<?= (int)$asset['id'] ?>"
                   class="am-btn am-btn-secondary" style="flex:1;padding:7px 8px;font-size:.78rem;" onclick="event.stopPropagation()">
                    <i class="ti ti-eye"></i> Ver
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="am-list-table">
        <thead><tr>
            <th style="width:36px;"><input type="checkbox" id="am-tr-select-all" onchange="amToggleTransferAll(this)"></th>
            <th>Tipo</th><th>Nome</th><th>Serial</th><th>Nº Ativo</th><th>Entidade</th><th>Status</th><th>Situação</th><th>Ação</th>
        </tr></thead>
        <tbody>
        <?php foreach ($assets as $asset):
            $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
            $locked = !empty($asset['transfer_status']);
        ?>
        <tr class="am-list-row <?= $locked ? 'am-row-transferred' : '' ?>">
            <td>
                <?php if (!$locked): ?>
                <input type="checkbox" class="am-tr-checkbox" value="<?= (int)$asset['id'] ?>"
                    data-itemtype="<?= htmlspecialchars($asset['itemtype']) ?>"
                    data-name="<?= htmlspecialchars($asset['name']) ?>"
                    onchange="amUpdateTransferBar()">
                <?php else: ?>
                <i class="ti ti-lock" style="color:#f97316;" title="Em transferência"></i>
                <?php endif; ?>
            </td>
            <td><span style="display:flex;align-items:center;gap:5px;font-size:.82rem;"><i class="ti <?= $asset['asset_icon'] ?>"></i><?= htmlspecialchars($asset['asset_type_label']) ?></span></td>
            <td><strong><?= htmlspecialchars($asset['name']) ?></strong></td>
            <td style="color:#9ca3af;"><?= htmlspecialchars($asset['serial'] ?? '—') ?></td>
            <td style="color:#9ca3af;"><?= htmlspecialchars($asset['otherserial'] ?? '—') ?></td>
            <td style="color:#6366f1;font-size:.82rem;"><?= htmlspecialchars($asset['entity_name'] ?? '—') ?></td>
            <td><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($plugin_status) ?>"><?= MaintenanceRecord::getStatusLabel($plugin_status) ?></span></td>
            <td>
                <?php if ($locked): ?>
                <span style="color:#f97316;font-size:.78rem;font-weight:700;"><i class="ti ti-transfer"></i> Transferido</span>
                <?php else: ?>
                <span style="color:#10b981;font-size:.78rem;">Disponível</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?= $CFG_GLPI['root_doc'] ?>/front/asset/asset.form.php?class=<?= htmlspecialchars($asset['asset_type_key']) ?>&id=<?= (int)$asset['id'] ?>"
                   class="am-btn am-btn-secondary" style="padding:6px 10px;width:auto;" title="Ver"><i class="ti ti-eye"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal de Transferência -->
<?php if ($can_transfer): ?>
<div id="am-modal-transfer" class="am-modal-overlay" onclick="event.stopPropagation()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:580px;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#0f172a,#1e40af);">
            <div class="am-modal-title"><i class="ti ti-transfer"></i><span>Nova Transferência</span></div>
            <button class="am-modal-close" onclick="amCloseTransferModal()"><i class="ti ti-x"></i></button>
        </div>
        <form id="am-transfer-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer.form.php">
            <input type="hidden" name="selected_assets" id="am-tr-selected-assets">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <div class="am-modal-body">

                <div id="am-tr-asset-list" style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px 14px;margin-bottom:18px;font-size:.85rem;color:#4b5563;max-height:100px;overflow-y:auto;"></div>

                <!-- Tipo de destino: URE ou Escola -->
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

                <!-- Seletor URE -->
                <div class="am-form-section" id="am-tr-ure-section">
                    <label class="am-form-label">URE de Destino <span class="am-required">*</span></label>
                    <select name="entity_dest" id="am-tr-entity-ure" class="am-input" required>
                        <option value="0" selected>Unidade Regional de Ensino de Jales</option>
                    </select>
                </div>

                <!-- Seletor Escola -->
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
                        $tcats = $DB->request([
                            'FROM'  => 'glpi_itilcategories',
                            'ORDER' => ['completename ASC'],
                        ]);
                        foreach ($tcats as $tcat): ?>
                        <option value="<?= (int)$tcat['id'] ?>"><?= htmlspecialchars($tcat['completename']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block;margin-top:6px;color:#6b7280;font-size:.75rem;">
                        <i class="ti ti-ticket"></i> Um chamado será aberto automaticamente no GLPI com todas as informações da transferência.
                    </small>
                </div>

                <label class="am-agree-check">
                    <input type="checkbox" id="am-tr-agree" onchange="amToggleTransferSubmit()">
                    <span>Confirmo que as informações da transferência estão corretas e autorizo o envio dos ativos selecionados.</span>
                </label>

            </div>
            <div class="am-modal-footer">
                <button type="button" class="am-btn am-btn-secondary" onclick="amCloseTransferModal()"><i class="ti ti-x"></i> Cancelar</button>
                <button type="submit" id="am-tr-submit" class="am-btn" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                    <i class="ti ti-transfer"></i> Confirmar Transferência
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.am-card-transferred { border-color: #f97316 !important; }
.am-row-transferred { background: #fff7ed !important; }
.am-row-transferred:hover { background: #ffedd5 !important; }
@media (prefers-color-scheme: dark), .dark {
  .am-row-transferred { background: #2d1a0a !important; }
}
</style>

<script>
function amUpdateTransferBar() {
    var checked = document.querySelectorAll('.am-tr-checkbox:checked');
    var bar = document.getElementById('am-transfer-bulk-bar');
    var count = document.getElementById('am-transfer-bulk-count');
    if (!bar) return;
    if (checked.length > 0) {
        bar.classList.add('open');
        count.textContent = checked.length + ' ativo(s) selecionado(s)';
    } else {
        bar.classList.remove('open');
    }
}
function amClearTransferSelection() {
    document.querySelectorAll('.am-tr-checkbox:checked').forEach(function(cb){ cb.checked = false; });
    var all = document.getElementById('am-tr-select-all');
    if (all) all.checked = false;
    amUpdateTransferBar();
}
function amToggleTransferAll(master) {
    document.querySelectorAll('.am-tr-checkbox').forEach(function(cb){ cb.checked = master.checked; });
    amUpdateTransferBar();
}
function amOpenTransferModal() {
    var checkboxes = document.querySelectorAll('.am-tr-checkbox:checked');
    if (checkboxes.length === 0) return;
    var items = [], names = [];
    checkboxes.forEach(function(cb) {
        items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype, name: cb.dataset.name});
        names.push(cb.dataset.name);
    });
    document.getElementById('am-tr-selected-assets').value = JSON.stringify(items);
    document.getElementById('am-tr-asset-list').innerHTML = '<strong>' + items.length + ' ativo(s) selecionado(s):</strong><br>' + names.join(', ');
    document.getElementById('am-tr-agree').checked = false;
    document.getElementById('am-tr-submit').disabled = true;
    document.getElementById('am-tr-submit').style.opacity = '.4';
    document.getElementById('am-tr-submit').style.cursor = 'not-allowed';
    // Reset tipo
    document.getElementById('am-tr-type-ure').checked = true;
    amSwitchTransferType('ure');
    document.getElementById('am-modal-transfer').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function amCloseTransferModal() {
    document.getElementById('am-modal-transfer').classList.remove('open');
    document.body.style.overflow = '';
}
function amToggleTransferSubmit() {
    var checked = document.getElementById('am-tr-agree').checked;
    var btn = document.getElementById('am-tr-submit');
    btn.disabled = !checked;
    btn.style.opacity = checked ? '1' : '.4';
    btn.style.cursor = checked ? 'pointer' : 'not-allowed';
}
function amSwitchTransferType(type) {
    var ureSection    = document.getElementById('am-tr-ure-section');
    var escolaSection = document.getElementById('am-tr-escola-section');
    var ureLabel      = document.getElementById('am-tr-type-ure-label');
    var escolaLabel   = document.getElementById('am-tr-type-escola-label');
    var ureSelect     = document.getElementById('am-tr-entity-ure');
    var escolaSelect  = document.getElementById('am-tr-entity-escola');

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
        ureLabel.style.background  = '#eff6ff';
        escolaLabel.style.borderColor = '#e8eaf0';
        escolaLabel.style.background  = '#f8f9fb';
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
        escolaLabel.style.background  = '#eff6ff';
        ureLabel.style.borderColor = '#e8eaf0';
        ureLabel.style.background  = '#f8f9fb';
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') amCloseTransferModal();
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
