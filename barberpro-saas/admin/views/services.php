<?php
/**
 * View – Gerenciamento de Serviços
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_services' ) ) wp_die( 'Sem permissão.' );

$services = BarberPro_Services::get_all();
?>
<div class="wrap barberpro-admin">
    <h1><?php esc_html_e( 'Serviços', 'barberpro-saas' ); ?>
        <button class="page-title-action" id="btnAddService">+ <?php esc_html_e( 'Novo Serviço', 'barberpro-saas' ); ?></button>
    </h1>

    <!-- Modal Form -->
    <div id="serviceModal" class="barberpro-modal" style="display:none">
        <div class="barberpro-modal-content">
            <span class="barberpro-modal-close">&times;</span>
            <h2 id="serviceModalTitle"><?php esc_html_e( 'Novo Serviço', 'barberpro-saas' ); ?></h2>
            <form id="serviceForm">
                <input type="hidden" name="id" id="serviceId" value="0">
                <?php wp_nonce_field( 'barberpro_ajax', 'nonce' ); ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Nome', 'barberpro-saas' ); ?> *</th>
                        <td><input type="text" name="name" id="serviceName" class="regular-text" required></td></tr>
                    <tr><th><?php esc_html_e( 'Descrição', 'barberpro-saas' ); ?></th>
                        <td><textarea name="description" id="serviceDesc" rows="3" class="large-text"></textarea></td></tr>
                    <tr><th><?php esc_html_e( 'Preço (R$)', 'barberpro-saas' ); ?> *</th>
                        <td><input type="number" name="price" id="servicePrice" step="0.01" min="0" class="small-text" required></td></tr>
                    <tr><th><?php esc_html_e( 'Duração (min)', 'barberpro-saas' ); ?> *</th>
                        <td><input type="number" name="duration" id="serviceDuration" min="5" class="small-text" required></td></tr>
                    <tr><th><?php esc_html_e( 'Categoria', 'barberpro-saas' ); ?></th>
                        <td><input type="text" name="category" id="serviceCategory" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Imagem', 'barberpro-saas' ); ?></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <img id="svc_photo_preview" src="" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:6px;display:none;border:2px solid #ddd;">
                                <div>
                                    <input type="hidden" name="photo" id="svc_photo_url" value="">
                                    <button type="button" class="button" id="btn_svc_photo">🖼️ Selecionar Imagem</button>
                                    <button type="button" class="button" id="btn_svc_photo_remove" style="display:none;color:#c00;">✕ Remover</button>
                                </div>
                            </div>
                        </td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Salvar', 'barberpro-saas' ); ?></button></p>
            </form>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th><?php esc_html_e( 'Nome', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Duração', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Preço', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Categoria', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Ações', 'barberpro-saas' ); ?></th>
        </tr></thead>
        <tbody id="servicesList">
        <?php foreach ( $services as $s ) : ?>
        <tr>
            <td><?php echo esc_html( $s->name ); ?></td>
            <td><?php echo esc_html( $s->duration ); ?> min</td>
            <td>R$ <?php echo esc_html( number_format( $s->price, 2, ',', '.' ) ); ?></td>
            <td><?php echo esc_html( $s->category ); ?></td>
            <td>
                <button class="button btn-edit-service"
                    data-id="<?php echo esc_attr( $s->id ); ?>"
                    data-name="<?php echo esc_attr( $s->name ); ?>"
                    data-price="<?php echo esc_attr( $s->price ); ?>"
                    data-duration="<?php echo esc_attr( $s->duration ); ?>"
                    data-category="<?php echo esc_attr( $s->category ); ?>"
                    data-description="<?php echo esc_attr( $s->description ); ?>"
                    data-photo="<?php echo esc_attr( $s->photo ?? '' ); ?>">
                    <?php esc_html_e( 'Editar', 'barberpro-saas' ); ?>
                </button>
                <button class="button button-link-delete btn-delete-service" data-id="<?php echo esc_attr( $s->id ); ?>">
                    <?php esc_html_e( 'Excluir', 'barberpro-saas' ); ?>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(function($){
    // ── Imagem do serviço via wp.media ──
    var svcMediaFrame;
    $('#btn_svc_photo').on('click', function(e){
        e.preventDefault();
        if(svcMediaFrame){ svcMediaFrame.open(); return; }
        svcMediaFrame = wp.media({ title: 'Selecionar Imagem', button: { text: 'Usar esta imagem' }, multiple: false });
        svcMediaFrame.on('select', function(){
            var att = svcMediaFrame.state().get('selection').first().toJSON();
            $('#svc_photo_url').val(att.url);
            $('#svc_photo_preview').attr('src', att.url).show();
            $('#btn_svc_photo_remove').show();
        });
        svcMediaFrame.open();
    });
    $('#btn_svc_photo_remove').on('click', function(){
        $('#svc_photo_url').val('');
        $('#svc_photo_preview').attr('src','').hide();
        $(this).hide();
    });
    // Populate when editing
    $(document).on('click', '.btn-edit-service', function(){
        var photo = $(this).data('photo') || '';
        if(photo){
            $('#svc_photo_url').val(photo);
            $('#svc_photo_preview').attr('src', photo).show();
            $('#btn_svc_photo_remove').show();
        } else {
            $('#svc_photo_url').val('');
            $('#svc_photo_preview').attr('src','').hide();
            $('#btn_svc_photo_remove').hide();
        }
    });
    // Reset on new
    $('#btnAddService').on('click', function(){
        $('#svc_photo_url').val('');
        $('#svc_photo_preview').attr('src','').hide();
        $('#btn_svc_photo_remove').hide();
    });
});
</script>

