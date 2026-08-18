<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Stats;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, READ);

global $CFG_GLPI, $DB;

Html::header('Dashboard — Manutenção', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'dashboard');

$entity_id  = Session::getActiveEntity();
$stats      = Stats::getAll($entity_id);
$monthly    = Stats::getMonthlyHistory($entity_id);
$alert_list = Stats::getAlertAssets($entity_id);
$recent     = MaintenanceRecord::getHistory('', 0, 5);
$comp_list  = MaintenanceRecord::getComponents();
?>
<div class="container-fluid am-page">
    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-dashboard"></i><h2>Dashboard — Manutenção de Ativos</h2></div>
        <div style="display:flex;gap:10px;">
            <button id="am-theme-btn" onclick="amToggleTheme()"
                class="am-btn am-btn-secondary" style="padding:8px 12px;font-size:.82rem;" title="Alternar tema claro/escuro">
                <i class="ti ti-moon"></i>
            </button>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/export.php?format=excel&entity=<?= $entity_id ?>" class="am-btn am-btn-secondary" style="padding:8px 16px;font-size:.85rem;"><i class="ti ti-file-spreadsheet"></i> Exportar Excel</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-primary" style="padding:8px 16px;font-size:.85rem;"><i class="ti ti-tool"></i> Manutenção</a>
        </div>
    </div>

    <!-- Cards resumo -->
    <div class="am-dash-grid">
        <?php foreach (MaintenanceRecord::getStatusOptions() as $key => $label):
            $count = $stats['by_status'][$key] ?? 0; ?>
        <div class="am-dash-card">
            <div class="am-dash-card-top"><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($key) ?>"><?= htmlspecialchars($label) ?></span></div>
            <div class="am-dash-number"><?= $count ?></div>
            <div class="am-dash-label">ativos</div>
        </div>
        <?php endforeach; ?>
        <div class="am-dash-card am-dash-card-alert">
            <div class="am-dash-card-top"><span style="font-size:.8rem;font-weight:700;color:#dc2626;"><i class="ti ti-alert-triangle"></i> Alerta +60d</span></div>
            <div class="am-dash-number" style="color:#dc2626;"><?= $stats['alert_60'] ?></div>
            <div class="am-dash-label">sem manutenção</div>
        </div>
        <div class="am-dash-card">
            <div class="am-dash-card-top"><span style="font-size:.8rem;font-weight:700;color:#10b981;"><i class="ti ti-tools"></i> Manutenções</span></div>
            <div class="am-dash-number" style="color:#10b981;"><?= $stats['manutencoes_mes'] ?></div>
            <div class="am-dash-label">este mês</div>
        </div>
        <div class="am-dash-card">
            <div class="am-dash-card-top"><span style="font-size:.8rem;font-weight:700;color:#ef4444;"><i class="ti ti-package-off"></i> Baixas</span></div>
            <div class="am-dash-number" style="color:#ef4444;"><?= $stats['baixas_mes'] ?></div>
            <div class="am-dash-label">este mês</div>
        </div>
        <?php
        $em_atraso = 0;
        try {
            $em_atraso = (int)$DB->request([
                'SELECT' => ['COUNT' => 'glpi_plugin_assetmgrstatus_records.id AS total'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_records',
                'INNER JOIN' => ['glpi_assets_assets' => ['ON' => ['glpi_assets_assets' => 'id', 'glpi_plugin_assetmgrstatus_records' => 'items_id']]],
                'WHERE'  => [
                    'glpi_plugin_assetmgrstatus_records.expected_return_date' => ['<', date('Y-m-d')],
                    'glpi_plugin_assetmgrstatus_records.am_status' => [MaintenanceRecord::STATUS_MANUTENCAO, MaintenanceRecord::STATUS_GARANTIA],
                    'glpi_assets_assets.is_deleted' => 0,
                ],
            ])->current()['total'] ?? 0;
        } catch (\Throwable $e) {}
        ?>
        <div class="am-dash-card">
            <div class="am-dash-card-top"><span style="font-size:.8rem;font-weight:700;color:#f97316;"><i class="ti ti-calendar-exclamation"></i> Em atraso</span></div>
            <div class="am-dash-number" style="color:#f97316;"><?= $em_atraso ?></div>
            <div class="am-dash-label">devolução prevista vencida</div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
        <!-- Gráfico mensal -->
        <div class="am-dash-section">
            <div class="am-section-title"><i class="ti ti-chart-bar"></i><h3>Manutenções por Mês</h3></div>
            <div class="am-chart-wrap">
                <?php if (empty($monthly)): ?>
                <div class="am-empty-state am-empty-small"><i class="ti ti-chart-bar-off"></i><p>Sem dados ainda.</p></div>
                <?php else: $max = max(array_column($monthly, 'total')) ?: 1; ?>
                <div class="am-bar-chart">
                    <?php foreach ($monthly as $m): ?>
                    <div class="am-bar-col">
                        <div class="am-bar-value"><?= $m['total'] ?></div>
                        <div class="am-bar" style="height:<?= round(($m['total']/$max)*140) ?>px;"></div>
                        <div class="am-bar-label"><?= date('M/y', mktime(0,0,0,$m['month'],1,$m['year'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alertas +60d -->
        <div class="am-dash-section">
            <div class="am-section-title"><i class="ti ti-alert-circle" style="color:#dc2626;"></i><h3>Ativos com Alerta +60 dias</h3></div>
            <?php if (empty($alert_list)): ?>
            <div class="am-empty-state am-empty-small" style="color:#10b981;"><i class="ti ti-circle-check"></i><p>Todos os ativos estão em dia!</p></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;">
                <?php foreach ($alert_list as $al): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
                    <div>
                        <div style="font-weight:700;font-size:.9rem;"><?= htmlspecialchars($al['name']) ?></div>
                        <div style="font-size:.78rem;color:#9ca3af;"><?= htmlspecialchars($al['asset_type']??'') ?> • <?= htmlspecialchars($al['entity_name']??'') ?></div>
                    </div>
                    <span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:700;"><?= $al['days']!==null?$al['days'].'d':'nunca' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Histórico recente -->
    <div class="am-dash-section">
        <div class="am-section-title" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:10px;"><i class="ti ti-history"></i><h3>Histórico Recente</h3></div>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" style="font-size:.82rem;color:#4f46e5;">Ver tudo →</a>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php if (empty($recent)): ?>
            <div class="am-empty-state am-empty-small"><i class="ti ti-clipboard-off"></i><p>Nenhum registro ainda.</p></div>
            <?php else: foreach ($recent as $h):
                $rt = $h['record_type'] ?? MaintenanceRecord::RECORD_STATUS_CHANGE;
                $border = match($rt){MaintenanceRecord::RECORD_MANUTENCAO=>'#10b981',MaintenanceRecord::RECORD_BAIXA=>'#ef4444',default=>'#4f46e5'};
                $tl = match($rt){MaintenanceRecord::RECORD_MANUTENCAO=>'🔧 Manutenção',MaintenanceRecord::RECORD_BAIXA=>'📦 Baixa',default=>'🔄 Status'};
                $u=new User(); $uname=($h['users_id']&&$u->getFromDB($h['users_id']))?$u->getName():'Sistema';
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#fff;border:1.5px solid #e8eaf0;border-left:4px solid <?= $border ?>;border-radius:10px;">
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:.9rem;"><?= htmlspecialchars($h['item_name']) ?></div>
                    <div style="font-size:.78rem;color:#9ca3af;"><?= $tl ?> • <?= htmlspecialchars($uname) ?> • <?= Html::convDateTime($h['date_creation']) ?></div>
                    <?php $desc = $h['action_description'] ?: $h['reason']; if ($desc): ?>
                    <div style="font-size:.82rem;color:#555;margin-top:3px;"><?= htmlspecialchars(mb_substr($desc,0,80)) ?>...</div>
                    <?php endif; ?>
                </div>
                <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($h['status_new']) ?>"><?= MaintenanceRecord::getStatusLabel($h['status_new']) ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php Html::footer(); ?>
