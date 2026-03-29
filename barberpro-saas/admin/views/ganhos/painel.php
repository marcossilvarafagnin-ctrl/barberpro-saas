<?php
/**
 * View – Painel de Ganhos (Dia / Semana / Mes / Ano / Personalizado)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$preset = sanitize_key( $_GET['preset'] ?? 'mes' );
$today  = current_time( 'Y-m-d' );

switch ( $preset ) {
    case 'hoje':
        $date_from = $date_to = $today;
        $label = 'Hoje (' . date_i18n('d/m/Y') . ')';
        break;
    case 'semana':
        $date_from = date( 'Y-m-d', strtotime( 'monday this week' ) );
        $date_to   = date( 'Y-m-d', strtotime( 'sunday this week' ) );
        $label = 'Esta Semana (' . date_i18n('d/m', strtotime($date_from)) . ' - ' . date_i18n('d/m/Y', strtotime($date_to)) . ')';
        break;
    case 'ano':
        $date_from = current_time( 'Y' ) . '-01-01';
        $date_to   = current_time( 'Y' ) . '-12-31';
        $label = 'Este Ano (' . current_time('Y') . ')';
        break;
    case 'custom':
        $date_from = sanitize_text_field( $_GET['date_from'] ?? date( 'Y-m-01' ) );
        $date_to   = sanitize_text_field( $_GET['date_to']   ?? $today );
        $label = 'Personalizado: ' . date_i18n('d/m/Y', strtotime($date_from)) . ' - ' . date_i18n('d/m/Y', strtotime($date_to));
        break;
    default:
        $preset    = 'mes';
        $date_from = date( 'Y-m-01' );
        $date_to   = date( 'Y-m-t' );
        $label = 'Este Mes (' . date_i18n('F Y') . ')';
}

$view         = sanitize_key( $_GET['view'] ?? 'todos' );
$active_mods  = BarberPro_Modules::active_list();
$company_id   = ( $view === 'todos' ) ? 0 : BarberPro_Modules::company_id( $view );
$data         = BarberPro_Finance::get_period_summary( $date_from, $date_to, $company_id );

$company_names = [
    1 => BarberPro_Database::get_setting('module_barbearia_name','Barbearia'),
    2 => BarberPro_Database::get_setting('module_lavacar_name','Lava-Car'),
];
$method_labels = [
    'dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Debito',
    'cartao_credito'=>'Credito','transferencia'=>'Transferencia',
    'presencial'=>'No local','outro'=>'Outro',
];

function bp_ganhos_money( $v ): string {
    return 'R$ ' . number_format( (float)$v, 2, ',', '.' );
}
function bp_ganhos_url( array $p = [] ): string {
    $base = is_admin() ? admin_url('admin.php?page=barberpro_ganhos') : get_permalink();
    return esc_url( add_query_arg( $p, $base ) );
}
?>
<div class="wrap barberpro-admin" id="bpGanhosPanel">
<style>
.bp-presets{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.bp-preset-btn{padding:8px 18px;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;border:2px solid #e5e7eb;background:#fff;color:#374151;text-decoration:none;transition:all .15s}
.bp-preset-btn:hover,.bp-preset-btn.active{background:#1a1a2e;color:#fff;border-color:#1a1a2e}
.bp-view-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.bp-view-tab{padding:7px 16px;border-radius:20px;font-size:.85rem;font-weight:600;text-decoration:none;border:2px solid #e5e7eb;color:#6b7280;transition:all .15s}
.bp-view-tab[data-mod="todos"].active{background:#1a1a2e;color:#fff;border-color:#1a1a2e}
.bp-view-tab[data-mod="barbearia"].active{background:#e94560;color:#fff;border-color:#e94560}
.bp-view-tab[data-mod="lavacar"].active{background:#3b82f6;color:#fff;border-color:#3b82f6}
.bp-kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:22px}
.bp-kpi-card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.bp-kpi-icon{font-size:1.5rem;margin-bottom:5px}
.bp-kpi-label{font-size:.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.bp-kpi-val{font-size:1.35rem;font-weight:800;color:#1a1a2e}
.bp-kpi-val.green{color:#10b981}.bp-kpi-val.red{color:#ef4444}
.bp-charts-row{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:18px}
.bp-g-card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.bp-g-card h3{margin:0 0 14px;font-size:.92rem}
.bp-method-list{list-style:none;margin:0;padding:0}
.bp-method-list li{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.85rem}
.bp-method-list li:last-child{border:none}
.bp-mbar{height:5px;border-radius:3px;background:#3b82f6;margin-top:2px}
.bp-compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.bp-ccard{border-radius:12px;padding:18px;color:#fff}
.bp-top-tbl{width:100%;border-collapse:collapse;font-size:.85rem}
.bp-top-tbl th{text-align:left;padding:7px 10px;background:#f8f9fa;font-size:.72rem;text-transform:uppercase;color:#9ca3af}
.bp-top-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6}
.bp-top-tbl tr:last-child td{border:none}
.bp-custom-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;padding:12px;background:#f8f9fa;border-radius:8px;margin-bottom:18px}
.bp-custom-row label{font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px}
.bp-custom-row input{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem}
@media(max-width:680px){.bp-charts-row,.bp-compare-grid{grid-template-columns:1fr}}
</style>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div>
            <h1 style="margin:0">💰 Painel de Ganhos</h1>
            <p style="color:#6b7280;margin:4px 0 0;font-size:.88rem"><?php echo esc_html($label); ?></p>
        </div>
    </div>

    <!-- Presets de periodo -->
    <div class="bp-presets">
        <?php
        $ps = ['hoje'=>'Hoje','semana'=>'Semana','mes'=>'Mes','ano'=>'Ano','custom'=>'Personalizado'];
        foreach ($ps as $k=>$l): ?>
        <a href="<?php echo bp_ganhos_url(['preset'=>$k,'view'=>$view]); ?>"
           class="bp-preset-btn <?php echo $preset===$k?'active':''; ?>"><?php echo esc_html($l); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($preset==='custom'): ?>
    <form method="get" class="bp-custom-row">
        <?php if(is_admin()):?><input type="hidden" name="page" value="barberpro_ganhos"><?php endif;?>
        <input type="hidden" name="preset" value="custom">
        <input type="hidden" name="view" value="<?php echo esc_attr($view);?>">
        <div><label>De</label><input type="date" name="date_from" value="<?php echo esc_attr($date_from);?>"></div>
        <div><label>Ate</label><input type="date" name="date_to" value="<?php echo esc_attr($date_to);?>"></div>
        <button type="submit" class="button button-primary" style="padding:8px 16px;height:auto">Filtrar</button>
    </form>
    <?php endif; ?>

    <!-- Abas de visao -->
    <div class="bp-view-tabs">
        <a href="<?php echo bp_ganhos_url(['preset'=>$preset,'view'=>'todos']);?>"
           class="bp-view-tab <?php echo $view==='todos'?'active':'';?>" data-mod="todos">Todos</a>
        <?php foreach($active_mods as $mk=>$mm):
            $mname = BarberPro_Database::get_setting("module_{$mk}_name",$mm['label']);?>
        <a href="<?php echo bp_ganhos_url(['preset'=>$preset,'view'=>$mk]);?>"
           class="bp-view-tab <?php echo $view===$mk?'active':'';?>" data-mod="<?php echo esc_attr($mk);?>">
            <?php echo $mm['icon'].' '.esc_html($mname);?>
        </a>
        <?php endforeach;?>
    </div>

    <!-- KPIs -->
    <div class="bp-kpi-row">
        <?php
        $lc = $data['lucro']>=0?'green':'red';
        foreach([
            ['Receita',   $data['receita'],           'green',''],
            ['Despesas',  $data['despesa'],            'red',  ''],
            ['Lucro',     $data['lucro'],              $lc,    ''],
            ['Margem',    $data['margem'].'%',         $lc,    ''],
        ] as [$lbl,$val,$cls,$_sub]):
            $fmt = is_numeric($val) && strpos((string)$val,'%')===false ? bp_ganhos_money($val) : $val;
        ?>
        <div class="bp-kpi-card">
            <div class="bp-kpi-label"><?php echo esc_html($lbl);?></div>
            <div class="bp-kpi-val <?php echo esc_attr($cls);?>"><?php echo esc_html($fmt);?></div>
        </div>
        <?php endforeach;?>
    </div>

    <!-- Comparativo por modulo (so quando "todos") -->
    <?php if($view==='todos' && !empty($data['by_company'])):?>
    <div class="bp-compare-grid">
        <?php foreach($data['by_company'] as $bc):
            $cid_k = (int)$bc->company_id;
            $cname = $company_names[$cid_k] ?? "Empresa {$cid_k}";
            $color = $cid_k===1?'#e94560':'#3b82f6';
            $icon  = $cid_k===1?'Barbearia':'Lava-Car';
            $lucro_c = (float)$bc->receita-(float)$bc->despesa;
            $pct_c   = $data['receita']>0?round((float)$bc->receita/$data['receita']*100):0;
        ?>
        <div class="bp-ccard" style="background:<?php echo esc_attr($color);?>">
            <h4 style="margin:0 0 10px;opacity:.9"><?php echo esc_html($cname);?></h4>
            <div style="font-size:1.5rem;font-weight:800;margin-bottom:4px"><?php echo esc_html(bp_ganhos_money($bc->receita));?></div>
            <div style="opacity:.75;font-size:.8rem">Receita</div>
            <div style="display:flex;gap:18px;margin-top:10px;font-size:.85rem">
                <div><div style="opacity:.75">Despesas</div><strong><?php echo esc_html(bp_ganhos_money($bc->despesa));?></strong></div>
                <div><div style="opacity:.75">Lucro</div><strong><?php echo esc_html(bp_ganhos_money($lucro_c));?></strong></div>
            </div>
            <div style="margin-top:10px;height:5px;background:rgba(255,255,255,.2);border-radius:3px">
                <div style="height:5px;background:#fff;border-radius:3px;width:<?php echo $pct_c;?>%"></div>
            </div>
            <div style="font-size:.72rem;opacity:.75;margin-top:3px"><?php echo $pct_c;?>% do total</div>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>

    <!-- Grafico + Metodos -->
    <div class="bp-charts-row">
        <div class="bp-g-card">
            <h3>Receita x Despesa por Dia</h3>
            <?php if(empty($data['by_day'])):?>
            <p style="color:#9ca3af;text-align:center;padding:24px 0">Nenhum lancamento neste periodo.</p>
            <?php else:?>
            <canvas id="bpGanhosChart" style="max-height:220px"></canvas>
            <script>
            document.addEventListener('DOMContentLoaded',function(){
                var ctx=document.getElementById('bpGanhosChart');
                if(!ctx||typeof Chart==='undefined')return;
                new Chart(ctx,{
                    type:'bar',
                    data:{
                        labels:<?php echo wp_json_encode(array_map(function($d) { return date_i18n('d/m', strtotime($d->dia)); }, $data['by_day']));?>,
                        datasets:[
                            {label:'Receita',data:<?php echo wp_json_encode(array_map(function($d) { return (float)$d->receita; }, $data['by_day']));?>,backgroundColor:'#10b98180',borderColor:'#10b981',borderWidth:1.5,borderRadius:4},
                            {label:'Despesa',data:<?php echo wp_json_encode(array_map(function($d) { return (float)$d->despesa; }, $data['by_day']));?>,backgroundColor:'#ef444450',borderColor:'#ef4444',borderWidth:1.5,borderRadius:4}
                        ]
                    },
                    options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return'R$ '+v.toLocaleString('pt-BR')}}}}}
                });
            });
            </script>
            <?php endif;?>
        </div>
        <div class="bp-g-card">
            <h3>Por Forma de Pagamento</h3>
            <?php if(empty($data['by_method'])):?>
            <p style="color:#9ca3af;font-size:.85rem">Sem recebimentos no periodo.</p>
            <?php else:
                $max_m=max(array_map(function($m) { return (float)$m->total; }, $data['by_method']));
                foreach($data['by_method'] as $m):
                    $pct=($max_m>0)?round((float)$m->total/$max_m*100):0;
                    $lbl=$method_labels[$m->payment_method]??ucfirst($m->payment_method);
            ?>
            <ul class="bp-method-list">
                <li>
                    <div style="flex:1">
                        <div style="display:flex;justify-content:space-between">
                            <span><?php echo esc_html($lbl);?></span>
                            <strong><?php echo esc_html(bp_ganhos_money($m->total));?></strong>
                        </div>
                        <div class="bp-mbar" style="width:<?php echo $pct;?>%"></div>
                    </div>
                </li>
            </ul>
            <?php endforeach; endif;?>
        </div>
    </div>

    <!-- Top servicos -->
    <?php if(!empty($data['top_services'])):?>
    <div class="bp-g-card">
        <h3>Servicos Mais Rentaveis no Periodo</h3>
        <table class="bp-top-tbl">
            <thead><tr><th>#</th><th>Servico</th><th>Qtd</th><th>Total</th><th>Ticket Medio</th></tr></thead>
            <tbody>
            <?php foreach($data['top_services'] as $i=>$s):
                $ticket=$s->qty>0?(float)$s->total/(int)$s->qty:0;?>
            <tr>
                <td style="color:#9ca3af;font-weight:700"><?php echo $i+1;?></td>
                <td><strong><?php echo esc_html($s->service_name);?></strong></td>
                <td><span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:.78rem"><?php echo esc_html($s->qty);?>x</span></td>
                <td style="font-weight:700;color:#10b981"><?php echo esc_html(bp_ganhos_money($s->total));?></td>
                <td style="color:#6b7280"><?php echo esc_html(bp_ganhos_money($ticket));?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <?php endif;?>
</div>
