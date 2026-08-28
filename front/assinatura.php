<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_assinatura', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_admin', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI, $DB;

$filter = $_GET['f'] ?? 'pendente'; // pendente | assinado | todos
if (!in_array($filter, ['pendente','assinado','todos'], true)) $filter = 'pendente';

$all = Transfer::getAll();
$pendentes = array_values(array_filter($all, fn($t) => Transfer::precisaAssinatura($t)));
$assinados = array_values(array_filter($all, fn($t) => Transfer::isAssinado($t)));

if ($filter === 'pendente') $transfers = $pendentes;
elseif ($filter === 'assinado') $transfers = $assinados;
else $transfers = $all;

// Ordena pendentes por data criação mais antigos primeiro (fila)
if ($filter === 'pendente') {
    usort($transfers, fn($a,$b) => strtotime($a['date_creation']) <=> strtotime($b['date_creation']));
} else {
    usort($transfers, fn($a,$b) => strtotime($b['assinatura_data'] ?? $b['date_creation']) <=> strtotime($a['assinatura_data'] ?? $a['date_creation']));
}

Html::header('Assinatura', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'assinatura');
?>

<style>
.am-sig-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;}
@media(max-width:768px){.am-sig-grid{grid-template-columns:1fr;}}
.am-sig-card{cursor:pointer;}
.am-sig-card:hover{border-color:#4f46e5;transform:translateY(-2px);}
.am-sig-badge-pend{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.am-sig-badge-ok{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
/* Modal assinatura */
#am-modal-assinatura .am-modal{max-width:560px;}
.am-doc-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0;}
.am-doc-btn{padding:18px;border:2px solid #e8eaf0;border-radius:14px;background:#f8f9fb;font-weight:800;font-size:1.05rem;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;transition:all .15s;}
.am-doc-btn:hover{border-color:#4f46e5;background:#eef2ff;}
.am-doc-btn.active{border-color:#4f46e5;background:#4f46e5;color:#fff;box-shadow:0 6px 20px rgba(79,70,229,.25);}
.am-doc-btn small{font-weight:600;font-size:.75rem;opacity:.7;}
.am-numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0;}
.am-numpad button{height:58px;border:none;border-radius:12px;background:#f3f4f6;font-size:1.4rem;font-weight:800;cursor:pointer;transition:all .12s;}
.am-numpad button:active{transform:scale(.96);background:#e5e7eb;}
.am-numpad button.am-numpad-action{background:#4f46e5;color:#fff;}
.am-numpad button.am-numpad-del{background:#fef2f2;color:#dc2626;}
.am-sig-display{background:#fff;border:2px solid #e8eaf0;border-radius:12px;padding:14px;text-align:center;font-size:1.6rem;font-weight:800;letter-spacing:.12em;min-height:58px;display:flex;align-items:center;justify-content:center;color:#1e1b4b;}
.am-sig-display.empty{color:#9ca3af;font-size:.95rem;letter-spacing:normal;font-weight:600;}
.am-sig-canvas-wrap{background:#fff;border:2px solid #e8eaf0;border-radius:12px;overflow:hidden;position:relative;touch-action:none;}
.am-sig-canvas{width:100%;height:220px;display:block;touch-action:none;cursor:crosshair;}
@media(max-width:768px){.am-sig-canvas{height:260px;}}
.am-sig-hint{font-size:.72rem;color:#9ca3af;text-align:center;margin-top:6px;}
</style>

<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Inventário</a>
        <i class="ti ti-chevron-right"></i>
        <span>Assinatura</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-signature"></i><h2>Assinatura de Termos</h2></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-clipboard-list"></i> Inventário</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-tools"></i> Técnico</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/dashboard.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-dashboard"></i> Dashboard</a>
        </div>
    </div>

    <div style="background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <i class="ti ti-info-circle" style="font-size:1.5rem;color:#1a73b5;"></i>
        <div style="font-size:.85rem;color:#1e3a5f;line-height:1.4;">
            <strong>Tablet / Celular — 2 etapas:</strong> toque no <strong style="color:#92400e;">Pendente</strong> → <strong>1. Recebedor:</strong> escolha <strong>RG/CPF</strong> → teclado → assine → <strong>Próximo</strong> → <strong>2. Técnico:</strong> repita RG/CPF + teclado + assinatura. As duas ficam no termo.
        </div>
    </div>

    <div class="am-filters-bar" style="margin-bottom:20px;">
        <div class="am-filter-group">
            <label>FILTRO</label>
            <div class="am-type-tabs">
                <a href="?f=pendente" class="am-type-tab <?= $filter==='pendente'?'active':'' ?>">⏳ Pendentes <span class="am-type-count"><?= count($pendentes) ?></span></a>
                <a href="?f=assinado" class="am-type-tab <?= $filter==='assinado'?'active':'' ?>">✅ Assinados <span class="am-type-count"><?= count($assinados) ?></span></a>
                <a href="?f=todos" class="am-type-tab <?= $filter==='todos'?'active':'' ?>">Todos <span class="am-type-count"><?= count($all) ?></span></a>
            </div>
        </div>
    </div>

    <?php if (empty($transfers)): ?>
        <div class="am-empty-state"><i class="ti ti-signature-off"></i><p><?= $filter==='pendente' ? 'Nenhum termo pendente de assinatura. 🎉' : ($filter==='assinado' ? 'Nenhum termo assinado ainda.' : 'Nenhuma transferência encontrada.') ?></p></div>
    <?php else: ?>
        <div class="am-sig-grid">
            <?php foreach ($transfers as $t):
                $isAssinado = Transfer::isAssinado($t);
                $isParcial  = Transfer::isAssinadoLegado($t) && !$isAssinado; // só recebedor, falta técnico
                $precisa   = Transfer::precisaAssinatura($t);
                $status_label = Transfer::getStatusOptions()[$t['status']] ?? $t['status'];
                if ($isAssinado) { $badgeClass='am-sig-badge-ok'; $badgeText='✍️ ASSINADO (2/2)'; }
                elseif ($isParcial) { $badgeClass='am-sig-badge-pend'; $badgeText='⚠️ FALTA TÉCNICO (1/2)'; }
                elseif ($precisa) { $badgeClass='am-sig-badge-pend'; $badgeText='⏳ AGUARDANDO ASSINATURA'; }
                else { $badgeClass=Transfer::getStatusBadgeClass($t['status']); $badgeText=$status_label; }
                $docMasked = $docMaskedTec = '';
                if (!empty($t['assinatura_document'])) $docMasked = Transfer::maskDocumento($t['assinatura_document_type'] ?? '', $t['assinatura_document'] ?? '');
                if (!empty($t['assinatura_tecnico_document'])) $docMaskedTec = Transfer::maskDocumento($t['assinatura_tecnico_document_type'] ?? '', $t['assinatura_tecnico_document'] ?? '');
            ?>
            <div class="am-tc-card am-sig-card" onclick="<?= $precisa ? "amOpenAssinaturaModal(".(int)$t['id'].")" : "void(0)" ?>" style="<?= $precisa ? 'border-color:#f59e0b;' : '' ?>">
                <div class="am-tc-card-header" style="border-left:4px solid <?= $isAssinado ? '#10b981' : ($precisa ? '#f59e0b' : Transfer::getStatusColor($t['status'])) ?>;">
                    <div>
                        <div style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">Transferência #<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></div>
                        <div style="font-weight:800;font-size:1rem;color:#1e2333;"><?= htmlspecialchars($t['origin_entity_name']) ?> → <?= htmlspecialchars($t['entity_dest_name']) ?></div>
                    </div>
                    <span class="am-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                </div>
                <div class="am-tc-card-body">
                    <div class="am-tc-info-row"><i class="ti ti-box"></i><span><?= (int)$t['items_count'] ?> ativo(s)</span></div>
                    <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span>Criada em <?= date('d/m/Y H:i', strtotime($t['date_creation'])) ?></span></div>
                    <?php if (!empty($t['date_pronto'])): ?><div class="am-tc-info-row"><i class="ti ti-check"></i><span>Pronto em <?= date('d/m/Y H:i', strtotime($t['date_pronto'])) ?></span></div><?php endif; ?>
                    <?php if (!empty($t['date_finalizado'])): ?><div class="am-tc-info-row"><i class="ti ti-flag-check"></i><span>Finalizada em <?= date('d/m/Y H:i', strtotime($t['date_finalizado'])) ?></span></div><?php endif; ?>
                    <?php if ($isAssinado): ?>
                        <div style="margin-top:8px;background:#f0fdf4;border:1px solid #a7f3d0;border-radius:8px;padding:8px 10px;">
                            <div style="font-size:.75rem;font-weight:700;color:#065f46;">✍️ Assinado — 2/2 em <?= date('d/m/Y H:i', strtotime($t['assinatura_tecnico_data'] ?? $t['assinatura_data'])) ?></div>
                            <div style="font-size:.78rem;color:#374151;">📥 Recebedor: <?= htmlspecialchars($t['assinatura_nome'] ?: '—') ?> — <?= htmlspecialchars($t['assinatura_document_type'] ?? '') ?> <?= htmlspecialchars($docMasked) ?></div>
                            <div style="font-size:.78rem;color:#374151;">🔧 Técnico: <?= htmlspecialchars($t['assinatura_tecnico_nome'] ?: '—') ?> — <?= htmlspecialchars($t['assinatura_tecnico_document_type'] ?? '') ?> <?= htmlspecialchars($docMaskedTec) ?></div>
                        </div>
                    <?php elseif ($isParcial): ?>
                        <div style="margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
                            <div style="font-size:.75rem;font-weight:700;color:#92400e;">⚠️ Parcial — falta técnico</div>
                            <div style="font-size:.78rem;color:#374151;">📥 Recebedor: <?= htmlspecialchars($t['assinatura_nome'] ?: '—') ?> <?= htmlspecialchars($docMasked) ?> — <?= date('d/m/Y H:i', strtotime($t['assinatura_data'])) ?></div>
                            <div style="font-size:.70rem;color:#9ca3af;">Toque para completar assinatura do técnico</div>
                        </div>
                    <?php elseif ($precisa): ?>
                        <div style="margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;font-size:.78rem;color:#92400e;text-align:center;font-weight:600;">
                            👆 Toque aqui para assinar no tablet
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($t['reason'])): ?><div class="am-tc-reason"><?= htmlspecialchars(mb_substr($t['reason'],0,90)) ?></div><?php endif; ?>
                </div>
                <div class="am-tc-card-footer">
                    <?php if ($precisa): ?>
                        <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;" onclick="event.stopPropagation();amOpenAssinaturaModal(<?= (int)$t['id'] ?>)"><i class="ti ti-signature"></i> Assinar</button>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;" title="Ver termo (ainda sem assinatura)"><i class="ti ti-file-type-pdf"></i></a>
                    <?php elseif ($isAssinado): ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="flex:1;"><i class="ti ti-file-type-pdf"></i> PDF Assinado</a>
                        <button class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;flex:1;" onclick="window.open('<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=pronto','_blank')"><i class="ti ti-printer"></i> Imprimir no PC</button>
                    <?php else: ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=<?= $t['status']==='finalizado'?'pronto':'transfer' ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;"><i class="ti ti-file-type-pdf"></i> Ver Termo</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Assinatura Dual (Recebedor + Técnico) -->
<div id="am-modal-assinatura" class="am-modal-overlay" style="z-index:10001;">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:560px;max-height:92vh;display:flex;flex-direction:column;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#1a73b5,#4f46e5);">
            <div class="am-modal-title"><i class="ti ti-signature"></i><span id="am-sig-modal-title">Assinatura do Termo</span></div>
            <button class="am-modal-close" onclick="amCloseAssinaturaModal()"><i class="ti ti-x"></i></button>
        </div>
        <!-- Progresso 2 etapas -->
        <div style="background:#f0f2f8;border-bottom:1px solid #e8eaf0;padding:8px 16px;display:flex;align-items:center;gap:10px;font-size:.78rem;">
            <div style="display:flex;align-items:center;gap:6px;flex:1;">
                <span id="am-sig-prog-rec" style="display:flex;align-items:center;gap:5px;background:#4f46e5;color:#fff;padding:4px 10px;border-radius:99px;font-weight:700;"><i class="ti ti-user"></i> 1. Recebedor</span>
                <span style="flex:1;height:2px;background:#e8eaf0;border-radius:99px;overflow:hidden;"><span id="am-sig-prog-bar" style="display:block;height:100%;width:0%;background:#4f46e5;transition:width .3s;"></span></span>
                <span id="am-sig-prog-tec" style="display:flex;align-items:center;gap:5px;background:#e8eaf0;color:#9ca3af;padding:4px 10px;border-radius:99px;font-weight:700;"><i class="ti ti-tool"></i> 2. Técnico</span>
            </div>
        </div>

        <!-- Step Nome -->
        <div id="am-sig-step-nome" class="am-modal-body" style="display:block;">
            <div style="text-align:center;margin-bottom:12px;">
                <div id="am-sig-nome-badge" style="display:inline-flex;align-items:center;gap:5px;background:#4f46e5;color:#fff;padding:3px 10px;border-radius:99px;font-size:.70rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px;"><i class="ti ti-user"></i> <span id="am-sig-nome-badge-text">RECEBEDOR</span></div>
                <div id="am-sig-nome-title" style="font-weight:800;font-size:1rem;color:#1e1b4b;">Nome do recebedor</div>
                <div id="am-sig-nome-sub" style="font-size:.82rem;color:#6b7280;">Quem está recebendo os equipamentos</div>
            </div>
            <label class="am-form-label">Nome completo <span class="am-required">*</span> <small style="font-weight:400;text-transform:none;">(como no documento)</small></label>
            <input type="text" id="am-sig-nome" class="am-input" placeholder="Ex: João da Silva" style="margin-bottom:12px;font-size:1.05rem;" autocomplete="off">
            <div style="background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#1e3a5f;margin-bottom:14px;">
                <i class="ti ti-info-circle" style="color:#1a73b5;"></i> <span id="am-sig-nome-info">Digite o nome do <strong>recebedor</strong> antes de escolher o documento.</span>
            </div>
            <button type="button" class="am-btn" style="width:100%;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:12px;" onclick="amSigNextFromNome()"><i class="ti ti-arrow-right"></i> Próximo: Documento</button>
            <div id="am-sig-nome-back-wrap" style="display:none;margin-top:10px;text-align:center;">
                <button type="button" class="am-btn am-btn-secondary" style="padding:6px 14px;font-size:.78rem;" onclick="amSigBackToPrevStage()"><i class="ti ti-arrow-left"></i> Voltar</button>
            </div>
        </div>

        <!-- Step 1: Escolha RG/CPF -->
        <div id="am-sig-step1" class="am-modal-body" style="display:none;">
            <div style="text-align:center;margin-bottom:12px;">
                <div id="am-sig-step1-badge" style="display:inline-flex;align-items:center;gap:5px;background:#4f46e5;color:#fff;padding:3px 10px;border-radius:99px;font-size:.70rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px;"><i class="ti ti-user"></i> <span id="am-sig-step1-badge-text">RECEBEDOR</span></div>
                <div id="am-sig-step1-title" style="font-weight:800;font-size:1rem;color:#1e1b4b;">Documento do recebedor</div>
                <div id="am-sig-step1-sub" style="font-size:.82rem;color:#6b7280;">Escolha o tipo de documento</div>
            </div>
            <div class="am-doc-choice">
                <button type="button" class="am-doc-btn" data-type="RG" onclick="amSigChooseDoc('RG')">
                    <i class="ti ti-id" style="font-size:1.6rem;"></i> RG
                    <small>Registro Geral</small>
                </button>
                <button type="button" class="am-doc-btn" data-type="CPF" onclick="amSigChooseDoc('CPF')">
                    <i class="ti ti-id-badge-2" style="font-size:1.6rem;"></i> CPF
                    <small>11 dígitos</small>
                </button>
            </div>
            <div id="am-sig-step1-hint" style="text-align:center;font-size:.78rem;color:#9ca3af;margin-top:8px;">Selecione RG ou CPF</div>
            <div style="margin-top:14px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#1e3a5f;">
                <i class="ti ti-shield-check" style="color:#1a73b5;"></i> <span id="am-sig-step1-info">Documento do <strong>recebedor</strong> será impresso no termo.</span>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;">
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigBackToNome()"><i class="ti ti-arrow-left"></i> Voltar</button>
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;background:#fef2f2;color:#dc2626;border-color:#fecaca;" onclick="amCloseAssinaturaModal()"><i class="ti ti-x"></i> Cancelar</button>
            </div>
            <div id="am-sig-step1-back-wrap" style="display:none;margin-top:12px;text-align:center;">
                <button type="button" class="am-btn am-btn-secondary" style="padding:6px 14px;font-size:.78rem;" onclick="amSigBackToRecebedor()"><i class="ti ti-arrow-left"></i> Voltar ao Recebedor</button>
            </div>
        </div>

        <!-- Step 2: Teclado numérico + Assinatura (juntos) -->
        <div id="am-sig-step2" class="am-modal-body" style="display:none;flex:1;overflow-y:auto;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:8px;">
                <button type="button" class="am-btn am-btn-secondary" style="padding:6px 10px;font-size:.78rem;" onclick="amSigBackToDoc()"><i class="ti ti-arrow-left"></i> Voltar</button>
                <div style="display:flex;gap:6px;align-items:center;">
                    <span id="am-sig-stage-badge" style="background:#4f46e5;color:#fff;padding:4px 10px;border-radius:8px;font-weight:700;font-size:.78rem;">RECEBEDOR</span>
                    <span id="am-sig-doc-badge" style="background:#4f46e5;color:#fff;padding:4px 10px;border-radius:8px;font-weight:700;font-size:.78rem;">CPF</span>
                </div>
            </div>
            <div style="background:#f8f9fb;border:1px solid #e8eaf0;border-radius:10px;padding:8px 12px;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                <i class="ti ti-user" style="color:#4f46e5;"></i>
                <span style="font-size:.85rem;font-weight:700;color:#1e1b4b;" id="am-sig-step2-nome-preview">—</span>
            </div>

            <label class="am-form-label">Número do documento <span class="am-required">*</span> <small style="font-weight:400;text-transform:none;letter-spacing:0;">(<span id="am-sig-doc-hint">11 dígitos</span>)</small> — <span id="am-sig-doc-owner" style="color:#4f46e5;">recebedor</span></label>
            <div id="am-sig-display" class="am-sig-display empty">Toque no teclado abaixo</div>
            <input type="hidden" id="am-sig-doc-value">

            <div class="am-numpad">
                <button type="button" onclick="amSigPress('1')">1</button>
                <button type="button" onclick="amSigPress('2')">2</button>
                <button type="button" onclick="amSigPress('3')">3</button>
                <button type="button" onclick="amSigPress('4')">4</button>
                <button type="button" onclick="amSigPress('5')">5</button>
                <button type="button" onclick="amSigPress('6')">6</button>
                <button type="button" onclick="amSigPress('7')">7</button>
                <button type="button" onclick="amSigPress('8')">8</button>
                <button type="button" onclick="amSigPress('9')">9</button>
                <button type="button" class="am-numpad-del" onclick="amSigPress('del')"><i class="ti ti-backspace"></i></button>
                <button type="button" onclick="amSigPress('0')">0</button>
                <button type="button" class="am-numpad-action" onclick="amSigPress('ok')"><i class="ti ti-check"></i></button>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigClear()"><i class="ti ti-trash"></i> Limpar</button>
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigBackspace()"><i class="ti ti-backspace"></i> Apagar</button>
            </div>

            <label class="am-form-label">Assinatura <span id="am-sig-canvas-label">do recebedor</span> <span class="am-required">*</span> <small style="font-weight:400;text-transform:none;letter-spacing:0;">use dedo/caneta — já com número acima</small></label>
            <div class="am-sig-canvas-wrap">
                <canvas id="am-sig-canvas" class="am-sig-canvas"></canvas>
            </div>
            <div class="am-sig-hint">Desenhe acima com o dedo/caneta. Use Limpar para refazer.</div>
            <div style="display:flex;gap:8px;margin-top:10px;">
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigClearCanvas()"><i class="ti ti-eraser"></i> Limpar assinatura</button>
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;background:#fef2f2;color:#dc2626;border-color:#fecaca;" onclick="amCloseAssinaturaModal()"><i class="ti ti-x"></i> Cancelar</button>
            </div>
        </div>

        <div id="am-sig-footer" class="am-modal-footer" style="display:none;">
            <button type="button" id="am-sig-next-btn" class="am-btn" style="display:none;flex:1;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amSigNextToTecnico()"><i class="ti ti-arrow-right"></i> Próximo: Assinatura do Técnico →</button>
            <button type="button" id="am-sig-save-btn" class="am-btn" style="display:none;flex:1;background:linear-gradient(135deg,#10b981,#059669);color:#fff;" onclick="amSigSave()"><i class="ti ti-device-floppy"></i> Confirmar e Assinar (2 assinaturas)</button>
        </div>
    </div>
</div>

<script>
const amCsrfToken = "<?= Session::getNewCSRFToken() ?>";
let amSigTransferId = 0;
let amSigDocType = '';
let amSigDocNumber = '';
let amSigStage = 'rec_nome'; // rec_nome | rec_doc | rec_sign | tec_nome | tec_doc | tec_sign
let amSigRecDocType = '', amSigRecDocNumber = '', amSigRecNome = '', amSigRecImage = '';
let amSigTecDocType = '', amSigTecDocNumber = '', amSigTecNome = '', amSigTecImage = '';
let amSigCanvas = null, amSigCtx = null, amSigDrawing = false, amSigHasDrawn = false;

function amOpenAssinaturaModal(transferId) {
    amSigTransferId = transferId;
    amSigDocType = ''; amSigDocNumber = ''; amSigHasDrawn = false;
    amSigRecDocType=''; amSigRecDocNumber=''; amSigRecNome=''; amSigRecImage='';
    amSigTecDocType=''; amSigTecDocNumber=''; amSigTecNome=''; amSigTecImage='';
    amSigStage='rec_nome';
    document.getElementById('am-sig-step-nome').style.display = 'block';
    document.getElementById('am-sig-step1').style.display = 'none';
    document.getElementById('am-sig-step2').style.display = 'none';
    document.getElementById('am-sig-footer').style.display = 'none';
    document.getElementById('am-sig-next-btn').style.display='none';
    document.getElementById('am-sig-save-btn').style.display='none';
    document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('am-sig-modal-title').textContent = 'Assinatura — Transferência #' + String(transferId).padStart(4,'0');
    document.getElementById('am-sig-doc-value').value = '';
    document.getElementById('am-sig-nome').value = '';
    amSigUpdateStageUI();
    amSigUpdateDisplay();
    setTimeout(()=>amSigClearCanvas(), 80);
    document.getElementById('am-modal-assinatura').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(()=>{ document.getElementById('am-sig-nome').focus(); }, 250);
    setTimeout(amSigInitCanvas, 120);
}
function amCloseAssinaturaModal() {
    document.getElementById('am-modal-assinatura').classList.remove('open');
    document.body.style.overflow = '';
}
function amSigUpdateStageUI(){
    const isRec = amSigStage.startsWith('rec');
    const isNome = amSigStage.endsWith('nome');
    const isDoc = amSigStage.endsWith('doc');
    const progRec = document.getElementById('am-sig-prog-rec');
    const progTec = document.getElementById('am-sig-prog-tec');
    const progBar = document.getElementById('am-sig-prog-bar');
    const badgeNome = document.getElementById('am-sig-nome-badge-text');
    const nomeTitle = document.getElementById('am-sig-nome-title');
    const nomeSub = document.getElementById('am-sig-nome-sub');
    const nomeInfo = document.getElementById('am-sig-nome-info');
    const nomeBackWrap = document.getElementById('am-sig-nome-back-wrap');
    const badgeText = document.getElementById('am-sig-step1-badge-text');
    const stepTitle = document.getElementById('am-sig-step1-title');
    const stepSub = document.getElementById('am-sig-step1-sub');
    const stepInfo = document.getElementById('am-sig-step1-info');
    const backWrap = document.getElementById('am-sig-step1-back-wrap');
    const stageBadge = document.getElementById('am-sig-stage-badge');
    const docOwner = document.getElementById('am-sig-doc-owner');
    const canvasLabel = document.getElementById('am-sig-canvas-label');
    const nomePreview = document.getElementById('am-sig-step2-nome-preview');
    // progress widths: rec_nome 0%, rec_doc 15%, rec_sign 45%, tec_nome 50%, tec_doc 65%, tec_sign 90%
    const widths = {rec_nome:'0%', rec_doc:'15%', rec_sign:'45%', tec_nome:'50%', tec_doc:'65%', tec_sign:'90%'};
    if(progBar) progBar.style.width = widths[amSigStage] || '0%';
    if(isRec){
        if(progRec) { progRec.style.background='#4f46e5'; progRec.style.color='#fff'; }
        if(progTec) { progTec.style.background='#e8eaf0'; progTec.style.color='#9ca3af'; }
        if(badgeNome) badgeNome.textContent='RECEBEDOR';
        if(nomeTitle) nomeTitle.textContent='Nome do recebedor';
        if(nomeSub) nomeSub.textContent='Quem está recebendo os equipamentos';
        if(nomeInfo) nomeInfo.innerHTML='Digite o nome do <strong>recebedor</strong> antes de escolher o documento.';
        if(nomeBackWrap) nomeBackWrap.style.display='none';
        if(badgeText) badgeText.textContent='RECEBEDOR';
        if(stepTitle) stepTitle.textContent='Documento do recebedor';
        if(stepSub) stepSub.textContent='Escolha o tipo de documento do recebedor';
        if(stepInfo) stepInfo.innerHTML='Documento do <strong>recebedor</strong> será impresso no termo.';
        if(backWrap) backWrap.style.display='none';
        if(stageBadge) { stageBadge.textContent='RECEBEDOR'; stageBadge.style.background='#4f46e5'; }
        if(docOwner) { docOwner.textContent='recebedor'; docOwner.style.color='#4f46e5'; }
        if(canvasLabel) canvasLabel.textContent='do recebedor';
        const hint = document.getElementById('am-sig-step1-hint'); if(hint) hint.textContent='Selecione RG ou CPF do recebedor';
    } else {
        if(progRec) { progRec.style.background='#d1fae5'; progRec.style.color='#065f46'; }
        if(progTec) { progTec.style.background='#4f46e5'; progTec.style.color='#fff'; }
        if(badgeNome) badgeNome.textContent='TÉCNICO';
        if(nomeTitle) nomeTitle.textContent='Nome do técnico';
        if(nomeSub) nomeSub.textContent='Quem está entregando / responsável técnico';
        if(nomeInfo) nomeInfo.innerHTML='Digite o nome do <strong>técnico</strong>.';
        if(nomeBackWrap) nomeBackWrap.style.display='block';
        if(badgeText) badgeText.textContent='TÉCNICO';
        if(stepTitle) stepTitle.textContent='Documento do técnico';
        if(stepSub) stepSub.textContent='Escolha o tipo de documento do técnico';
        if(stepInfo) stepInfo.innerHTML='Documento do <strong>técnico</strong> será impresso no termo.';
        if(backWrap) backWrap.style.display='block';
        if(stageBadge) { stageBadge.textContent='TÉCNICO'; stageBadge.style.background='#059669'; }
        if(docOwner) { docOwner.textContent='técnico'; docOwner.style.color='#059669'; }
        if(canvasLabel) canvasLabel.textContent='do técnico';
        const hint = document.getElementById('am-sig-step1-hint'); if(hint) hint.textContent='Selecione RG ou CPF do técnico';
    }
    const hintEl = document.getElementById('am-sig-step1-hint'); if(hintEl) hintEl.style.color='#9ca3af';
    if(nomePreview){
        const curNome = isRec ? amSigRecNome : amSigTecNome;
        const inputNome = document.getElementById('am-sig-nome').value.trim();
        // mostra preview do nome já digitado
        if(isRec && amSigStage==='rec_sign') nomePreview.textContent = amSigRecNome || inputNome || '—';
        else if(!isRec && amSigStage==='tec_sign') nomePreview.textContent = amSigTecNome || inputNome || '—';
        else nomePreview.textContent = curNome || '—';
    }
    const nextBtn = document.getElementById('am-sig-next-btn');
    const saveBtn = document.getElementById('am-sig-save-btn');
    if(amSigStage==='rec_sign'){ nextBtn.style.display='flex'; saveBtn.style.display='none'; }
    else if(amSigStage==='tec_sign'){ nextBtn.style.display='none'; saveBtn.style.display='flex'; }
    else { nextBtn.style.display='none'; saveBtn.style.display='none'; }
}
function amSigNextFromNome(){
    const nome = document.getElementById('am-sig-nome').value.trim();
    if(nome.length < 2){ alert('Digite o nome completo (mín. 2 letras).'); document.getElementById('am-sig-nome').focus(); return; }
    const isRec = amSigStage==='rec_nome';
    if(isRec){ amSigRecNome = nome; amSigStage='rec_doc'; }
    else { amSigTecNome = nome; amSigStage='tec_doc'; }
    document.getElementById('am-sig-step-nome').style.display='none';
    document.getElementById('am-sig-step1').style.display='block';
    document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.remove('active'));
    // se já tinha doc escolhido, reativa
    const curDoc = isRec ? amSigRecDocType : amSigTecDocType;
    if(curDoc) document.querySelectorAll('.am-doc-btn').forEach(b=>{ if(b.dataset.type===curDoc) b.classList.add('active'); });
    amSigUpdateStageUI();
}
function amSigBackToNome(){
    const isRec = amSigStage==='rec_doc';
    amSigStage = isRec ? 'rec_nome' : 'tec_nome';
    document.getElementById('am-sig-step1').style.display='none';
    document.getElementById('am-sig-step-nome').style.display='block';
    document.getElementById('am-sig-nome').value = isRec ? amSigRecNome : amSigTecNome;
    amSigUpdateStageUI();
    setTimeout(()=>document.getElementById('am-sig-nome').focus(), 100);
}
function amSigBackFromNome(){
    // de tec_nome volta para rec_sign
    if(amSigStage==='tec_nome'){
        amSigStage='rec_sign';
        document.getElementById('am-sig-step-nome').style.display='none';
        document.getElementById('am-sig-step2').style.display='block';
        document.getElementById('am-sig-footer').style.display='flex';
        amSigDocType = amSigRecDocType; amSigDocNumber = amSigRecDocNumber;
        document.getElementById('am-sig-doc-value').value = amSigDocNumber;
        amSigUpdateStageUI(); amSigUpdateDisplay();
        setTimeout(amSigInitCanvas, 100);
    }
}
function amSigBackToPrevStage(){
    if(amSigStage==='tec_nome') amSigBackFromNome();
}
function amSigChooseDoc(type) {
    amSigDocType = type;
    if(amSigStage==='rec_doc') amSigRecDocType=type; else amSigTecDocType=type;
    document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.toggle('active', b.dataset.type===type));
    const hint = document.getElementById('am-sig-step1-hint');
    if(hint){ hint.textContent = type + ' selecionado — abrindo teclado + assinatura...'; hint.style.color='#059669'; }
    setTimeout(()=>amSigGoToKeypad(), 220);
}
function amSigGoToKeypad() {
    if (!amSigDocType) return;
    const isRec = amSigStage==='rec_doc';
    amSigStage = isRec ? 'rec_sign' : 'tec_sign';
    document.getElementById('am-sig-step-nome').style.display='none';
    document.getElementById('am-sig-step1').style.display = 'none';
    document.getElementById('am-sig-step2').style.display = 'block';
    document.getElementById('am-sig-footer').style.display = 'flex';
    document.getElementById('am-sig-doc-badge').textContent = amSigDocType;
    document.getElementById('am-sig-doc-badge').style.background = amSigDocType==='CPF' ? '#4f46e5' : '#059669';
    document.getElementById('am-sig-doc-hint').textContent = amSigDocType==='CPF' ? '11 dígitos' : '5 a 12 dígitos';
    // restaura doc se editando
    if(isRec && amSigRecDocNumber && amSigDocType===amSigRecDocType){
        amSigDocNumber = amSigRecDocNumber;
    } else if(!isRec && amSigTecDocNumber && amSigDocType===amSigTecDocType){
        amSigDocNumber = amSigTecDocNumber;
    } else {
        amSigDocNumber = '';
        amSigHasDrawn=false;
    }
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateStageUI();
    amSigUpdateDisplay();
    amSigClearCanvas();
    setTimeout(amSigInitCanvas, 100);
}
function amSigBackToDoc() {
    const isRec = amSigStage==='rec_sign';
    // guarda doc atual
    if(isRec) amSigRecDocNumber = amSigDocNumber;
    else amSigTecDocNumber = amSigDocNumber;
    amSigStage = isRec ? 'rec_doc' : 'tec_doc';
    document.getElementById('am-sig-step2').style.display = 'none';
    document.getElementById('am-sig-footer').style.display = 'none';
    document.getElementById('am-sig-step1').style.display = 'block';
    amSigUpdateStageUI();
    document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.toggle('active', b.dataset.type===amSigDocType));
}
function amSigBackToRecebedor(){
    amSigStage='rec_sign';
    amSigDocType = amSigRecDocType;
    amSigDocNumber = amSigRecDocNumber;
    document.getElementById('am-sig-step-nome').style.display='none';
    document.getElementById('am-sig-step1').style.display='none';
    document.getElementById('am-sig-step2').style.display='block';
    document.getElementById('am-sig-footer').style.display='flex';
    document.getElementById('am-sig-doc-badge').textContent = amSigDocType;
    document.getElementById('am-sig-doc-badge').style.background = amSigDocType==='CPF' ? '#4f46e5' : '#059669';
    document.getElementById('am-sig-doc-hint').textContent = amSigDocType==='CPF' ? '11 dígitos' : '5 a 12 dígitos';
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateStageUI();
    amSigUpdateDisplay();
    setTimeout(amSigInitCanvas, 100);
    amSigHasDrawn = false;
    amSigClearCanvas();
}
function amSigNextToTecnico(){
    if (!amSigDocType) return alert('Selecione RG ou CPF do recebedor.');
    if (amSigDocType==='CPF' && amSigDocNumber.length!==11) return alert('CPF do recebedor precisa de 11 dígitos.');
    if (amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)) return alert('RG do recebedor precisa de 5 a 12 dígitos.');
    if (!amSigHasDrawn) return alert('Faça a assinatura do recebedor com dedo/caneta no quadro.');
    const c = document.getElementById('am-sig-canvas');
    const dataUrl = c.toDataURL('image/png');
    if (!dataUrl || dataUrl.length < 500) return alert('Assinatura do recebedor vazia — desenhe novamente.');
    amSigRecDocType = amSigDocType;
    amSigRecDocNumber = amSigDocNumber;
    // nome já salvo em amSigRecNome via etapa nome
    amSigRecImage = dataUrl;
    // Avança para técnico — nome
    amSigStage='tec_nome';
    amSigDocType=''; amSigDocNumber=''; amSigHasDrawn=false;
    document.getElementById('am-sig-step2').style.display='none';
    document.getElementById('am-sig-footer').style.display='none';
    document.getElementById('am-sig-step-nome').style.display='block';
    document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('am-sig-doc-value').value='';
    document.getElementById('am-sig-nome').value = amSigTecNome;
    amSigUpdateStageUI();
    amSigUpdateDisplay();
    setTimeout(()=>{ document.getElementById('am-sig-nome').focus(); amSigClearCanvas(); }, 80);
    setTimeout(amSigInitCanvas, 120);
}
function amSigPress(k) {
    if (k==='del') { amSigBackspace(); return; }
    if (k==='ok')  { amSigCheckDocComplete(); return; }
    if (amSigDocType==='CPF' && amSigDocNumber.length >= 11) return;
    if (amSigDocType==='RG' && amSigDocNumber.length >= 12) return;
    amSigDocNumber += k;
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateDisplay();
}
function amSigBackspace() {
    amSigDocNumber = amSigDocNumber.slice(0,-1);
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateDisplay();
}
function amSigClear() {
    amSigDocNumber = '';
    document.getElementById('am-sig-doc-value').value = '';
    amSigUpdateDisplay();
}
function amSigMaskDoc(raw, type) {
    const d = raw.replace(/\D/g,'');
    if (type==='CPF' && d.length===11) return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
    if (type==='RG' && d.length>2) {
        if (d.length<=7) return d;
        return d.slice(0,2)+'.'+d.slice(2,5)+'.'+d.slice(5,8)+'-'+d.slice(8);
    }
    return d;
}
function amSigUpdateDisplay() {
    const el = document.getElementById('am-sig-display');
    if (!amSigDocNumber) {
        el.textContent = 'Toque no teclado abaixo';
        el.classList.add('empty');
        return;
    }
    el.classList.remove('empty');
    el.textContent = amSigMaskDoc(amSigDocNumber, amSigDocType) || amSigDocNumber;
    const need = amSigDocType==='CPF' ? 11 : 5;
    const max = amSigDocType==='CPF' ? 11 : 12;
    if (amSigDocNumber.length < need) el.style.borderColor = '#f59e0b';
    else if (amSigDocNumber.length <= max) el.style.borderColor = '#10b981';
    else el.style.borderColor = '#e8eaf0';
    if (amSigDocType==='CPF') el.style.borderColor = amSigDocNumber.length===11 ? '#10b981' : '#f59e0b';
}
function amSigCheckDocComplete() {
    if (amSigDocType==='CPF' && amSigDocNumber.length!==11) { alert('CPF precisa de 11 dígitos.'); return; }
    if (amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)) { alert('RG precisa de 5 a 12 dígitos.'); return; }
    // nome já coletado na etapa anterior, foca no canvas (assinatura)
    const c = document.getElementById('am-sig-canvas');
    if(c) c.scrollIntoView({behavior:'smooth', block:'center'});
}
function amSigInitCanvas() {
    const c = document.getElementById('am-sig-canvas');
    if (!c) return;
    const rect = c.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    c.width = rect.width * dpr;
    c.height = rect.height * dpr;
    const ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 2.2;
    ctx.strokeStyle = '#1e1b4b';
    amSigCanvas = c; amSigCtx = ctx;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0,0,rect.width,rect.height);
    // Evita duplicar listeners ao reabrir
    if (c.dataset.bound==='1') return;
    c.dataset.bound='1';
    let getPos = (e) => {
        const r = c.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return {x: t.clientX - r.left, y: t.clientY - r.top};
    };
    let last = null;
    const start = (e)=>{ e.preventDefault(); amSigDrawing=true; last=getPos(e); amSigHasDrawn=true; };
    const move = (e)=>{ if(!amSigDrawing) return; e.preventDefault(); const p=getPos(e); ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke(); last=p; };
    const end = ()=>{ amSigDrawing=false; last=null; };
    c.addEventListener('mousedown', start);
    c.addEventListener('mousemove', move);
    c.addEventListener('mouseup', end);
    c.addEventListener('mouseleave', end);
    c.addEventListener('touchstart', start, {passive:false});
    c.addEventListener('touchmove', move, {passive:false});
    c.addEventListener('touchend', end);
}
function amSigClearCanvas() {
    const c = document.getElementById('am-sig-canvas');
    if (!c || !amSigCtx) { setTimeout(amSigClearCanvas, 50); return; }
    const rect = c.getBoundingClientRect();
    amSigCtx.fillStyle = '#fff';
    amSigCtx.fillRect(0,0,rect.width,rect.height);
    amSigHasDrawn = false;
}
async function amSigSave() {
    if (!amSigTransferId) return alert('Transferência inválida.');
    if(amSigStage==='rec_sign') return amSigNextToTecnico();
    if(amSigStage==='rec_nome' || amSigStage==='rec_doc') return alert('Complete primeiro a assinatura do recebedor (nome → documento → número + assinatura).');
    if(amSigStage==='tec_nome' || amSigStage==='tec_doc') return alert('Selecione o documento e preencha número + assinatura do técnico.');
    if (!amSigRecImage) return alert('Assinatura do recebedor não capturada — volte e assine.');
    if (amSigDocType==='CPF' && amSigDocNumber.length!==11) return alert('CPF do técnico precisa de 11 dígitos.');
    if (amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)) return alert('RG do técnico precisa de 5 a 12 dígitos.');
    if (!amSigHasDrawn) return alert('Faça a assinatura do técnico com dedo/caneta no quadro.');
    const c = document.getElementById('am-sig-canvas');
    const tecDataUrl = c.toDataURL('image/png');
    if (!tecDataUrl || tecDataUrl.length < 500) return alert('Assinatura do técnico vazia — desenhe novamente.');
    amSigTecDocType = amSigDocType;
    amSigTecDocNumber = amSigDocNumber;
    // nome já salvo em amSigTecNome na etapa tec_nome
    amSigTecImage = tecDataUrl;
    const btn = document.getElementById('am-sig-save-btn');
    const old = btn.innerHTML; btn.disabled=true; btn.innerHTML='<i class="ti ti-loader-2" style="animation:amSpin .8s linear infinite;display:inline-block;"></i> Salvando...';
    const nextBtn = document.getElementById('am-sig-next-btn'); if(nextBtn) nextBtn.disabled=true;
    const base = (window.location.pathname.split('/plugins/assetmgrstatus')[0] || '') + '/plugins/assetmgrstatus';
    async function trySave(url, useFormData) {
        const headers = {'X-Glpi-Csrf-Token': amCsrfToken, 'X-Requested-With': 'XMLHttpRequest'};
        let body;
        if (useFormData) {
            const fd = new FormData();
            fd.append('_glpi_csrf_token', amCsrfToken);
            fd.append('transfer_id', String(amSigTransferId));
            fd.append('doc_type', amSigRecDocType);
            fd.append('doc_number', amSigRecDocNumber);
            fd.append('nome', amSigRecNome);
            fd.append('image', amSigRecImage);
            fd.append('tec_doc_type', amSigTecDocType);
            fd.append('tec_doc_number', amSigTecDocNumber);
            fd.append('tec_nome', amSigTecNome);
            fd.append('tec_image', amSigTecImage);
            body = fd;
        } else {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify({transfer_id: amSigTransferId, doc_type: amSigRecDocType, doc_number: amSigRecDocNumber, nome: amSigRecNome, image: amSigRecImage, tec_doc_type: amSigTecDocType, tec_doc_number: amSigTecDocNumber, tec_nome: amSigTecNome, tec_image: amSigTecImage, _glpi_csrf_token: amCsrfToken});
        }
        return fetch(url, {method:'POST', credentials:'same-origin', headers: headers, body: body});
    }
    try {
        let res = await trySave(base + '/ajax/assinatura_save.php', true);
        let text = await res.text();
        let j = null; try { j = JSON.parse(text); } catch(e) {}
        if ((!j && res.status===403) || (!j && res.status===404)) {
            console.warn('ajax 403 HTML, tentando front FormData', text.slice(0,300));
            res = await trySave(base + '/front/assinatura.form.php', true);
            text = await res.text();
            try { j = JSON.parse(text); } catch(e) { j=null; }
        }
        if (!j) {
            try { j = JSON.parse(text); } catch(parseErr) {
                console.warn('FormData falhou, tentando JSON puro');
                res = await trySave(base + '/ajax/assinatura_save.php', false);
                const t2 = await res.text();
                try { j = JSON.parse(t2); text=t2; } catch(e2) {
                    console.error('Resposta não-JSON:', text, t2);
                    alert('❌ Erro servidor (resposta não-JSON, HTTP ' + res.status + '):\n' + (text||t2).slice(0,1500).replace(/<[^>]*>/g,' ').trim().substring(0,700) + '\n\nDica: teste ' + base + '/ajax/assinatura_save.php?ping=1');
                    btn.disabled=false; btn.innerHTML=old; if(nextBtn) nextBtn.disabled=false;
                    return;
                }
            }
        }
        if (j && j.ok) {
            alert('✅ Assinaturas salvas! Recebedor e Técnico. Termo atualizado. Já pode imprimir.');
            location.reload();
        } else {
            const err = (j && j.error) ? j.error : ('HTTP '+res.status+' — '+ text.slice(0,400).replace(/<[^>]*>/g,' ').trim());
            alert('❌ ' + err);
            console.error(j, text);
            btn.disabled=false; btn.innerHTML=old; if(nextBtn) nextBtn.disabled=false;
        }
    } catch(e) {
        alert('Erro de rede: ' + e.message);
        console.error(e);
        btn.disabled=false; btn.innerHTML=old; if(nextBtn) nextBtn.disabled=false;
    }
}
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') amCloseAssinaturaModal(); });
document.getElementById('am-modal-assinatura').addEventListener('click', (e)=>{ if(e.target.id==='am-modal-assinatura') amCloseAssinaturaModal(); });
</script>

<?php Html::footer(); ?>
