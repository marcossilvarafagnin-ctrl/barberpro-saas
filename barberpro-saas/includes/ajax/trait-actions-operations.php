<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Actions_Operations {

    private function action_update_booking_status(): void {
        $id     = absint($_POST['booking_id']);
        $status = sanitize_key($_POST['status']);
        $allowed = ['agendado','confirmado','em_atendimento','finalizado','cancelado','no_show'];
        if ( ! $id )                       wp_send_json_error(['message'=>'ID invalido.']);
        if ( ! in_array($status,$allowed) ) wp_send_json_error(['message'=>'Status invalido.']);

        $ok = BarberPro_Database::update_booking_status($id, $status);
        if ( $ok && $status === 'finalizado' ) {
            $booking = BarberPro_Database::get_booking($id);
            if ( $booking ) do_action('barberpro_booking_finished', $booking);
        }
        $ok ? wp_send_json_success() : wp_send_json_error(['message'=>'Erro ao atualizar status.']);
    }

    private function action_get_booking_form(): void {
        $cid  = absint($_POST['company_id']);
        $pros = BarberPro_Database::get_professionals($cid);
        $svcs = BarberPro_Database::get_services($cid);
        $today = current_time('Y-m-d');
        ob_start(); ?>
        <div class="bp-modal-header"><div class="bp-modal-title">📅 Novo Agendamento</div><button class="bp-modal-close" onclick="BP.closeModal()">✕</button></div>
        <div class="bp-modal-body">
            <input type="hidden" id="nb_cid" value="<?php echo $cid; ?>">
            <input type="hidden" id="nb_time" value="">
            <div class="bp-field"><label>Nome do Cliente *</label><input type="text" id="nb_name" placeholder="Nome completo" required></div>
            <div class="bp-field"><label>WhatsApp / Telefone</label><input type="tel" id="nb_phone" placeholder="(44) 99999-0000"></div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Data *</label>
                    <input type="date" id="nb_date" value="<?php echo $today; ?>" required
                           onchange="bpAdminLoadSlots()">
                </div>
                <div class="bp-field">
                    <label>Serviço *</label>
                    <select id="nb_svc" required onchange="bpAdminLoadSlots()">
                        <option value="">-- Selecione --</option>
                        <?php foreach($svcs as $s): ?>
                        <option value="<?php echo $s->id; ?>" data-dur="<?php echo (int)($s->duration_minutes ?? $s->duration ?? 30); ?>">
                            <?php echo esc_html($s->name.' – R$'.number_format($s->price,2,',','.')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="bp-field">
                <label>Profissional</label>
                <select id="nb_pro" onchange="bpAdminLoadSlots()">
                    <option value="">-- Qualquer --</option>
                    <?php foreach($pros as $p): ?>
                    <option value="<?php echo $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bp-field">
                <label>Horário *</label>
                <div id="nb_slots_wrap" style="min-height:44px;display:flex;align-items:center">
                    <span style="color:var(--text3);font-size:.85rem">Selecione data, serviço e profissional para ver os horários disponíveis.</span>
                </div>
            </div>
            <div class="bp-field"><label>Observações</label><textarea id="nb_notes" rows="2"></textarea></div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpSaveBooking()">✅ Agendar</button>
        </div>
        <script>
        // Carrega slots assim que o modal abre se campos já tiverem valor
        (function(){ if(document.getElementById('nb_svc').value) bpAdminLoadSlots(); })();
        </script>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_get_admin_slots(): void {
        $pro_id  = absint($_POST['professional_id'] ?? 0);
        $svc_id  = absint($_POST['service_id'] ?? 0);
        $date    = sanitize_text_field($_POST['booking_date'] ?? '');
        $cid     = absint($_POST['company_id'] ?? 0);

        if ( ! $date || ! $svc_id ) {
            wp_send_json_error(['message' => 'Data e serviço são obrigatórios.']);
        }

        $service = BarberPro_Database::get_service($svc_id);
        if ( ! $service ) wp_send_json_error(['message' => 'Serviço não encontrado.']);

        $dur = (int)($service->duration_minutes ?? $service->duration ?? 30);

        // Se nenhum profissional escolhido, busca slots de todos e une
        if ( $pro_id === 0 ) {
            $pros = BarberPro_Database::get_professionals($cid);
            $merged = [];
            foreach ( $pros as $p ) {
                $slots = BarberPro_Bookings::get_available_slots((int)$p->id, $date, $dur, true);
                foreach ($slots as $s) {
                    $merged[$s] = true; // dedup
                }
            }
            ksort($merged);
            $slots = array_keys($merged);
        } else {
            $slots = BarberPro_Bookings::get_available_slots($pro_id, $date, $dur, true);
        }

        wp_send_json_success(['slots' => $slots]);
    }

    private function action_save_booking(): void {
        $cid         = absint($_POST['company_id']);
        $client_name = sanitize_text_field($_POST['client_name']??'');
        $date        = sanitize_text_field($_POST['booking_date']??'');
        $time        = sanitize_text_field($_POST['booking_time']??'');
        $service_id  = absint($_POST['service_id']??0);
        $pro_id      = absint($_POST['professional_id']??0);

        // Validações
        if ( empty($client_name) ) wp_send_json_error(['message'=>'Informe o nome do cliente.']);
        if ( empty($date) )        wp_send_json_error(['message'=>'Informe a data.']);
        if ( empty($time) )        wp_send_json_error(['message'=>'Informe o horário.']);
        if ( ! $service_id )       wp_send_json_error(['message'=>'Selecione um serviço.']);

        // Se nenhum profissional escolhido, pega o primeiro ativo do módulo
        if ( ! $pro_id ) {
            $pros = BarberPro_Database::get_professionals($cid);
            $pro_id = ! empty($pros) ? (int)$pros[0]->id : 0;
        }
        if ( ! $pro_id ) wp_send_json_error(['message'=>'Nenhum profissional disponível. Cadastre um primeiro.']);

        $result = BarberPro_Bookings::create_booking([
            'company_id'      => $cid,
            'service_id'      => $service_id,
            'professional_id' => $pro_id,
            'client_name'     => $client_name,
            'client_phone'    => sanitize_text_field($_POST['client_phone']??''),
            'client_email'    => sanitize_email($_POST['client_email']??''),
            'booking_date'    => $date,
            'booking_time'    => $time,
            'notes'           => sanitize_textarea_field($_POST['notes']??''),
            'status'          => 'agendado',
            'payment_method'  => 'presencial',
            'admin_mode'      => true, // admin usa intervalo de 15min
        ]);

        if ( ! empty($result['success']) ) {
            wp_send_json_success(['booking_id' => $result['booking_id'] ?? 0]);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Erro ao salvar agendamento.']);
        }
    }

    private function action_get_service_form(): void {
        $id  = absint($_POST['service_id']??0);
        $cid = absint($_POST['company_id']);
        $s   = $id ? BarberPro_Database::get_service($id) : null;
        ob_start(); ?>
        <div class="bp-modal-header"><div class="bp-modal-title"><?php echo $s?'Editar Serviço':'Novo Serviço'; ?></div><button class="bp-modal-close" onclick="BP.closeModal()">✕</button></div>
        <div class="bp-modal-body">
            <input type="hidden" id="sf_id" value="<?php echo $id; ?>">
            <input type="hidden" id="sf_cid" value="<?php echo $cid; ?>">
            <div class="bp-field"><label>Nome *</label><input type="text" id="sf_name" value="<?php echo esc_attr($s->name??''); ?>" required></div>
            <div class="bp-field"><label>Descrição</label><textarea id="sf_desc" rows="2"><?php echo esc_textarea($s->description??''); ?></textarea></div>
            <div class="bp-field-row">
                <div class="bp-field"><label>Preço (R$) *</label><input type="text" id="sf_price" value="<?php echo $s?number_format($s->price,2,',','.'):''; ?>" required></div>
                <div class="bp-field"><label>Duração (min)</label><input type="number" id="sf_dur" value="<?php echo esc_attr($s->duration??30); ?>" min="5" step="5"></div>
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpSaveService()">✅ Salvar</button>
        </div>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_save_service(): void {
        $id   = absint($_POST['service_id']??0);
        $cid  = absint($_POST['company_id']);
        $name = sanitize_text_field($_POST['name']??'');
        if ( empty($name) ) wp_send_json_error(['message'=>'Informe o nome do servico.']);
        if ( ! $cid )       wp_send_json_error(['message'=>'Empresa invalida.']);

        // Coluna na tabela e "duration" (nao "duration_minutes")
        $data = [
            'company_id'  => $cid,
            'name'        => $name,
            'description' => sanitize_textarea_field($_POST['description']??''),
            'price'       => round((float)str_replace(',','.',$_POST['price']??'0'), 2),
            'duration'    => absint($_POST['duration_minutes']??30),
            'status'      => 'active',
        ];

        global $wpdb;
        if ( $id ) {
            // update_service returns true even if no rows changed (same data) - safe
            BarberPro_Database::update_service($id, $data);
            if ( ! $wpdb->last_error ) {
                wp_send_json_success(['id'=>$id]);
            } else {
                wp_send_json_error(['message'=>'Erro ao atualizar serviço: '.$wpdb->last_error]);
            }
        } else {
            $new_id = BarberPro_Database::insert_service($data);
            $new_id ? wp_send_json_success(['id'=>$new_id])
                    : wp_send_json_error(['message'=>'Erro ao criar serviço: '.($wpdb->last_error?:'verifique os dados.')]);
        }
    }

    private function action_get_pro_form(): void {
        $id  = absint($_POST['pro_id']??0);
        $cid = absint($_POST['company_id']);
        global $wpdb;
        $p   = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}barber_professionals WHERE id=%d",$id)) : null;
        $lbl = $cid===1?'Profissional':'Atendente';

        // Defaults
        $work_start          = $p->work_start          ?? '09:00:00';
        $work_end            = $p->work_end            ?? '18:00:00';
        $lunch_start         = $p->lunch_start         ?? '12:00:00';
        $lunch_end           = $p->lunch_end           ?? '13:00:00';
        $slot_interval       = $p->slot_interval       ?? 15;
        $client_slot_interval= $p->client_slot_interval?? 60;
        $work_days_arr       = $p ? array_map('intval', explode(',', $p->work_days)) : [1,2,3,4,5];

        $days_labels = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        ob_start(); ?>
        <div class="bp-modal-header">
            <div class="bp-modal-title"><?php echo $p?"Editar {$lbl}":"Novo {$lbl}"; ?></div>
            <button class="bp-modal-close" onclick="BP.closeModal()">✕</button>
        </div>
        <div class="bp-modal-body" style="max-height:75vh;overflow-y:auto">
            <input type="hidden" id="pf_id"  value="<?php echo $id; ?>">
            <input type="hidden" id="pf_cid" value="<?php echo $cid; ?>">

            <!-- ── Dados Básicos ───────────────────────────────────────────── -->
            <div style="font-size:.75rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.6px;margin:0 0 10px">Dados</div>
            <div class="bp-field"><label>Nome *</label>
                <input type="text" id="pf_name" value="<?php echo esc_attr($p->name??''); ?>" required>
            </div>
            <div class="bp-field"><label>Especialidade</label>
                <input type="text" id="pf_spec" value="<?php echo esc_attr($p->specialty??''); ?>" placeholder="Ex: Corte, Barba, Coloração...">
            </div>
            <div class="bp-field-row">
                <div class="bp-field"><label>Comissão %</label>
                    <input type="number" id="pf_comm" value="<?php echo esc_attr($p->commission_pct??40); ?>" min="0" max="100" step="0.5">
                </div>
                <div class="bp-field"><label>WhatsApp</label>
                    <input type="tel" id="pf_phone" value="<?php echo esc_attr($p->phone??''); ?>" placeholder="55449...">
                </div>
            </div>

            <!-- ── Dias de Trabalho ────────────────────────────────────────── -->
            <div style="font-size:.75rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.6px;margin:16px 0 10px">📅 Dias de Trabalho</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
                <?php foreach($days_labels as $d=>$label): ?>
                <label style="display:flex;flex-direction:column;align-items:center;gap:4px;cursor:pointer">
                    <input type="checkbox" name="pf_days[]" value="<?php echo $d; ?>"
                           id="pf_day_<?php echo $d; ?>"
                           <?php echo in_array($d,$work_days_arr)?'checked':''; ?>
                           style="width:18px;height:18px;accent-color:var(--accent)">
                    <span style="font-size:.78rem;font-weight:600;color:var(--text2)"><?php echo $label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- ── Horários ────────────────────────────────────────────────── -->
            <div style="font-size:.75rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🕐 Horários</div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Início do expediente</label>
                    <input type="time" id="pf_work_start" value="<?php echo esc_attr(substr($work_start,0,5)); ?>">
                </div>
                <div class="bp-field">
                    <label>Fim do expediente</label>
                    <input type="time" id="pf_work_end" value="<?php echo esc_attr(substr($work_end,0,5)); ?>">
                </div>
            </div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Início do almoço</label>
                    <input type="time" id="pf_lunch_start" value="<?php echo esc_attr(substr($lunch_start,0,5)); ?>">
                </div>
                <div class="bp-field">
                    <label>Fim do almoço</label>
                    <input type="time" id="pf_lunch_end" value="<?php echo esc_attr(substr($lunch_end,0,5)); ?>">
                </div>
            </div>

            <!-- ── Intervalos de Slots ─────────────────────────────────────── -->
            <div style="font-size:.75rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.6px;margin:16px 0 10px">⏱ Intervalos de Agendamento</div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>✂️ Intervalo admin (barbeiro)</label>
                    <select id="pf_slot_interval">
                        <?php foreach([5,10,15,20,30,45,60] as $m): ?>
                        <option value="<?php echo $m; ?>" <?php selected((int)$slot_interval,$m); ?>><?php echo $m; ?> min</option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--text3)">No painel admin</small>
                </div>
                <div class="bp-field">
                    <label>📱 Intervalo cliente (público)</label>
                    <select id="pf_client_slot_interval">
                        <?php foreach([15,30,60,90,120] as $m): ?>
                        <option value="<?php echo $m; ?>" <?php selected((int)$client_slot_interval,$m); ?>><?php echo $m; ?> min</option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--text3)">No site público</small>
                </div>
            </div>
            <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:10px 12px;font-size:.8rem;color:var(--text2)">
                💡 <strong>Exemplo:</strong> Expediente 09:00–18:00, almoço 12:00–13:00, intervalo cliente 60min → cliente vê: 09:00 · 10:00 · 11:00 · 13:00 · 14:00 ...
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpSavePro()">✅ Salvar</button>
        </div>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_save_pro(): void {
        global $wpdb;
        $id   = absint($_POST['pro_id']??0);
        $cid  = absint($_POST['company_id']);
        $name = sanitize_text_field($_POST['name']??'');
        if ( empty($name) ) wp_send_json_error(['message'=>'Informe o nome do profissional.']);
        if ( ! $cid )       wp_send_json_error(['message'=>'Empresa invalida.']);

        // Dias da semana — JS manda string "0,1,2,3,4,5,6", formulário HTML manda array
        $raw_days  = $_POST['work_days'] ?? '1,2,3,4,5';
        if ( is_array($raw_days) ) {
            $work_days = implode(',', array_map('intval', $raw_days));
        } else {
            // Sanitiza a string: só dígitos e vírgulas
            $work_days = preg_replace('/[^0-9,]/', '', sanitize_text_field((string)$raw_days));
        }
        if ( empty($work_days) ) $work_days = '1,2,3,4,5';

        $data = [
            'company_id'          => $cid,
            'name'                => $name,
            'specialty'           => sanitize_text_field($_POST['specialty']??''),
            'commission_pct'      => round((float)($_POST['commission_pct']??0), 2),
            'phone'               => sanitize_text_field($_POST['phone']??''),
            'work_days'           => $work_days,
            'work_start'          => sanitize_text_field($_POST['work_start']??'09:00'),
            'work_end'            => sanitize_text_field($_POST['work_end']??'18:00'),
            'lunch_start'         => sanitize_text_field($_POST['lunch_start']??'12:00'),
            'lunch_end'           => sanitize_text_field($_POST['lunch_end']??'13:00'),
            'slot_interval'       => max(5,  absint($_POST['slot_interval']??15)),
            'client_slot_interval'=> max(15, absint($_POST['client_slot_interval']??60)),
            'status'              => 'active',
            'updated_at'          => current_time('mysql'),
        ];

        if ( $id ) {
            $r = $wpdb->update("{$wpdb->prefix}barber_professionals", $data, ['id'=>$id]);
            $r !== false ? wp_send_json_success(['id'=>$id])
                         : wp_send_json_error(['message'=>'Erro ao atualizar profissional: '.($wpdb->last_error?:'registro não encontrado.')]);
        } else {
            $data['created_at'] = current_time('mysql');
            $r = $wpdb->insert("{$wpdb->prefix}barber_professionals", $data);
            $r ? wp_send_json_success(['id'=>$wpdb->insert_id])
               : wp_send_json_error(['message'=>'Erro ao criar profissional: '.$wpdb->last_error]);
        }
    }

    private function action_open_bar_comanda(): void {
        $id = BarberPro_Bar::open_comanda(['table_number'=>sanitize_text_field($_POST['table_number']??''),'client_name'=>sanitize_text_field($_POST['client_name']??'')]);
        $id ? wp_send_json_success(['id'=>$id]) : wp_send_json_error(['message'=>'Erro ao abrir comanda.']);
    }

    private function action_get_bar_comanda_view(): void {
        $id      = absint($_POST['comanda_id']);
        $comanda = BarberPro_Bar::get_comanda($id);
        if(!$comanda) wp_send_json_error(['message'=>'Comanda não encontrada.']);
        $items    = BarberPro_Bar::get_items($id);
        $products = BarberPro_Bar::get_products();
        $id_label = trim(($comanda->table_number?'Mesa '.$comanda->table_number:'').($comanda->client_name?' – '.$comanda->client_name:''))?:'-';
        $disc_val = $comanda->discount_type==='percentual'?round((float)$comanda->total_items*(float)$comanda->discount/100,2):(float)$comanda->discount;
        $mlabels  = bp_get_payment_methods();
        $aguardando = $comanda->status === 'aguardando_pagamento';
        $is_open    = in_array($comanda->status, ['aberta','aguardando_pagamento']);
        ob_start(); ?>
        <div class="bp-modal-header">
            <div>
                <div class="bp-modal-title">🧾 <?php echo esc_html($id_label); ?>
                    <?php if($aguardando): ?>
                    <span style="font-size:.72rem;background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:20px;padding:2px 8px;margin-left:6px;font-weight:700">💳 Aguardando Pagamento</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.76rem;color:var(--text3);font-family:var(--font-mono)">#<?php echo esc_html($comanda->comanda_code); ?></div>
            </div>
            <button class="bp-modal-close" onclick="BP.closeModal()">✕</button>
        </div>
        <div class="bp-modal-body">
            <?php if($is_open): ?>
            <!-- Adicionar produto -->
            <div style="margin-bottom:16px">
                <div style="font-size:.8rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Adicionar Produto</div>
                <?php
                $prods_disp = array_filter($products, function($p) { return (float)$p->stock_qty > 0; });
                $auto_sel   = count($prods_disp) === 1 ? array_values($prods_disp)[0] : null;
                ?>
                <div class="bp-prod-grid" id="bpBarProdGrid">
                    <?php foreach($products as $pr): $out=(float)$pr->stock_qty<=0; ?>
                    <div class="bp-prod-card <?php echo $out?'out':''; ?> <?php echo ($auto_sel && $auto_sel->id===$pr->id)?'selected':''; ?>"
                         data-pid="<?php echo (int)$pr->id; ?>"
                         onclick="<?php if(!$out){ $nm=esc_js($pr->name); echo "bpBarSelectProd({$pr->id},'{$nm}',{$pr->sale_price})"; } ?>">
                        <div style="font-size:.7rem;color:var(--text3)"><?php echo esc_html($pr->category??''); ?></div>
                        <div class="bp-prod-name"><?php echo esc_html($pr->name); ?></div>
                        <div class="bp-prod-price"><?php echo $this->money($pr->sale_price); ?></div>
                        <div class="bp-prod-stock"><?php echo number_format($pr->stock_qty,1,',','.'); ?> <?php echo esc_html($pr->unit); ?><?php echo $out?' ❌':''; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if($auto_sel): ?>
                <script>
                (function(){
                    if(typeof bpBarSelectProd==='function'){
                        bpBarSelectProd(<?php echo (int)$auto_sel->id; ?>,<?php echo json_encode($auto_sel->name); ?>,<?php echo (float)$auto_sel->sale_price; ?>);
                    }
                })();
                </script>
                <?php endif; ?>
                <div id="bpBarSelInfo" style="display:none;background:var(--accent-dim);border:1px solid rgba(245,166,35,.3);border-radius:8px;padding:10px 12px;margin-bottom:10px">
                    <strong id="bpBarSelName"></strong> – <span id="bpBarSelPrice" style="color:var(--green)"></span>
                </div>
                <div style="display:flex;gap:8px;align-items:flex-end">
                    <div class="bp-field" style="flex:1;margin:0"><label>Qtd</label><input type="number" id="bpBarQty" value="1" min="0.5" step="0.5" style="background:var(--bg3);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:10px;width:100%;font-size:.9rem"></div>
                    <button class="bp-btn bp-btn-primary" id="bpBarAddBtn" onclick="bpBarConfirmAdd(<?php echo $id; ?>)" <?php echo $auto_sel?'':'disabled'; ?> style="margin-bottom:0">+ Add</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Itens -->
            <?php if(!empty($items)): ?>
            <div style="margin-bottom:14px">
                <div style="font-size:.8rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Itens (<?php echo count($items); ?>)</div>
                <?php foreach($items as $it): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:.84rem">
                    <div>
                        <strong><?php echo esc_html($it->product_name); ?></strong>
                        <span style="color:var(--text3);margin-left:6px">x<?php echo number_format($it->quantity,1,',','.'); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <strong style="color:var(--green)"><?php echo $this->money($it->total_price); ?></strong>
                        <?php if($is_open): ?>
                        <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpBarRemoveItem(<?php echo $it->id; ?>,<?php echo $id; ?>)">🗑</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Totais -->
            <div class="bp-total-box">
                <div class="bp-total-row"><span>Subtotal</span><span><?php echo $this->money($comanda->total_items); ?></span></div>
                <?php if($disc_val>0): ?><div class="bp-total-row"><span>Desconto</span><span class="red">- <?php echo $this->money($disc_val); ?></span></div><?php endif; ?>
                <div class="bp-total-row final"><span>TOTAL</span><span class="green"><?php echo $this->money($comanda->total_final); ?></span></div>
            </div>

            <?php if($is_open && !empty($items)): ?>
            <!-- Pagamento -->
            <div style="margin-top:14px" id="bpPmtSection">
                <div style="font-size:.8rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Pagamento</div>
                <div class="bp-split-grid">
                    <?php foreach(bp_get_payment_methods() as $m=>$ml): ?>
                    <div class="bp-split-box">
                        <label><?php echo esc_html($ml); ?></label>
                        <input type="text" id="bpPmt_<?php echo $m; ?>" placeholder="0,00" oninput="bpBarCalcPaid(<?php echo $comanda->total_final; ?>)">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="bp-total-box">
                    <div class="bp-total-row"><span>Total</span><span id="bpBarTotalDisp"><?php echo $this->money($comanda->total_final); ?></span></div>
                    <div class="bp-total-row"><span>Informado</span><span id="bpBarPaidAmt">R$ 0,00</span></div>
                    <div class="bp-total-row final"><span>Troco</span><span id="bpBarTroco" class="green">R$ 0,00</span></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if($is_open && !empty($items)): ?>
        <div class="bp-modal-footer" style="flex-direction:column;gap:8px">
            <!-- Linha principal de ações -->
            <div style="display:flex;gap:8px;width:100%">
                <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpBarCancel(<?php echo $id; ?>)" title="Cancelar e estornar estoque">✕</button>
                <?php if(!$aguardando): ?>
                <button class="bp-btn bp-btn-sm" style="flex:1;background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.3)"
                        onclick="bpBarAguardandoPag(<?php echo $id; ?>)"
                        title="Deixar aberta — cliente paga na saída">
                    💳 Pagar na Saída
                </button>
                <?php else: ?>
                <button class="bp-btn bp-btn-sm" style="flex:1;background:rgba(99,102,241,.1);color:var(--accent);border:1px solid rgba(99,102,241,.3)"
                        onclick="bpBarReabrirComanda(<?php echo $id; ?>)"
                        title="Voltar para aberta para adicionar mais itens">
                    🔓 Reabrir Comanda
                </button>
                <?php endif; ?>
                <button class="bp-btn bp-btn-primary" style="flex:1" onclick="bpBarPay(<?php echo $id; ?>,<?php echo $comanda->total_final; ?>)">✅ Pagar Agora</button>
            </div>
            <!-- Fechar sem pagar -->
            <button class="bp-btn bp-btn-ghost" style="width:100%;font-size:.82rem" onclick="BP.closeModal()">
                ← Fechar (manter comanda aberta)
            </button>
        </div>
        <?php elseif($is_open): ?>
        <div class="bp-modal-footer" style="flex-direction:column;gap:8px">
            <div style="display:flex;gap:8px;width:100%">
                <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpBarCancel(<?php echo $id; ?>)">Cancelar Comanda</button>
                <button class="bp-btn bp-btn-ghost" style="flex:1" onclick="BP.closeModal()">← Fechar</button>
            </div>
        </div>
        <?php elseif($comanda->status==='paga'): ?>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-secondary" onclick="BP.closeModal()">Fechar</button>
        </div>
        <?php endif; ?>
        <?php
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_bar_add_item(): void {
        $cid = absint($_POST['comanda_id']);
        $pid = absint($_POST['product_id']);
        $qty = (float)str_replace(',','.',$_POST['quantity']??'1');
        if ( ! $cid ) wp_send_json_error(['message'=>'Comanda invalida.']);
        if ( ! $pid ) wp_send_json_error(['message'=>'Produto invalido.']);
        if ( $qty <= 0 ) wp_send_json_error(['message'=>'Quantidade invalida.']);

        $res = BarberPro_Bar::add_item($cid, $pid, $qty);
        $res['success'] ? wp_send_json_success($res) : wp_send_json_error($res);
    }
    private function action_bar_remove_item(): void {
        $item_id = absint($_POST['item_id']??0);
        if ( ! $item_id ) wp_send_json_error(['message'=>'Item invalido.']);
        $ok = BarberPro_Bar::remove_item($item_id);
        $ok ? wp_send_json_success() : wp_send_json_error(['message'=>'Erro ao remover item.']);
    }
    private function action_bar_pay_comanda(): void {
        $cid      = absint($_POST['comanda_id']??0);
        $raw      = stripslashes($_POST['payments']??'[]');
        $payments = json_decode($raw, true);
        if ( ! $cid )                      wp_send_json_error(['message'=>'Comanda invalida.']);
        if ( ! is_array($payments) || empty($payments) ) wp_send_json_error(['message'=>'Informe ao menos um pagamento.']);

        $res = BarberPro_Bar::pay_comanda($cid, $payments);
        $res['success'] ? wp_send_json_success($res) : wp_send_json_error($res);
    }
    private function action_bar_cancel_comanda(): void {
        BarberPro_Bar::cancel_comanda(absint($_POST['comanda_id']));
        wp_send_json_success();
    }

    private function action_bar_aguardando_pag(): void {
        $cid = absint($_POST['comanda_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message' => 'Comanda inválida.']);
        $ok = BarberPro_Bar::set_aguardando_pagamento($cid);
        $ok ? wp_send_json_success(['message' => 'Comanda marcada para pagar na saída.'])
            : wp_send_json_error(['message' => 'Não foi possível alterar o status.']);
    }

    private function action_bar_reabrir_comanda(): void {
        $cid = absint($_POST['comanda_id'] ?? 0);
        if (!$cid) wp_send_json_error(['message' => 'Comanda inválida.']);
        $ok = BarberPro_Bar::reabrir_comanda($cid);
        $ok ? wp_send_json_success(['message' => 'Comanda reaberta.'])
            : wp_send_json_error(['message' => 'Não foi possível reabrir a comanda.']);
    }

    private function action_bar_receipt(): void {
        $id = absint($_POST['comanda_id']);
        $c  = BarberPro_Bar::get_comanda($id);
        if(!$c) wp_send_json_error();
        $items = BarberPro_Bar::get_items($id);
        global $wpdb;
        $pmts = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}barber_bar_payments WHERE comanda_id=%d",$id)) ?: [];
        $id_label=trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:''))?:'-';
        $disc_val=$c->discount_type==='percentual'?round((float)$c->total_items*(float)$c->discount/100,2):(float)$c->discount;
        $mlabels=bp_get_payment_methods();
        ob_start(); ?>
        <div class="bp-print-receipt">
            <div style="text-align:center;border-bottom:1px dashed #000;padding-bottom:5px;margin-bottom:5px">
                <strong>BAR/EVENTOS</strong><br>
                <small>#<?php echo esc_html($c->comanda_code); ?></small><br>
                <small><?php echo date_i18n('d/m/Y H:i'); ?></small>
            </div>
            <div style="margin-bottom:5px;font-size:11px"><?php echo esc_html($id_label); ?></div>
            <div style="border-bottom:1px dashed #000;margin-bottom:5px">
                <?php foreach($items as $it): ?>
                <div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 0">
                    <span><?php echo esc_html($it->product_name); ?> x<?php echo number_format($it->quantity,1); ?></span>
                    <span><?php echo $this->money($it->total_price); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if($disc_val>0): ?><div style="display:flex;justify-content:space-between;font-size:11px"><span>Desconto</span><span>-<?php echo $this->money($disc_val); ?></span></div><?php endif; ?>
            <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:13px;border-top:1px dashed #000;margin-top:3px;padding-top:3px"><span>TOTAL</span><span><?php echo $this->money($c->total_final); ?></span></div>
            <?php if(!empty($pmts)): ?>
            <div style="margin-top:5px;font-size:11px;border-top:1px dashed #000;padding-top:4px">
                <?php foreach($pmts as $p): ?><div style="display:flex;justify-content:space-between"><span><?php echo esc_html($mlabels[$p->payment_method]??$p->payment_method); ?></span><span><?php echo $this->money($p->amount); ?></span></div><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:4px">Obrigado!</div>
        </div>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_get_product_form(): void {
        $id = absint($_POST['product_id']??0);
        $p  = $id ? BarberPro_Bar::get_product($id) : null;
        ob_start(); ?>
        <div class="bp-modal-header"><div class="bp-modal-title"><?php echo $p?'Editar Produto':'Novo Produto'; ?></div><button class="bp-modal-close" onclick="BP.closeModal()">✕</button></div>
        <div class="bp-modal-body">
            <input type="hidden" id="prd_id" value="<?php echo $id; ?>">
            <div class="bp-field"><label>Nome *</label><input type="text" id="prd_name" value="<?php echo esc_attr($p->name??''); ?>" required></div>
            <div class="bp-field-row">
                <div class="bp-field"><label>Categoria</label><input type="text" id="prd_cat" value="<?php echo esc_attr($p->category??''); ?>" placeholder="Bebida, Petisco..."></div>
                <div class="bp-field"><label>Unidade</label><select id="prd_unit"><?php foreach(['un'=>'Unidade','ml'=>'ml','l'=>'Litro','g'=>'Grama','kg'=>'KG','cx'=>'Caixa'] as $k=>$v): ?><option value="<?php echo $k; ?>" <?php selected($p->unit??'un',$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="bp-field-row">
                <div class="bp-field"><label>Custo (R$)</label><input type="text" id="prd_cost" value="<?php echo $p?number_format($p->cost_price,2,',','.'):''; ?>" placeholder="0,00"></div>
                <div class="bp-field"><label>Venda (R$) *</label><input type="text" id="prd_price" value="<?php echo $p?number_format($p->sale_price,2,',','.'):''; ?>" placeholder="0,00" required></div>
            </div>
            <div class="bp-field-row">
                <div class="bp-field"><label>Estoque Mínimo</label><input type="text" id="prd_min" value="<?php echo $p?number_format($p->stock_min,1,',','.'):'0'; ?>"></div>
                <div class="bp-field"><label>Estoque Máximo</label><input type="text" id="prd_max" value="<?php echo $p?number_format($p->stock_max,1,',','.'):'0'; ?>"></div>
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpSaveProduct()">✅ Salvar</button>
        </div>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    private function action_save_product(): void {
        $id   = absint($_POST['product_id']??0);
        $name = sanitize_text_field($_POST['name']??'');
        if ( empty($name) )           wp_send_json_error(['message'=>'Informe o nome do produto.']);
        if ( empty($_POST['sale_price']) ) wp_send_json_error(['message'=>'Informe o preco de venda.']);

        $result = BarberPro_Bar::save_product($_POST, $id);
        $result ? wp_send_json_success(['id'=>$result])
                : wp_send_json_error(['message'=>'Erro ao salvar produto.']);
    }

    private function action_stock_move(): void {
        $pid = absint($_POST['product_id']);
        BarberPro_Bar::stock_move($pid, $_POST);
        wp_send_json_success();
    }


    // ── Financeiro: Lançamento form ───────────────────────────────
    private function action_get_finance_form(): void {
        global $wpdb;
        $id  = absint($_POST['lancamento_id'] ?? 0);
        $cid = absint($_POST['company_id'] ?? 1);
        $lanc = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}barber_finance WHERE id=%d", $id)) : null;
        $cats_r = BarberPro_Finance::get_categories('receita', $cid);
        $cats_d = BarberPro_Finance::get_categories('despesa', $cid);
        ob_start(); ?>
        <div class="bp-modal-header">
            <div class="bp-modal-title"><?php echo $lanc ? 'Editar Lançamento' : 'Novo Lançamento'; ?></div>
            <button class="bp-modal-close" onclick="BP.closeModal()">✕</button>
        </div>
        <div class="bp-modal-body">
            <input type="hidden" id="flId" value="<?php echo $id; ?>">
            <input type="hidden" id="flCid" value="<?php echo $cid; ?>">

            <!-- Tipo: Receita / Despesa -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
                <button id="flBtnReceita" onclick="bpFinTipoToggle('receita')"
                    class="bp-btn <?php echo (!$lanc||$lanc->type==='receita')?'bp-btn-primary':'bp-btn-ghost'; ?>"
                    style="justify-content:center">💰 Receita</button>
                <button id="flBtnDespesa" onclick="bpFinTipoToggle('despesa')"
                    class="bp-btn <?php echo ($lanc&&$lanc->type==='despesa')?'bp-btn-danger':'bp-btn-ghost'; ?>"
                    style="justify-content:center">💸 Despesa</button>
            </div>
            <input type="hidden" id="flType" value="<?php echo esc_attr($lanc->type??'receita'); ?>">

            <div class="bp-field">
                <label>Descrição *</label>
                <input type="text" id="flDesc" value="<?php echo esc_attr($lanc->description??''); ?>" placeholder="Ex: Corte de cabelo, Aluguel...">
            </div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Valor (R$) *</label>
                    <input type="number" id="flAmount" value="<?php echo esc_attr($lanc->amount??''); ?>" step="0.01" min="0" placeholder="0,00">
                </div>
                <div class="bp-field">
                    <label>Data de Competência *</label>
                    <input type="date" id="flDate" value="<?php echo esc_attr($lanc->competencia_date??current_time('Y-m-d')); ?>">
                </div>
            </div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Categoria</label>
                    <select id="flCat" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%">
                        <option value="">— Sem categoria —</option>
                        <optgroup label="Receitas" id="flCatReceita">
                        <?php foreach($cats_r as $c): ?>
                        <option value="<?php echo $c->id; ?>" <?php selected($lanc->category_id??0,$c->id); ?>><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Despesas" id="flCatDespesa">
                        <?php foreach($cats_d as $c): ?>
                        <option value="<?php echo $c->id; ?>" <?php selected($lanc->category_id??0,$c->id); ?>><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="bp-field">
                    <label>Forma de Pagamento</label>
                    <select id="flMethod" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%">
                        <?php foreach(bp_get_payment_methods() as $k=>$v): ?>
                        <option value="<?php echo $k; ?>" <?php selected($lanc->payment_method??'dinheiro',$k); ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Status</label>
                    <select id="flStatus" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%">
                        <option value="pago" <?php selected($lanc->status??'pago','pago'); ?>>✅ Pago/Recebido</option>
                        <option value="pendente" <?php selected($lanc->status??'','pendente'); ?>>⏳ Pendente</option>
                    </select>
                </div>
                <div class="bp-field">
                    <label>Vencimento</label>
                    <input type="date" id="flDue" value="<?php echo esc_attr($lanc->due_date??''); ?>">
                </div>
            </div>
            <div class="bp-field">
                <label>Fornecedor / Cliente</label>
                <input type="text" id="flSupplier" value="<?php echo esc_attr($lanc->supplier??''); ?>" placeholder="Nome do fornecedor ou cliente">
            </div>
            <div class="bp-field">
                <label>Observações</label>
                <textarea id="flNotes" rows="2" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;resize:vertical"><?php echo esc_textarea($lanc->notes??''); ?></textarea>
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpFinSalvarLanc()">💾 Salvar</button>
        </div>
        <?php wp_send_json_success(['html'=>ob_get_clean()]);
    }

    // ── Financeiro: Salvar lançamento ─────────────────────────────
    private function action_save_finance(): void {
        global $wpdb;
        $id   = absint($_POST['id'] ?? 0);
        $cid  = absint($_POST['company_id'] ?? 1);
        $desc = sanitize_text_field($_POST['description'] ?? '');
        if ( empty($desc) ) wp_send_json_error(['message'=>'Descrição obrigatória.']);
        $amount = round((float)str_replace(',','.',$_POST['amount']??'0'), 2);
        if ( $amount <= 0 ) wp_send_json_error(['message'=>'Valor deve ser maior que zero.']);

        $data = [
            'company_id'       => $cid,
            'type'             => sanitize_key($_POST['type'] ?? 'receita'),
            'description'      => $desc,
            'amount'           => $amount,
            'category_id'      => absint($_POST['category_id']??0) ?: null,
            'payment_method'   => sanitize_key($_POST['payment_method'] ?? 'dinheiro'),
            'status'           => sanitize_key($_POST['status'] ?? 'pago'),
            'competencia_date' => sanitize_text_field($_POST['competencia_date'] ?? current_time('Y-m-d')),
            'due_date'         => sanitize_text_field($_POST['due_date'] ?? '') ?: null,
            'supplier'         => sanitize_text_field($_POST['supplier'] ?? '') ?: null,
            'notes'            => sanitize_textarea_field($_POST['notes'] ?? '') ?: null,
            'paid_at'          => ($_POST['status']??'') === 'pago' ? current_time('mysql') : null,
        ];

        if ( $id ) {
            $data['updated_at'] = current_time('mysql');
            $r = $wpdb->update("{$wpdb->prefix}barber_finance", $data, ['id'=>$id]);
            $r !== false ? wp_send_json_success(['id'=>$id]) : wp_send_json_error(['message'=>'Erro: '.$wpdb->last_error]);
        } else {
            $data['created_at'] = current_time('mysql');
            $r = $wpdb->insert("{$wpdb->prefix}barber_finance", $data);
            $r ? wp_send_json_success(['id'=>$wpdb->insert_id]) : wp_send_json_error(['message'=>'Erro: '.$wpdb->last_error]);
        }
    }

    // ── Financeiro: Deletar lançamento ────────────────────────────
    private function action_delete_finance(): void {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $wpdb->delete("{$wpdb->prefix}barber_finance", ['id'=>$id]);
        wp_send_json_success();
    }

    // ── Financeiro: Marcar como pago ──────────────────────────────
    private function action_pagar_finance(): void {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $wpdb->update("{$wpdb->prefix}barber_finance", [
            'status'  => 'pago',
            'paid_at' => current_time('mysql'),
        ], ['id'=>$id]);
        wp_send_json_success();
    }

    // ── Financeiro: Salvar categoria ──────────────────────────────
    private function action_save_finance_cat(): void {
        $name = sanitize_text_field($_POST['name'] ?? '');
        $cid  = absint($_POST['company_id'] ?? 1);
        if ( empty($name) ) wp_send_json_error(['message'=>'Nome obrigatório.']);
        $id = BarberPro_Finance::insert_category([
            'company_id' => $cid,
            'type'       => sanitize_key($_POST['type'] ?? 'receita'),
            'name'       => $name,
            'color'      => sanitize_hex_color($_POST['color'] ?? '') ?: '#22c55e',
        ]);
        $id ? wp_send_json_success(['id'=>$id]) : wp_send_json_error(['message'=>'Erro ao salvar.']);
    }

    // ── Financeiro: Excluir categoria ─────────────────────────────
    private function action_delete_finance_cat(): void {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $used = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}barber_finance WHERE category_id=%d", $id));
        if ( $used > 0 ) wp_send_json_error(['message'=>'Categoria tem lançamentos e não pode ser excluída.']);
        $wpdb->delete("{$wpdb->prefix}barber_finance_categories", ['id'=>$id]);
        wp_send_json_success();
    }


    // ── Impressora Térmica: gera dados ESC/POS da comanda ────────
    private function action_get_escpos_receipt(): void {
        $id  = absint($_POST['comanda_id'] ?? 0);
        $c   = BarberPro_Bar::get_comanda($id);
        if ( ! $c ) wp_send_json_error(['message' => 'Comanda não encontrada.']);

        $items  = BarberPro_Bar::get_items($id);
        global $wpdb;
        $pmts   = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bar_payments WHERE comanda_id=%d", $id)) ?: [];

        $header  = BarberPro_Database::get_setting('printer_header', '');
        $footer  = BarberPro_Database::get_setting('printer_footer', 'Obrigado pela preferência!');
        $width   = (int) BarberPro_Database::get_setting('printer_width', '58');
        $cols    = $width >= 80 ? 42 : 32; // colunas de texto
        $mlabels = array_map(function($v) { return strip_tags(str_replace(['💵','⚡','💳','🏦','🎟️','📄','💰',' '], ['','','','','','',''], $v), ''); }, bp_get_payment_methods());
        $id_label = trim(($c->table_number ? 'Mesa '.$c->table_number : '').($c->client_name ? ' - '.$c->client_name : '')) ?: '-';

        // ── Monta o texto do cupom ──
        $lines = [];
        $center = function(string $s) use ($cols) { return str_pad('', max(0, intval(($cols - mb_strlen($s)) / 2))) . $s; };
        $divider = str_repeat('-', $cols);
        $lr = function(string $l, string $r) use ($cols): string {
            $pad = $cols - mb_strlen($l) - mb_strlen($r);
            return $l . str_repeat(' ', max(1, $pad)) . $r;
        };

        // Cabeçalho personalizado
        if ( $header ) {
            foreach ( explode('\n', $header) as $hl ) {
                $lines[] = ['type'=>'center', 'text'=>trim($hl)];
            }
        }
        $lines[] = ['type'=>'divider'];
        $lines[] = ['type'=>'center-bold', 'text'=>'COMANDA #'.$c->comanda_code];
        $lines[] = ['type'=>'center', 'text'=>date_i18n('d/m/Y H:i')];
        $lines[] = ['type'=>'left', 'text'=>$id_label];
        $lines[] = ['type'=>'divider'];

        // Itens
        $disc_val = $c->discount_type === 'percentual'
            ? round((float)$c->total_items * (float)$c->discount / 100, 2)
            : (float)$c->discount;

        foreach ( $items as $it ) {
            $qty  = number_format((float)$it->quantity, 1, ',', '.');
            $tot  = 'R$ ' . number_format((float)$it->total_price, 2, ',', '.');
            $lines[] = ['type'=>'lr', 'left'=>$it->product_name.' x'.$qty, 'right'=>$tot];
        }
        $lines[] = ['type'=>'divider'];

        if ( $disc_val > 0 ) {
            $lines[] = ['type'=>'lr', 'left'=>'Desconto', 'right'=>'- R$ '.number_format($disc_val,2,',','.')];
        }
        $lines[] = ['type'=>'lr-bold', 'left'=>'TOTAL', 'right'=>'R$ '.number_format((float)$c->total_final,2,',','.')];

        if ( ! empty($pmts) ) {
            $lines[] = ['type'=>'divider'];
            foreach ( $pmts as $p ) {
                $lines[] = ['type'=>'lr', 'left'=>($mlabels[$p->payment_method]??$p->payment_method), 'right'=>'R$ '.number_format((float)$p->amount,2,',','.')];
            }
        }
        $lines[] = ['type'=>'divider'];
        $lines[] = ['type'=>'center', 'text'=>$footer];
        $lines[] = ['type'=>'feed', 'lines'=>4]; // avança papel

        wp_send_json_success([
            'lines'  => $lines,
            'cols'   => $cols,
            'copies' => (int) BarberPro_Database::get_setting('printer_copies', '1'),
        ]);
    }


    // ── Busca config da impressora para o JS ─────────────────────
    private function action_get_printer_settings(): void {
        wp_send_json_success([
            'printer_name'    => BarberPro_Database::get_setting('printer_name', ''),
            'printer_width'   => BarberPro_Database::get_setting('printer_width', '58'),
            'printer_copies'  => BarberPro_Database::get_setting('printer_copies', '1'),
            'printer_enabled' => BarberPro_Database::get_setting('printer_enabled', '0'),
        ]);
    }

    private function action_get_loja_produto_form(): void {
        $id  = absint($_POST['product_id'] ?? 0);
        $cid = absint($_POST['company_id'] ?? 1);
        $p   = $id ? BarberPro_Shop::get_product($id) : null;
        $mod = $cid === 1 ? '✂️ Barbearia' : '🚗 Lava-Car';
        ob_start(); ?>
        <div class="bp-modal-header">
            <div class="bp-modal-title"><?php echo $p ? 'Editar Produto' : 'Novo Produto'; ?> — <?php echo $mod; ?></div>
            <button class="bp-modal-close" onclick="BP.closeModal()">✕</button>
        </div>
        <div class="bp-modal-body" style="max-height:75vh;overflow-y:auto">
            <input type="hidden" id="lpId"  value="<?php echo $id; ?>">
            <input type="hidden" id="lpCid" value="<?php echo $cid; ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="bp-field" style="grid-column:1/-1"><label>Nome *</label>
                    <input type="text" id="lpName" value="<?php echo esc_attr($p->name??''); ?>" required></div>
                <div class="bp-field"><label>Categoria</label>
                    <input type="text" id="lpCat" value="<?php echo esc_attr($p->category??''); ?>" placeholder="Ex: Pomadas, Shampoos..."></div>
                <div class="bp-field"><label>SKU</label>
                    <input type="text" id="lpSku" value="<?php echo esc_attr($p->sku??''); ?>"></div>
                <div class="bp-field"><label>Preço de Venda (R$) *</label>
                    <input type="text" id="lpPrice" value="<?php echo $p?number_format((float)$p->sale_price,2,',','.'):''; ?>" required></div>
                <div class="bp-field"><label>Preço de Custo (R$)</label>
                    <input type="text" id="lpCost" value="<?php echo $p?number_format((float)$p->cost_price,2,',','.'):''; ?>"></div>
                <div class="bp-field"><label>Estoque atual</label>
                    <input type="number" id="lpStock" value="<?php echo $p?(float)$p->stock_qty:0; ?>" step="1" min="0"></div>
                <div class="bp-field"><label>Estoque mínimo ⚠️</label>
                    <input type="number" id="lpMin" value="<?php echo $p?(float)$p->stock_min:0; ?>" step="1" min="0"></div>
                <div class="bp-field"><label>Peso (gramas)</label>
                    <input type="number" id="lpWeight" value="<?php echo (int)($p->weight_g??0); ?>" step="1" min="0"></div>
                <div class="bp-field" style="grid-column:1/-1"><label>Foto do Produto</label>
                    <?php $foto_atual = $p->photo??''; ?>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px">
                        <img id="lpPhotoPreview"
                             src="<?php echo esc_url($foto_atual); ?>"
                             style="width:64px;height:64px;border-radius:8px;border:1px solid #e5e7eb;object-fit:cover;<?php echo $foto_atual ? '' : 'display:none'; ?>">
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <input type="hidden" id="lpPhoto" value="<?php echo esc_attr($foto_atual); ?>">
                            <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;cursor:pointer;font-size:.82rem;white-space:nowrap">
                                📁 Selecionar arquivo
                                <input type="file" id="lpPhotoFile" accept="image/*" style="display:none" onchange="bpUploadPhoto(this)">
                            </label>
                            <input type="url" id="lpPhotoUrl" value="<?php echo esc_attr($foto_atual); ?>"
                                   placeholder="Ou cole uma URL de imagem"
                                   style="font-size:.78rem;padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px"
                                   oninput="bpPhotoUrlInput(this.value)">
                        </div>
                        <?php if($foto_atual): ?>
                        <button type="button" onclick="bpPhotoRemove()" style="color:#ef4444;background:none;border:none;cursor:pointer;font-size:.8rem">✕ Remover</button>
                        <?php endif; ?>
                    </div>
                    <div id="lpPhotoStatus" style="font-size:.75rem;color:#6b7280;margin-top:4px"></div>
                    <script>
                    function bpUploadPhoto(input) {
                        if (!input.files || !input.files[0]) return;
                        var file = input.files[0];
                        if (file.size > 5*1024*1024) { document.getElementById('lpPhotoStatus').textContent = 'Arquivo muito grande (máx 5MB).'; return; }
                        var status = document.getElementById('lpPhotoStatus');
                        status.textContent = 'Enviando...';
                        var fd = new FormData();
                        fd.append('action', 'bp_upload_product_photo');
                        fd.append('nonce', (typeof bpAppData!=='undefined'?bpAppData.nonce:'')||(typeof barberproPublic!=='undefined'?barberproPublic.nonce:''));
                        fd.append('file', file);
                        var _ajaxUrl=(typeof bpAppData!=='undefined'?bpAppData.ajaxUrl:null)||(typeof barberproPublic!=='undefined'?barberproPublic.ajaxUrl:null)||'/?admin-ajax.php';
                        fetch(_ajaxUrl, { method: 'POST', body: fd })
                            .then(function(r){ return r.json(); })
                            .then(function(data) {
                                if (data.success && data.data && data.data.url) {
                                    var url = data.data.url;
                                    document.getElementById('lpPhoto').value = url;
                                    document.getElementById('lpPhotoUrl').value = url;
                                    var prev = document.getElementById('lpPhotoPreview');
                                    prev.src = url; prev.style.display = 'block';
                                    status.textContent = '✅ Imagem enviada!';
                                } else {
                                    status.textContent = '❌ Erro: ' + (data.data && data.data.message ? data.data.message : 'Tente novamente.');
                                }
                            }).catch(function(){ status.textContent = '❌ Erro ao enviar.'; });
                    }
                    function bpPhotoUrlInput(val) {
                        document.getElementById('lpPhoto').value = val;
                        var prev = document.getElementById('lpPhotoPreview');
                        if (val) { prev.src = val; prev.style.display = 'block'; }
                        else { prev.src = ''; prev.style.display = 'none'; }
                    }
                    function bpPhotoRemove() {
                        document.getElementById('lpPhoto').value = '';
                        document.getElementById('lpPhotoUrl').value = '';
                        var prev = document.getElementById('lpPhotoPreview');
                        prev.src = ''; prev.style.display = 'none';
                    }
                    </script>
                </div>
                <div class="bp-field" style="grid-column:1/-1"><label>Descrição</label>
                    <textarea id="lpDesc" rows="2"><?php echo esc_textarea($p->description??''); ?></textarea></div>
                <div class="bp-field"><label>Status</label>
                    <select id="lpStatus">
                        <option value="active"   <?php selected($p->status??'active','active'); ?>>Ativo</option>
                        <option value="inactive" <?php selected($p->status??'active','inactive'); ?>>Inativo</option>
                    </select></div>
            </div>
            <div class="bp-field" style="margin-top:12px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="lpShopActive" <?php checked((int)($p->shop_active??0),1); ?> style="width:18px;height:18px;accent-color:var(--accent)">
                    <span>Visível na loja do site (<?php echo $mod; ?>)</span>
                </label>
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
            <button class="bp-btn bp-btn-primary" onclick="bpSaveLojaProduto()">✅ Salvar</button>
        </div>
        <?php wp_send_json_success(['html' => ob_get_clean()]);
    }

    // ── Loja: Salvar produto ──────────────────────────────────────
    private function action_save_loja_produto(): void {
        global $wpdb;
        $id   = absint($_POST['product_id'] ?? 0);
        $cid  = absint($_POST['company_id'] ?? 1);
        $name = sanitize_text_field($_POST['name'] ?? '');
        if ( empty($name) ) wp_send_json_error(['message'=>'Nome é obrigatório.']);

        $price = round((float)str_replace(',','.',$_POST['sale_price']??'0'),2);
        $cost  = round((float)str_replace(',','.',$_POST['cost_price'] ??'0'),2);

        $data = [
            'company_id'  => $cid,
            'name'        => $name,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'category'    => sanitize_text_field($_POST['category'] ?? ''),
            'sku'         => sanitize_text_field($_POST['sku'] ?? ''),
            'sale_price'  => $price,
            'cost_price'  => $cost,
            'stock_qty'   => max(0,(float)($_POST['stock_qty'] ?? 0)),
            'stock_min'   => max(0,(float)($_POST['stock_min'] ?? 0)),
            'weight_g'    => absint($_POST['weight_g'] ?? 0),
            'photo'       => sanitize_url($_POST['photo'] ?? ''),
            'shop_active' => ($_POST['shop_active']??'0')==='1' ? 1 : 0,
            'status'      => in_array($_POST['status']??'active',['active','inactive']) ? $_POST['status'] : 'active',
            'updated_at'  => current_time('mysql'),
        ];

        if ( $id ) {
            $r = $wpdb->update("{$wpdb->prefix}barber_products", $data, ['id'=>$id]);
            $r !== false
                ? wp_send_json_success(['id'=>$id])
                : wp_send_json_error(['message'=>'Erro: '.$wpdb->last_error]);
        } else {
            $data['unit']       = 'un';
            $data['created_at'] = current_time('mysql');
            $r = $wpdb->insert("{$wpdb->prefix}barber_products", $data);
            $r  ? wp_send_json_success(['id'=>$wpdb->insert_id])
                : wp_send_json_error(['message'=>'Erro: '.$wpdb->last_error]);
        }
    }

    // ── Loja: Detalhe do pedido ───────────────────────────────────
    private function action_get_loja_pedido_detalhe(): void {
        $id    = absint($_POST['order_id'] ?? 0);
        $order = BarberPro_Shop::get_order($id);
        if (!$order) wp_send_json_error(['message'=>'Pedido não encontrado.']);
        $items = BarberPro_Shop::get_order_items($id);
        ob_start(); ?>
        <div class="bp-modal-header">
            <div class="bp-modal-title">📦 Pedido #<?php echo esc_html($order->order_code); ?></div>
            <button class="bp-modal-close" onclick="BP.closeModal()">✕</button>
        </div>
        <div class="bp-modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div><div style="font-size:.75rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.5px">Cliente</div>
                    <div style="font-weight:700"><?php echo esc_html($order->client_name); ?></div>
                    <div style="font-size:.82rem;color:var(--text2)"><?php echo esc_html($order->client_phone??''); ?></div>
                    <?php if($order->client_email): ?><div style="font-size:.82rem;color:var(--text2)"><?php echo esc_html($order->client_email); ?></div><?php endif; ?>
                </div>
                <div><div style="font-size:.75rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.5px">Entrega</div>
                    <?php if ($order->delivery_type === 'entrega'): ?>
                    <div style="font-size:.85rem"><?php echo esc_html($order->address_street.', '.$order->address_number); ?></div>
                    <div style="font-size:.82rem;color:var(--text2)"><?php echo esc_html(($order->address_neighborhood?$order->address_neighborhood.' — ':'').$order->address_city.'/'.$order->address_state); ?></div>
                    <div style="font-size:.82rem;color:var(--text2)">CEP <?php echo esc_html($order->address_zip); ?></div>
                    <?php else: ?>
                    <div style="font-weight:600">🏪 Retirada na loja</div>
                    <?php endif; ?>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;margin-bottom:14px">
                <thead><tr style="border-bottom:2px solid var(--border)">
                    <th style="text-align:left;padding:6px 0;font-size:.78rem;color:var(--text3)">Produto</th>
                    <th style="text-align:center;padding:6px 0;font-size:.78rem;color:var(--text3)">Qtd</th>
                    <th style="text-align:right;padding:6px 0;font-size:.78rem;color:var(--text3)">Total</th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                <tr style="border-bottom:1px solid var(--border)">
                    <td style="padding:7px 0;font-size:.88rem"><?php echo esc_html($item->product_name); ?></td>
                    <td style="text-align:center;font-size:.88rem"><?php echo (float)$item->quantity; ?></td>
                    <td style="text-align:right;font-size:.88rem;font-weight:700">R$ <?php echo number_format((float)$item->total_price,2,',','.'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php if ($order->shipping_cost > 0): ?>
                    <tr><td colspan="2" style="padding:5px 0;font-size:.85rem;color:var(--text2)">Frete</td>
                        <td style="text-align:right;font-size:.85rem">R$ <?php echo number_format((float)$order->shipping_cost,2,',','.'); ?></td></tr>
                    <?php endif; ?>
                    <tr style="border-top:2px solid var(--border)">
                        <td colspan="2" style="padding:8px 0;font-weight:800">Total</td>
                        <td style="text-align:right;font-weight:800;font-size:1rem;color:var(--accent)">R$ <?php echo number_format((float)$order->total,2,',','.'); ?></td>
                    </tr>
                </tfoot>
            </table>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem">
                <div><span style="color:var(--text3)">Pagamento:</span> <strong><?php echo esc_html($order->payment_method??'—'); ?></strong></div>
                <div><span style="color:var(--text3)">Data:</span> <strong><?php echo date_i18n('d/m/Y H:i', strtotime($order->created_at)); ?></strong></div>
                <?php if ($order->notes): ?><div style="grid-column:1/-1"><span style="color:var(--text3)">Obs:</span> <?php echo esc_html($order->notes); ?></div><?php endif; ?>
            </div>
        </div>
        <div class="bp-modal-footer">
            <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Fechar</button>
        </div>
        <?php wp_send_json_success(['html' => ob_get_clean()]);
    }

    // ── Clientes: Salvar ──────────────────────────────────────
    private function action_save_client(): void {
        $result = BarberPro_Clients::save([
            'id'                  => absint($_POST['id'] ?? 0),
            'company_id'          => absint($_POST['company_id'] ?? 1),
            'name'                => sanitize_text_field($_POST['name'] ?? ''),
            'phone'               => sanitize_text_field($_POST['phone'] ?? ''),
            'email'               => sanitize_email($_POST['email'] ?? ''),
            'tipo'                => sanitize_key($_POST['tipo'] ?? 'normal'),
            'recorrencia_dias'    => absint($_POST['recorrencia_dias'] ?? 0),
            'recurrence_weekdays' => sanitize_text_field(wp_unslash($_POST['recurrence_weekdays'] ?? '')),
            'professional_id'     => absint($_POST['professional_id'] ?? 0),
            'notes'               => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);
        $result
            ? wp_send_json_success(['id' => $result])
            : wp_send_json_error(['message' => 'Erro ao salvar cliente.']);
    }

    /** Salva configurações de mensagem de ausência (WhatsApp). */
    private function action_save_absence_settings(): void {
        BarberPro_Database::update_setting( 'absence_reminder_active', ! empty($_POST['absence_active']) ? '1' : '0' );
        BarberPro_Database::update_setting( 'absence_reminder_days', (string) max( 1, absint( $_POST['absence_days'] ?? 30 ) ) );
        BarberPro_Database::update_setting( 'absence_reminder_msg', sanitize_textarea_field( wp_unslash( $_POST['absence_msg'] ?? '' ) ) );
        wp_send_json_success();
    }

    /**
     * Envio em massa WhatsApp para clientes da carteira (delay entre cada envio).
     */
    private function action_client_bulk_whatsapp(): void {
        $company_id = absint( $_POST['company_id'] ?? 1 );
        $message    = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $delay      = max( 0, min( 120, absint( $_POST['delay_seconds'] ?? 3 ) ) );
        if ( $message === '' ) {
            wp_send_json_error( ['message' => 'Digite a mensagem.'] );
        }
        $ids = [];
        if ( ! empty( $_POST['client_ids'] ) ) {
            $raw = wp_unslash( $_POST['client_ids'] );
            if ( is_string( $raw ) ) {
                $dec = json_decode( $raw, true );
                if ( is_array( $dec ) ) {
                    $ids = array_map( 'absint', $dec );
                }
            } elseif ( is_array( $raw ) ) {
                $ids = array_map( 'absint', $raw );
            }
        }
        $list = BarberPro_Clients::list( $company_id, '', '' );
        if ( $ids ) {
            $list = array_values( array_filter( $list, static function ( $c ) use ( $ids ) {
                return in_array( (int) $c->id, $ids, true );
            } ) );
        }
        $sent = 0;
        foreach ( $list as $c ) {
            if ( empty( $c->phone ) ) {
                continue;
            }
            if ( $sent > 0 && $delay > 0 ) {
                sleep( $delay );
            }
            BarberPro_WhatsApp::send( $c->phone, str_replace( '{nome}', $c->name, $message ) );
            $sent++;
            if ( $sent >= 80 ) {
                break;
            }
        }
        wp_send_json_success( ['sent' => $sent] );
    }

    /**
     * Envio em massa com progresso em tempo real (processado via WP-Cron).
     * Subs:
     *  - selection_mode: 'all' | 'filtered'
     *  - filter_search, filter_tipo (quando filtered)
     *  - media_file (opcional, $_FILES['media_file'])
     */
    private function action_client_bulk_whatsapp_start(): void {
        $company_id     = absint( $_POST['company_id'] ?? 1 );
        $message        = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $delay_seconds  = max( 0, min( 120, absint( $_POST['delay_seconds'] ?? 3 ) ) );
        $selection_mode = sanitize_key( (string) ( $_POST['selection_mode'] ?? 'all' ) );

        $filter_search = sanitize_text_field( wp_unslash( $_POST['filter_search'] ?? '' ) );
        $filter_tipo   = sanitize_key( (string) ( $_POST['filter_tipo'] ?? '' ) );

        if ( ! $company_id ) wp_send_json_error(['message'=>'company_id inválido.']);
        if ( $message === '' ) wp_send_json_error(['message'=>'Digite a mensagem.']);

        // Media (opcional)
        $media_url  = null;
        $media_type = 'image';
        if ( ! empty( $_FILES['media_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['media_file']['tmp_name'] ) ) {
            $size_bytes = (int) ( $_FILES['media_file']['size'] ?? 0 );
            if ( $size_bytes > 15 * 1024 * 1024 ) {
                wp_send_json_error(['message'=>'Mídia muito grande. Limite: 15MB.']);
            }
            if ( ! function_exists( 'wp_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $check = wp_check_filetype( $_FILES['media_file']['tmp_name'] );
            $mime  = $check['type'] ?? (string) ( $_FILES['media_file']['type'] ?? '' );

            $allowed = [
                'image' => 'image/',
                'video' => 'video/',
                'doc'   => ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','text/plain'],
            ];
            $is_image = is_string($mime) && str_starts_with( $mime, 'image/' );
            $is_video = is_string($mime) && str_starts_with( $mime, 'video/' );

            // Para docs: pdf (e alguns tipos comuns)
            $is_doc = false;
            if ( is_string($mime) ) {
                $is_doc = in_array( $mime, $allowed['doc'], true );
            }
            if ( ! $is_image && ! $is_video && ! $is_doc ) {
                // Permite também outros anexos via document
                $media_type = 'document';
            } else {
                $media_type = $is_image ? 'image' : ( ( $is_video ? 'video' : 'document' ) );
            }

            $upload = wp_handle_upload(
                $_FILES['media_file'],
                [ 'test_form' => false, 'mimes' => null ]
            );
            if ( ! isset( $upload['url'] ) ) {
                wp_send_json_error(['message'=>'Falha ao fazer upload da mídia.']);
            }
            $media_url = (string) $upload['url'];
        }

        // Determina lista de clientes.
        $search = '';
        $tipo   = '';
        if ( $selection_mode === 'filtered' ) {
            $search = $filter_search;
            $tipo   = $filter_tipo;
        }

        $clients = BarberPro_Clients::list( $company_id, $search, $tipo );
        $total_before_cap = count( $clients );
        // Evita excesso (mesma lógica do envio antigo)
        $clients = array_slice( $clients, 0, 80 );
        $capped  = $total_before_cap > count( $clients );

        $targets = [];
        $skipped_no_phone = 0;
        foreach ( $clients as $c ) {
            $phone = preg_replace( '/\D/', '', (string) ( $c->phone ?? '' ) );
            if ( ! $phone || strlen($phone) < 10 ) {
                $skipped_no_phone++;
                continue;
            }
            $targets[] = [ 'phone' => $phone, 'name' => (string) ( $c->name ?? '' ) ];
        }

        $job = BarberPro_Bulk_WhatsApp::start_job([
            'job_id'        => sanitize_key( (string) ( $_POST['job_id'] ?? '' ) ),
            'company_id'    => $company_id,
            'delay_seconds' => $delay_seconds,
            'message'       => $message,
            'media_url'     => $media_url,
            'media_type'    => $media_url ? $media_type : 'image',
            'targets'       => $targets,
        ]);

        if ( empty( $job['success'] ) ) {
            wp_send_json_error( [ 'message' => $job['message'] ?? 'Erro ao iniciar disparo.' ] );
        }

        wp_send_json_success([
            'job_id' => $job['job_id'] ?? null,
            'total'  => $job['total'] ?? count($targets),
            'skipped_no_phone' => $skipped_no_phone,
            'capped' => $capped,
        ]);
    }

    private function action_client_bulk_whatsapp_progress(): void {
        $job_id = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );
        if ( ! $job_id ) wp_send_json_error(['message'=>'job_id inválido.']);

        $status = BarberPro_Bulk_WhatsApp::get_status( $job_id );
        if ( empty( $status['found'] ) ) {
            wp_send_json_error(['message'=>'Job não encontrado ou expirado.']);
        }
        wp_send_json_success( $status );
    }

}
