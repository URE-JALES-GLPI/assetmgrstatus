<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI;

$filter_status = $_GET['status'] ?? '';
$filter_tech   = (int)($_GET['tech'] ?? 0);
$filter_date   = $_GET['date'] ?? '';
$filter_sort   = $_GET['sort'] ?? 'recent';
$transfers     = Transfer::getAll($filter_status);

Html::header('Técnico', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'tecnico');
?>

<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Manutenção</a>
        <i class="ti ti-chevron-right"></i>
        <span>Técnico</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-tools"></i><h2>Painel do Técnico</h2></div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-arrow-left"></i> Manutenção
            </a>
            <?php if (Session::haveRight('plugin_assetmgrstatus_transfer', READ)): ?>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-transfer"></i> Transferência
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filtro de status -->
    <div class="am-filters-bar" style="margin-bottom:20px;">
        <div class="am-filter-group">
            <label>STATUS</label>
            <div class="am-type-tabs">
                <a href="?<?= http_build_query(['tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort]) ?>"
                   class="am-type-tab <?= $filter_status==='' ? 'active' : '' ?>">Todos</a>
                <?php foreach (Transfer::getStatusOptions() as $key => $label): ?>
                <a href="?<?= http_build_query(['status' => $key, 'tech' => $filter_tech ?: '', 'date' => $filter_date, 'sort' => $filter_sort]) ?>"
                   class="am-type-tab <?= $filter_status===$key ? 'active' : '' ?>">
                    <span style="color:<?= Transfer::getStatusColor($key) ?>;font-weight:700;"><?= htmlspecialchars($label) ?></span>
                </a>
                <?php endforeach; ?>
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

    // Ordenação: mais antigos primeiro
    if ($filter_sort === 'old') {
        usort($transfers, fn($a, $b) => strtotime($a['date_creation']) <=> strtotime($b['date_creation']));
    }

    // Paginação
    $tc_page     = max(1, (int)($_GET['page'] ?? 1));
    $tc_per_page = 12;
    $tc_total    = count($transfers);
    $tc_pages    = max(1, (int)ceil($tc_total / $tc_per_page));
    $tc_page     = min($tc_page, $tc_pages);
    $transfers   = array_slice($transfers, ($tc_page - 1) * $tc_per_page, $tc_per_page);

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
                <a href="?<?= http_build_query(['status' => $filter_status, 'date' => $filter_date, 'sort' => $filter_sort]) ?>"
                   class="am-type-tab <?= !$filter_tech ? 'active' : '' ?>">
                    Todos
                </a>
                <?php foreach ($techs_in_transfers as $uid => $uname): ?>
                <a href="?<?= http_build_query(['status' => $filter_status, 'tech' => $uid, 'date' => $filter_date, 'sort' => $filter_sort]) ?>"
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
                    <a href="?<?= http_build_query(['status' => $filter_status, 'tech' => $filter_tech ?: '', 'sort' => 'recent']) ?>"
                       class="am-type-tab <?= $filter_sort !== 'old' && !$filter_date ? 'active' : '' ?>">
                        Mais recente
                    </a>
                    <a href="?<?= http_build_query(['status' => $filter_status, 'tech' => $filter_tech ?: '', 'sort' => 'old']) ?>"
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
                    <a href="?<?= http_build_query(['status' => $filter_status, 'tech' => $filter_tech ?: '', 'sort' => $filter_sort]) ?>"
                       class="am-type-tab active" title="Limpar filtro de data">
                        <i class="ti ti-x"></i> Limpar
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($transfers)): ?>
    <div class="am-empty-state"><i class="ti ti-clipboard-off"></i><p>Nenhuma transferência encontrada.</p></div>
    <?php else: ?>
    <div class="am-tc-grid">
        <?php foreach ($transfers as $t):
            $elapsed      = Transfer::getElapsedTime($t['date_creation']);
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
                          data-end="<?= $t['date_finalizado'] ?? '' ?>">
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

            <!-- Tempos por etapa -->
            <div class="am-tc-times">
                <?php
                $stages = [
                    ['label' => 'Pendente',   'from' => $t['date_pending'],    'to' => $t['date_manutencao']],
                    ['label' => 'Manutenção', 'from' => $t['date_manutencao'], 'to' => $t['date_pronto']],
                    ['label' => 'Pronto',     'from' => $t['date_pronto'],     'to' => $t['date_finalizado']],
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
                        onclick="amCancelar(<?= $t['id'] ?>)">
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
                        onclick="amCancelar(<?= $t['id'] ?>)">
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
        <?php endforeach; ?>
    </div>

    <?php if ($tc_pages > 1): ?>
    <div class="am-pagination">
        <div class="am-pagination-info"><?= $tc_total ?> transferência(s) — página <?= $tc_page ?> de <?= $tc_pages ?></div>
        <div class="am-pagination-pages">
            <?php
            $tc_qs = fn($p) => http_build_query([
                'status' => $filter_status,
                'tech'   => $filter_tech ?: '',
                'date'   => $filter_date,
                'sort'   => $filter_sort,
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

<script>
function amCancelar(id) {
    var num = String(id).padStart(4, '0');
    if (!confirm('Cancelar a transferência #' + num + '?\nOs ativos serão liberados e o chamado receberá um aviso.')) return;
    var motivo = prompt('Motivo do cancelamento da transferência #' + num + ' (obrigatório):');
    if (motivo === null) return;
    motivo = motivo.trim();
    if (!motivo) { alert('Informe o motivo para cancelar.'); return; }
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.form.php';
    f.innerHTML = '<input type="hidden" name="action" value="cancelar">'
        + '<input type="hidden" name="transfer_id" value="' + id + '">'
        + '<input type="hidden" name="motivo" value="' + motivo.replace(/"/g, '&quot;') + '">'
        + '<input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">';
    document.body.appendChild(f);
    f.submit();
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
    amClosePegarModal(); amCloseFinalizarModal();
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

// ---- Atualização suave via AJAX (sem F5 visual) ----
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

setInterval(amSoftRefresh, 10000);
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("am-theme-btn");
    var dark = localStorage.getItem("am_theme") === "dark";
    btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
});
</script>
<?php Html::footer(); ?>
