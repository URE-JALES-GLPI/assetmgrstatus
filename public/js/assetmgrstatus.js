(function () {
    'use strict';

    var _ajaxBase = '';

    function amDetectAjaxBase() {
        if (_ajaxBase) return _ajaxBase;
        var scripts = document.querySelectorAll('script[src*="assetmgrstatus"]');
        if (scripts.length > 0) {
            _ajaxBase = scripts[scripts.length - 1].src.split('/public/js/')[0];
        }
        if (!_ajaxBase) {
            // Fallback: detecta pela URL atual da página (front/maintenance.php etc)
            var path = window.location.pathname;
            var idx = path.indexOf('/plugins/assetmgrstatus/');
            if (idx !== -1) {
                _ajaxBase = path.substring(0, idx) + '/plugins/assetmgrstatus';
            }
        }
        return _ajaxBase;
    }

    // Detecta imediatamente (não espera DOMContentLoaded)
    amDetectAjaxBase();

    // ---- Mostra/esconde campo de prazo de retorno conforme status ----
    document.addEventListener('change', function (e) {
        if (e.target.name === 'status' && e.target.closest('#am-maintenance-form')) {
            var section = document.getElementById('am-expected-return-section');
            if (section) {
                section.style.display = (e.target.value === 'manutencao') ? 'block' : 'none';
                if (e.target.value !== 'manutencao') {
                    document.getElementById('am-expected-return').value = '';
                }
            }
        }
    });
    window.amToggleCompPanel = function () {
        var panel = document.getElementById('am-comp-panel');
        if (panel) panel.classList.toggle('open');
    };

    window.amToggleFabPanel = function () {
        var panel = document.getElementById('am-fab-panel');
        if (panel) panel.classList.toggle('open');
    };

    window.amToggleEntityPanel = function () {
        var panel = document.getElementById('am-entity-panel');
        if (panel) panel.classList.toggle('open');
    };

    window.amClearEntityFilters = function () {
        document.querySelectorAll('#am-entity-panel input[type="checkbox"]').forEach(function(cb){ cb.checked = false; });
    };

    window.amSelectAllEntityFilters = function () {
        document.querySelectorAll('#am-entity-panel input[type="checkbox"]').forEach(function(cb){ cb.checked = true; });
    };

    window.amFilterEntityList = function (term) {
        term = (term || '').toLowerCase();
        document.querySelectorAll('.am-entity-row').forEach(function(row){
            var name = row.dataset.name || '';
            row.style.display = name.indexOf(term) !== -1 ? '' : 'none';
        });
    };

    window.amClearCompFilters = function () {
        document.querySelectorAll('.am-comp-3state').forEach(function(group) {
            var compKey = group.dataset.comp;
            group.querySelectorAll('.am-3state-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.value === '');
            });
            var input = document.getElementById('comp-input-' + compKey);
            if (input) input.value = '';
        });
    };

    window.amClearFabFilters = function () {
        document.querySelectorAll('#am-fab-panel input[type="checkbox"]').forEach(function(cb){ cb.checked = false; });
    };

    document.addEventListener('click', function (e) {
        // Alterna estado do botão 3-state
        if (e.target.classList.contains('am-3state-btn')) {
            var group = e.target.closest('.am-comp-3state');
            var compKey = group.dataset.comp;
            var value = e.target.dataset.value;

            group.querySelectorAll('.am-3state-btn').forEach(function(btn) {
                btn.classList.remove('active');
            });
            e.target.classList.add('active');

            var input = document.getElementById('comp-input-' + compKey);
            if (input) input.value = value;
            return;
        }

        // Fecha painéis se clicar fora
        ['am-comp-panel','am-fab-panel','am-entity-panel'].forEach(function(pid){
            var p = document.getElementById(pid);
            if (!p || !p.classList.contains('open')) return;
            var btnSelector = pid === 'am-comp-panel' ? '[onclick="amToggleCompPanel()"]' : pid === 'am-fab-panel' ? '[onclick="amToggleFabPanel()"]' : '[onclick="amToggleEntityPanel()"]';
            var btn = document.querySelector(btnSelector);
            if (!p.contains(e.target) && (!btn || !btn.contains(e.target))) {
                p.classList.remove('open');
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        amDetectAjaxBase();

        // ---- Skeleton loader: remove classe ao carregar ----
        document.querySelectorAll('.am-skeleton').forEach(function(el) {
            el.classList.remove('am-skeleton');
        });

        // ---- Swipe mobile nos cards ----
        var swipeThreshold = 60;
        document.querySelectorAll('.am-asset-card').forEach(function(card) {
            var startX = 0, startY = 0;
            card.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            }, { passive: true });
            card.addEventListener('touchend', function(e) {
                var dx = e.changedTouches[0].clientX - startX;
                var dy = e.changedTouches[0].clientY - startY;
                if (Math.abs(dx) < swipeThreshold || Math.abs(dy) > Math.abs(dx) * 0.8) return;
                var btn     = card.querySelector('.am-btn-primary');
                var noteBtn = card.querySelector('.am-btn-note');
                if (dx > 0 && btn) {
                    card.style.transform = 'translateX(8px)';
                    setTimeout(function(){ card.style.transform = ''; btn.click(); }, 150);
                } else if (dx < 0 && noteBtn) {
                    card.style.transform = 'translateX(-8px)';
                    setTimeout(function(){ card.style.transform = ''; noteBtn.click(); }, 150);
                }
            }, { passive: true });
        });

        // Preserva view mode em todos os forms de ação (POST) — respeita view já injetado pelo PHP e fallback igual ao PHP (list no desktop, grid no mobile)
        var viewParam = new URLSearchParams(window.location.search).get('view');
        var viewMode = viewParam || (window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 'grid' : 'list');
        // Se a página já tem um input view_mode injetado pelo PHP (maintenance.php), usa o valor de lá como fonte da verdade
        var phpViewInput = document.querySelector('input[name="view_mode"][value]');
        if (phpViewInput && phpViewInput.value) viewMode = phpViewInput.value;
        // Fallback: se houver botão de view ativo, reflete o estado visual atual
        else {
            var activeViewBtn = document.querySelector('.am-view-btn.active');
            if (activeViewBtn) {
                var href = activeViewBtn.getAttribute('href') || '';
                if (href.indexOf('view=list') !== -1) viewMode = 'list';
                else if (href.indexOf('view=grid') !== -1) viewMode = 'grid';
            }
        }
        ['am-maintenance-form', 'am-bulk-form', 'am-undo-form', 'am-delete-form'].forEach(function(id) {
            var form = document.getElementById(id);
            if (!form) return;
            if (form.querySelector('[name="view_mode"]')) return; // já injetado pelo PHP, não duplica (evita grid sobrescrever list)
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'view_mode'; inp.value = viewMode;
            form.appendChild(inp);
        });

        var area = document.getElementById('am-upload-area');
        if (!area) return;
        area.addEventListener('dragover', function(e){e.preventDefault();area.classList.add('dragover');});
        area.addEventListener('dragleave', function(){area.classList.remove('dragover');});
        area.addEventListener('drop', function(e){
            e.preventDefault(); area.classList.remove('dragover');
            var dt=new DataTransfer();
            Array.from(e.dataTransfer.files).forEach(function(f){dt.items.add(f);});
            var input = document.getElementById('am-photos');
            input.files = dt.files;
            amHandlePhotos(input);
        });
    });

    // ---- Modal Status ----
    window.amOpenModal = function (items_id, itemtype, name, type_label, current_status) {
        document.getElementById('am-f-items-id').value   = items_id;
        document.getElementById('am-f-itemtype').value   = itemtype;
        document.getElementById('am-del-items-id').value = items_id;
        document.getElementById('am-del-itemtype').value = itemtype;
        document.getElementById('am-modal-asset-name').textContent = name + ' (' + type_label + ')';

        // Reset form
        document.getElementById('am-maintenance-form').reset();
        document.getElementById('am-photo-previews').innerHTML = '';
        document.querySelectorAll('.am-comp-field').forEach(function(f){f.style.display='none';});
        document.querySelectorAll('.am-component-item').forEach(function(i){i.classList.remove('checked');});
        if (current_status) { var r = document.getElementById('st_' + current_status); if (r) r.checked = true; }

        // Mostra campo de prazo de retorno se status atual já for manutenção
        var returnSection = document.getElementById('am-expected-return-section');
        if (returnSection) returnSection.style.display = (current_status === 'manutencao') ? 'block' : 'none';

        // Reset seções de foto/timeline/viewers
        var photoEl    = document.getElementById('am-modal-asset-photo');
        var timelineEl = document.getElementById('am-modal-timeline');
        var viewersEl  = document.getElementById('am-modal-viewers');
        if (photoEl)    { photoEl.style.display = 'none'; photoEl.src = ''; }
        if (timelineEl) timelineEl.innerHTML = '<span style="color:#9ca3af;font-size:.75rem;">Carregando...</span>';
        if (viewersEl)  viewersEl.innerHTML  = '<span style="color:#9ca3af;font-size:.75rem;">Carregando...</span>';

        // Log de view + carrega foto/timeline/viewers via AJAX
        var base = amDetectAjaxBase();
        fetch(base + '/ajax/view_log.php?items_id=' + items_id + '&itemtype=' + encodeURIComponent(itemtype))
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data) return;

                // Foto do ativo
                if (data.photo && photoEl) {
                    photoEl.src = data.photo;
                    photoEl.style.display = 'block';
                    photoEl.onerror = function(){ photoEl.style.display = 'none'; };
                }

                // Mini timeline
                if (timelineEl) {
                    if (!data.timeline || data.timeline.length === 0) {
                        timelineEl.innerHTML = '<span style="color:#9ca3af;font-size:.75rem;">Sem histórico</span>';
                    } else {
                        var tl = '';
                        data.timeline.forEach(function(t, i) {
                            var d = new Date(t.date);
                            var ds = d.toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit'});
                            tl += '<div class="am-tl-item" title="' + t.label + ' — ' + ds + '">' +
                                  '<div class="am-tl-dot" style="background:' + t.color + ';"></div>' +
                                  (i < data.timeline.length - 1 ? '<div class="am-tl-line"></div>' : '') +
                                  '</div>';
                        });
                        tl += '<span style="font-size:.72rem;color:#9ca3af;margin-left:6px;">' + data.timeline[data.timeline.length-1].label + '</span>';
                        timelineEl.innerHTML = tl;
                    }
                }

                // Quem visualizou
                if (viewersEl) {
                    if (!data.views || data.views.length === 0) {
                        viewersEl.innerHTML = '<span style="color:#9ca3af;font-size:.75rem;">Nenhuma visualização</span>';
                    } else {
                        viewersEl.innerHTML = data.views.map(function(v) {
                            var d = new Date(v.date);
                            var ds = d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'}) + ' ' + d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
                            return '<div class="am-viewer-item"><i class="ti ti-eye" style="color:#9ca3af;font-size:.8rem;"></i><span>' + v.user + '</span><span style="color:#9ca3af;font-size:.75rem;">' + ds + '</span></div>';
                        }).join('');
                    }
                }
            })
            .catch(function(){});

        // Carrega histórico recente no modal
        amLoadModalHistory(items_id, itemtype);

        // Carrega motivo e componentes salvos anteriormente
        amLoadCurrentRecord(items_id, itemtype);

        document.getElementById('am-modal-overlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.amLoadCurrentRecord = function (items_id, itemtype) {
        var url = amDetectAjaxBase() + '/ajax/current_record.php?items_id=' + items_id + '&itemtype=' + encodeURIComponent(itemtype);
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data) return;

                // Preenche motivo
                var reasonField = document.getElementById('am-reason');
                if (reasonField && data.reason) reasonField.value = data.reason;

                // Preenche prazo de retorno previsto
                var returnField = document.getElementById('am-expected-return');
                if (returnField && data.expected_return_date) returnField.value = data.expected_return_date;

                // Marca componentes e preenche descrições
                if (data.components) {
                    Object.keys(data.components).forEach(function(comp_key) {
                        var checkbox = document.querySelector('input[name="comp_check[]"][value="' + comp_key + '"]');
                        if (checkbox) {
                            checkbox.checked = true;
                            amToggleCompField(checkbox, comp_key);
                            var descInput = document.querySelector('input[name="comp_desc[' + comp_key + ']"]');
                            if (descInput) descInput.value = data.components[comp_key] || '';
                        }
                    });
                }
            })
            .catch(function() { /* silencioso */ });
    };

    window.amCloseModal = function (e) {
        if (e && e.target !== document.getElementById('am-modal-overlay')) return;
        document.getElementById('am-modal-overlay').classList.remove('open');
        document.body.style.overflow = '';
    };

    // ---- Carrega histórico no modal via AJAX ----
    window.amLoadModalHistory = function (items_id, itemtype) {
        var container = document.getElementById('am-modal-history');
        if (!container) return;

        container.innerHTML = '<div style="text-align:center;padding:16px;color:#9ca3af;font-size:.82rem;">Carregando...</div>';

        var url = amDetectAjaxBase() + '/ajax/history.php?items_id=' + items_id + '&itemtype=' + encodeURIComponent(itemtype);

        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.length) {
                    container.innerHTML = '<div style="text-align:center;padding:12px;color:#9ca3af;font-size:.82rem;"><i class="ti ti-clipboard-off"></i> Nenhum registro ainda.</div>';
                    return;
                }

                var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                data.forEach(function(h) {
                    html += '<div style="background:#fafafa;border:1px solid #e8eaf0;border-left:3px solid '+h.border_color+';border-radius:8px;padding:10px 12px;">';
                    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">';
                    html += '<span style="font-size:.75rem;font-weight:700;color:#6b7280;">'+h.type_label+'</span>';
                    html += '<span style="font-size:.72rem;color:#9ca3af;">'+h.date+'</span>';
                    html += '</div>';

                    if (h.record_type === 'status_change' || h.record_type === 'transferencia_retorno') {
                        html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">';
                        if (h.status_old_label && h.status_old_label !== h.status_new_label) html += '<span class="am-badge '+h.status_old_badge+'">'+h.status_old_label+'</span><i class="ti ti-arrow-right" style="color:#9ca3af;font-size:.8rem;"></i>';
                        html += '<span class="am-badge '+h.status_new_badge+'">'+h.status_new_label+'</span>';
                        html += '</div>';
                    } else if (h.record_type === 'transferencia') {
                        html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">';
                        html += '<span class="am-badge '+h.status_new_badge+'">'+h.status_new_label+'</span>';
                        html += '</div>';
                    }

                    if (h.description) {
                        html += '<div style="font-size:.82rem;color:#4b5563;">'+h.description.substring(0,100)+(h.description.length>100?'...':'')+'</div>';
                    }

                    if (h.action_date) {
                        html += '<div style="font-size:.78rem;color:#6b7280;margin-top:3px;">📅 Baixa: '+h.action_date+'</div>';
                    }

                    if (h.components.length) {
                        html += '<div style="margin-top:4px;">'+h.components.map(function(c){ return '<span style="background:#f0f0ff;border:1px solid #c7d2fe;border-radius:4px;padding:1px 6px;font-size:.72rem;color:#3730a3;margin-right:4px;">'+c+'</span>'; }).join('')+'</div>';
                    }

                    html += '<div style="font-size:.72rem;color:#9ca3af;margin-top:4px;"><i class="ti ti-user"></i> '+h.user+'</div>';
                    html += '</div>';
                });
                html += '</div>';
                container.innerHTML = html;
            })
            .catch(function() {
                container.innerHTML = '<div style="text-align:center;padding:12px;color:#9ca3af;font-size:.82rem;">Erro ao carregar histórico.</div>';
            });
    };

    // ---- Modal Manutenção Realizada ----
    window.amOpenManutencao = function (items_id, itemtype, name) {
        document.getElementById('am-manut-items-id').value = items_id;
        document.getElementById('am-manut-itemtype').value = itemtype;
        document.getElementById('am-manut-title').textContent = 'Manutenção Realizada — ' + name;
        document.getElementById('am-modal-manutencao').classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.amCloseManutencao = function (e) {
        if (e && e.target !== document.getElementById('am-modal-manutencao')) return;
        document.getElementById('am-modal-manutencao').classList.remove('open');
        document.body.style.overflow = '';
    };

    // ---- Modal Baixa ----
    window.amOpenBaixa = function (items_id, itemtype, name) {
        document.getElementById('am-baixa-items-id').value = items_id;
        document.getElementById('am-baixa-itemtype').value = itemtype;
        document.getElementById('am-baixa-title').textContent = 'Baixa — ' + name;
        document.getElementById('am-modal-baixa').classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.amCloseBaixa = function (e) {
        if (e && e.target !== document.getElementById('am-modal-baixa')) return;
        document.getElementById('am-modal-baixa').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.amOpenNote = function (items_id, itemtype, name) {
        document.getElementById('am-note-items-id').value = items_id;
        document.getElementById('am-note-itemtype').value = itemtype;
        document.getElementById('am-note-title').textContent = 'Observação — ' + name;
        document.getElementById('am-modal-note').classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.amCloseNote = function (e) {
        if (e && e.target !== document.getElementById('am-modal-note')) return;
        document.getElementById('am-modal-note').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.amConfirmDelete = function () {
        if (confirm('Tem certeza que deseja APAGAR este ativo? Esta ação não pode ser desfeita.')) {
            document.getElementById('am-delete-form').submit();
        }
    };

    window.amConfirmUndo = function (items_id, itemtype) {
        var url = amDetectAjaxBase() + '/ajax/undo_preview.php?items_id=' + items_id + '&itemtype=' + encodeURIComponent(itemtype);

        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data || !data.can_undo) {
                    alert('Não há alteração recente para reverter (prazo de 48h expirado).');
                    return;
                }

                var cur  = data.current;
                var prev = data.previous;

                var html =
                    '<div style="font-family:\'Inter\',sans-serif;max-width:480px;">' +
                    '<div style="margin-bottom:12px;font-size:.82rem;color:#6b7280;">⏱️ Disponível por mais <strong>' + data.hours_left + 'h</strong></div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">' +

                    // Estado atual
                    '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:12px;">' +
                    '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#dc2626;margin-bottom:8px;">ESTADO ATUAL</div>' +
                    '<div style="font-weight:700;font-size:.9rem;margin-bottom:6px;">' + cur.status + '</div>' +
                    (cur.reason ? '<div style="font-size:.78rem;color:#6b7280;margin-bottom:6px;">' + cur.reason.substring(0,80) + '</div>' : '') +
                    (cur.components.length ? '<div style="font-size:.75rem;color:#9ca3af;">' + cur.components.join(', ') + '</div>' : '<div style="font-size:.75rem;color:#d1d5db;">Sem componentes</div>') +
                    '</div>' +

                    // Estado anterior
                    '<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:12px;">' +
                    '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#059669;margin-bottom:8px;">VAI REVERTER PARA</div>' +
                    '<div style="font-weight:700;font-size:.9rem;margin-bottom:6px;">' + prev.status + '</div>' +
                    (prev.reason ? '<div style="font-size:.78rem;color:#6b7280;margin-bottom:6px;">' + prev.reason.substring(0,80) + '</div>' : '<div style="font-size:.78rem;color:#d1d5db;margin-bottom:6px;">Sem motivo</div>') +
                    (prev.components.length ? '<div style="font-size:.75rem;color:#9ca3af;">' + prev.components.join(', ') + '</div>' : '<div style="font-size:.75rem;color:#d1d5db;">Sem componentes</div>') +
                    '</div>' +

                    '</div>' +
                    '<div style="font-size:.82rem;color:#9ca3af;">Confirma a reversão?</div>' +
                    '</div>';

                // Mostra no modal de confirmação
                document.getElementById('am-undo-confirm-body').innerHTML = html;
                document.getElementById('am-undo-items-id').value  = items_id;
                document.getElementById('am-undo-itemtype').value  = itemtype;
                document.getElementById('am-undo-agree').checked = false; amToggleUndoBtn();
                document.getElementById('am-modal-undo-confirm').classList.add('open');
                document.body.style.overflow = 'hidden';
            })
            .catch(function() {
                alert('Erro ao carregar dados para reversão.');
            });
    };

    window.amCloseUndoConfirm = function (e) {
        if (e && e.target !== document.getElementById('am-modal-undo-confirm')) return;
        document.getElementById('am-modal-undo-confirm').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.amToggleUndoBtn = function () {
        var btn = document.getElementById('am-undo-confirm-btn');
        var checked = document.getElementById('am-undo-agree').checked;
        btn.disabled = !checked;
        btn.style.opacity = checked ? '1' : '.4';
        btn.style.cursor  = checked ? 'pointer' : 'not-allowed';
    };

    window.amToggleBulkConfirmBtn = function () {
        var btn = document.getElementById('am-bulk-confirm-btn');
        var cb = document.getElementById('am-bulk-agree');
        if (!btn || !cb) return;
        var checked = cb.checked;
        var count = document.querySelectorAll('.am-bulk-confirm-cb:checked').length;
        // Se a lista ainda não foi criada (primeiro modal), permite habilitar só pelo checkbox — compatibilidade
        var hasList = document.getElementById('am-bulk-confirm-list') && document.querySelectorAll('.am-bulk-confirm-cb').length > 0;
        var enable = hasList ? (checked && count > 0) : checked;
        btn.disabled = !enable;
        btn.style.opacity = enable ? '1' : '.4';
        btn.style.cursor  = enable ? 'pointer' : 'not-allowed';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            ['am-modal-overlay','am-modal-manutencao','am-modal-baixa','am-modal-bulk','am-modal-bulk-confirm','am-modal-transfer','am-modal-transfer-confirm','am-modal-bulk-delete','am-modal-undo-confirm','am-modal-note'].forEach(function(id){
                var el = document.getElementById(id);
                if (el) el.classList.remove('open');
            });
            document.body.style.overflow = '';
        }
    });

    window.amToggleCompField = function (checkbox, comp_key) {
        var field = document.getElementById('comp-field-' + comp_key);
        var item  = document.getElementById('comp-item-' + comp_key);
        if (checkbox.checked) { field.style.display='block'; item.classList.add('checked'); field.querySelector('input').focus(); }
        else { field.style.display='none'; field.querySelector('input').value=''; item.classList.remove('checked'); }
    };

    var selectedFiles = [];
    window.amHandlePhotos = function (input) {
        var previews = document.getElementById('am-photo-previews');
        if (!previews) return;
        Array.from(input.files).forEach(function(file) {
            if (selectedFiles.length >= 3) return;
            if (!['image/jpeg','image/png'].includes(file.type)) return;
            var idx = selectedFiles.length;
            selectedFiles.push(file);
            var reader = new FileReader();
            reader.onload = function(e) {
                var div = document.createElement('div'); div.className='am-preview-item'; div.dataset.idx=idx;
                div.innerHTML='<img src="'+e.target.result+'" alt="preview"><button type="button" class="am-preview-remove" onclick="amRemovePhoto('+idx+',this)">✕</button>';
                previews.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
        amRebuildInput();
    };
    window.amRemovePhoto = function(idx, btn) { selectedFiles[idx]=null; btn.closest('.am-preview-item').remove(); amRebuildInput(); };
    function amRebuildInput() {
        var input = document.getElementById('am-photos');
        if (!input) return;
        var dt=new DataTransfer();
        selectedFiles.forEach(function(f){if(f)dt.items.add(f);});
        input.files=dt.files;
    }

    // ---- Clique na linha/card seleciona checkbox ----
    window.amHandleAssetClick = function(el, e) {
        if (e.target.closest('a, button, input, .am-btn, .am-card-checkbox, .am-alert-trigger, .am-alert-popup')) return;
        if (el.classList.contains('am-card-locked-transfer') || el.classList.contains('am-row-locked-transfer')) return;
        var cb = el.querySelector('.am-bulk-checkbox');
        if (!cb || cb.disabled) return;
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change', {bubbles: true}));
        if (typeof window.amUpdateBulkBar === 'function') window.amUpdateBulkBar();
    };

    // ---- Seleção em massa ----
    window.amUpdateBulkBar = function () {
        // Desmarca qualquer checkbox desabilitado que por algum motivo ficou marcado (ex: manipulação via devtools)
        document.querySelectorAll('.am-bulk-checkbox:disabled:checked').forEach(function(cb){ cb.checked=false; });
        var checkboxes = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
        var bar = document.getElementById('am-bulk-bar');
        var countEl = document.getElementById('am-bulk-count');
        if (!bar) return;

        if (checkboxes.length > 0) {
            bar.classList.add('open');
            bar.style.display = 'flex';
            if (countEl) countEl.textContent = checkboxes.length + ' selecionado(s)';
            // Flutuante no rodapé — garante espaço para não cobrir paginação
            var page=document.querySelector('.am-page'); if(page) page.style.paddingBottom='90px';
        } else {
            bar.classList.remove('open');
            bar.style.display = 'none';
            var page2=document.querySelector('.am-page'); if(page2) page2.style.paddingBottom='';
        }

        // Marca visualmente os cards e linhas selecionadas
        document.querySelectorAll('.am-asset-card').forEach(function(card) {
            var cb = card.querySelector('.am-bulk-checkbox');
            if (cb && cb.checked) card.classList.add('am-card-selected');
            else card.classList.remove('am-card-selected');
        });
        document.querySelectorAll('.am-list-row').forEach(function(row) {
            var cb = row.querySelector('.am-bulk-checkbox');
            if (cb && cb.checked) row.classList.add('am-row-selected');
            else row.classList.remove('am-row-selected');
        });

        // Aplica has-selection para desfocar não selecionados (compat .am-asset-grid e .am-assets-grid)
        document.querySelectorAll('.am-asset-grid, .am-assets-grid').forEach(function(g){
            if (checkboxes.length > 0) g.classList.add('has-selection');
            else g.classList.remove('has-selection');
        });
        var table = document.querySelector('.am-list-table');
        if (table) {
            if (checkboxes.length > 0) table.classList.add('has-selection');
            else table.classList.remove('has-selection');
        }
    };

    window.amClearSelection = function () {
        document.querySelectorAll('.am-bulk-checkbox:not(:disabled)').forEach(function(cb){ cb.checked = false; });
        var selectAll = document.getElementById('am-select-all');
        if (selectAll) selectAll.checked = false;
        amUpdateBulkBar();
    };

    window.amToggleSelectAll = function (master) {
        document.querySelectorAll('.am-bulk-checkbox:not(:disabled)').forEach(function(cb){ cb.checked = master.checked; });
        // Garante que desabilitados permaneçam desmarcados
        document.querySelectorAll('.am-bulk-checkbox:disabled').forEach(function(cb){ cb.checked=false; });
        amUpdateBulkBar();
    };

    window.amOpenTransferModalFromBulk = function() {
        try {
            console.log('amOpenTransferModalFromBulk (js) called');
            var cbs = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
            console.log('checked', cbs.length);
            if (cbs.length === 0) { alert('Selecione ao menos um ativo.'); return; }
            var items=[], names=[];
            cbs.forEach(function(cb){
                var oserial = cb.dataset.otherserial || cb.dataset.serial || '';
                items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: oserial});
                names.push(cb.dataset.name + (oserial ? ' ('+oserial+')' : ''));
            });
            var inp = document.getElementById('am-tr-selected-assets');
            var lst = document.getElementById('am-tr-asset-list');
            if (inp) inp.value = JSON.stringify(items); else console.warn('am-tr-selected-assets missing');
            if (lst) lst.innerHTML = '<strong>'+items.length+' ativo(s) selecionado(s):</strong><br>'+names.join(', '); else console.warn('am-tr-asset-list missing');
            // reset destino para URE por padrão
            var ur = document.getElementById('am-tr-type-ure'); if (ur) { ur.checked=true; if (typeof window.amSwitchTransferType==='function') window.amSwitchTransferType('ure'); }
            var mod = document.getElementById('am-modal-transfer');
            if (mod) { mod.classList.add('open'); document.body.style.overflow='hidden'; console.log('modal opened'); } else { console.error('am-modal-transfer missing'); alert('Modal Transferir não encontrado.'); }
        } catch(e){ console.error(e); alert('Erro: '+e.message); }
    };
    window.amCloseTransferModal = function(e) {
        if (e && e.target !== document.getElementById('am-modal-transfer')) return;
        var m=document.getElementById('am-modal-transfer'); if(m) m.classList.remove('open'); document.body.style.overflow='';
    };
    // legado — mantido para fallback inline, mas não usado no novo fluxo (confirmação em 2ª janela)
    window.amToggleTransferSubmit = function() {
        var cb=document.getElementById('am-tr-agree'); var b=document.getElementById('am-tr-submit'); if(!cb||!b) return; b.disabled=!cb.checked; b.style.opacity=cb.checked?'1':'.4'; b.style.cursor=cb.checked?'pointer':'not-allowed';
    };
    window.amSwitchTransferType = function(type){
        var ureS=document.getElementById('am-tr-ure-section'); var escS=document.getElementById('am-tr-escola-section');
        var ureL=document.getElementById('am-tr-type-ure-label'); var escL=document.getElementById('am-tr-type-escola-label');
        var ureSel=document.getElementById('am-tr-entity-ure'); var escSel=document.getElementById('am-tr-entity-escola');
        if(!ureS||!escS) return;
        if(type==='ure'){ ureS.style.display='block'; escS.style.display='none'; ureSel.name='entity_dest'; ureSel.disabled=false; ureSel.required=true; escSel.name='entity_dest_escola_disabled'; escSel.disabled=true; escSel.required=false; ureL.style.borderColor='#1e40af'; ureL.style.background='#eff6ff'; escL.style.borderColor='#e8eaf0'; escL.style.background='#f8f9fb'; }
        else { ureS.style.display='none'; escS.style.display='block'; escSel.name='entity_dest'; escSel.disabled=false; escSel.required=true; ureSel.name='entity_dest_ure_disabled'; ureSel.disabled=true; ureSel.required=false; escL.style.borderColor='#1e40af'; escL.style.background='#eff6ff'; ureL.style.borderColor='#e8eaf0'; ureL.style.background='#f8f9fb'; }
    };
    // ---- Fluxo de confirmação da Transferência (igual ao de Ação em Massa) ----
    window.amConfirmTransfer = function() {
        var form = document.getElementById('am-transfer-form');
        if (!form) return;
        // Valida campos obrigatórios do primeiro modal
        var typeEl = form.querySelector('input[name="transfer_type"]:checked');
        if (!typeEl) { alert('Selecione o tipo de destino.'); return; }
        var type = typeEl.value;
        var ureSel = document.getElementById('am-tr-entity-ure');
        var escSel = document.getElementById('am-tr-entity-escola');
        var entityName = '';
        if (type === 'ure') {
            entityName = ureSel.options[ureSel.selectedIndex]?.text || 'URE';
            // URE sempre 0 (Unidade Regional), não precisa validar seleção
        } else {
            if (!escSel.value) { alert('Selecione a escola de destino.'); escSel.focus(); escSel.style.borderColor='#ef4444'; setTimeout(function(){escSel.style.borderColor='';},1500); return; }
            entityName = escSel.options[escSel.selectedIndex]?.text || 'Escola';
        }
        var reasonEl = form.querySelector('textarea[name="reason"]');
        var reason = reasonEl ? reasonEl.value.trim() : '';
        if (!reason) { alert('Preencha o motivo da transferência.'); if(reasonEl){reasonEl.focus(); reasonEl.style.borderColor='#ef4444'; setTimeout(function(){reasonEl.style.borderColor='';},1500);} return; }
        var catEl = form.querySelector('select[name="ticket_category"]');
        if (!catEl || !catEl.value) { alert('Selecione a categoria do chamado.'); if(catEl){catEl.focus(); catEl.style.borderColor='#ef4444'; setTimeout(function(){catEl.style.borderColor='';},1500);} return; }
        var catName = catEl.options[catEl.selectedIndex]?.text || '';
        // Itens selecionados
        var inp = document.getElementById('am-tr-selected-assets');
        var items = [];
        try { items = JSON.parse(inp.value || '[]'); } catch(e){ items=[]; }
        if (!items.length) { alert('Nenhum ativo selecionado.'); return; }
        // Monta resumo
        var typeLabel = type === 'ure' ? 'URE' : 'Escola';
        var summaryHtml =
            '<div style="text-align:center;margin-bottom:16px;">' +
            '<div style="width:56px;height:56px;background:linear-gradient(135deg,#1e40af,#3b82f6);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;"><i class="ti ti-transfer" style="font-size:1.8rem;color:#fff;"></i></div>' +
            '<div style="font-size:1.05rem;font-weight:800;color:#1e1b4b;margin-bottom:6px;">Confirmar transferência?</div>' +
            '<div style="font-size:.85rem;color:#9ca3af;">Esta ação enviará <strong>'+items.length+' ativo(s)</strong> para <strong>'+entityName+'</strong>.</div>' +
            '</div>' +
            '<div style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;">' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;"><span style="color:#9ca3af;">Tipo destino</span><strong>'+typeLabel+'</strong></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;"><span style="color:#9ca3af;">Destino</span><strong style="text-align:right;max-width:60%;">'+entityName+'</strong></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;gap:10px;"><span style="color:#9ca3af;flex-shrink:0;">Motivo</span><span style="text-align:right;color:#374151;">'+reason.substring(0,80)+(reason.length>80?'...':'')+'</span></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;"><span style="color:#9ca3af;">Categoria</span><span style="text-align:right;color:#374151;">'+catName+'</span></div>' +
            '</div>';
        document.getElementById('am-tr-confirm-body').innerHTML = summaryHtml;
        // Monta lista desmarcável
        var listEl = document.getElementById('am-tr-confirm-list');
        var listHtml = '';
        items.forEach(function(it, idx){
            var label = it.name || ('Ativo #'+it.id);
            var num = it.otherserial ? 'Nº '+it.otherserial : '';
            listHtml += '<label style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e8eaf0;border-radius:8px;padding:8px 10px;cursor:pointer;">' +
                        '<input type="checkbox" class="am-tr-confirm-cb" data-idx="'+idx+'" value="'+it.id+'" data-itemtype="'+it.itemtype+'" data-name="'+(it.name||'').replace(/"/g,'&quot;')+'" data-otherserial="'+(it.otherserial||'').replace(/"/g,'&quot;')+'" checked style="width:18px;height:18px;accent-color:#1e40af;flex-shrink:0;" onchange="amTransferConfirmUpdateCount()">' +
                        '<span style="flex:1;min-width:0;"><span style="font-weight:700;font-size:.85rem;color:#1f2937;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+label+'</span><span style="font-size:.75rem;color:#9ca3af;">'+num+'</span></span>' +
                        '</label>';
        });
        listEl.innerHTML = listHtml;
        document.getElementById('am-tr-confirm-count').textContent = items.length;
        document.getElementById('am-tr-confirm-agree').checked = false; amToggleTransferConfirmBtn();
        document.getElementById('am-modal-transfer-confirm').classList.add('open');
    };
    window.amCloseTransferConfirm = function(e){
        if (e && e.target !== document.getElementById('am-modal-transfer-confirm')) return;
        document.getElementById('am-modal-transfer-confirm').classList.remove('open');
    };
    window.amToggleTransferConfirmBtn = function(){
        var cb=document.getElementById('am-tr-confirm-agree'); var btn=document.getElementById('am-tr-confirm-btn'); if(!cb||!btn) return;
        var checked = cb.checked; var count = document.querySelectorAll('.am-tr-confirm-cb:checked').length;
        var enable = checked && count>0; btn.disabled=!enable; btn.style.opacity=enable?'1':'.4'; btn.style.cursor=enable?'pointer':'not-allowed';
    };
    window.amTransferConfirmUpdateCount = function(){
        var count = document.querySelectorAll('.am-tr-confirm-cb:checked').length;
        var el=document.getElementById('am-tr-confirm-count'); if(el) el.textContent = count;
        amToggleTransferConfirmBtn();
    };
    window.amTransferConfirmToggleAll = function(checked){
        document.querySelectorAll('.am-tr-confirm-cb').forEach(function(cb){ cb.checked=checked; });
        amTransferConfirmUpdateCount();
    };
    window.amSubmitTransferConfirmed = function(){
        var cbs = document.querySelectorAll('.am-tr-confirm-cb:checked');
        if (!cbs.length) { alert('Selecione ao menos um ativo para transferir.'); return; }
        var items=[];
        cbs.forEach(function(cb){ items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: cb.dataset.otherserial}); });
        var inp=document.getElementById('am-tr-selected-assets'); if(inp) inp.value = JSON.stringify(items);
        // Sincroniza seleção original na grade/lista (desmarca os removidos)
        var keptIds = items.map(function(i){return String(i.id)});
        document.querySelectorAll('.am-bulk-checkbox:checked').forEach(function(bcb){
            if (keptIds.indexOf(bcb.value)===-1) { bcb.checked=false; }
        });
        if (typeof window.amUpdateBulkBar === 'function') window.amUpdateBulkBar();
        // Atualiza lista resumida do primeiro modal para refletir o que vai ser enviado
        var lst=document.getElementById('am-tr-asset-list');
        if (lst) {
            var names = items.map(function(i){return i.name + (i.otherserial ? ' ('+i.otherserial+')' : '')});
            lst.innerHTML = '<strong>'+items.length+' ativo(s) selecionado(s):</strong><br>'+names.join(', ');
        }
        document.getElementById('am-modal-transfer-confirm').classList.remove('open');
        document.getElementById('am-transfer-form').submit();
    };

    window.amOpenBulkModal = function () {
        // Ignora itens desabilitados (em transferência)
        var checkboxes = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
        if (checkboxes.length === 0) { alert('Selecione ao menos um ativo.'); return; }

        var items = [];
        var names = [];
        checkboxes.forEach(function(cb) {
            var oserial = cb.dataset.otherserial || cb.dataset.serial || '';
            items.push({ id: parseInt(cb.value, 10), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: oserial });
            names.push(cb.dataset.name + (oserial ? ' ('+oserial+')' : ''));
        });

        document.getElementById('am-bulk-selected-assets').value = JSON.stringify(items);
        document.getElementById('am-bulk-asset-list').innerHTML =
            '<strong>' + items.length + ' ativo(s) selecionado(s):</strong><br>' + names.join(', ');

        document.getElementById('am-bulk-form').reset();
        document.getElementById('am-bulk-selected-assets').value = JSON.stringify(items);

        document.querySelectorAll('[id^="bulk-comp-field-"]').forEach(function(f){f.style.display='none';});
        document.querySelectorAll('[id^="bulk-comp-item-"]').forEach(function(i){i.classList.remove('checked');});

        document.getElementById('am-modal-bulk').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.amToggleBulkCompField = function (checkbox, comp_key) {
        var field = document.getElementById('bulk-comp-field-' + comp_key);
        var item  = document.getElementById('bulk-comp-item-' + comp_key);
        if (checkbox.checked) { field.style.display='block'; item.classList.add('checked'); field.querySelector('input').focus(); }
        else { field.style.display='none'; field.querySelector('input').value=''; item.classList.remove('checked'); }
    };

    window.amConfirmBulk = function () {
        // Valida campos obrigatórios antes de abrir confirmação (escopo no form bulk)
        var bulkForm = document.getElementById('am-bulk-form');
        var statusChecked = bulkForm ? bulkForm.querySelector('input[name="status"]:checked') : document.querySelector('#am-bulk-form input[name="status"]:checked');
        var reasonEl = document.getElementById('am-bulk-reason');
        var reason = reasonEl ? reasonEl.value.trim() : '';

        if (!statusChecked) { alert('Selecione um status.'); if (bulkForm) bulkForm.querySelector('.am-status-grid')?.scrollIntoView({behavior:'smooth',block:'center'}); return; }
        if (!reason) { alert('Preencha o motivo.'); if (reasonEl) { reasonEl.focus(); reasonEl.scrollIntoView({behavior:'smooth',block:'center'}); reasonEl.style.borderColor='#ef4444'; setTimeout(function(){reasonEl.style.borderColor='';},1500);} return; }

        var statusLabel = statusChecked.nextElementSibling.textContent.trim();

        // Itens selecionados — pega do JSON já salvo (igual Transferência)
        var inp = document.getElementById('am-bulk-selected-assets');
        var items = [];
        try { items = JSON.parse(inp.value || '[]'); } catch(e){ items=[]; }
        if (!items.length) {
            var cbs = document.querySelectorAll('.am-bulk-checkbox:checked:not(:disabled)');
            items = [];
            cbs.forEach(function(cb){
                var oserial = cb.dataset.otherserial || cb.dataset.serial || '';
                items.push({id: parseInt(cb.value,10), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: oserial});
            });
            if (inp) inp.value = JSON.stringify(items);
        }
        if (!items.length) { alert('Nenhum ativo selecionado.'); return; }
        var assetCount = items.length;

        // Componentes selecionados
        var comps = [];
        document.querySelectorAll('input[name="bulk_comp_check[]"]:checked').forEach(function(cb) {
            comps.push(cb.nextElementSibling.textContent.trim());
        });

        var html =
            '<div style="text-align:center;margin-bottom:20px;">' +
            '<div style="width:56px;height:56px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">' +
            '<i class="ti ti-alert-triangle" style="font-size:1.8rem;color:#fff;"></i></div>' +
            '<div style="font-size:1.05rem;font-weight:800;color:#1e1b4b;margin-bottom:6px;">Tem certeza que quer fazer isso?</div>' +
            '<div style="font-size:.85rem;color:#9ca3af;">Esta ação será aplicada a <strong>' + assetCount + ' ativo(s)</strong> ao mesmo tempo.</div>' +
            '</div>' +
            '<div style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;">' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;"><span style="color:#9ca3af;">Novo status</span><strong>' + statusLabel + '</strong></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:.85rem;gap:10px;"><span style="color:#9ca3af;flex-shrink:0;">Motivo</span><span style="text-align:right;color:#374151;">' + reason.substring(0, 80) + (reason.length > 80 ? '...' : '') + '</span></div>' +
            (comps.length ? '<div style="display:flex;justify-content:space-between;font-size:.85rem;gap:10px;"><span style="color:#9ca3af;flex-shrink:0;">Componentes</span><span style="text-align:right;color:#374151;">' + comps.join(', ') + '</span></div>' : '') +
            '</div>';

        document.getElementById('am-bulk-confirm-body').innerHTML = html;

        // Monta lista desmarcável (igual Transferência)
        var listEl = document.getElementById('am-bulk-confirm-list');
        var listHtml = '';
        items.forEach(function(it, idx){
            var label = it.name || ('Ativo #'+it.id);
            var num = it.otherserial ? 'Nº '+it.otherserial : '';
            listHtml += '<label style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e8eaf0;border-radius:8px;padding:8px 10px;cursor:pointer;">' +
                        '<input type="checkbox" class="am-bulk-confirm-cb" data-idx="'+idx+'" value="'+it.id+'" data-itemtype="'+it.itemtype+'" data-name="'+(it.name||'').replace(/"/g,'&quot;')+'" data-otherserial="'+(it.otherserial||'').replace(/"/g,'&quot;')+'" checked style="width:18px;height:18px;accent-color:#4f46e5;flex-shrink:0;" onchange="amBulkConfirmUpdateCount()">' +
                        '<span style="flex:1;min-width:0;"><span style="font-weight:700;font-size:.85rem;color:#1f2937;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+label+'</span><span style="font-size:.75rem;color:#9ca3af;">'+num+'</span></span>' +
                        '</label>';
        });
        if (listEl) listEl.innerHTML = listHtml;
        var countEl = document.getElementById('am-bulk-confirm-count');
        if (countEl) countEl.textContent = items.length;

        document.getElementById('am-bulk-agree').checked = false; amToggleBulkConfirmBtn();
        document.getElementById('am-modal-bulk-confirm').classList.add('open');
    };

    window.amCloseBulkConfirm = function (e) {
        if (e && e.target !== document.getElementById('am-modal-bulk-confirm')) return;
        document.getElementById('am-modal-bulk-confirm').classList.remove('open');
    };
    window.amToggleBulkConfirmBtn = function () {
        var cb=document.getElementById('am-bulk-agree'); var btn=document.getElementById('am-bulk-confirm-btn'); if(!cb||!btn) return;
        var checked = cb.checked; var count = document.querySelectorAll('.am-bulk-confirm-cb:checked').length;
        var hasList = document.getElementById('am-bulk-confirm-list') && document.querySelectorAll('.am-bulk-confirm-cb').length > 0;
        var enable = hasList ? (checked && count>0) : checked;
        btn.disabled=!enable; btn.style.opacity=enable?'1':'.4'; btn.style.cursor=enable?'pointer':'not-allowed';
    };
    window.amBulkConfirmUpdateCount = function(){
        var count = document.querySelectorAll('.am-bulk-confirm-cb:checked').length;
        var el=document.getElementById('am-bulk-confirm-count'); if(el) el.textContent = count;
        amToggleBulkConfirmBtn();
    };
    window.amBulkConfirmToggleAll = function(checked){
        document.querySelectorAll('.am-bulk-confirm-cb').forEach(function(cb){ cb.checked=checked; });
        amBulkConfirmUpdateCount();
    };
    window.amSubmitBulkConfirmed = function(){
        var cbs = document.querySelectorAll('.am-bulk-confirm-cb:checked');
        if (!cbs.length) { alert('Selecione ao menos um ativo.'); return; }
        var items=[];
        cbs.forEach(function(cb){ items.push({id: parseInt(cb.value), itemtype: cb.dataset.itemtype, name: cb.dataset.name, otherserial: cb.dataset.otherserial}); });
        var inp=document.getElementById('am-bulk-selected-assets'); if(inp) inp.value = JSON.stringify(items);
        // Sincroniza seleção original na grade/lista (desmarca os removidos) — igual Transferência
        var keptIds = items.map(function(i){return String(i.id)});
        document.querySelectorAll('.am-bulk-checkbox:checked').forEach(function(bcb){
            if (keptIds.indexOf(bcb.value)===-1) { bcb.checked=false; }
        });
        if (typeof window.amUpdateBulkBar === 'function') window.amUpdateBulkBar();
        // Atualiza lista resumida do primeiro modal
        var lst=document.getElementById('am-bulk-asset-list');
        if (lst) {
            var names = items.map(function(i){return i.name + (i.otherserial ? ' ('+i.otherserial+')' : '')});
            lst.innerHTML = '<strong>'+items.length+' ativo(s) selecionado(s):</strong><br>'+names.join(', ');
        }
        document.getElementById('am-modal-bulk-confirm').classList.remove('open');
        document.getElementById('am-bulk-form').submit();
    };

    window.amCloseBulkModal = function (e) {
        if (e && e.target !== document.getElementById('am-modal-bulk')) return;
        document.getElementById('am-modal-bulk').classList.remove('open');
        document.body.style.overflow = '';
    };
})();

// ---- Toggle Tema Claro/Escuro ----
var _amThemeKey = 'am_theme';
var _amIsDark = false;

function _amApplyTheme(dark) {
    _amIsDark = dark;
    var body = document.body;
    if (!body) return;
    if (dark) {
        body.classList.add('am-dark-mode');
    } else {
        body.classList.remove('am-dark-mode');
    }
    var btn = document.getElementById('am-theme-btn');
    if (btn) btn.innerHTML = dark ? '<i class="ti ti-sun"></i>' : '<i class="ti ti-moon"></i>';
}

function _amInitTheme() {
    var saved = localStorage.getItem(_amThemeKey);
    // Padrão sempre claro — dark só se explicitamente escolhido
    var isDark = saved === 'dark';
    _amApplyTheme(isDark);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _amInitTheme);
} else {
    _amInitTheme();
}

window.amToggleTheme = function() {
    var newDark = !_amIsDark;
    localStorage.setItem(_amThemeKey, newDark ? 'dark' : 'light');
    _amApplyTheme(newDark);
};

// ---- Bloqueia edição de ativo quando em transferência (descrição, etc) ----
(function(){
    function amGetBase(){
        var scripts=document.querySelectorAll('script[src*="assetmgrstatus"]');
        if(scripts.length) return scripts[scripts.length-1].src.split('/public/js/')[0];
        var p=window.location.pathname; var i=p.indexOf('/plugins/assetmgrstatus/');
        if(i!==-1) return p.substring(0,i)+'/plugins/assetmgrstatus';
        return '/plugins/assetmgrstatus';
    }
    function amLockAssetForm(transferId){
        // Evita duplicar banner
        if(document.getElementById('am-locked-banner')) return;
        var banner=document.createElement('div');
        banner.id='am-locked-banner';
        banner.style.cssText='background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 16px;margin:16px 0;display:flex;gap:10px;align-items:center;color:#92400e;font-size:.88rem;';
        banner.innerHTML='<i class="ti ti-lock" style="font-size:1.4rem;color:#f97316;"></i><div><strong style="display:block;color:#c2410c;">Ativo em transferência — edição bloqueada</strong>Este ativo está com status <strong>transferido</strong> (Transferência #'+String(transferId).padStart(4,'0')+') e aguardando retorno do técnico. Nenhuma alteração na descrição, status ou componentes pode ser feita até a devolução.</div>';
        // Tenta inserir no topo do form
        var form=document.querySelector('form');
        if(form && form.parentNode) form.parentNode.insertBefore(banner, form);
        else document.body.prepend(banner);
        // Desabilita inputs do form principal (exceto botões de voltar/visualizar)
        if(form){
            form.querySelectorAll('input, select, textarea, button[type="submit"]').forEach(function(el){
                // Mantém botões de navegação (voltar, lista) habilitados — detecta por texto/classe
                if(el.tagName==='BUTTON' && el.type!=='submit') return;
                if(el.closest && el.closest('.am-locked-banner')) return;
                // Não desabilita o próprio checkbox de transferência (não existe no form GLPI)
                el.disabled=true;
                el.style.opacity='0.6';
                el.style.pointerEvents='none';
                el.title='Bloqueado — ativo em transferência';
            });
            // Impede submit
            form.addEventListener('submit', function(e){ e.preventDefault(); alert('Edição bloqueada: ativo em transferência. Aguarde o retorno do técnico.'); return false; }, true);
        }
    }
    function amCheckLocked(){
        try{
            var path=window.location.pathname;
            if(path.indexOf('/front/asset/asset.form.php')===-1 && path.indexOf('/front/asset/')===-1) return;
            var params=new URLSearchParams(window.location.search);
            var id=parseInt(params.get('id')||params.get('items_id')||'0',10);
            if(!id) {
                // tenta extrair do DOM (GLPI às vezes usa campo hidden)
                var hid=document.querySelector('input[name="id"]'); if(hid) id=parseInt(hid.value||'0',10);
            }
            if(!id) return;
            var base=amGetBase();
            // Usa current_record com apenas items_id (agora suporta sem itemtype)
            fetch(base + '/ajax/current_record.php?items_id=' + id)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if(data && data.transfer_status==='transferido'){
                        amLockAssetForm(data.transfers_id || '');
                    }
                }).catch(function(){});
        }catch(e){}
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', amCheckLocked);
    else amCheckLocked();
})();

// Força GRADE no mobile (viewport) — se redimensionar para <=768px estando em lista, troca para grade
(function(){
    function amEnforceGridOnMobile(){
        try{
            if(window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                var p=new URLSearchParams(window.location.search);
                if(p.get('view')==='list'){
                    p.set('view','grid');
                    var u=window.location.pathname+(p.toString()?'?'+p.toString():'');
                    window.location.replace(u);
                }
            }
        }catch(e){}
    }
    window.addEventListener('resize', function(){
        clearTimeout(window._amGridResizeTimer);
        window._amGridResizeTimer=setTimeout(amEnforceGridOnMobile, 300);
    });
})();
