<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Stats;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, READ);

global $CFG_GLPI, $DB;

$filter_type    = $_GET['type']   ?? '';
$filter_status  = $_GET['status'] ?? '';
$report_mode    = $_GET['mode']   ?? 'assets';
$period_start   = $_GET['period_start'] ?? '';
$period_end     = $_GET['period_end']   ?? '';

$can_admin   = Session::haveRight('plugin_assetmgrstatus_admin', READ);

// Restringe modos ADMIN-only: somente Lista de Ativos e Relatório Mensal para todos
$admin_only_modes = ['history', 'technician', 'entity', 'components', 'avg_time'];
if (!$can_admin && in_array($report_mode, $admin_only_modes, true)) {
    // Bloqueia acesso direto por URL — redireciona para modo público
    $report_mode = 'assets';
}

Html::header('Relatórios — Inventário', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'reports');

$types       = MaintenanceRecord::getAssetTypes();
$status_opts = MaintenanceRecord::getStatusOptions();
$entity_id   = Session::getActiveEntity();
$comp_list   = MaintenanceRecord::getComponents();

// Dados base - filtrado pela entidade ativa (Session::getActiveEntity)
$preview_assets = MaintenanceRecord::getAssets($filter_type, '', $filter_status);
$asset_ids      = array_column($preview_assets, 'id');

// Dados por modo
$history_records  = [];
$tech_data        = [];
$entity_data      = [];
$component_data   = [];
$avg_time_data    = [];

if ($report_mode === 'history' && !empty($asset_ids)) {
    $hist_where = ['items_id' => $asset_ids];
    if ($period_start) $hist_where[] = ['date_creation' => ['>=', $period_start . ' 00:00:00']];
    if ($period_end)   $hist_where[] = ['date_creation' => ['<=', $period_end . ' 23:59:59']];
    $history_records = iterator_to_array($DB->request([
        'FROM'  => 'glpi_plugin_assetmgrstatus_histories',
        'WHERE' => $hist_where,
        'ORDER' => ['date_creation DESC'],
        'LIMIT' => 1000,
    ]));
} elseif ($report_mode === 'technician') {
    $tech_data = Stats::getByTechnician($entity_id, $period_start, $period_end);
} elseif ($report_mode === 'entity') {
    Session::checkRight('plugin_assetmgrstatus_admin', READ);
    $entity_data = Stats::getByEntity($entity_id);
} elseif ($report_mode === 'components') {
    $component_data = Stats::getComponentRanking($entity_id, $period_start, $period_end);
} elseif ($report_mode === 'avg_time') {
    $avg_time_data = Stats::getAverageMaintenanceTime($entity_id, $period_start, $period_end);
}

// Contagem pré-visualização
$preview_count = match($report_mode) {
    'assets'     => count($preview_assets),
    'history'    => count($history_records),
    'technician' => count($tech_data),
    'entity'     => count($entity_data),
    'components' => count($component_data),
    'avg_time'   => count($avg_time_data),
    default      => 0,
};
?>
<div class="container-fluid am-page">

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-report"></i><h2>Relatórios Personalizados</h2></div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/dashboard.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-dashboard"></i> Dashboard</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-clipboard-list"></i> Inventário</a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_tecnico', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-tools"></i> Técnico</a>
            <?php endif; ?>
            <a href="http://10.180.152.27/glpi/plugins/cadastroativos/Cadastro" target="_blank" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-plus"></i> Cadastrar</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 16px;font-size:.82rem;"><i class="ti ti-arrow-left"></i> Voltar</a>
        </div>
    </div>

    <div class="am-report-builder">

        <!-- Seletor de modo -->
        <div class="am-report-section">
            <h3><i class="ti ti-toggle-left"></i> Tipo de Relatório</h3>
            <div class="am-report-mode-grid">
                <?php
                $modes = [
                    'assets'     => ['icon' => 'ti-list-details',  'title' => 'Lista de Ativos',         'desc' => 'Estado atual de cada ativo',                    'color' => '#4f46e5'],
                    'history'    => ['icon' => 'ti-history',        'title' => 'Histórico de Movimentações','desc' => 'Todas as mudanças de status e manutenções',    'color' => '#0891b2'],
                    'technician' => ['icon' => 'ti-user-check',     'title' => 'Por Técnico',              'desc' => 'Quantas ações cada técnico realizou',           'color' => '#7c3aed'],
                    'components' => ['icon' => 'ti-cpu',             'title' => 'Componentes Problemáticos','desc' => 'Ranking de componentes mais afetados',         'color' => '#dc2626'],
                    'avg_time'   => ['icon' => 'ti-clock',           'title' => 'Tempo Médio em Manutenção','desc' => 'Dias que ativos ficam em manutenção por tipo', 'color' => '#d97706'],
                    'mensal'     => ['icon' => 'ti-file-spreadsheet',  'title' => 'Relatório Mensal',          'desc' => 'Gera planilha ODS no padrão da Secretaria',   'color' => '#16a34a'],
                ];
                // So ADMIN vê "Por Entidade" (consolidado por entidade)
                if ($can_admin) {
                    // insere após technician, antes de components
                    $modes = array_merge(
                        array_slice($modes, 0, 3, true),
                        ['entity' => ['icon' => 'ti-building-community','title' => 'Por Entidade', 'desc' => 'Consolidado por entidade (ADMIN)', 'color' => '#059669']],
                        array_slice($modes, 3, null, true)
                    );
                } else {
                    // Não-ADMIN vê apenas Lista de Ativos e Relatório Mensal
                    $modes = array_intersect_key($modes, array_flip(['assets', 'mensal']));
                }
                foreach ($modes as $key => $def):
                ?>
                <a href="?mode=<?= $key ?>&type=<?= urlencode($filter_type) ?>&status=<?= urlencode($filter_status) ?>"
                   class="am-mode-card-v2 <?= $report_mode === $key ? 'active' : '' ?>"
                   style="<?= $report_mode === $key ? "--mode-color:{$def['color']};" : '' ?>">
                    <i class="ti <?= $def['icon'] ?>" style="color:<?= $def['color'] ?>;"></i>
                    <strong><?= $def['title'] ?></strong>
                    <small><?= $def['desc'] ?></small>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .rep-filter-toggle{margin:16px 0;display:flex;align-items:center;gap:8px}
        .rep-filter-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#fff;border:1.5px solid #e8eaf0;border-radius:8px;font-size:.85rem;font-weight:700;color:#374151;cursor:pointer;transition:all .15s}
        .rep-filter-btn:hover{background:#f8fafc;border-color:#cbd5e1}
        .rep-filter-btn.active{background:#eef2ff;border-color:#c7d2fe;color:#4f46e5}
        .rep-filters-collapsible.collapsed{display:none}
        .rep-filters-collapsible.expanded{display:block;animation:repFadeIn .2s ease}
        @keyframes repFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
        </style>
        <?php
        $rep_has_filter = ($filter_type!=='' || $filter_status!=='' || $period_start!=='' || $period_end!=='');
        $rep_active_count = ($filter_type!==''?1:0)+($filter_status!==''?1:0)+($period_start!==''?1:0)+($period_end!==''?1:0);
        ?>
        <div class="rep-filter-toggle">
            <button type="button" id="rep-filter-btn" class="rep-filter-btn" onclick="toggleRepFilters()">
                <i class="ti ti-filter"></i> Filtros <?php if($rep_has_filter) echo "<span class='am-comp-filter-count' style='margin-left:4px'>$rep_active_count</span>"; ?> <span id="rep-filter-text">Expandir</span> <i id="rep-filter-icon" class="ti ti-chevron-down" style="margin-left:4px"></i>
            </button>
            <?php if($rep_has_filter): ?><small style="color:#6b7280;font-size:.78rem"><i class="ti ti-info-circle"></i> filtros ativos</small><?php endif; ?>
        </div>
        <div id="rep-filters-collapsible" class="rep-filters-collapsible collapsed" style="display:none">
        <!-- Filtros (só relevantes para assets e history) -->
        <?php if (in_array($report_mode, ['assets', 'history'])): ?>
        <div class="am-report-section">
            <h3><i class="ti ti-filter"></i> Filtros</h3>
            <form method="GET" action="" id="am-report-form">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($report_mode) ?>">
                <div class="am-report-field">
                    <label>Tipo de Ativo</label>
                    <div class="am-report-options">
                        <label class="am-report-radio"><input type="radio" name="type" value="" <?= $filter_type==='' ? 'checked' : '' ?> onchange="this.form.submit()"><span><i class="ti ti-layout-grid"></i> Todos</span></label>
                        <?php foreach ($types as $key => $def): ?>
                        <label class="am-report-radio"><input type="radio" name="type" value="<?= $key ?>" <?= $filter_type===$key ? 'checked' : '' ?> onchange="this.form.submit()"><span><i class="ti <?= $def['icon'] ?>"></i> <?= htmlspecialchars($def['label']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="am-report-field">
                    <label>Status</label>
                    <div class="am-report-options">
                        <label class="am-report-radio"><input type="radio" name="status" value="" <?= $filter_status==='' ? 'checked' : '' ?> onchange="this.form.submit()"><span>Todos</span></label>
                        <?php foreach ($status_opts as $key => $label): ?>
                        <label class="am-report-radio"><input type="radio" name="status" value="<?= $key ?>" <?= $filter_status===$key ? 'checked' : '' ?> onchange="this.form.submit()"><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($report_mode === 'mensal'):
    // Relatório mensal - redireciona para download direto
?>
    <div style="text-align:center;padding:40px;">
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:32px;display:inline-block;max-width:480px;">
            <i class="ti ti-file-spreadsheet" style="font-size:3rem;color:#16a34a;display:block;margin-bottom:12px;"></i>
            <h3 style="color:#15803d;margin-bottom:8px;">Relatório Mensal de Inventário</h3>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:20px;">Gera uma planilha ODS com todos os ativos do inventário preenchida automaticamente no padrão da Secretaria de Educação.</p>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/export_mensal.php"
               class="am-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;padding:12px 28px;font-size:.95rem;display:inline-flex;align-items:center;gap:8px;">
                <i class="ti ti-download"></i> Baixar Planilha ODS
            </a>
        </div>
    </div>
<?php
elseif (in_array($report_mode, ['history','technician','components','avg_time'])): ?>
        <div class="am-report-section">
            <h3><i class="ti ti-calendar-stats"></i> Período</h3>
            <form method="GET" action="" id="am-period-form" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="mode"   value="<?= htmlspecialchars($report_mode) ?>">
                <input type="hidden" name="type"   value="<?= htmlspecialchars($filter_type) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <div>
                    <label class="am-form-label" style="margin-bottom:6px;">De</label>
                    <input type="date" name="period_start" class="am-input" style="width:auto;" value="<?= htmlspecialchars($period_start) ?>">
                </div>
                <div>
                    <label class="am-form-label" style="margin-bottom:6px;">Até</label>
                    <input type="date" name="period_end" class="am-input" style="width:auto;" value="<?= htmlspecialchars($period_end) ?>">
                </div>
                <button type="submit" class="am-btn am-btn-primary" style="padding:9px 18px;">
                    <i class="ti ti-filter"></i> Aplicar
                </button>
                <?php if ($period_start || $period_end): ?>
                <a href="?mode=<?= $report_mode ?>" class="am-btn am-btn-secondary" style="padding:9px 14px;">
                    <i class="ti ti-x"></i> Limpar
                </a>
                <?php endif; ?>
                <?php if ($period_start || $period_end): ?>
                <span style="font-size:.8rem;color:#9ca3af;align-self:center;">
                    Filtrando: <?= $period_start ? date('d/m/Y', strtotime($period_start)) : '...' ?> até <?= $period_end ? date('d/m/Y', strtotime($period_end)) : 'hoje' ?>
                </span>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>
        </div>
        <script>
        function toggleRepFilters(){
          var c=document.getElementById('rep-filters-collapsible');
          var b=document.getElementById('rep-filter-btn');
          var t=document.getElementById('rep-filter-text');
          var icon=document.getElementById('rep-filter-icon');
          if(c.style.display==='none' || c.classList.contains('collapsed')){
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
        </script>

        <!-- Pré-visualização + Exportar -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="am-report-section">
                <h3><i class="ti ti-eye"></i> Pré-visualização</h3>
                <div class="am-report-preview-box" style="padding:16px 20px;">
                    <div class="am-report-preview-number"><?= $preview_count ?></div>
                    <div class="am-report-preview-label"><?= match($report_mode) {
                        'assets'     => 'ativo(s)',
                        'history'    => 'registro(s)',
                        'technician' => 'técnico(s)',
                        'entity'     => 'entidade(s)',
                        'components' => 'componente(s)',
                        'avg_time'   => 'tipo(s) de ativo',
                        default      => 'resultado(s)',
                    } ?></div>
                </div>
            </div>
            <?php if (in_array($report_mode, ['assets', 'history', 'technician', 'entity', 'components', 'avg_time'])): ?>
            <div class="am-report-section">
                <h3><i class="ti ti-download"></i> Exportar</h3>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/export.php?format=excel&mode=<?= $report_mode ?>&type=<?= urlencode($filter_type) ?>&status=<?= urlencode($filter_status) ?>&period_start=<?= urlencode($period_start) ?>&period_end=<?= urlencode($period_end) ?>" class="am-report-export-btn am-export-excel"><i class="ti ti-file-spreadsheet"></i><div><strong>Excel</strong><small>Arquivo .csv</small></div></a>
                    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/export.php?format=pdf&mode=<?= $report_mode ?>&asset_type=<?= urlencode($filter_type) ?>&status=<?= urlencode($filter_status) ?>&period_start=<?= urlencode($period_start) ?>&period_end=<?= urlencode($period_end) ?>" target="_blank" class="am-report-export-btn am-export-pdf"><i class="ti ti-file-type-pdf"></i><div><strong>PDF</strong><small>Abrir para imprimir</small></div></a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Resultado do relatório -->
        <div class="am-report-section">
            <?php if ($report_mode === 'assets'): ?>
                <h3><i class="ti ti-list-details"></i> Lista de Ativos</h3>
                <?php if (empty($preview_assets)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-inbox"></i><p>Nenhum ativo encontrado.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="am-list-table">
                    <thead><tr><th>Nome</th><th>Tipo</th><th>Serial</th><th>Patrimônio</th><th>Entidade</th><th>Status</th><th>Dias s/ manutenção</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview_assets as $a):
                        $ps = $a['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
                        $days = $a['days_since_maintenance'];
                    ?>
                    <tr class="am-list-row">
                        <td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
                        <td><?= htmlspecialchars($a['asset_type_label']) ?></td>
                        <td style="color:#9ca3af;"><?= htmlspecialchars($a['serial'] ?? '—') ?></td>
                        <td style="color:#9ca3af;"><?= htmlspecialchars($a['otherserial'] ?? '—') ?></td>
                        <td style="color:#6366f1;font-size:.82rem;"><?= htmlspecialchars($a['entity_name'] ?? '—') ?></td>
                        <td><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($ps) ?>"><?= MaintenanceRecord::getStatusLabel($ps) ?></span></td>
                        <td style="<?= ($a['alert_60days'] ?? false) ? 'color:#dc2626;font-weight:700;' : '' ?>"><?= $days !== null ? $days.'d' : '—' ?><?= ($a['alert_60days'] ?? false) ? ' ⚠️' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

            <?php elseif ($report_mode === 'history'): ?>
                <h3><i class="ti ti-history"></i> Histórico de Movimentações</h3>
                <?php if (empty($history_records)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-clipboard-off"></i><p>Nenhum registro encontrado.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="am-list-table">
                    <thead><tr><th>Data</th><th>Ativo</th><th>Tipo</th><th>Status anterior</th><th>Status novo</th><th>Descrição</th><th>Técnico</th></tr></thead>
                    <tbody>
                    <?php foreach ($history_records as $h):
                        $rt = $h['record_type'] ?? 'status_change';
                        $u = new User(); $uname = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
                        $type_badge_color = match($rt) { 'manutencao_realizada' => '#10b981', 'baixa' => '#ef4444', default => '#4f46e5' };
                        $type_label = match($rt) { 'manutencao_realizada' => '🔧 Manutenção', 'baixa' => '📦 Baixa', default => '🔄 Status' };
                    ?>
                    <tr class="am-list-row">
                        <td style="font-size:.8rem;color:#9ca3af;white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($h['date_creation'])) ?></td>
                        <td><strong><?= htmlspecialchars($h['item_name']) ?></strong></td>
                        <td><span style="font-size:.75rem;font-weight:700;color:<?= $type_badge_color ?>;"><?= $type_label ?></span></td>
                        <td><?= $h['status_old'] ? '<span class="am-badge '.MaintenanceRecord::getStatusBadgeClass($h['status_old']).'">'.MaintenanceRecord::getStatusLabel($h['status_old']).'</span>' : '—' ?></td>
                        <td><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($h['status_new']) ?>"><?= MaintenanceRecord::getStatusLabel($h['status_new']) ?></span></td>
                        <td style="font-size:.82rem;color:#4b5563;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($h['action_description'] ?: $h['reason'] ?: '') ?>"><?= htmlspecialchars(mb_substr($h['action_description'] ?: $h['reason'] ?: '—', 0, 60)) ?></td>
                        <td style="font-size:.82rem;"><?= htmlspecialchars($uname) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

            <?php elseif ($report_mode === 'technician'): ?>
                <h3><i class="ti ti-user-check"></i> Atividade por Técnico</h3>
                <?php if (empty($tech_data)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-users-off"></i><p>Nenhum registro encontrado.</p></div>
                <?php else: $max_total = max(array_column($tech_data, 'total')) ?: 1; ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($tech_data as $t): ?>
                    <div style="background:#fafbff;border:1.5px solid #e8eaf0;border-radius:12px;padding:14px 18px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center;"><i class="ti ti-user" style="color:#fff;font-size:1rem;"></i></div>
                                <div>
                                    <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($t['user_name']) ?></div>
                                    <div style="font-size:.75rem;color:#9ca3af;"><?= $t['total'] ?> ação(ões) no total</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <span style="background:#f0f0ff;color:#4f46e5;border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:700;">🔄 <?= $t['status_change'] ?> status</span>
                                <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:700;">🔧 <?= $t['manutencao_realizada'] ?> manutenções</span>
                                <span style="background:#fef2f2;color:#991b1b;border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:700;">📦 <?= $t['baixa'] ?> baixas</span>
                            </div>
                        </div>
                        <div style="background:#f0f2f8;border-radius:20px;height:8px;overflow:hidden;">
                            <div style="background:linear-gradient(90deg,#4f46e5,#7c3aed);height:100%;width:<?= round(($t['total']/$max_total)*100) ?>%;border-radius:20px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php elseif ($report_mode === 'entity'): ?>
                <h3><i class="ti ti-building-community"></i> Consolidado por Entidade <small style="font-weight:400;color:#9ca3af;font-size:.75rem;margin-left:8px;">filtrado pela entidade ativa</small></h3>
                <?php if (empty($entity_data)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-building-off"></i><p>Nenhuma entidade com ativos encontrada.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="am-list-table">
                    <thead>
                        <tr>
                            <th>Entidade</th>
                            <th>Total</th>
                            <?php foreach ($status_opts as $key => $label): ?>
                            <th><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entity_data as $e): ?>
                    <tr class="am-list-row">
                        <td style="font-weight:600;color:#4f46e5;"><?= htmlspecialchars($e['entity_name']) ?></td>
                        <td><strong><?= $e['total'] ?></strong></td>
                        <?php foreach ($status_opts as $key => $label): ?>
                        <td>
                            <?php $n = $e['by_status'][$key] ?? 0; ?>
                            <?= $n > 0 ? '<strong style="color:#374151;">'.$n.'</strong>' : '<span style="color:#d1d5db;">0</span>' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

            <?php elseif ($report_mode === 'components'): ?>
                <h3><i class="ti ti-cpu"></i> Componentes Mais Problemáticos</h3>
                <?php if (empty($component_data)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-cpu-off"></i><p>Nenhum dado de componentes ainda.</p></div>
                <?php else: $max_comp = $component_data[0]['count'] ?: 1; ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($component_data as $idx => $c): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;background:#fafbff;border:1.5px solid #e8eaf0;border-radius:10px;">
                        <div style="width:28px;height:28px;background:<?= $idx < 3 ? 'linear-gradient(135deg,#dc2626,#ef4444)' : '#f3f4f6' ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;color:<?= $idx < 3 ? '#fff' : '#6b7280' ?>;">#<?= $idx+1 ?></div>
                        <div style="flex:1;">
                            <div style="font-weight:700;font-size:.9rem;margin-bottom:4px;"><?= htmlspecialchars($c['label']) ?></div>
                            <div style="background:#f0f2f8;border-radius:20px;height:7px;overflow:hidden;">
                                <div style="background:<?= $idx < 3 ? 'linear-gradient(90deg,#dc2626,#ef4444)' : 'linear-gradient(90deg,#4f46e5,#7c3aed)' ?>;height:100%;width:<?= round(($c['count']/$max_comp)*100) ?>%;border-radius:20px;"></div>
                            </div>
                        </div>
                        <div style="font-size:1.2rem;font-weight:800;color:<?= $idx < 3 ? '#dc2626' : '#4f46e5' ?>;min-width:40px;text-align:right;"><?= $c['count'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php elseif ($report_mode === 'avg_time'): ?>
                <h3><i class="ti ti-clock"></i> Tempo Médio em Manutenção</h3>
                <?php if (empty($avg_time_data)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-clock-off"></i><p>Sem dados de manutenção ainda.</p></div>
                <?php else: $max_avg = max(array_column($avg_time_data, 'avg_days')) ?: 1; ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($avg_time_data as $at): ?>
                    <div style="background:#fafbff;border:1.5px solid #e8eaf0;border-radius:12px;padding:16px 20px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($at['asset_type']) ?></div>
                            <div style="display:flex;gap:10px;font-size:.78rem;">
                                <span style="color:#9ca3af;"><?= $at['count'] ?> ocorrência(s)</span>
                                <span style="background:#fff7ed;color:#c2410c;border-radius:20px;padding:2px 10px;font-weight:700;">mín <?= $at['min_days'] ?>d</span>
                                <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:2px 10px;font-weight:700;">máx <?= $at['max_days'] ?>d</span>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="flex:1;background:#f0f2f8;border-radius:20px;height:10px;overflow:hidden;">
                                <div style="background:linear-gradient(90deg,#d97706,#f59e0b);height:100%;width:<?= round(($at['avg_days']/$max_avg)*100) ?>%;border-radius:20px;"></div>
                            </div>
                            <div style="font-size:1.4rem;font-weight:800;color:#d97706;min-width:70px;text-align:right;"><?= $at['avg_days'] ?>d</div>
                        </div>
                        <div style="font-size:.75rem;color:#9ca3af;margin-top:6px;">Média de dias em Manutenção</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Relatórios Rápidos -->
        <?php if ($report_mode === 'assets'): ?>
        <div class="am-report-section">
            <h3><i class="ti ti-bolt"></i> Atalhos Rápidos</h3>
            <div class="am-quick-reports">
                <a href="?mode=assets&type=&status=" class="am-quick-report-card"><i class="ti ti-list"></i><strong>Todos os Ativos</strong><small>Sem filtro</small></a>
                <a href="?mode=assets&type=&status=<?= MaintenanceRecord::STATUS_MANUTENCAO ?>" class="am-quick-report-card"><i class="ti ti-tools" style="color:#c2410c;"></i><strong>Em Manutenção</strong><small>Qualquer tipo</small></a>
                <a href="?mode=assets&type=&status=<?= MaintenanceRecord::STATUS_INSERVIVEL ?>" class="am-quick-report-card"><i class="ti ti-package-off" style="color:#991b1b;"></i><strong>Inservíveis</strong><small>Candidatos à baixa</small></a>
                <a href="?mode=assets&type=&status=<?= MaintenanceRecord::STATUS_ESTOQUE ?>" class="am-quick-report-card"><i class="ti ti-box" style="color:#6d28d9;"></i><strong>Em Estoque</strong><small>Disponíveis</small></a>
                <?php if ($can_admin): ?>
                <a href="?mode=components" class="am-quick-report-card"><i class="ti ti-cpu" style="color:#dc2626;"></i><strong>Componentes</strong><small>Ver ranking</small></a>
                <a href="?mode=entity" class="am-quick-report-card"><i class="ti ti-building-community" style="color:#059669;"></i><strong>Por Entidade</strong><small>Consolidado (ADMIN)</small></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("am-theme-btn");
    var dark = localStorage.getItem("am_theme") === "dark";
    btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
});
</script>
<?php Html::footer(); ?>
