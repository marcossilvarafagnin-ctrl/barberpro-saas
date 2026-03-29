/* BarberPro Widget Chat — JS (v3.8.8 slots + pagamento) */
(function () {
    'use strict';

    var SESSION_KEY = 'bp_wc_session';
    var STATE = {
        open: false, session: '', etapa: '', slots: [],
        started: false, sending: false,
    };

    // ── localStorage seguro (Safari privado) ──────────────────
    var LS = {
        get:    function(k)   { try { return localStorage.getItem(k); }    catch(e) { return null; } },
        set:    function(k,v) { try { localStorage.setItem(k, v); }        catch(e) {} },
        remove: function(k)   { try { localStorage.removeItem(k); }        catch(e) {} },
    };

    // ── Typing delay — simula digitação humana ────────────────
    // Calcula delay baseado no tamanho do texto: mínimo 600ms, +20ms por char, máx 2200ms
    function typingDelay(text) {
        var len = (text || '').length;
        return Math.min(600 + len * 20, 2200);
    }

    // Mostra pontinhos, depois de `delay`ms esconde e chama callback
    // slotButtons: opcional [{label, value}] — botões dentro do balão (horários)
    function withTyping(text, type, callback, slotButtons, paymentBtn) {
        showTyping();
        setTimeout(function() {
            hideTyping();
            addBotMessage(text, type, slotButtons, paymentBtn);
            if (callback) callback();
        }, typingDelay(text));
    }

    // Enfileira múltiplas mensagens com delay encadeado
    function typeSequence(messages, onDone, slotButtonsLast, paymentBtnLast) {
        if (!messages || !messages.length) { if (onDone) onDone(); return; }
        var i = 0;
        var n = messages.length;
        function next() {
            if (i >= n) { if (onDone) onDone(); return; }
            var m = messages[i];
            var last = (i === n - 1);
            var slots = (last && slotButtonsLast && slotButtonsLast.length) ? slotButtonsLast : null;
            var pay   = (last && paymentBtnLast && paymentBtnLast.url) ? paymentBtnLast : null;
            i++;
            withTyping(m.text, m.type || '', function() {
                setTimeout(next, 150);
            }, slots, pay);
        }
        next();
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        if (!document.getElementById('bpWidgetChat')) return;
        if (typeof bpWidgetData === 'undefined') return;

        var cor = bpWidgetData.cor || '#f5a623';
        document.documentElement.style.setProperty('--bp-wc-cor', cor);

        STATE.session = LS.get(SESSION_KEY) || generateId();
        LS.set(SESSION_KEY, STATE.session);

        var toggle = document.getElementById('bpWcToggle');
        if (toggle) toggle.addEventListener('click', toggleChat);

        var input = document.getElementById('bpWcInput');
        if (input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
            });
            input.addEventListener('input', function() {
                var btn = document.getElementById('bpWcSend');
                if (btn) btn.disabled = !this.value.trim();
            });
        }

        window.bpWcClose = closeChat;
        window.bpWcSend  = sendMsg;

        setTimeout(function() { if (!STATE.open) showBadge(); }, 3000);
    }

    // ── Toggle ────────────────────────────────────────────────
    function toggleChat() { STATE.open ? closeChat() : openChat(); }

    function openChat() {
        STATE.open = true;
        hideBadge();
        var win = document.getElementById('bpWcWindow');
        if (!win) return;
        win.style.cssText = 'display:flex !important; flex-direction:column;';
        setIcon(false);

        if (!STATE.started) {
            STATE.started = true;
            // Pequeno delay antes da primeira mensagem — parece que o bot "acordou"
            setTimeout(startConversation, 500);
        }
        setTimeout(function() {
            var inp = document.getElementById('bpWcInput');
            if (inp) { try { inp.focus(); } catch(e) {} }
            scrollToBottom();
        }, 120);
    }

    function closeChat() {
        STATE.open = false;
        var win = document.getElementById('bpWcWindow');
        if (win) win.style.cssText = 'display:none !important;';
        setIcon(true);
    }

    function setIcon(showOpen) {
        var open  = document.querySelector('.bp-wc-toggle-open');
        var close = document.querySelector('.bp-wc-toggle-close');
        if (open)  open.style.display  = showOpen ? '' : 'none';
        if (close) close.style.display = showOpen ? 'none' : '';
    }

    function showBadge() {
        var b = document.getElementById('bpWcBadge');
        if (b) b.style.display = 'flex';
    }
    function hideBadge() {
        var b = document.getElementById('bpWcBadge');
        if (b) b.style.display = 'none';
    }

    // ── Conversa ──────────────────────────────────────────────
    function startConversation() {
        ajax({ chat_action: 'start', session_id: STATE.session }, function(data) {
            if (!data) {
                withTyping('Olá! 😊 Como posso ajudar?', '', null);
                return;
            }
            if (data.restored) {
                var bvMsg = data.etapa === 'ia_livre'
                    ? 'Olá de novo! 👋 No que posso ajudar?'
                    : 'Bem-vindo de volta! 👋 Vamos continuar?';
                var bvReplies = data.etapa === 'ia_livre'
                    ? ['Quero agendar', 'Ver horários']
                    : ['Sim, continuar', 'Começar do zero'];
                withTyping(bvMsg, '', function() {
                    setQuickReplies(bvReplies);
                });
            } else {
                STATE.etapa = data.etapa || 'coletar_nome';
                withTyping(data.message || 'Para começar, qual é o seu nome?', '', function() {
                    setQuickReplies(data.quick_replies || []);
                });
            }
        });
    }

    function sendMsg() {
        var input = document.getElementById('bpWcInput');
        if (!input) return;
        var msg = input.value.trim();
        if (!msg || STATE.sending) return;
        input.value = '';
        input.dispatchEvent(new Event('input'));
        addUserMessage(msg);
        clearQuickReplies();
        sendToServer(msg);
    }

    function sendToServer(msg) {
        if (STATE.sending) return;
        STATE.sending = true;

        // Delay curto antes de mostrar pontinhos — parece que o bot está lendo
        var readDelay = 300 + Math.random() * 400; // 300–700ms
        setTimeout(function() {
            showTyping();
        }, readDelay);

        // Reiniciar conversa
        if (msg === 'Começar do zero') {
            setTimeout(function() {
                LS.remove(SESSION_KEY);
                STATE.session = generateId();
                LS.set(SESSION_KEY, STATE.session);
                STATE.started = false;
                hideTyping();
                STATE.sending = false;
                var msgs = document.getElementById('bpWcMessages');
                if (msgs) msgs.innerHTML = '';
                var saudacao = (bpWidgetData && bpWidgetData.saudacao) || 'Olá! Posso te ajudar 😊';
                withTyping(saudacao, '', function() {
                    startConversation();
                });
            }, 800);
            return;
        }

        ajax({ chat_action: 'message', session_id: STATE.session, message: msg }, function(data) {
            STATE.sending = false;
            hideTyping();

            if (!data || data.error) {
                withTyping('Ops! Ocorreu um erro. Tente novamente 😕', '', null);
                return;
            }

            STATE.etapa = data.etapa || STATE.etapa;
            if (data.slots) STATE.slots = data.slots;

            var isSuccess = !!(data.success && data.etapa === 'concluido');
            var text      = data.message || '';
            var replies   = data.quick_replies || [];
            var slotBtns  = buildSlotButtons(data);
            var hasSlotUi = !!(slotBtns && slotBtns.length);
            var payUrl = data.payment_url ? String(data.payment_url).trim() : '';
            var paymentBtn = /^https?:\/\//i.test(payUrl)
                ? { url: payUrl, label: (data.payment_button_label || '💳 Pagar agora') }
                : null;

            // Com botões de horário no balão: um único bloco (evita quebrar texto e perder botões)
            var paragrafos = hasSlotUi
                ? [text]
                : text.split(/\n\n+/).map(function(t) { return t.trim(); }).filter(Boolean);
            if (!paragrafos.length) paragrafos = [''];

            var repliesAfterSlots = hasSlotUi ? [] : replies;

            if (paragrafos.length > 1) {
                var msgs_seq = paragrafos.map(function(p, idx) {
                    return { text: p, type: (isSuccess && idx === paragrafos.length - 1) ? 'success' : '' };
                });
                typeSequence(msgs_seq, function() {
                    setQuickReplies(repliesAfterSlots);
                    if (isSuccess) lockInput();
                }, slotBtns, paymentBtn);
            } else {
                withTyping(paragrafos[0], isSuccess ? 'success' : '', function() {
                    setQuickReplies(repliesAfterSlots);
                    if (isSuccess) lockInput();
                }, hasSlotUi ? slotBtns : null, paymentBtn);
            }
        });
    }

    function lockInput() {
        var inp = document.getElementById('bpWcInput');
        var btn = document.getElementById('bpWcSend');
        if (inp) { inp.disabled = true; inp.placeholder = 'Agendamento concluído ✅'; }
        if (btn) btn.disabled = true;
    }

    // ── Mensagens ─────────────────────────────────────────────
    function slotLabelFromSlot(s) {
        s = String(s);
        var m = s.match(/^(\d{1,2}):(\d{2})/);
        if (!m) return s;
        var hh = ('0' + m[1]).slice(-2);
        var mm = m[2];
        return mm === '00' ? hh + 'h' : hh + 'h' + mm;
    }

    /** Monta [{label, value}] a partir do JSON do PHP (slot_buttons ou slots). */
    function buildSlotButtons(data) {
        if (data.slot_buttons && data.slot_buttons.length) {
            var out = [];
            for (var i = 0; i < data.slot_buttons.length; i++) {
                var sb = data.slot_buttons[i];
                if (!sb) continue;
                var val = sb.value != null ? String(sb.value) : '';
                var lab = sb.label != null ? String(sb.label) : val;
                if (val || lab) out.push({ label: lab || val, value: val || lab });
            }
            return out.length ? out : null;
        }
        if (data.etapa === 'escolher_horario' && data.slots && data.slots.length) {
            var o2 = [];
            for (var j = 0; j < data.slots.length; j++) {
                var sl = data.slots[j];
                o2.push({ label: slotLabelFromSlot(sl), value: String(sl) });
            }
            return o2.length ? o2 : null;
        }
        return null;
    }

    function addBotMessage(text, type, slotButtons, paymentBtn) {
        var msgs = document.getElementById('bpWcMessages');
        if (!msgs) return;
        var wrap   = document.createElement('div');
        wrap.className = 'bp-wc-msg bp-wc-msg-bot bp-wc-msg-in' + (type ? ' bp-wc-msg-' + type : '');
        var bubble = document.createElement('div');
        bubble.className = 'bp-wc-msg-bubble';
        bubble.innerHTML = formatText(text || '');
        if (slotButtons && slotButtons.length) {
            var grid = document.createElement('div');
            grid.className = 'bp-wc-slot-grid';
            for (var si = 0; si < slotButtons.length; si++) {
                (function(btn) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'bp-wc-slot-btn';
                    b.setAttribute('aria-label', 'Horário ' + btn.label);
                    b.textContent = btn.label;
                    b.onclick = function(ev) {
                        ev.preventDefault();
                        if (STATE.sending) return;
                        clearQuickReplies();
                        addUserMessage(btn.label);
                        sendToServer(btn.value);
                    };
                    grid.appendChild(b);
                })(slotButtons[si]);
            }
            bubble.appendChild(grid);
        }
        if (paymentBtn && paymentBtn.url) {
            var payWrap = document.createElement('div');
            payWrap.className = 'bp-wc-pay-wrap';
            var pb = document.createElement('button');
            pb.type = 'button';
            pb.className = 'bp-wc-pay-btn';
            pb.setAttribute('aria-label', paymentBtn.label || 'Pagar agora');
            pb.textContent = paymentBtn.label || '💳 Pagar agora';
            pb.onclick = function(ev) {
                ev.preventDefault();
                window.open(paymentBtn.url, '_blank', 'noopener,noreferrer');
            };
            payWrap.appendChild(pb);
            bubble.appendChild(payWrap);
        }
        var time = document.createElement('div');
        time.className = 'bp-wc-msg-time';
        time.textContent = getTime();
        wrap.appendChild(bubble);
        wrap.appendChild(time);
        msgs.appendChild(wrap);
        scrollToBottom();
    }

    function addUserMessage(text) {
        var msgs = document.getElementById('bpWcMessages');
        if (!msgs) return;
        var wrap   = document.createElement('div');
        wrap.className = 'bp-wc-msg bp-wc-msg-user bp-wc-msg-in';
        var bubble = document.createElement('div');
        bubble.className = 'bp-wc-msg-bubble';
        bubble.textContent = text;
        var time = document.createElement('div');
        time.className = 'bp-wc-msg-time';
        time.textContent = getTime();
        wrap.appendChild(bubble);
        wrap.appendChild(time);
        msgs.appendChild(wrap);
        scrollToBottom();
    }

    // ── Typing indicator ─────────────────────────────────────
    function showTyping() {
        var msgs = document.getElementById('bpWcMessages');
        if (!msgs || document.getElementById('bpWcTyping')) return;
        var wrap = document.createElement('div');
        wrap.id = 'bpWcTyping';
        wrap.className = 'bp-wc-msg bp-wc-msg-bot bp-wc-typing';
        wrap.innerHTML  = '<div class="bp-wc-msg-bubble">'
                        + '<div class="bp-wc-typing-dots">'
                        + '<span></span><span></span><span></span>'
                        + '</div></div>';
        msgs.appendChild(wrap);
        scrollToBottom();
    }

    function hideTyping() {
        var t = document.getElementById('bpWcTyping');
        if (t && t.parentNode) t.parentNode.removeChild(t);
    }

    // ── Quick replies ─────────────────────────────────────────
    function setQuickReplies(replies) {
        var wrap = document.getElementById('bpWcQuickReplies');
        if (!wrap) return;
        wrap.innerHTML = '';
        if (!replies || !replies.length) return;
        // Anima os botões aparecendo em cascata
        for (var i = 0; i < replies.length; i++) {
            (function(r, delay) {
                var btn = document.createElement('button');
                btn.className = 'bp-wc-qr';
                btn.textContent = r;
                btn.style.opacity = '0';
                btn.style.transform = 'translateY(6px)';
                btn.style.transition = 'opacity .2s, transform .2s';
                btn.onclick = function() {
                    var inp = document.getElementById('bpWcInput');
                    if (inp) inp.value = r;
                    sendMsg();
                };
                wrap.appendChild(btn);
                setTimeout(function() {
                    btn.style.opacity = '1';
                    btn.style.transform = 'translateY(0)';
                }, delay);
            })(replies[i], i * 80);
        }
    }

    function clearQuickReplies() {
        var wrap = document.getElementById('bpWcQuickReplies');
        if (wrap) wrap.innerHTML = '';
    }

    // ── Helpers ───────────────────────────────────────────────
    function formatText(text) {
        return String(text)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/\*([^*]+)\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }
    function getTime() {
        var d  = new Date();
        var hh = ('0' + d.getHours()).slice(-2);
        var mm = ('0' + d.getMinutes()).slice(-2);
        return hh + ':' + mm;
    }
    function scrollToBottom() {
        var msgs = document.getElementById('bpWcMessages');
        if (msgs) msgs.scrollTop = msgs.scrollHeight;
    }
    function generateId() {
        return 'bpwc_' + Math.random().toString(36).slice(2) + '_' + Date.now();
    }

    // ── AJAX com timeout ──────────────────────────────────────
    function ajax(params, callback) {
        var ajaxUrl = (bpWidgetData && bpWidgetData.ajaxUrl) || '';
        var nonce   = (bpWidgetData && bpWidgetData.nonce)   || '';
        if (!ajaxUrl) { callback(null); return; }

        var fd = new FormData();
        fd.append('action', 'bp_widget_chat');
        fd.append('nonce',  nonce);
        for (var k in params) {
            if (Object.prototype.hasOwnProperty.call(params, k)) fd.append(k, params[k]);
        }

        var done = false;
        var timer = setTimeout(function() {
            if (!done) { done = true; callback({ error: true }); }
        }, 45000);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(res) {
                if (done) return;
                done = true; clearTimeout(timer);
                if (res && res.success && res.data) callback(res.data);
                else callback({ error: true });
            })
            .catch(function() {
                if (done) return;
                done = true; clearTimeout(timer);
                callback({ error: true });
            });
    }

    // ── Boot ──────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
