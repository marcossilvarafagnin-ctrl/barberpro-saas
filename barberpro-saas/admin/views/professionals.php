<?php
/**
 * View – Profissionais
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_staff' ) ) wp_die( 'Sem permissão.' );

$professionals = BarberPro_Professionals::get_all();
$wp_users = get_users( [ 'orderby' => 'display_name' ] );
?>
<div class="wrap barberpro-admin">
    <h1><?php esc_html_e( 'Profissionais', 'barberpro-saas' ); ?>
        <button class="page-title-action" id="btnAddPro">+ <?php esc_html_e( 'Novo Profissional', 'barberpro-saas' ); ?></button>
    </h1>

    <!-- Modal -->
    <div id="proModal" class="barberpro-modal" style="display:none">
        <div class="barberpro-modal-content">
            <span class="barberpro-modal-close">&times;</span>
            <h2><?php esc_html_e( 'Cadastrar Profissional', 'barberpro-saas' ); ?></h2>
            <form id="proForm">
                <?php wp_nonce_field( 'barberpro_ajax', 'nonce' ); ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Nome', 'barberpro-saas' ); ?> *</th>
                        <td><input type="text" name="name" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e( 'Especialidade', 'barberpro-saas' ); ?></th>
                        <td><input type="text" name="specialty" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Telefone', 'barberpro-saas' ); ?></th>
                        <td><input type="text" name="phone" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Usuário WP', 'barberpro-saas' ); ?></th>
                        <td><select name="user_id">
                            <option value=""><?php esc_html_e( '— Nenhum —', 'barberpro-saas' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                    <tr><th><?php esc_html_e( 'Dias de Trabalho', 'barberpro-saas' ); ?></th>
                        <td>
                            <?php $days = ['0'=>'Dom','1'=>'Seg','2'=>'Ter','3'=>'Qua','4'=>'Qui','5'=>'Sex','6'=>'Sáb'];
                            foreach ( $days as $val => $label ) : ?>
                            <label style="margin-right:8px">
                                <input type="checkbox" name="work_days[]" value="<?php echo esc_attr( $val ); ?>"
                                    <?php checked( in_array( $val, ['1','2','3','4','5'] ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </td></tr>
                    <tr><th><?php esc_html_e( 'Horário', 'barberpro-saas' ); ?></th>
                        <td>
                            <input type="time" name="work_start" value="09:00">
                            <?php esc_html_e( 'até', 'barberpro-saas' ); ?>
                            <input type="time" name="work_end" value="18:00">
                        </td></tr>
                    <tr><th><?php esc_html_e( 'Almoço', 'barberpro-saas' ); ?></th>
                        <td>
                            <input type="time" name="lunch_start" value="12:00">
                            <?php esc_html_e( 'até', 'barberpro-saas' ); ?>
                            <input type="time" name="lunch_end" value="13:00">
                        </td></tr>
                    <tr><th><?php esc_html_e( 'Intervalo (min)', 'barberpro-saas' ); ?></th>
                        <td><input type="number" name="slot_interval" value="30" min="5" class="small-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Comissão (%)', 'barberpro-saas' ); ?></th>
                        <td><input type="number" name="commission_pct" value="40" min="0" max="100" step="0.5" class="small-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Meta Mensal (R\$)', 'barberpro-saas' ); ?></th>
                        <td><input type="number" name="monthly_goal" value="0" min="0" step="100" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Foto', 'barberpro-saas' ); ?></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <img id="pro_photo_preview" src="" alt="" style="width:60px;height:60px;border-radius:50%;object-fit:cover;display:none;border:2px solid #ddd;">
                                <div>
                                    <input type="hidden" name="photo" id="pro_photo_url" value="">
                                    <button type="button" class="button" id="btn_pro_photo">📷 Selecionar Imagem</button>
                                    <button type="button" class="button" id="btn_pro_photo_remove" style="display:none;color:#c00;">✕ Remover</button>
                                </div>
                            </div>
                        </td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Salvar', 'barberpro-saas' ); ?></button></p>
            </form>
        </div>
    </div>

    <div class="barberpro-pro-grid">
    <?php foreach ( $professionals as $p ) : ?>
    <div class="barberpro-pro-card">
        <?php if ( $p->photo ) : ?>
        <img src="<?php echo esc_url( $p->photo ); ?>" alt="<?php echo esc_attr( $p->name ); ?>">
        <?php else : ?>
        <div class="pro-avatar"><?php echo esc_html( strtoupper( substr( $p->name, 0, 1 ) ) ); ?></div>
        <?php endif; ?>
        <h3><?php echo esc_html( $p->name ); ?></h3>
        <p class="pro-specialty"><?php echo esc_html( $p->specialty ); ?></p>
        <p class="pro-rating">⭐ <?php echo esc_html( number_format( $p->rating, 1 ) ); ?> (<?php echo esc_html( $p->rating_count ); ?>)</p>
        <p class="pro-commission"><?php echo esc_html( $p->commission_pct ); ?>% comissão</p>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<script>
jQuery(function($){
    // ── Foto do profissional via wp.media ──
    var proMediaFrame;
    $('#btn_pro_photo').on('click', function(e){
        e.preventDefault();
        if(proMediaFrame){ proMediaFrame.open(); return; }
        proMediaFrame = wp.media({ title: 'Selecionar Foto', button: { text: 'Usar esta foto' }, multiple: false });
        proMediaFrame.on('select', function(){
            var att = proMediaFrame.state().get('selection').first().toJSON();
            $('#pro_photo_url').val(att.url);
            $('#pro_photo_preview').attr('src', att.url).show();
            $('#btn_pro_photo_remove').show();
        });
        proMediaFrame.open();
    });
    $('#btn_pro_photo_remove').on('click', function(){
        $('#pro_photo_url').val('');
        $('#pro_photo_preview').attr('src','').hide();
        $(this).hide();
    });
    // Populate photo when editing
    $(document).on('barberpro:pro_loaded', function(e, pro){
        if(pro.photo){
            $('#pro_photo_url').val(pro.photo);
            $('#pro_photo_preview').attr('src', pro.photo).show();
            $('#btn_pro_photo_remove').show();
        } else {
            $('#pro_photo_url').val('');
            $('#pro_photo_preview').attr('src','').hide();
            $('#btn_pro_photo_remove').hide();
        }
    });
    // Reset on new
    $(document).on('barberpro:pro_new', function(){
        $('#pro_photo_url').val('');
        $('#pro_photo_preview').attr('src','').hide();
        $('#btn_pro_photo_remove').hide();
    });
});
</script>

