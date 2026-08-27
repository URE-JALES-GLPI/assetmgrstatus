<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Stats;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, READ);

global $CFG_GLPI, $DB;

Html::header('Dashboard — Inventário', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'dashboard');

$entity_id  = Session::getActiveEntity();
$stats      = Stats::getAll($entity_id);
$monthly    = Stats::getMonthlyHistory($entity_id);
$alert_list = Stats::getAlertAssets($entity_id);
?>
<div class="container-fluid am-page">
    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-dashboard"></i><h2>Dashboard — Inventário de Ativos</h2></div>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-clipboard-list"></i> Inventário</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/reports.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-report"></i> Relatórios</a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_tecnico', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-tools"></i> Técnico</a>
            <?php endif; ?>
            <a href="http://10.180.152.27/glpi/plugins/cadastroativos/Cadastro" target="_blank" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-plus"></i> Cadastrar</a>
            <button id="am-theme-btn" onclick="amToggleTheme()"
                class="am-btn am-btn-secondary" style="padding:8px 12px;font-size:.82rem;" title="Alternar tema claro/escuro">
                <i class="ti ti-moon"></i>
            </button>
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

    <!-- Histórico Recente (movido de maintenance.php) -->
    <div class="am-section-title" style="margin-top:32px;"><i class="ti ti-history"></i><h3>Histórico Recente</h3></div>
    <div class="am-history-cards">
        <?php
        $history = MaintenanceRecord::getHistory('', 0, 20);
        $comp_list = MaintenanceRecord::getComponents();
        if (empty($history)):
        ?><div class="am-empty-state am-empty-small"><i class="ti ti-clipboard-off"></i><p>Nenhuma manutenção registrada ainda.</p></div>
        <?php else: foreach ($history as $h):
            $comps      = $h['components'] ? json_decode($h['components'], true) : [];
            $photos     = $h['photos']     ? json_decode($h['photos'], true)     : [];
            $u          = new User();
            $uname      = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
            $upload_url = $CFG_GLPI['root_doc'] . '/files/uploads/plugin_assetmgrstatus/';
            $record_type = $h['record_type'] ?? MaintenanceRecord::RECORD_STATUS_CHANGE;
            $border = MaintenanceRecord::getRecordTypeColor($record_type);
            $type_label = MaintenanceRecord::getRecordTypeLabel($record_type);
            // Verifica se este registro específico é desfazível (último status_change não desfeito dentro de 48h)
            $can_undo_history = false;
            $is_undone_history = !empty($h['is_undone']);
            if (!$is_undone_history && $record_type === MaintenanceRecord::RECORD_STATUS_CHANGE && Session::haveRight(MaintenanceRecord::RIGHT_VIEW, UPDATE)) {
                $undo_candidate = MaintenanceRecord::getUndoableChange($h['itemtype'], (int)$h['items_id']);
                if ($undo_candidate && (int)$undo_candidate['id'] === (int)$h['id']) {
                    $can_undo_history = true;
                }
            }
        ?>
        <div class="am-history-card" style="border-left:4px solid <?= $border ?>;<?= $is_undone_history ? 'opacity:.75;background:#f9fafb;' : '' ?>">
            <div class="am-history-card-header">
                <div class="am-history-card-title"><i class="ti ti-device-laptop"></i><?= htmlspecialchars($h['item_name']) ?><span style="font-size:.78rem;font-weight:600;color:#6b7280;margin-left:8px;"><?= $type_label ?></span><?php if ($is_undone_history): ?><span style="background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;border-radius:20px;padding:2px 8px;font-size:.70rem;font-weight:700;margin-left:8px;"><i class="ti ti-arrow-back-up"></i> Revertido</span><?php endif; ?></div>
                <div class="am-history-card-meta">
                    <span><i class="ti ti-calendar"></i> <?= Html::convDateTime($h['date_creation']) ?></span>
                    <span><i class="ti ti-user"></i> <?= htmlspecialchars($uname) ?></span>
                    <?php if ($can_undo_history): ?>
                    <button class="am-btn am-btn-undo" style="padding:4px 10px;font-size:.75rem;margin-left:8px;" onclick="amConfirmUndo(<?= (int)$h['items_id'] ?>,'<?= htmlspecialchars(addslashes($h['itemtype'])) ?>')" title="Desfazer esta alteração (até 48h)"><i class="ti ti-arrow-back-up"></i> Desfazer</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="am-history-card-body">
                <?php if ($record_type === MaintenanceRecord::RECORD_STATUS_CHANGE || $record_type === MaintenanceRecord::RECORD_TRANSFER_RETURN): ?>
                <div class="am-history-status-change">
                    <?php if (!empty($h['status_old']) && $h['status_old'] !== $h['status_new']): ?><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($h['status_old']) ?>"><?= MaintenanceRecord::getStatusLabel($h['status_old']) ?></span><i class="ti ti-arrow-right" style="color:#9ca3af;font-size:.85rem;"></i><?php endif; ?>
                    <span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($h['status_new']) ?>"><?= MaintenanceRecord::getStatusLabel($h['status_new']) ?></span>
                </div>
                <?php if (!empty($h['action_description']) && $record_type === MaintenanceRecord::RECORD_TRANSFER_RETURN): ?>
                <div style="display:flex;gap:7px;font-size:.88rem;color:#1f2937;margin-bottom:10px;background:#f9fafb;padding:10px 12px;border-radius:8px;border-left:3px solid <?= $border ?>;">
                    <i class="ti ti-<?= MaintenanceRecord::getRecordTypeIcon($record_type) ?>" style="flex-shrink:0;margin-top:2px;color:<?= $border ?>;"></i>
                    <span><?= htmlspecialchars($h['action_description']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($h['reason'] && $record_type === MaintenanceRecord::RECORD_STATUS_CHANGE): ?><div class="am-history-reason"><i class="ti ti-notes" style="flex-shrink:0;margin-top:2px;color:#4f46e5;"></i><span><?= htmlspecialchars($h['reason']) ?></span></div><?php endif; ?>
                <?php elseif (!empty($h['action_description'])): ?>
                <?php if ($record_type === MaintenanceRecord::RECORD_TRANSFER): ?>
                <div style="display:flex;gap:7px;font-size:.88rem;color:#1f2937;margin-bottom:10px;background:#f9fafb;padding:10px 12px;border-radius:8px;border-left:3px solid <?= $border ?>;">
                    <i class="ti ti-<?= MaintenanceRecord::getRecordTypeIcon($record_type) ?>" style="flex-shrink:0;margin-top:2px;color:<?= $border ?>;"></i>
                    <span><?= htmlspecialchars($h['action_description']) ?></span>
                </div>
                <div style="margin-bottom:8px;"><span class="am-badge <?= MaintenanceRecord::getStatusBadgeClass($h['status_new']) ?>"><?= MaintenanceRecord::getStatusLabel($h['status_new']) ?></span> <span style="font-size:.8rem;color:#6b7280;">status no momento do envio</span></div>
                <?php else: ?>
                <div style="display:flex;gap:7px;font-size:.88rem;color:#1f2937;margin-bottom:10px;background:#f9fafb;padding:10px 12px;border-radius:8px;border-left:3px solid <?= $border ?>;">
                    <i class="ti ti-<?= MaintenanceRecord::getRecordTypeIcon($record_type) ?>" style="flex-shrink:0;margin-top:2px;color:<?= $border ?>;"></i>
                    <span><?= htmlspecialchars($h['action_description']) ?></span>
                </div>
                <?php if ($record_type === MaintenanceRecord::RECORD_BAIXA && !empty($h['action_date'])): ?>
                <div style="font-size:.82rem;color:#6b7280;margin-bottom:8px;"><i class="ti ti-calendar-event"></i> Data da baixa: <strong><?= date('d/m/Y', strtotime($h['action_date'])) ?></strong></div>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($comps)): ?>
                <div class="am-history-components"><?php foreach ($comps as $ck => $cd): ?><span class="am-comp-chip"><strong><?= htmlspecialchars($comp_list[$ck] ?? $ck) ?></strong><?= $cd?': '.htmlspecialchars($cd):'' ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if (!empty($photos)): ?>
                <div class="am-history-photos"><?php foreach ($photos as $photo): ?><a href="<?= $upload_url.htmlspecialchars($photo) ?>" target="_blank"><img src="<?= $upload_url.htmlspecialchars($photo) ?>" class="am-photo-thumb" alt="Foto"></a><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <form id="am-undo-form" method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/undo.form.php" style="display:none">
        <input type="hidden" name="itemtype" id="am-undo-itemtype">
        <input type="hidden" name="items_id" id="am-undo-items-id">
        <input type="hidden" name="return_to" value="dashboard">
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
</div>
<?php Html::footer(); ?>
