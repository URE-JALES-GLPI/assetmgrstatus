<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Stats;

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, READ);

global $DB, $CFG_GLPI;

$format       = $_GET['format']       ?? 'excel';
$entity_id    = (int)($_GET['entity'] ?? Session::getActiveEntity());
$type         = $_GET['type']         ?? '';
$status       = $_GET['status']       ?? '';
$mode         = $_GET['mode']         ?? 'assets';
$period_start = $_GET['period_start'] ?? '';
$period_end   = $_GET['period_end']   ?? '';

$assets    = MaintenanceRecord::getAssets($type, '', $status);
$asset_ids = array_column($assets, 'id');
$comp_list = MaintenanceRecord::getComponents();

// Pré-carrega dados conforme modo
$history_records = [];
$tech_data       = [];
$entity_data     = [];
$component_data  = [];
$avg_time_data   = [];

if ($mode === 'history' && !empty($asset_ids)) {
    $hist_where = ['items_id' => $asset_ids];
    if ($period_start) $hist_where[] = ['date_creation' => ['>=', $period_start . ' 00:00:00']];
    if ($period_end)   $hist_where[] = ['date_creation' => ['<=', $period_end . ' 23:59:59']];
    $history_records = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_histories', 'WHERE' => $hist_where, 'ORDER' => ['date_creation DESC'], 'LIMIT' => 1000]));
} elseif ($mode === 'technician') {
    $tech_data = Stats::getByTechnician($entity_id, $period_start, $period_end);
} elseif ($mode === 'entity') {
    $entity_data = Stats::getByEntity();
} elseif ($mode === 'components') {
    $component_data = Stats::getComponentRanking($entity_id, $period_start, $period_end);
} elseif ($mode === 'avg_time') {
    $avg_time_data = Stats::getAverageMaintenanceTime($entity_id, $period_start, $period_end);
}

$period_label = ($period_start || $period_end)
    ? ' (' . ($period_start ? date('d/m/Y', strtotime($period_start)) : '...') . ' até ' . ($period_end ? date('d/m/Y', strtotime($period_end)) : 'hoje') . ')'
    : '';

// -------------------------------------------------------
// EXCEL (CSV com BOM para abrir corretamente no Excel)
// -------------------------------------------------------
if ($format === 'excel') {
    $filenames = ['assets' => 'ativos', 'history' => 'historico', 'technician' => 'por_tecnico', 'entity' => 'por_entidade', 'components' => 'componentes', 'avg_time' => 'tempo_medio'];
    $filename = ($filenames[$mode] ?? $mode) . '_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    if ($mode === 'assets') {
        fputcsv($out, ['Nome', 'Tipo', 'Serial', 'Patrimônio', 'Entidade', 'Status', 'Alerta +60d', 'Dias sem manutenção'], ';');
        foreach ($assets as $asset) {
            $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
            $days          = $asset['days_since_maintenance'];
            $alert         = ($asset['alert_60days'] ?? false) ? 'SIM' : 'não';
            fputcsv($out, [$asset['name'], $asset['asset_type_label'], $asset['serial'] ?? '', $asset['otherserial'] ?? '', $asset['entity_name'] ?? '', MaintenanceRecord::getStatusLabel($plugin_status), $alert, $days !== null ? $days . ' dias' : 'Sem manutenção registrada'], ';');
        }
    } elseif ($mode === 'history') {
        fputcsv($out, ['Data', 'Ativo', 'Tipo de Registro', 'Status Anterior', 'Status Novo', 'Motivo / Descrição', 'Componentes', 'Usuário'], ';');
        $u = new User();
        foreach ($history_records as $h) {
            $rt = $h['record_type'] ?? MaintenanceRecord::RECORD_STATUS_CHANGE;
            $type_label = match($rt) { MaintenanceRecord::RECORD_MANUTENCAO => 'Manutenção Realizada', MaintenanceRecord::RECORD_BAIXA => 'Baixa', default => 'Alteração de Status' };
            $uname = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
            $comps = $h['components'] ? json_decode($h['components'], true) : [];
            $comp_text = [];
            foreach ($comps as $ck => $cd) { $comp_text[] = ($comp_list[$ck] ?? $ck) . ($cd ? ": $cd" : ''); }
            fputcsv($out, [date('d/m/Y H:i', strtotime($h['date_creation'])), $h['item_name'], $type_label, $h['status_old'] ? MaintenanceRecord::getStatusLabel($h['status_old']) : '', MaintenanceRecord::getStatusLabel($h['status_new']), $h['action_description'] ?: ($h['reason'] ?: ''), implode(' | ', $comp_text), $uname], ';');
        }
    } elseif ($mode === 'technician') {
        fputcsv($out, ['Técnico', 'Alterações de Status', 'Manutenções Realizadas', 'Baixas', 'Total'], ';');
        foreach ($tech_data as $t) {
            fputcsv($out, [$t['user_name'], $t['status_change'], $t['manutencao_realizada'], $t['baixa'], $t['total']], ';');
        }
    } elseif ($mode === 'entity') {
        $status_opts = MaintenanceRecord::getStatusOptions();
        $header = ['Entidade', 'Total'];
        foreach ($status_opts as $label) $header[] = $label;
        fputcsv($out, $header, ';');
        foreach ($entity_data as $e) {
            $row = [$e['entity_name'], $e['total']];
            foreach ($status_opts as $key => $label) $row[] = $e['by_status'][$key] ?? 0;
            fputcsv($out, $row, ';');
        }
    } elseif ($mode === 'components') {
        fputcsv($out, ['Posição', 'Componente', 'Ocorrências'], ';');
        foreach ($component_data as $i => $c) {
            fputcsv($out, [$i + 1, $c['label'], $c['count']], ';');
        }
    } elseif ($mode === 'avg_time') {
        fputcsv($out, ['Tipo de Ativo', 'Ocorrências', 'Média (dias)', 'Mínimo (dias)', 'Máximo (dias)'], ';');
        foreach ($avg_time_data as $at) {
            fputcsv($out, [$at['asset_type'], $at['count'], $at['avg_days'], $at['min_days'], $at['max_days']], ';');
        }
    }

        fclose($out);
    exit;
}

// -------------------------------------------------------
// PDF (página HTML otimizada para impressão/salvar PDF)
// -------------------------------------------------------
if ($format === 'pdf') {
    $asset_type_key = $_GET['asset_type'] ?? $type;
    $items_id       = (int)($_GET['items_id'] ?? 0);
    $pdf_status     = $_GET['status'] ?? $status;

    // PDF de um ativo específico (sempre mostra histórico daquele ativo)
    if ($items_id) {
        $itemtype   = $_GET['itemtype'] ?? '';
        $history    = MaintenanceRecord::getHistory($itemtype, $items_id, 200);
        $name_iter  = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_assets_assets', 'WHERE' => ['id' => $items_id], 'LIMIT' => 1]);
        $asset_name = $name_iter->count() > 0 ? $name_iter->current()['name'] : 'Ativo';
        $pdf_mode   = 'history';
    } else {
        $type_label   = $asset_type_key && isset(MaintenanceRecord::getAssetTypes()[$asset_type_key]) ? MaintenanceRecord::getAssetTypes()[$asset_type_key]['label'] : 'Todos os Tipos';
        $status_label = $pdf_status ? MaintenanceRecord::getStatusLabel($pdf_status) : 'Todos os Status';
        $asset_name   = $type_label . ' — ' . $status_label;
        $pdf_mode     = $mode;
        $history      = $history_records; // já calculado acima com base no $mode
    }

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Relatório de Manutenção — <?= htmlspecialchars($asset_name) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1f2937; padding: 30px; }
  .header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #4f46e5; padding-bottom:16px; margin-bottom:24px; }
  .header-title h1 { font-size:20px; color:#1e1b4b; font-weight:800; }
  .header-title p { font-size:11px; color:#9ca3af; margin-top:4px; }
  .header-meta { text-align:right; font-size:11px; color:#6b7280; }
  .badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; }
  .badge-ativo      { background:#d1fae5; color:#065f46; }
  .badge-estoque    { background:#f5f3ff; color:#6d28d9; }
  .badge-inativo    { background:#fef2f2; color:#991b1b; }
  .badge-garantia   { background:#eff6ff; color:#1d4ed8; }
  .badge-inservivel { background:#f3f4f6; color:#374151; }
  .badge-manutencao { background:#fff7ed; color:#c2410c; }
  .badge-realizada  { background:#d1fae5; color:#065f46; }
  .badge-baixa      { background:#fef2f2; color:#991b1b; }
  table.assets-table { width:100%; border-collapse:collapse; font-size:11px; }
  table.assets-table th { background:#f8f9fb; text-align:left; padding:8px 10px; border-bottom:2px solid #e8eaf0; font-size:10px; text-transform:uppercase; color:#6b7280; }
  table.assets-table td { padding:7px 10px; border-bottom:1px solid #f0f2f8; }
  table.assets-table tr:nth-child(even) { background:#fafbff; }
  .record { border:1px solid #e8eaf0; border-radius:8px; margin-bottom:12px; overflow:hidden; page-break-inside:avoid; }
  .record-header { display:flex; justify-content:space-between; align-items:center; padding:8px 14px; background:#f8f9fb; border-bottom:1px solid #eef0f4; }
  .record-title { font-weight:700; font-size:13px; }
  .record-meta { font-size:10px; color:#9ca3af; }
  .record-body { padding:10px 14px; }
  .record-desc { background:#f9fafb; padding:8px 10px; border-radius:6px; border-left:3px solid #4f46e5; font-size:11px; margin-bottom:8px; }
  .record-desc.green { border-color:#10b981; }
  .record-desc.red   { border-color:#ef4444; }
  .comp-tag { display:inline-block; background:#f0f0ff; border:1px solid #c7d2fe; border-radius:4px; padding:2px 7px; font-size:10px; color:#3730a3; margin:2px; }
  .footer { margin-top:32px; padding-top:12px; border-top:1px solid #e8eaf0; font-size:10px; color:#9ca3af; text-align:center; }
  @media print { body { padding:15px; } .no-print { display:none; } }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:20px;display:flex;gap:10px;">
    <button onclick="window.print()" style="padding:8px 20px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;">🖨️ Imprimir / Salvar PDF</button>
    <button onclick="window.close()" style="padding:8px 20px;background:#f3f4f6;color:#374151;border:none;border-radius:6px;cursor:pointer;font-size:13px;">✕ Fechar</button>
</div>

<div class="header">
    <div class="header-title">
        <h1>Relatório de Manutenção</h1>
        <p><?= htmlspecialchars($asset_name) ?> <?= $pdf_mode === 'assets' ? '— Lista de Ativos' : '— Histórico de Movimentações' ?></p>
    </div>
    <div class="header-meta">
        <div>Gerado em: <?= date('d/m/Y H:i') ?></div>
        <div><?= $pdf_mode === 'assets' ? count($assets) . ' ativos' : count($history) . ' registros' ?></div>
    </div>
</div>

<?php if ($pdf_mode === 'assets'): ?>
    <?php if (empty($assets)): ?>
    <p style="color:#9ca3af;text-align:center;padding:40px;">Nenhum ativo encontrado.</p>
    <?php else: ?>
    <table class="assets-table">
        <thead><tr><th>Nome</th><th>Tipo</th><th>Serial</th><th>Patrimônio</th><th>Entidade</th><th>Status</th><th>Dias sem manutenção</th></tr></thead>
        <tbody>
        <?php foreach ($assets as $asset):
            $plugin_status = $asset['plugin_status'] ?? MaintenanceRecord::STATUS_ESTOQUE;
            $days = $asset['days_since_maintenance'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($asset['name']) ?></strong></td>
            <td><?= htmlspecialchars($asset['asset_type_label']) ?></td>
            <td><?= htmlspecialchars($asset['serial'] ?? '—') ?></td>
            <td><?= htmlspecialchars($asset['otherserial'] ?? '—') ?></td>
            <td><?= htmlspecialchars($asset['entity_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $plugin_status ?>"><?= MaintenanceRecord::getStatusLabel($plugin_status) ?></span></td>
            <td><?= $days !== null ? $days . ' dias' : 'Sem registro' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php else: ?>
    <?php if (empty($history)): ?>
    <p style="color:#9ca3af;text-align:center;padding:40px;">Nenhum registro encontrado.</p>
    <?php else: foreach ($history as $h):
        $rt = $h['record_type'] ?? MaintenanceRecord::RECORD_STATUS_CHANGE;
        $type_label = match($rt) { MaintenanceRecord::RECORD_MANUTENCAO => '🔧 Manutenção Realizada', MaintenanceRecord::RECORD_BAIXA => '📦 Baixa', default => '🔄 Alteração de Status' };
        $border_class = match($rt) { MaintenanceRecord::RECORD_MANUTENCAO => 'green', MaintenanceRecord::RECORD_BAIXA => 'red', default => '' };
        $u = new User(); $uname = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
        $comps = $h['components'] ? json_decode($h['components'], true) : [];
    ?>
    <div class="record">
        <div class="record-header">
            <div>
                <div class="record-title"><?= htmlspecialchars($h['item_name']) ?></div>
                <div class="record-meta"><?= $type_label ?> • <?= htmlspecialchars($uname) ?></div>
            </div>
            <div style="text-align:right;">
                <span class="badge badge-<?= $h['status_new'] ?>"><?= MaintenanceRecord::getStatusLabel($h['status_new']) ?></span>
                <div class="record-meta" style="margin-top:4px;"><?= date('d/m/Y H:i', strtotime($h['date_creation'])) ?></div>
            </div>
        </div>
        <div class="record-body">
            <?php if (!empty($h['action_description'])): ?>
            <div class="record-desc <?= $border_class ?>"><?= htmlspecialchars($h['action_description']) ?></div>
            <?php endif; ?>
            <?php if ($h['reason'] && $rt === MaintenanceRecord::RECORD_STATUS_CHANGE): ?>
            <div class="record-desc"><?= htmlspecialchars($h['reason']) ?></div>
            <?php endif; ?>
            <?php if ($rt === MaintenanceRecord::RECORD_BAIXA && !empty($h['action_date'])): ?>
            <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">📅 Data da baixa: <strong><?= date('d/m/Y', strtotime($h['action_date'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($comps)): ?>
            <div><?php foreach ($comps as $ck => $cd): ?>
            <span class="comp-tag"><strong><?= htmlspecialchars($comp_list[$ck] ?? $ck) ?></strong><?= $cd?': '.htmlspecialchars($cd):'' ?></span>
            <?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
<?php endif; ?>

<?php if ($pdf_mode === 'technician'): ?>
    <h2 style="font-size:14px;margin-bottom:14px;">Atividade por Técnico<?= $period_label ?></h2>
    <?php if (empty($tech_data)): ?>
    <p style="color:#9ca3af;">Nenhum dado encontrado.</p>
    <?php else: foreach ($tech_data as $t): ?>
    <div style="border:1px solid #e8eaf0;border-radius:8px;padding:12px 16px;margin-bottom:10px;page-break-inside:avoid;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <strong><?= htmlspecialchars($t['user_name']) ?></strong>
            <span style="font-size:11px;color:#9ca3af;"><?= $t['total'] ?> ação(ões)</span>
        </div>
        <div style="margin-top:8px;font-size:11px;display:flex;gap:12px;">
            <span>🔄 <?= $t['status_change'] ?> mudanças</span>
            <span>🔧 <?= $t['manutencao_realizada'] ?> manutenções</span>
            <span>📦 <?= $t['baixa'] ?> baixas</span>
        </div>
    </div>
    <?php endforeach; endif; ?>

<?php elseif ($pdf_mode === 'entity'): ?>
    <h2 style="font-size:14px;margin-bottom:14px;">Ativos por Entidade</h2>
    <table class="assets-table">
        <thead><tr><th>Entidade</th><th>Total</th><?php foreach (MaintenanceRecord::getStatusOptions() as $k => $l): ?><th><?= htmlspecialchars($l) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($entity_data as $e): ?>
        <tr>
            <td><strong><?= htmlspecialchars($e['entity_name']) ?></strong></td>
            <td><?= $e['total'] ?></td>
            <?php foreach (MaintenanceRecord::getStatusOptions() as $k => $l): ?>
            <td><?= $e['by_status'][$k] ?? 0 ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($pdf_mode === 'components'): ?>
    <h2 style="font-size:14px;margin-bottom:14px;">Componentes Mais Problemáticos<?= $period_label ?></h2>
    <table class="assets-table">
        <thead><tr><th>#</th><th>Componente</th><th>Ocorrências</th></tr></thead>
        <tbody>
        <?php foreach ($component_data as $i => $c): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($c['label']) ?></strong></td>
            <td style="color:<?= $i < 3 ? '#dc2626' : '#374151' ?>;font-weight:<?= $i < 3 ? '700' : '400' ?>;"><?= $c['count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($pdf_mode === 'avg_time'): ?>
    <h2 style="font-size:14px;margin-bottom:14px;">Tempo Médio em Manutenção<?= $period_label ?></h2>
    <table class="assets-table">
        <thead><tr><th>Tipo de Ativo</th><th>Ocorrências</th><th>Média</th><th>Mínimo</th><th>Máximo</th></tr></thead>
        <tbody>
        <?php foreach ($avg_time_data as $at): ?>
        <tr>
            <td><strong><?= htmlspecialchars($at['asset_type']) ?></strong></td>
            <td><?= $at['count'] ?></td>
            <td style="color:#d97706;font-weight:700;"><?= $at['avg_days'] ?>d</td>
            <td><?= $at['min_days'] ?>d</td>
            <td><?= $at['max_days'] ?>d</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="footer">Relatório gerado pelo plugin Asset Maintenance & Status — GLPI</div>
</body>
</html>
<?php
    exit;
}