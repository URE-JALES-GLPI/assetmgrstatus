<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_transfer', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $DB, $CFG_GLPI;

$transfer_id = (int)($_GET['id']    ?? 0);
$stage       = $_GET['stage']       ?? 'transfer'; // transfer | pronto | final

if (!$transfer_id) { echo 'ID inválido'; exit; }

$transfer = Transfer::getById($transfer_id);
if (!$transfer) { echo 'Transferência não encontrada'; exit; }

$items     = Transfer::getItems($transfer_id);
$comp_list = MaintenanceRecord::getComponents();

$ent = new Entity();
$entity_dest_name = ($ent->getFromDB((int)$transfer['entity_dest'])) ? $ent->getName() : 'Não informada';

// Busca entidade de origem (escola de onde vieram os ativos)
$origin_entity_name = '';
if (!empty($items)) {
    $items_list = array_values($items);
    $first = $items_list[0];
    if (!empty($first['origin_entity_name'])) {
        $origin_entity_name = $first['origin_entity_name'];
    } elseif (!empty($first['origin_entity_id'])) {
        $ent_orig = new Entity();
        if ($ent_orig->getFromDB((int)$first['origin_entity_id'])) {
            $origin_entity_name = $ent_orig->getName();
        }
    }
}
// URE onde foi feita a manutenção (entidade destino da transferência = URE de Jales)
$manut_entity_name = $entity_dest_name;

$u = new User();
$tech_name = ($transfer['users_id_tech'] && $u->getFromDB($transfer['users_id_tech'])) ? $u->getName() : '—';

$u2 = new User();
$creator_name = ($transfer['users_id_created'] && $u2->getFromDB($transfer['users_id_created'])) ? $u2->getName() : '—';

// Assinatura digital dual (recebedor + técnico) — coleta via tablet
$assinatura_image         = $transfer['assinatura_image'] ?? '';
$assinatura_doc_type      = $transfer['assinatura_document_type'] ?? '';
$assinatura_doc_raw       = $transfer['assinatura_document'] ?? '';
$assinatura_nome          = trim($transfer['assinatura_nome'] ?? '');
$assinatura_data          = $transfer['assinatura_data'] ?? '';
$assinatura_doc_masked    = $assinatura_doc_raw ? Transfer::maskDocumento($assinatura_doc_type, $assinatura_doc_raw) : '';
$assinatura_data_fmt      = $assinatura_data ? date('d/m/Y H:i', strtotime($assinatura_data)) : '';
$assinatura_data_short    = $assinatura_data ? date('d/m/Y', strtotime($assinatura_data)) : '';
$assinatura_tec_image     = $transfer['assinatura_tecnico_image'] ?? '';
$assinatura_tec_doc_type  = $transfer['assinatura_tecnico_document_type'] ?? '';
$assinatura_tec_doc_raw   = $transfer['assinatura_tecnico_document'] ?? '';
$assinatura_tec_nome      = trim($transfer['assinatura_tecnico_nome'] ?? '');
$assinatura_tec_data      = $transfer['assinatura_tecnico_data'] ?? '';
$assinatura_tec_doc_masked = $assinatura_tec_doc_raw ? Transfer::maskDocumento($assinatura_tec_doc_type, $assinatura_tec_doc_raw) : '';
$assinatura_tec_data_fmt  = $assinatura_tec_data ? date('d/m/Y H:i', strtotime($assinatura_tec_data)) : '';
$hasRec = !empty($assinatura_image);
$hasTec = !empty($assinatura_tec_image);
$is_assinado = $hasRec && $hasTec;
// fallback técnico nunca vazio
$tecFallbackName = $tech_name;
if ($tecFallbackName === 'Sistema' || trim($tecFallbackName) === '') $tecFallbackName = $assinatura_tec_nome !== '' ? $assinatura_tec_nome : $creator_name;
if ($tecFallbackName === 'Sistema' || trim($tecFallbackName) === '') $tecFallbackName = 'Técnico';
$tecDisplayName = $hasTec && $assinatura_tec_nome !== '' ? $assinatura_tec_nome : $tech_name;
if ($tecDisplayName === 'Sistema' || trim($tecDisplayName) === '') $tecDisplayName = $assinatura_tec_nome !== '' ? $assinatura_tec_nome : $tech_name;

// Logo em base64
$logo_file = GLPI_ROOT . '/plugins/assetmgrstatus/img/logo_ure.png';
$logo_b64  = file_exists($logo_file) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file)) : '';

// Tempos
$time_pending = ($transfer['date_pending'] && $transfer['date_manutencao'])
    ? Transfer::getElapsedTime($transfer['date_pending'], $transfer['date_manutencao']) : null;
$time_manut = ($transfer['date_manutencao'] && $transfer['date_pronto'])
    ? Transfer::getElapsedTime($transfer['date_manutencao'], $transfer['date_pronto']) : null;
$time_total = Transfer::getElapsedTime($transfer['date_creation'],
    $transfer['date_finalizado'] ?: ($transfer['date_pronto'] ?: null));

$is_pronto = in_array($stage, ['pronto', 'final']);
$doc_title = $is_pronto ? 'Termo de Devolução de Equipamento' : 'Termo de Retirada de Equipamento';

// Anexa o termo de devolução ao chamado automático (uma única vez) ao abrir o PDF de pronto
if ($is_pronto) {
    Transfer::attachStageDoc($transfer_id, 'pronto');
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($doc_title) ?> — #<?= $transfer_id ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Arial', sans-serif; font-size: 11px; color: #2d2d2d; background: #f4f4f4; }
  .page { background: #fff; max-width: 820px; margin: 20px auto; padding: 26px 40px; box-shadow: 0 2px 16px rgba(0,0,0,.12); }
  @page { size: A4; margin: 10mm; }

  /* Header */
  .doc-header { display:flex; align-items:center; justify-content:space-between; border-bottom: 2px solid #1a73b5; padding-bottom: 10px; margin-bottom: 14px; }
  .doc-header img { height: 52px; }
  .doc-header-right { text-align: right; }
  .doc-header-right .org { font-size: 10px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .06em; }
  .doc-title { font-size: 15px; font-weight: 800; color: #1a73b5; margin-top: 2px; }
  .doc-number { font-size: 11px; color: #9ca3af; margin-top: 3px; }

  /* Declaração */
  .declaracao { background: #f0f7ff; border-left: 3px solid #1a73b5; border-radius: 0 6px 6px 0; padding: 9px 14px; margin-bottom: 14px; font-size: 10px; color: #1e3a5f; line-height: 1.4; }

  /* Corpo */
  .eu-declaro { font-size: 10.5px; line-height: 1.5; margin-bottom: 14px; color: #2d2d2d; }
  .eu-declaro strong { color: #1a73b5; }

  /* Info grid */
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
  .info-box { background: #f8f9fb; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 12px; }
  .info-box label { font-size: 8.5px; font-weight: 700; text-transform: uppercase; color: #9ca3af; letter-spacing: .04em; display: block; margin-bottom: 2px; }
  .info-box span { font-size: 10.5px; font-weight: 700; color: #1e2333; }

  /* Tabela de equipamentos */
  .section-title { font-size: 9.5px; font-weight: 700; text-transform: uppercase; color: #1a73b5; letter-spacing: .06em; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 14px; }
  table th { background: #1a73b5; color: #fff; text-align: left; padding: 5px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
  table td { padding: 5px 8px; border-bottom: 1px solid #f0f2f8; }
  table tr:nth-child(even) td { background: #fafbff; }
  .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; text-transform:uppercase; }
  .badge-ativo      { background:#d1fae5; color:#065f46; }
  .badge-garantia   { background:#dbeafe; color:#1d4ed8; }
  .badge-inservivel { background:#f3f4f6; color:#374151; }

  /* Tempos */
  .time-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-bottom: 14px; }
  .time-box { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 6px; text-align: center; }
  .time-box label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #6b7280; display:block; margin-bottom:4px; }
  .time-box span { font-size: 14px; font-weight: 800; color: #1a73b5; }

  /* Assinaturas */
  .sign-section { margin-top: 18px; page-break-inside: avoid; break-inside: avoid; }
  .sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 6px; }
  .sign-box { }
  .sign-line-space { height: 34px; border-bottom: 1.5px solid #2d2d2d; margin-bottom: 6px; position:relative; }
  .sign-name { font-weight: 700; font-size: 11.5px; color: #1e2333; }
  .sign-sub { font-size: 10px; color: #6b7280; margin-top: 2px; }
  .sign-fields { display:flex; flex-direction:column; gap:4px; margin-top:6px; font-size:10.5px; color:#4b5563; }
  .sign-fields span { border-bottom:1px solid #d1d5db; padding-bottom:3px; }
  .sign-img { max-height:70px; max-width:100%; object-fit:contain; display:block; margin:0 auto 4px; }
  .sign-badge { display:inline-block; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; border-radius:6px; padding:2px 8px; font-size:8.5px; font-weight:700; margin-top:4px; }

  /* Footer */
  .doc-footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e2e8f0; display:flex; justify-content:space-between; font-size: 8.5px; color: #9ca3af; }

  .no-print { margin-bottom: 24px; display:flex; gap:10px; max-width:820px; margin-left:auto; margin-right:auto; padding: 0 10px; }
  @media print { body { background:#fff; } .page { box-shadow:none; margin:0; padding:30px; } .no-print { display:none; } }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" style="padding:9px 22px;background:#1a73b5;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">🖨️ Imprimir / Salvar PDF</button>
    <button onclick="window.close()" style="padding:9px 22px;background:#f3f4f6;color:#374151;border:none;border-radius:8px;cursor:pointer;font-size:13px;">✕ Fechar</button>
</div>

<div class="page">

    <!-- Cabeçalho -->
    <div class="doc-header">
        <?php if ($logo_b64): ?>
        <img src="<?= $logo_b64 ?>" alt="Logo URE">
        <?php else: ?>
        <div style="font-weight:800;font-size:14px;color:#1a73b5;">UNIDADE REGIONAL DE ENSINO — REGIÃO DE JALES</div>
        <?php endif; ?>
        <div class="doc-header-right">
            <div class="org">Unidade Regional de Ensino — Região de Jales</div>
            <div class="doc-title"><?= htmlspecialchars($doc_title) ?></div>
            <div class="doc-number">Nº <?= str_pad($transfer_id, 6, '0', STR_PAD_LEFT) ?> &nbsp;|&nbsp; <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <?php if (!$is_pronto): ?>
    <!-- ======================================================
         DOCUMENTO DE TRANSFERÊNCIA / RETIRADA
         ====================================================== -->

    <!-- Declaração institucional -->
    <div class="declaracao">
        A Unidade Regional de Ensino – Região de Jales declara que o(s) equipamento(s) abaixo mencionado(s) foi(ram) retirado(s) pelo responsável identificado abaixo. O responsável está ciente de que retirou exatamente o(s) equipamento(s) que foi(ram) apresentado(s) ao suporte técnico, conforme verificado no momento da retirada.
    </div>

    <!-- Corpo do termo -->
    <div class="eu-declaro">
        Eu, <strong><?= htmlspecialchars($creator_name) ?></strong>, portador(a) do documento de identidade, em cumprimento às normas e procedimentos da Unidade Regional de Ensino – Região de <strong>JALES</strong>, declaro para os devidos fins que realizei a retirada do(s) equipamento(s) descrito(s) abaixo:
    </div>

    <!-- Informações -->
    <div class="info-grid">
        <div class="info-box">
            <label>Data de Retirada</label>
            <span><?= date('d/m/Y', strtotime($transfer['date_creation'])) ?></span>
        </div>
        <div class="info-box">
            <label>Escola / URE de Destino</label>
            <span><?= htmlspecialchars($entity_dest_name) ?></span>
        </div>
        <div class="info-box" style="grid-column:1/-1;">
            <label>Motivo da Transferência</label>
            <span style="font-weight:400;"><?= htmlspecialchars($transfer['reason']) ?></span>
        </div>
    </div>

    <!-- Equipamentos -->
    <div class="section-title">Equipamento(s) Retirado(s)</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Nome do Equipamento</th>
                <th>Tipo</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
            <td style="color:#6b7280;font-size:10px;"><?= htmlspecialchars(str_replace(['Glpi\\CustomAsset\\','Asset'], '', $item['itemtype'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php else: ?>
    <!-- ======================================================
         DOCUMENTO DE DEVOLUÇÃO / CONCLUSÃO (TÉCNICO - PRONTO)
         ====================================================== -->

    <div class="declaracao">
        A Unidade Regional de Ensino – Região de Jales declara que o(s) equipamento(s) abaixo mencionado(s) foi(ram) devolvido(s) após a realização dos procedimentos de manutenção técnica. O responsável pelo recebimento está ciente das condições e do novo status de cada equipamento, conforme verificado no momento da devolução.
    </div>

    <div class="eu-declaro">
        Eu, <strong><?= htmlspecialchars($tecDisplayName) ?></strong>, técnico(a) responsável pelo atendimento, portador(a) do documento de identidade, em cumprimento às normas e procedimentos da Unidade Regional de Ensino – Região de <strong>JALES</strong>, declaro que os equipamentos abaixo foram submetidos ao suporte técnico e estão sendo devolvidos ao responsável identificado abaixo, conforme verificado no momento da entrega:
    </div>

    <!-- Informações -->
    <div class="info-grid">
        <div class="info-box">
            <label>Data de Devolução</label>
            <span><?= date('d/m/Y', strtotime($transfer['date_pronto'] ?: $transfer['date_creation'])) ?></span>
        </div>
        <div class="info-box">
            <label>Escola / URE de Destino</label>
            <span><?= htmlspecialchars($entity_dest_name) ?></span>
        </div>
        <div class="info-box">
            <label>Técnico Responsável</label>
            <span><?= htmlspecialchars($tecDisplayName) ?><?= $hasTec ? ' — ' . htmlspecialchars($assinatura_tec_doc_type . ' ' . $assinatura_tec_doc_masked) : '' ?></span>
        </div>
        <div class="info-box">
            <label>Responsável pela Retirada</label>
            <span><?= htmlspecialchars($creator_name) ?></span>
        </div>
        <div class="info-box">
            <label>📍 Escola de Origem (de onde veio)</label>
            <span><?= htmlspecialchars($origin_entity_name ?: 'Não informada') ?></span>
        </div>
        <div class="info-box">
            <label>🔧 Local da Manutenção</label>
            <span><?= htmlspecialchars($manut_entity_name) ?></span>
        </div>
        <div class="info-box" style="grid-column:1/-1;background:#e0f2fe;border-color:#7dd3fc;">
            <label style="color:#0369a1;">↩️ Retornando para</label>
            <span style="color:#0369a1;font-size:13px;"><?= htmlspecialchars($origin_entity_name ?: 'Escola de origem') ?></span>
        </div>
        <?php if ($transfer['reason']): ?>
        <div class="info-box" style="grid-column:1/-1;">
            <label>Motivo Original da Transferência</label>
            <span style="font-weight:400;"><?= htmlspecialchars($transfer['reason']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tempos -->
    <?php if ($time_pending || $time_manut): ?>
    <div class="section-title">Controle de Tempo</div>
    <div class="time-grid">
        <?php if ($time_pending): ?>
        <div class="time-box"><label>Tempo Pendente</label><span><?= $time_pending['label'] ?></span></div>
        <?php endif; ?>
        <?php if ($time_manut): ?>
        <div class="time-box"><label>Tempo em Manutenção</label><span><?= $time_manut['label'] ?></span></div>
        <?php endif; ?>
        <div class="time-box" style="background:#e0f2fe;border-color:#7dd3fc;">
            <label>Tempo Total</label><span><?= $time_total['label'] ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Equipamentos com status final -->
    <div class="section-title">Equipamento(s) Devolvido(s)</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Nome do Equipamento</th>
                <th>Tipo</th>
                <th>Status Final</th>
                <th>Motivo / Observação</th>
                <th>Componentes Afetados / Resolvidos</th>
                <th>O Que Foi Feito</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item):
            $wrow_iter = $DB->request(['SELECT'=>['work_log','work_components'],'FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['transfers_id'=>$transfer_id,'items_id'=>(int)$item['items_id']],'LIMIT'=>1]);
            $wrow = $wrow_iter->count() > 0 ? $wrow_iter->current() : null;
            $wlog   = $wrow['work_log'] ?? '';
            $wcomps = ($wrow['work_components'] ?? '') ? json_decode($wrow['work_components'], true) : [];
            $resolved = [];
            foreach ($wcomps as $ck => $cs) {
                if ($cs === 'resolved') $resolved[$ck] = $comp_list[$ck] ?? $ck;
            }
        ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
            <td style="color:#6b7280;font-size:10px;"><?= htmlspecialchars(str_replace(['Glpi\\CustomAsset\\','Asset'], '', $item['itemtype'])) ?></td>
            <td>
                <?php if ($item['final_status']): ?>
                <span class="badge badge-<?= $item['final_status'] ?>"><?= MaintenanceRecord::getStatusLabel($item['final_status']) ?></span>
                <?php else: ?><span style="color:#9ca3af;">—</span><?php endif; ?>
            </td>
            <td style="font-size:10.5px;color:#4b5563;"><?= htmlspecialchars($item['final_reason'] ?? '—') ?></td>
            <td style="font-size:10px;color:#4b5563;">
                <?php
                $fcomps = !empty($item['final_components']) ? json_decode($item['final_components'], true) : [];
                $comps_html = [];
                foreach ($fcomps as $ckey => $cdesc) {
                    $clabel = $comp_list[$ckey] ?? $ckey;
                    $comps_html[] = '<span style="color:#b91c1c;">◆</span> <strong>' . htmlspecialchars($clabel) . '</strong>' . ($cdesc ? ': ' . htmlspecialchars($cdesc) : '');
                }
                foreach ($resolved as $rlabel) {
                    $comps_html[] = '<span style="color:#059669;font-weight:700;">✓ ' . htmlspecialchars($rlabel) . ' (resolvido)</span>';
                }
                echo !empty($comps_html)
                    ? implode('<br>', $comps_html)
                    : '<span style="color:#9ca3af;">—</span>';
                ?>
            </td>
            <td style="font-size:10px;color:#4b5563;">
                <?= $wlog ? nl2br(htmlspecialchars($wlog)) : '<span style="color:#9ca3af;">—</span>' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Assinaturas — dual via tablet (recebedor + técnico) -->
    <div class="sign-section">
        <div class="section-title">Assinaturas <?= $is_assinado ? '<span class="sign-badge">✍️ Assinado digitalmente — Recebedor ' . htmlspecialchars($assinatura_data_fmt) . ' / Técnico ' . htmlspecialchars($assinatura_tec_data_fmt) . '</span>' : ($hasRec || $hasTec ? '<span class="sign-badge" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">⚠️ Parcial ' . (!$hasRec?'recebedor ':'') . (!$hasRec&&!$hasTec?'e ':'') . (!$hasTec?'técnico':'') . '</span>' : '') ?></div>
        <div class="sign-grid">
            <div class="sign-box" style="<?= $hasTec ? 'background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px;' : '' ?>">
                <?php if ($hasTec): ?>
                    <img src="<?= $assinatura_tec_image ?>" alt="Assinatura Técnico" class="sign-img">
                    <div class="sign-line-space" style="height:1.5px;margin-bottom:6px;border-color:#2d2d2d;"></div>
                <?php else: ?>
                    <div class="sign-line-space"></div>
                <?php endif; ?>
                <div class="sign-name"><?= $is_pronto ? 'Responsável pela Entrega (Técnico)' : 'Responsável pelo Envio' ?> <?= $hasTec ? '<span style="color:#059669;font-size:9px;">● ASSINADO</span>' : '' ?></div>
                <div class="sign-fields">
                    <span>Nome: <?= $hasTec && $assinatura_tec_nome !== '' ? htmlspecialchars($assinatura_tec_nome) : htmlspecialchars($tecFallbackName) ?></span>
                    <span>Documento (<?= $hasTec ? htmlspecialchars($assinatura_tec_doc_type) : 'RG/CPF' ?>): <?= $hasTec ? htmlspecialchars($assinatura_tec_doc_type . ' ' . $assinatura_tec_doc_masked) : '_________________________________' ?></span>
                    <span>Data: <?= $hasTec ? htmlspecialchars($assinatura_tec_data_fmt) : '_____ / _____ / ___________' ?></span>
                    <?php if ($hasTec): ?>
                        <span style="font-size:8px;color:#6b7280;border:none;padding:0;">via tablet em <?= htmlspecialchars($assinatura_tec_data_fmt) ?> — IP <?= htmlspecialchars($transfer['assinatura_tecnico_ip'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sign-box" style="<?= $hasRec ? 'background:#fff;border:1.5px solid #e8eaf0;border-radius:10px;padding:10px;' : '' ?>">
                <?php if ($hasRec): ?>
                    <img src="<?= $assinatura_image ?>" alt="Assinatura" class="sign-img">
                    <div class="sign-line-space" style="height:1.5px;margin-bottom:6px;border-color:#2d2d2d;"></div>
                <?php else: ?>
                    <div class="sign-line-space"></div>
                <?php endif; ?>
                <div class="sign-name">Responsável pelo Recebimento <?= $hasRec ? '<span style="color:#059669;font-size:9px;">● ASSINADO</span>' : '' ?></div>
                <div class="sign-fields">
                    <span>Nome: <?= $hasRec && $assinatura_nome !== '' ? htmlspecialchars($assinatura_nome) : '_____________________________________________' ?></span>
                    <span>Documento (<?= $hasRec ? htmlspecialchars($assinatura_doc_type) : 'RG/CPF' ?>): <?= $hasRec ? htmlspecialchars($assinatura_doc_type . ' ' . $assinatura_doc_masked) : '_________________________________' ?></span>
                    <span>Data: <?= $hasRec ? htmlspecialchars($assinatura_data_fmt) : '_____ / _____ / ___________' ?></span>
                    <?php if ($hasRec): ?>
                        <span style="font-size:8px;color:#6b7280;border:none;padding:0;">via tablet em <?= htmlspecialchars($assinatura_data_fmt) ?> — IP <?= htmlspecialchars($transfer['assinatura_ip'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!$is_assinado): ?>
            <?php if ($hasRec || $hasTec): ?>
            <div style="margin-top:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:8px 12px;font-size:9px;color:#92400e;text-align:center;">
                ⚠️ Termo parcialmente assinado — falta <?= !$hasRec ? 'recebedor' : '' ?><?= !$hasRec && !$hasTec ? ' e ' : '' ?><?= !$hasTec ? 'técnico' : '' ?>. Colete na aba <strong>Assinatura</strong>.
            </div>
            <?php else: ?>
            <div style="margin-top:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:8px 12px;font-size:9px;color:#92400e;text-align:center;">
                ⚠️ Termo ainda não assinado — colete na aba <strong>Assinatura</strong> (recebedor + técnico) via tablet.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Rodapé -->
    <div class="doc-footer">
        <span>Unidade Regional de Ensino — Região de Jales &nbsp;|&nbsp; Suporte Técnico</span>
        <span>Gerado em <?= date('d/m/Y \à\s H:i') ?> &nbsp;|&nbsp; Transferência #<?= str_pad($transfer_id, 6, '0', STR_PAD_LEFT) ?></span>
    </div>

</div><!-- .page -->
</body>
</html>
<?php
