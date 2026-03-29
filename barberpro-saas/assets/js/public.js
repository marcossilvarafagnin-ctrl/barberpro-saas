/* =========================================================================
   BarberPro – Frontend Booking JS
   Suporte: barbearia padrão + lava-car (variantes de porte + campos de veículo)
   ========================================================================= */
(function ($) {
    'use strict';

    var BP = {
        step:            1,
        serviceId:       null,
        serviceName:     '',
        servicePrice:    0,
        serviceDuration: 30,
        serviceVariants: [],
        variantName:     '',
        variantPrice:    null,
        proId:           null,
        proName:         '',
        date:            '',
        time:            '',
        companyId:       1,   // FIX: company_id lido do data-company
        mode:            'default', // 'default' | 'vehicle'
        deliveryOptions: {},
        deliveryType:    '',
        deliveryFee:     0,
        deliveryAddress: '',
        requireAddress:  true,

        init: function () {
            var $wrap = $('#barberproBooking');
            if (!$wrap.length) return;

            BP.mode      = $wrap.data('mode')    || 'default';
            BP.companyId = $wrap.data('company') || 1;   // FIX: lê company_id do template
            BP.deliveryOptions = $wrap.data('delivery') || {};
            BP.requireAddress  = $wrap.data('require-address') !== '0';
            BP.ajaxUrl = $wrap.data('ajaxurl') || barberproPublic.ajaxUrl;
            BP.nonce   = $wrap.data('nonce')   || barberproPublic.nonce;

            BP.bindService();
            BP.bindVariant();
            BP.bindProfessional();
            BP.bindDate();
            BP.bindConfirm();
            BP.bindBack();
            BP.bindDelivery();
        },

        // ── Helpers de navegação ────────────────────────────────────────────
        goTo: function (panel) {
            BP.step = panel;
            $('.bp-panel').hide();
            $('.bp-panel[data-panel="' + panel + '"]').fadeIn(200);
            BP.updateProgress(panel);
            $('html, body').animate({ scrollTop: $('#barberproBooking').offset().top - 20 }, 200);
        },

        updateProgress: function (step) {
            // Mapeia painéis para o número do step visual (1b conta como 1)
            var visual = (step === '1b') ? 1 : parseInt(step);
            $('.bp-step').each(function () {
                var s = parseInt($(this).data('step'));
                $(this).toggleClass('active',    s === visual)
                       .toggleClass('completed', s < visual);
            });
        },

        // ── Etapa 1: Serviço ────────────────────────────────────────────────
        bindService: function () {
            $(document).on('click', '.bp-service-card', function () {
                BP.serviceId       = $(this).data('id');
                BP.serviceName     = $(this).data('name');
                BP.servicePrice    = parseFloat($(this).data('price')) || 0;
                BP.serviceDuration = parseInt($(this).data('duration')) || 30;
                BP.serviceVariants = $(this).data('variants') || [];
                BP.variantName     = '';
                BP.variantPrice    = null;

                $('.bp-service-card').removeClass('selected');
                $(this).addClass('selected');

                // Se tem variantes → vai para tela 1b
                if (BP.serviceVariants && BP.serviceVariants.length > 0) {
                    BP.renderVariants();
                    BP.goTo('1b');
                } else {
                    BP.goTo(2);
                }
            });
        },

        // ── Etapa 1b: Variante de porte ─────────────────────────────────────
        renderVariants: function () {
            var icons = { hatch:'🚗', sedan:'🚙', suv:'🛻', pickup:'🚚', van:'🚌', p:'🚗', m:'🚙', g:'🛻' };
            var html = '';
            $.each(BP.serviceVariants, function (i, v) {
                var icon = icons[v.size_key] || '🚘';
                html += '<div class="bp-variant-card" data-idx="' + i + '">'
                      + '<div class="bp-vehicle-icon">' + icon + '</div>'
                      + '<div class="bp-vc-name">' + BP.esc(v.name) + '</div>'
                      + '<div class="bp-vc-price">R$ ' + BP.fmtMoney(v.price) + '</div>'
                      + (v.duration ? '<div class="bp-vc-dur">' + v.duration + ' min</div>' : '')
                      + '</div>';
            });
            $('#bpVariantGrid').html(html);
        },

        bindVariant: function () {
            $(document).on('click', '.bp-variant-card', function () {
                var idx = parseInt($(this).data('idx'));
                var v   = BP.serviceVariants[idx];
                BP.variantName     = v.name;
                BP.variantPrice    = v.price;
                if (v.duration) BP.serviceDuration = v.duration;

                $('.bp-variant-card').removeClass('selected');
                $(this).addClass('selected');

                // Mostra campos de veículo no step 5
                $('#bpVehicleFields').show();
                $('#bpVehiclePlate').prop('required', true);

                BP.goTo(2);
            });
        },

        // ── Etapa 2: Profissional ────────────────────────────────────────────
        bindProfessional: function () {
            $(document).on('click', '.bp-pro-card', function () {
                BP.proId   = $(this).data('id');
                BP.proName = $(this).data('name');
                $('.bp-pro-card').removeClass('selected');
                $(this).addClass('selected');
                BP.goTo(3);
            });
        },

        // ── Etapa 3: Data ────────────────────────────────────────────────────
        bindDate: function () {
            $(document).on('change', '#bpDate', function () {
                BP.date = $(this).val();
                $('#btnGoToSlots').prop('disabled', !BP.date);
            });
            $(document).on('click', '#btnGoToSlots', function () {
                if (!BP.date) return;
                BP.goTo(4);
                BP.loadSlots();
            });
        },

        loadSlots: function () {
            $('#bpSlotsWrap').html('<p class="bp-loading">⏳ Carregando horários...</p>');
            $.post(BP.ajaxUrl, {
                action:          'barberpro_get_slots',
                nonce:           BP.nonce,
                professional_id: BP.proId,
                date:            BP.date,
                service_id:      BP.serviceId,
                company_id:      BP.companyId,  // FIX: envia company_id
            }, function (res) {
                if (!res.success || !res.data.slots.length) {
                    $('#bpSlotsWrap').html('<p style="color:#ef4444">❌ Nenhum horário disponível nesta data.</p>');
                    return;
                }
                var html = '<div class="bp-slots-grid">';
                $.each(res.data.slots, function (i, slot) {
                    // slot vem como HH:MM:SS ou HH:MM — mostra só HH:MM
                    // se minutos forem :00, mostra apenas a hora (ex: "14h")
                    var parts = slot.substring(0,5).split(':');
                    var hh = parts[0], mm = parts[1]||'00';
                    var label = mm === '00' ? hh + 'h' : hh + ':' + mm;
                    html += '<button class="bp-slot-btn" data-time="' + slot + '">' + label + '</button>';
                });
                html += '</div>';
                $('#bpSlotsWrap').html(html);
            }).fail(function () {
                $('#bpSlotsWrap').html('<p style="color:#ef4444">Erro ao carregar horários.</p>');
            });
        },

        // ── Etapa 4: Horário ─────────────────────────────────────────────────
        // (bindado por delegação depois do loadSlots)

        // ── Etapa 5 → 6: Dados → Pagamento ──────────────────────────────────
        bindConfirm: function () {
            // Slot
            $(document).on('click', '.bp-slot-btn', function () {
                BP.time = $(this).data('time');
                $('.bp-slot-btn').removeClass('selected');
                $(this).addClass('selected');
                // Lava-car: vai para etapa de coleta/entrega antes dos dados do cliente
                if (BP.mode === 'vehicle' && Object.keys(BP.deliveryOptions).length > 0) {
                    BP.goTo(5);
                } else {
                    BP.goTo(5);
                }
            });

            // Botão para ir ao pagamento
            // In vehicle mode, 'Pagamento' is on panel 7
            $(document).on('click', '#btnGoToPayment', function () {
                var name  = $('#bpClientName').val().trim();
                var phone = $('#bpClientPhone').val().trim();
                if (!name || !phone) {
                    alert(barberproPublic.i18n.required_field || 'Preencha nome e telefone.');
                    return;
                }
                // Valida placa se modo veículo
                if (BP.variantPrice !== null) {
                    var plate = $('#bpVehiclePlate').val().trim();
                    if (!plate) { alert('Informe a placa do veículo.'); return; }
                }
                BP.renderSummary();
                BP.goTo(6);
            });

            // Confirmar agendamento
            $(document).on('click', '#btnConfirmBooking', function () {
                BP.submitBooking();
            });

            // Novo agendamento
            $(document).on('click', '#btnNewBooking', function () {
                location.reload();
            });
        },

        renderSummary: function () {
            var price = BP.variantPrice !== null ? BP.variantPrice : BP.servicePrice;
            var html = '<table style="width:100%;font-size:.9rem;border-collapse:collapse">';
            var totalPrice = price + (BP.deliveryFee || 0);
            var rows = [
                ['✂️ Serviço',      BP.serviceName + (BP.variantName ? ' – ' + BP.variantName : '')],
                ['👤 Profissional', BP.proName],
                ['📅 Data',         BP.fmtDate(BP.date)],
                ['🕐 Horário',      BP.time],
                ['💰 Serviço',      'R$ ' + BP.fmtMoney(price)],
            ];
            if (BP.deliveryType && BP.deliveryType !== 'cliente_traz') {
                var dlabel = BP.deliveryOptions[BP.deliveryType] ? BP.deliveryOptions[BP.deliveryType].label : BP.deliveryType;
                rows.push(['🚐 Coleta/Entrega', BP.esc(dlabel)]);
                if (BP.deliveryFee > 0) rows.push(['📦 Taxa', 'R$ ' + BP.fmtMoney(BP.deliveryFee)]);
                if (BP.deliveryAddress) rows.push(['📍 Endereço', BP.esc(BP.deliveryAddress)]);
            }
            rows.push(['💳 Total', '<strong>R$ ' + BP.fmtMoney(totalPrice) + '</strong>']);
            var plate = $('#bpVehiclePlate').val().trim();
            var model = $('#bpVehicleModel').val().trim();
            if (plate) rows.push(['🚗 Veículo', plate + (model ? ' – ' + model : '')]);
            $.each(rows, function (i, r) {
                html += '<tr><td style="color:#6b7280;padding:6px 0;width:40%">' + r[0] + '</td>'
                      + '<td style="font-weight:600;padding:6px 0">' + BP.esc(r[1]) + '</td></tr>';
            });
            html += '</table>';
            $('#bpSummary').html(html);
        },

        submitBooking: function () {
            $('#bpGlobalLoader').show();
            $('#btnConfirmBooking').prop('disabled', true).text('Enviando...');

            var data = {
                action:           'barberpro_create_booking',
                nonce:            BP.nonce,
                company_id:       BP.companyId,   // FIX: envia company_id correto
                service_id:       BP.serviceId,
                professional_id:  BP.proId,
                booking_date:     BP.date,
                booking_time:     BP.time,
                client_name:      $('#bpClientName').val().trim(),
                client_phone:     $('#bpClientPhone').val().trim(),
                client_email:     $('#bpClientEmail').val().trim(),
                payment_method:   $('input[name="payment_method"]:checked').val(),
                notes:            $('#bpNotes').val().trim(),
                delivery_type:    BP.deliveryType,
                delivery_address: BP.deliveryAddress,
                delivery_fee:     BP.deliveryFee,
                // Campos de veículo
                vehicle_plate:    $('#bpVehiclePlate').val().trim(),
                vehicle_model:    $('#bpVehicleModel').val().trim(),
                vehicle_color:    $('#bpVehicleColor').val().trim(),
                vehicle_size:     BP.serviceVariants.length ? (BP.serviceVariants.find(function(v){ return v.name === BP.variantName; }) || {}).size_key || '' : '',
                service_variant:  BP.variantName,
                amount_variant:   BP.variantPrice !== null ? BP.variantPrice : '',
            };

            $.post(BP.ajaxUrl, data, function (res) {
                $('#bpGlobalLoader').hide();
                if (res.success) {
                    BP.showSuccess(res.data);
                } else {
                    alert(res.data.message || barberproPublic.i18n.booking_error);
                    $('#btnConfirmBooking').prop('disabled', false).text('✅ Confirmar Agendamento');
                }
            }).fail(function () {
                $('#bpGlobalLoader').hide();
                alert(barberproPublic.i18n.booking_error);
                $('#btnConfirmBooking').prop('disabled', false).text('✅ Confirmar Agendamento');
            });
        },

        showSuccess: function (data) {
            $('#bpBookingCode').text(data.booking_code);
            var price = BP.variantPrice !== null ? BP.variantPrice : BP.servicePrice;
            var details = '<p>Serviço: <strong>' + BP.esc(data.service_name || BP.serviceName) + '</strong></p>'
                        + (BP.variantName ? '<p>Porte: <strong>' + BP.esc(BP.variantName) + '</strong></p>' : '')
                        + '<p>Data: <strong>' + BP.fmtDate(data.date) + ' às ' + data.time + '</strong></p>'
                        + '<p>Valor: <strong>R$ ' + BP.fmtMoney(price) + '</strong></p>';
            var plate = $('#bpVehiclePlate').val().trim();
            if (plate) details += '<p>Veículo: <strong>' + BP.esc(plate) + '</strong></p>';
            $('#bpSuccessDetails').html(details);

            // Google Agenda
            var gcal = 'https://calendar.google.com/calendar/r/eventedit?text=' +
                encodeURIComponent(BP.serviceName) + '&dates=' +
                data.date.replace(/-/g,'') + 'T' + data.time.replace(':','') + '00/' +
                data.date.replace(/-/g,'') + 'T' + data.time.replace(':','') + '00';
            $('#bpGoogleCalBtn').attr('href', gcal);

            // Verifica se precisa processar pagamento online
            var method = $('input[name="payment_method"]:checked').val() || 'presencial';
            if ( method !== 'presencial' && data.booking_code ) {
                BP.processPayment( data.booking_code, method );
            } else {
                BP.goTo(7);
            }
        },

        processPayment: function(bookingCode, method) {
            // Mostra painel de QR Code/loading
            var panel = $('#bpPaymentQrPanel');
            panel.show();
            BP.goTo('payment_qr');

            var content = $('#bpPaymentQrContent');
            content.html('<div style="text-align:center;padding:30px 0"><div class="bp-spinner"></div><p style="color:#6b7280;margin-top:12px">Gerando cobrança...</p></div>');

            $.post(barberproPublic.ajaxUrl, {
                action:         'barberpro_create_payment',
                nonce:          barberproPublic.nonce,
                booking_code:   bookingCode,
                payment_method: method,
            }, function(res) {
                if ( ! res.success ) {
                    content.html('<div style="text-align:center;color:#dc2626;padding:20px"><p>❌ ' + BP.esc(res.data.message || 'Erro ao gerar cobrança.') + '</p><button class="bp-btn-confirm" onclick="BP.goTo(7)" style="margin-top:12px">Continuar sem pagar agora</button></div>');
                    return;
                }
                var d = res.data;
                if ( d.method === 'mercadopago' && d.payment_url ) {
                    content.html(
                        '<div style="text-align:center;padding:20px">' +
                        '<div style="font-size:2.5rem;margin-bottom:12px">💳</div>' +
                        '<h3 style="margin-bottom:8px">Pagamento via Mercado Pago</h3>' +
                        '<p style="color:#6b7280;margin-bottom:20px">' + BP.esc(d.instructions||'') + '</p>' +
                        '<a href="' + d.payment_url + '" target="_blank" class="bp-btn-confirm" style="display:inline-block;text-decoration:none;padding:14px 28px;border-radius:10px;background:#009ee3;color:#fff;font-weight:700;font-size:1rem">💳 Pagar com Mercado Pago</a>' +
                        '<br><br><button onclick="BP.goTo(7)" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:.85rem;text-decoration:underline">Pagar depois (no local)</button>' +
                        '</div>'
                    );
                } else if ( d.pix_payload ) {
                    var qr = d.qr_code_base64
                        ? '<img src="' + d.qr_code_base64 + '" alt="QR Code PIX" style="width:240px;height:240px;border:4px solid #f0fdf4;border-radius:12px">'
                        : '<img src="' + d.qr_code_url + '" alt="QR Code PIX" style="width:240px;height:240px;border:4px solid #f0fdf4;border-radius:12px">';
                    var expMsg = d.expiracao_min ? '<p style="color:#d97706;font-size:.82rem;margin-top:6px">⏱ Expira em ' + d.expiracao_min + ' minutos</p>' : '';
                    content.html(
                        '<div style="text-align:center;padding:10px 0">' +
                        '<div style="font-size:2.5rem;margin-bottom:8px">⚡</div>' +
                        '<h3 style="margin-bottom:4px">Pague com PIX</h3>' +
                        '<p style="color:#6b7280;font-size:.88rem;margin-bottom:16px">' + BP.esc(d.instructions||'Escaneie o QR Code abaixo') + '</p>' +
                        qr + expMsg +
                        '<div style="margin-top:16px;background:#f0fdf4;border-radius:8px;padding:12px;font-size:.82rem">' +
                        '<p style="font-weight:700;margin-bottom:6px">Ou copie o código PIX:</p>' +
                        '<div style="display:flex;gap:6px;align-items:center">' +
                        '<input id="bpPixPayload" readonly value="' + BP.esc(d.pix_payload) + '" style="flex:1;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.72rem;background:#fff;font-family:monospace">' +
                        '<button onclick="navigator.clipboard.writeText(document.getElementById(\'bpPixPayload\').value);this.textContent=\'✓\';setTimeout(()=>this.textContent=\'Copiar\',2000)" style="padding:8px 12px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;white-space:nowrap">Copiar</button>' +
                        '</div></div>' +
                        '<div id="bpPixPolling" style="margin-top:14px;color:#6b7280;font-size:.82rem">⏳ Aguardando confirmação do pagamento...</div>' +
                        '<br><button onclick="BP.goTo(7)" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:.82rem;text-decoration:underline">Pagar depois (no local)</button>' +
                        '</div>'
                    );
                    // Polling: verifica a cada 5s se PIX foi pago
                    BP.startPixPolling(bookingCode);
                }
            }).fail(function() {
                content.html('<div style="text-align:center;color:#dc2626;padding:20px"><p>❌ Erro ao conectar. Verifique sua conexão.</p><button class="bp-btn-confirm" onclick="BP.goTo(7)">Continuar</button></div>');
            });
        },

        startPixPolling: function(bookingCode) {
            var attempts = 0;
            var maxAttempts = 24; // 2 min (a cada 5s)
            var poll = setInterval(function() {
                attempts++;
                $.get(barberproPublic.restUrl + 'barberpro/v1/pix-status', { booking: bookingCode }, function(res) {
                    if ( res.paid ) {
                        clearInterval(poll);
                        $('#bpPixPolling').html('<span style="color:#16a34a;font-weight:700">✅ Pagamento confirmado!</span>');
                        setTimeout(function(){ BP.goTo(7); }, 1500);
                    }
                });
                if ( attempts >= maxAttempts ) {
                    clearInterval(poll);
                    $('#bpPixPolling').html('<span style="color:#d97706">Tempo esgotado. Se já pagou, seu agendamento será confirmado em breve.</span>');
                }
            }, 5000);
        },

        // ── Etapa 5: Coleta/Entrega (lava-car) ─────────────────────────────────
        bindDelivery: function () {
            $(document).on('click', '.bp-delivery-card', function () {
                $('.bp-delivery-card').removeClass('selected');
                $(this).addClass('selected');
                BP.deliveryType = $(this).data('key');
                BP.deliveryFee  = parseFloat($(this).data('fee')) || 0;
                var needsAddr   = $(this).data('needs-address') === 1 || $(this).data('needs-address') === '1';

                if (needsAddr && BP.requireAddress) {
                    $('#bpAddressWrap').slideDown(200);
                    $('#bpDeliveryStreet, #bpDeliveryNeighborhood').prop('required', true);
                } else {
                    $('#bpAddressWrap').slideUp(200);
                    $('#bpDeliveryStreet, #bpDeliveryNeighborhood').prop('required', false);
                }
                $('#btnGoToClientFromDelivery').prop('disabled', false);
            });

            $(document).on('click', '#btnGoToClientFromDelivery', function () {
                // Valida endereço se necessário
                var needsAddr = $('.bp-delivery-card.selected').data('needs-address');
                if ((needsAddr === 1 || needsAddr === '1') && BP.requireAddress) {
                    var street = $('#bpDeliveryStreet').val().trim();
                    var neigh  = $('#bpDeliveryNeighborhood').val().trim();
                    if (!street || !neigh) {
                        alert('Por favor, informe a rua e o bairro para coleta/entrega.');
                        return;
                    }
                    BP.deliveryAddress = street + ', ' + neigh;
                    var comp  = $('#bpDeliveryComplement').val().trim();
                    var notes = $('#bpDeliveryNotes').val().trim();
                    if (comp)  BP.deliveryAddress += ' – ' + comp;
                    if (notes) BP.deliveryAddress += ' (' + notes + ')';
                }
                BP.goTo(6); // dados do cliente
            });
        },

        bindBack: function () {
            $(document).on('click', '.bp-btn-back', function () {
                var cur = parseInt(BP.step) || BP.step;
                if (cur === '1b' || cur === 2) {
                    if (cur === '1b') {
                        BP.variantName = ''; BP.variantPrice = null;
                        $('#bpVehicleFields').hide();
                        $('#bpVehiclePlate').prop('required', false);
                    }
                    BP.goTo(1);
                } else if (cur === 3) {
                    BP.goTo(BP.serviceVariants && BP.serviceVariants.length ? '1b' : 2);
                } else if (cur === 5 && BP.mode === 'vehicle') {
                    BP.goTo(4); // volta para horário
                } else if (cur === 6 && BP.mode === 'vehicle') {
                    // volta para entrega se tem opções, senão para horário
                    var hasDelivery = Object.keys(BP.deliveryOptions).length > 0;
                    BP.goTo(hasDelivery ? 5 : 4);
                } else {
                    BP.goTo(parseInt(cur) - 1);
                }
            });
        },

        // ── Utils ────────────────────────────────────────────────────────────
        fmtMoney: function (v) {
            return parseFloat(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        fmtDate: function (d) {
            if (!d) return '';
            var p = d.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
        },
        esc: function (s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },
    };

    $(document).ready(function () { BP.init(); });

})(jQuery);
