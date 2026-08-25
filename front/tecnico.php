<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus_tecnico', READ);

// Limpeza automática de PDFs após 7 dias (1x/dia, mantém dados para regenerar)
try { Transfer::maybeRunCleanup(); } catch (\Throwable $e) {}

global $CFG_GLPI;

$filter_status = $_GET['status'] ?? '';
$filter_tech   = (int)($_GET['tech'] ?? 0);
$filter_date   = $_GET['date'] ?? '';
$filter_sort   = $_GET['sort'] ?? 'recent';
$transfers     = Transfer::getAll($filter_status);

Html::header('Técnico', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'tecnico');
?>

<style>@keyframes amSpin{to{transform:rotate(360deg)}}</style>
<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Inventário</a>
        <i class="ti ti-chevron-right"></i>
        <span>Técnico</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-tools"></i><h2>Painel do Técnico</h2></div>
        <div style="display:flex;gap:8px;align-items:center;">
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
            $endForElapsed = $t['date_finalizado'] ?? $t['date_cancelado'] ?? null;
            // Para finalizado/cancelada congela o cronômetro na data de encerramento; demais seguem rodando
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
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("am-theme-btn");
    var dark = localStorage.getItem("am_theme") === "dark";
    btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
});
</script>
<?php Html::footer(); ?>
