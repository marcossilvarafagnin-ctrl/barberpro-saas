<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_Bookings {

    private function section_agenda( int $company_id ): void {
        global $wpdb;
        $today   = current_time('Y-m-d');
        $date    = sanitize_text_field($_POST['date'] ?? $_GET['date'] ?? $today);
        $periodo = sanitize_key($_POST['periodo'] ?? 'dia');
        $mod     = $company_id===1?'barbearia':'lavacar';
        $mod_name = BarberPro_Database::get_setting("module_{$mod}_name", $company_id===1?'Barbearia':'Lava-Car');
        $icon    = $company_id===1?'✂️':'🚗';

        // Calcula intervalo conforme período
        switch ($periodo) {
            case 'semana':
                $inicio = date('Y-m-d', strtotime('monday this week', strtotime($date)));
                $fim    = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
                break;
            case 'mes':
                $inicio = date('Y-m-01', strtotime($date));
                $fim    = date('Y-m-t',  strtotime($date));
                break;
            case 'ano':
                $inicio = date('Y-01-01', strtotime($date));
                $fim    = date('Y-12-31', strtotime($date));
                break;
            default: // dia
                $inicio = $date;
                $fim    = $date;
                break;
        }

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, p.name AS pro_name, s.name AS svc_name, s.duration AS duration_minutes
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id=p.id
             LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id=s.id
             WHERE b.company_id=%d AND b.booking_date BETWEEN %s AND %s
             ORDER BY b.booking_date ASC, b.booking_time ASC",
            $company_id, $inicio, $fim
        )) ?: [];

        $status_map=['agendado'=>['Agendado','amber'],'confirmado'=>['Confirmado','blue'],'em_atendimento'=>['Em atendimento','blue'],'finalizado'=>['Finalizado','green'],'cancelado'=>['Cancelado','red'],'no_show'=>['No-Show','red']];
        ?>
        <?php
        $subtitulo = match($periodo) {
            'semana' => 'Semana: ' . date_i18n('d/m', strtotime($inicio)) . ' – ' . date_i18n('d/m/Y', strtotime($fim)),
            'mes'    => date_i18n('F \d\e Y', strtotime($date)),
            'ano'    => date_i18n('Y', strtotime($date)),
            default  => date_i18n('d \d\e F \d\e Y', strtotime($date)),
        };
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title"><?php echo $icon; ?> Agendamentos – <?php echo esc_html($mod_name); ?></div>
                <div class="bp-page-subtitle"><?php echo $subtitulo; ?> — <strong><?php echo count($bookings); ?></strong> agendamento(s)</div>
            </div>
            <button class="bp-btn bp-btn-primary bp-btn-sm" onclick="bpOpenNewBooking(<?php echo $company_id; ?>)">+ Novo</button>
        </div>

        <!-- Filtros de período -->
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:14px" class="bp-animate-in">
            <?php foreach(['dia'=>'📅 Dia','semana'=>'📆 Semana','mes'=>'🗓 Mês','ano'=>'📊 Ano'] as $p=>$label): ?>
            <button onclick="BP.navigate('<?php echo $mod; ?>_agenda', null, {date:'<?php echo $date; ?>',periodo:'<?php echo $p; ?>'})"
                    class="bp-btn bp-btn-sm <?php echo $periodo===$p ? 'bp-btn-primary' : 'bp-btn-ghost'; ?>">
                <?php echo $label; ?>
            </button>
            <?php endforeach; ?>
            <input type="date" id="bpAgendaDatePicker" value="<?php echo esc_attr($date); ?>"
                   style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;color:var(--text1);padding:6px 10px;font-size:.82rem"
                   onblur="if(this.value && this.value !== '<?php echo esc_js($date); ?>') BP.navigate('<?php echo $mod; ?>_agenda', null, {date:this.value,periodo:'<?php echo $periodo; ?>'})"
                   onkeydown="if(event.key==='Enter' && this.value) BP.navigate('<?php echo $mod; ?>_agenda', null, {date:this.value,periodo:'<?php echo $periodo; ?>'})">
            <?php if($date !== $today || $periodo !== 'dia'): ?>
            <button onclick="BP.navigate('<?php echo $mod; ?>_agenda', null, {date:'<?php echo $today; ?>',periodo:'dia'})"
                    class="bp-btn bp-btn-ghost bp-btn-sm">↩ Hoje</button>
            <?php endif; ?>
        </div>

        <?php if(empty($bookings)): ?>
        <div class="bp-empty bp-animate-in">
            <div class="bp-empty-icon">📅</div>
            <div class="bp-empty-title">Nenhum agendamento nesta data</div>
            <div class="bp-empty-text">Selecione outra data ou crie um novo agendamento.</div>
        </div>
        <?php else: ?>
        <div class="bp-card bp-animate-in">
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr>
                    <?php if($periodo !== 'dia'): ?><th>Data</th><?php endif; ?>
                    <th>Hora</th><th>Cliente</th><th>Serviço</th><th>Profissional</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach($bookings as $b):
                    [$slabel,$scls]=$status_map[$b->status]??[$b->status,'gray']; ?>
                <tr>
                    <?php if($periodo !== 'dia'): ?><td style="font-size:.8rem;color:var(--text2)"><?php echo esc_html(date_i18n('d/m', strtotime($b->booking_date))); ?></td><?php endif; ?>
                    <td style="font-family:var(--font-mono);font-weight:700;color:var(--accent)"><?php echo esc_html(substr($b->booking_time??'',0,5)); ?></td>
                    <td>
                        <strong><?php echo esc_html($b->client_name); ?></strong>
                        <?php if($b->client_phone): ?><br><small style="color:var(--text3)"><?php echo esc_html($b->client_phone); ?></small><?php endif; ?>
                    </td>
                    <td><?php echo esc_html($b->svc_name??'—'); ?></td>
                    <td><?php echo esc_html($b->pro_name??'—'); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $scls; ?>"><?php echo esc_html($slabel); ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <?php if($b->status==='agendado'): ?>
                            <button class="bp-btn bp-btn-success bp-btn-sm" onclick="bpUpdateStatus(<?php echo $b->id; ?>,'confirmado')">✓</button>
                            <?php endif; ?>
                            <?php if(in_array($b->status,['agendado','confirmado'])): ?>
                            <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpUpdateStatus(<?php echo $b->id; ?>,'cancelado')">✕</button>
                            <?php endif; ?>
                            <?php if($b->status==='confirmado'): ?>
                            <button class="bp-btn bp-btn-sm" style="background:var(--accent-dim);color:var(--accent);border:1px solid rgba(245,166,35,.3)" onclick="bpUpdateStatus(<?php echo $b->id; ?>,'finalizado')">✅</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    // ── Kanban ────────────────────────────────────────────────────
    private function section_kanban( int $company_id ): void {
        global $wpdb;
        $mod = $company_id===1?'barbearia':'lavacar';
        $mod_name = BarberPro_Database::get_setting("module_{$mod}_name", $company_id===1?'Barbearia':'Lava-Car');
        $icon = $company_id===1?'✂️':'🚗';
        $today = current_time('Y-m-d');

        $columns = ['agendado'=>'Aguardando','confirmado'=>'Confirmado','em_atendimento'=>'Em atendimento','finalizado'=>'Finalizado'];
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, p.name AS pro_name, s.name AS svc_name
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id=p.id
             LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id=s.id
             WHERE b.company_id=%d AND b.booking_date=%s AND b.status != 'cancelado'
             ORDER BY b.booking_time ASC", $company_id, $today
        )) ?: [];

        $by_status = [];
        foreach($bookings as $b) $by_status[$b->status][] = $b;
        $colors=['agendado'=>'var(--accent)','confirmado'=>'var(--blue)','em_atendimento'=>'var(--purple)','finalizado'=>'var(--green)'];
        $auto_enabled = BarberPro_Database::get_setting('kanban_auto_enabled','1') === '1';
        $auto_log     = $auto_enabled ? array_slice(BarberPro_Kanban_Auto::get_log(), 0, 3) : [];
        $status_labels = ['agendado'=>'Agendado','confirmado'=>'Confirmado','em_atendimento'=>'Em atendimento','finalizado'=>'Finalizado'];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title"><?php echo $icon; ?> Kanban – <?php echo esc_html($mod_name); ?></div>
                <div class="bp-page-subtitle"><?php echo date_i18n('d/m/Y'); ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <?php if($auto_enabled): ?>
                <span style="font-size:.75rem;background:rgba(16,185,129,.12);color:#10b981;border:1px solid rgba(16,185,129,.3);border-radius:20px;padding:4px 12px;font-weight:700">
                    🤖 Automação ativa
                </span>
                <?php else: ?>
                <span style="font-size:.75rem;background:var(--bg3);color:var(--text3);border:1px solid var(--border);border-radius:20px;padding:4px 12px">
                    Automação desativada
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php if(!empty($auto_log)): ?>
        <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:.78rem;color:var(--text2)" class="bp-animate-in">
            <span style="font-weight:700;color:#10b981">🤖 Últimas movimentações automáticas</span>
            <?php foreach($auto_log as $entry): ?>
            <div style="margin-top:5px;padding-top:5px;border-top:1px solid rgba(16,185,129,.15)">
                Agend. #<?php echo (int)$entry['booking_id']; ?> —
                <strong><?php echo esc_html($status_labels[$entry['from']]??$entry['from']); ?></strong>
                → <strong style="color:#10b981"><?php echo esc_html($status_labels[$entry['to']]??$entry['to']); ?></strong>
                <span style="color:var(--text3);margin-left:6px"><?php echo esc_html(date_i18n('H:i', strtotime($entry['time']))); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="bp-kanban bp-animate-in">
            <?php foreach($columns as $status=>$label): $items=$by_status[$status]??[]; ?>
            <div class="bp-kanban-col">
                <div class="bp-kanban-col-header">
                    <div class="bp-kanban-col-title" style="color:<?php echo $colors[$status]; ?>"><?php echo esc_html($label); ?></div>
                    <span class="bp-kanban-count"><?php echo count($items); ?></span>
                </div>
                <div class="bp-kanban-items">
                    <?php if(empty($items)): ?>
                    <div style="text-align:center;color:var(--text3);font-size:.78rem;padding:12px">Vazio</div>
                    <?php endif; ?>
                    <?php foreach($items as $b): ?>
                    <div class="bp-kanban-card">
                        <div class="bp-kanban-card-title"><?php echo esc_html($b->client_name); ?></div>
                        <div class="bp-kanban-card-meta">
                            <span>⏰ <?php echo esc_html(substr($b->booking_time??'',0,5)); ?></span>
                            <?php if($b->svc_name): ?><span>✂️ <?php echo esc_html($b->svc_name); ?></span><?php endif; ?>
                            <?php if($b->pro_name): ?><span>👤 <?php echo esc_html($b->pro_name); ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:4px;margin-top:8px">
                            <?php if($status==='agendado'): ?><button class="bp-btn bp-btn-sm" style="background:var(--accent-dim);color:var(--accent);border:1px solid rgba(245,166,35,.3);font-size:.72rem" onclick="bpKanbanMove(<?php echo $b->id; ?>,'confirmado','<?php echo $mod; ?>')">Confirmar →</button><?php endif; ?>
                            <?php if($status==='confirmado'): ?><button class="bp-btn bp-btn-sm" style="background:rgba(167,139,250,.1);color:var(--purple);border:1px solid rgba(167,139,250,.2);font-size:.72rem" onclick="bpKanbanMove(<?php echo $b->id; ?>,'em_atendimento','<?php echo $mod; ?>')">Iniciar →</button><?php endif; ?>
                            <?php if($status==='em_atendimento'): ?><button class="bp-btn bp-btn-sm bp-btn-success" style="font-size:.72rem" onclick="bpKanbanMove(<?php echo $b->id; ?>,'finalizado','<?php echo $mod; ?>')">Finalizar ✓</button><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ── Serviços ──────────────────────────────────────────────────
    private function section_servicos( int $company_id ): void {
        $mod = $company_id===1?'barbearia':'lavacar';
        $mod_name = BarberPro_Database::get_setting("module_{$mod}_name", $company_id===1?'Barbearia':'Lava-Car');
        $mod = $company_id===1?'barbearia':'lavacar';
        $services = BarberPro_Database::get_services($company_id, true); // true = mostrar ativos + inativos
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">✂️ Serviços – <?php echo esc_html($mod_name); ?></div>
        </div>
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header">
                <div class="bp-card-title">Lista de Serviços</div>
                <button class="bp-btn bp-btn-primary bp-btn-sm" onclick="bpOpenServiceForm(0,<?php echo $company_id; ?>)">+ Novo Serviço</button>
            </div>
            <?php if(empty($services)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">✂️</div><div class="bp-empty-title">Nenhum serviço cadastrado</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Serviço</th><th>Duração</th><th>Preço</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach($services as $s): ?>
                <tr>
                    <td><strong><?php echo esc_html($s->name); ?></strong><?php if($s->description): ?><br><small style="color:var(--text3)"><?php echo esc_html(mb_substr($s->description,0,60)); ?></small><?php endif; ?></td>
                    <td style="color:var(--text2)"><?php echo (int)($s->duration??$s->duration_minutes??0); ?>min</td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($s->price); ?></td>
                    <td>
                        <button class="bp-btn bp-btn-sm" id="bpSvcToggle_<?php echo $s->id; ?>"
                            style="background:<?php echo $s->status==='active'?'rgba(34,211,160,.15)':'rgba(144,144,170,.12)'; ?>;color:<?php echo $s->status==='active'?'var(--green)':'var(--text3)'; ?>;border:1px solid <?php echo $s->status==='active'?'rgba(34,211,160,.3)':'var(--border)'; ?>"
                            onclick="bpToggleStatus('service',<?php echo $s->id; ?>,'<?php echo $mod; ?>_servicos')">
                            <?php echo $s->status==='active'?'✅ Ativo':'⏸ Inativo'; ?>
                        </button>
                    </td>
                    <td>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpOpenServiceForm(<?php echo $s->id; ?>,<?php echo $company_id; ?>)">✏️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Profissionais ─────────────────────────────────────────────
    private function section_profissionais( int $company_id ): void {
        $mod = $company_id===1?'barbearia':'lavacar';
        $mod_name = BarberPro_Database::get_setting("module_{$mod}_name", $company_id===1?'Barbearia':'Lava-Car');
        $pros = BarberPro_Database::get_professionals($company_id, true); // true = mostrar ativos + inativos
        $label_pro = $company_id===1?'Profissionais':'Atendentes';
        $days_labels = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">👤 <?php echo esc_html($label_pro.' – '.$mod_name); ?></div>
        </div>
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header">
                <div class="bp-card-title"><?php echo esc_html($label_pro); ?></div>
                <button class="bp-btn bp-btn-primary bp-btn-sm" onclick="bpOpenProForm(0,<?php echo $company_id; ?>)">+ Novo</button>
            </div>
            <?php if(empty($pros)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">👤</div><div class="bp-empty-title">Nenhum cadastrado</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Nome</th><th>Especialidade</th><th>Horário</th><th>Dias</th><th>Intervalos</th><th>Comissão</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach($pros as $p):
                    $ws   = substr($p->work_start??'09:00',0,5);
                    $we   = substr($p->work_end??'18:00',0,5);
                    $ls   = substr($p->lunch_start??'12:00',0,5);
                    $le   = substr($p->lunch_end??'13:00',0,5);
                    $wdays = array_map('intval', explode(',', $p->work_days??'1,2,3,4,5'));
                    $dias_str = implode(' ', array_map(function($d) use ($days_labels) { return "<span style='font-size:.7rem;background:var(--accent-dim);color:var(--accent);border-radius:4px;padding:1px 5px'>".$days_labels[$d]."</span>"; }, $wdays));
                ?>
                <tr>
                    <td><strong><?php echo esc_html($p->name); ?></strong>
                        <?php if($p->phone): ?><br><small style="color:var(--text3)"><?php echo esc_html($p->phone); ?></small><?php endif; ?>
                    </td>
                    <td style="color:var(--text2)"><?php echo esc_html($p->specialty??'—'); ?></td>
                    <td style="font-size:.8rem;white-space:nowrap">
                        <span style="color:var(--green)"><?php echo $ws; ?> – <?php echo $we; ?></span><br>
                        <span style="color:var(--text3)">🍽 <?php echo $ls; ?> – <?php echo $le; ?></span>
                    </td>
                    <td style="font-size:.78rem"><?php echo $dias_str; ?></td>
                    <td style="font-size:.78rem;white-space:nowrap;color:var(--text2)">
                        Admin: <?php echo (int)($p->slot_interval??15); ?>min<br>
                        Cliente: <?php echo (int)($p->client_slot_interval??60); ?>min
                    </td>
                    <td style="color:var(--accent)"><?php echo (float)($p->commission_pct??0); ?>%</td>
                    <td>
                        <button class="bp-btn bp-btn-sm" id="bpProToggle_<?php echo $p->id; ?>"
                            style="background:<?php echo $p->status==='active'?'rgba(34,211,160,.15)':'rgba(144,144,170,.12)'; ?>;color:<?php echo $p->status==='active'?'var(--green)':'var(--text3)'; ?>;border:1px solid <?php echo $p->status==='active'?'rgba(34,211,160,.3)':'var(--border)'; ?>"
                            onclick="bpToggleStatus('pro',<?php echo $p->id; ?>,'<?php echo $mod; ?>_profis')">
                            <?php echo $p->status==='active'?'✅ Ativo':'⏸ Inativo'; ?>
                        </button>
                    </td>
                    <td><button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpOpenProForm(<?php echo $p->id; ?>,<?php echo $company_id; ?>)" title="Editar">✏️</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }


}
