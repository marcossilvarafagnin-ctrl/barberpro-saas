<?php
/**
 * BarberPro – Gerenciador de Pagamentos
 *
 * Métodos suportados (todos opcionais):
 *  - Pagar no local    : sem cobrança online
 *  - PIX Estático      : QR Code gerado localmente via chave PIX (sem API externa)
 *  - PIX Dinâmico      : cobrança via Efí Bank / Gerencianet com vencimento e verificação
 *  - Mercado Pago      : Checkout Pro com PIX preferencial opcional
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Payment {

    // =========================================================
    // MÉTODO CENTRAL
    // =========================================================

    public static function create_charge( object $booking, string $method = '' ): array {
        if ( ! $method ) $method = self::get_default_method();

        $service = BarberPro_Database::get_service( (int)$booking->service_id );
        $amount  = (float)( $service->price ?? 0 );

        if ( BarberPro_Database::get_setting('require_deposit','0') === '1' ) {
            $pct    = max(1, (float)BarberPro_Database::get_setting('deposit_pct', 50));
            $amount = round( $amount * $pct / 100, 2 );
        }

        if ( $amount <= 0 ) return ['success'=>false,'message'=>'Valor inválido.'];

        $desc = sprintf('Agendamento %s – %s',
            $booking->booking_code ?? '#'.$booking->id,
            $service->name ?? 'Serviço'
        );

        return match($method) {
            'pix_static'  => self::pix_static( $booking, $amount, $desc ),
            'pix_dynamic' => self::pix_dynamic( $booking, $amount, $desc ),
            'mercadopago' => self::mercadopago( $booking, $amount, $desc ),
            default       => ['success'=>false,'message'=>"Método desconhecido: {$method}"],
        };
    }

    // =========================================================
    // PIX ESTÁTICO — QR Code local, zero dependência
    // =========================================================

    public static function pix_static( object $booking, float $amount, string $desc ): array {
        $chave   = trim( BarberPro_Database::get_setting('pix_key','') );
        $titular = trim( BarberPro_Database::get_setting('pix_holder', get_bloginfo('name')) );
        $cidade  = trim( BarberPro_Database::get_setting('pix_city','SAO PAULO') );

        if ( empty($chave) ) return ['success'=>false,'message'=>'Chave PIX não configurada.'];

        $txid    = 'BP'.str_pad((string)$booking->id, 10, '0', STR_PAD_LEFT);
        $payload = self::build_pix_payload($chave, $titular, $cidade, $amount, $txid, $desc);

        $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='.urlencode($payload);
        $qr_base64 = self::fetch_image_base64($qr_url);

        return [
            'success'        => true,
            'method'         => 'pix_static',
            'pix_payload'    => $payload,
            'qr_code_url'    => $qr_url,
            'qr_code_base64' => $qr_base64,
            'amount'         => $amount,
            'txid'           => $txid,
            'instructions'   => 'Abra o app do seu banco → PIX → Ler QR Code ou cole o código abaixo.',
        ];
    }

    // =========================================================
    // PIX DINÂMICO — Efí Bank (Gerencianet)
    // =========================================================

    public static function pix_dynamic( object $booking, float $amount, string $desc ): array {
        $client_id  = BarberPro_Database::get_setting('efi_client_id','');
        $client_sec = BarberPro_Database::get_setting('efi_client_secret','');
        $sandbox    = BarberPro_Database::get_setting('efi_sandbox','1') === '1';

        if ( ! $client_id || ! $client_sec ) return ['success'=>false,'message'=>'Credenciais Efí Bank não configuradas.'];

        $base = $sandbox ? 'https://pix-h.api.efipay.com.br' : 'https://pix.api.efipay.com.br';

        // Token OAuth
        $tr = wp_remote_post($base.'/oauth/token', [
            'headers' => ['Content-Type'=>'application/json','Authorization'=>'Basic '.base64_encode("{$client_id}:{$client_sec}")],
            'body'    => wp_json_encode(['grant_type'=>'client_credentials']),
            'timeout' => 15,
        ]);
        if ( is_wp_error($tr) ) return ['success'=>false,'message'=>$tr->get_error_message()];
        $td = json_decode(wp_remote_retrieve_body($tr), true);
        $token = $td['access_token'] ?? null;
        if ( ! $token ) return ['success'=>false,'message'=>'Falha ao obter token Efí Bank.'];

        // Cria cobrança
        $exp   = (int)BarberPro_Database::get_setting('pix_expiracao_minutos','30') * 60;
        $chave = BarberPro_Database::get_setting('pix_key','');
        $txid  = 'BP'.str_pad((string)$booking->id, 10, '0', STR_PAD_LEFT);
        $nome  = sanitize_text_field($booking->client_name ?? 'Cliente');

        $cr = wp_remote_request($base."/v2/cob/{$txid}", [
            'method'  => 'PUT',
            'headers' => ['Content-Type'=>'application/json','Authorization'=>'Bearer '.$token],
            'body'    => wp_json_encode([
                'calendario'          => ['expiracao'=>$exp],
                'devedor'             => ['nome'=>$nome,'cpf'=>'00000000000'],
                'valor'               => ['original'=>number_format($amount,2,'.','')],
                'chave'               => $chave,
                'solicitacaoPagador'  => mb_substr($desc,0,140),
            ]),
            'timeout' => 15,
        ]);
        if ( is_wp_error($cr) ) return ['success'=>false,'message'=>$cr->get_error_message()];
        $cob = json_decode(wp_remote_retrieve_body($cr), true);
        $loc_id = $cob['loc']['id'] ?? null;
        if ( ! $loc_id ) return ['success'=>false,'message'=>$cob['mensagem']??$cob['detail']??'Erro na cobrança PIX.'];

        // QR Code
        $qr  = wp_remote_get($base."/v2/loc/{$loc_id}/qrcode", [
            'headers' => ['Authorization'=>'Bearer '.$token], 'timeout'=>15,
        ]);
        $qrd = json_decode(wp_remote_retrieve_body($qr), true);

        return [
            'success'        => true,
            'method'         => 'pix_dynamic',
            'pix_payload'    => $qrd['qrcode'] ?? '',
            'qr_code_base64' => $qrd['imagemQrcode'] ?? '',
            'qr_code_url'    => '',
            'txid'           => $txid,
            'loc_id'         => $loc_id,
            'amount'         => $amount,
            'expiracao_min'  => intval($exp/60),
            'instructions'   => 'QR Code PIX com vencimento em '.intval($exp/60).' minutos.',
        ];
    }

    // =========================================================
    // MERCADO PAGO — Checkout Pro
    // =========================================================

    public static function mercadopago( object $booking, float $amount, string $desc ): array {
        $access_token = BarberPro_Database::get_setting('mp_access_token','');
        if ( ! $access_token ) return ['success'=>false,'message'=>'Access Token Mercado Pago não configurado.'];

        $service     = BarberPro_Database::get_service((int)$booking->service_id);
        $success_url = add_query_arg(['status'=>'pago','booking'=>$booking->booking_code],
                           BarberPro_Database::get_setting('mp_success_url', home_url('/agendamento/')));
        $failure_url = add_query_arg(['status'=>'falhou','booking'=>$booking->booking_code],
                           BarberPro_Database::get_setting('mp_failure_url', home_url('/agendamento/')));
        $pending_url = add_query_arg(['status'=>'pendente','booking'=>$booking->booking_code],
                           BarberPro_Database::get_setting('mp_pending_url', home_url('/agendamento/')));

        $body = [
            'items'               => [['title'=>$desc,'quantity'=>1,'unit_price'=>$amount,'currency_id'=>'BRL']],
            'payer'               => [
                'name'  => sanitize_text_field($booking->client_name ?? ''),
                'email' => sanitize_email($booking->client_email ?? 'cliente@email.com'),
                'phone' => ['number'=>preg_replace('/\D/','', $booking->client_phone ?? '')],
            ],
            'back_urls'           => ['success'=>$success_url,'failure'=>$failure_url,'pending'=>$pending_url],
            'auto_return'         => 'approved',
            'external_reference'  => $booking->booking_code ?? 'BP-'.$booking->id,
            'notification_url'    => rest_url('barberpro/v1/mp-webhook'),
            'statement_descriptor'=> mb_substr(get_bloginfo('name'),0,16),
            'expires'             => true,
            'expiration_date_to'  => date('c', strtotime('+'.BarberPro_Database::get_setting('mp_expiracao_horas','24').' hours')),
        ];

        if ( BarberPro_Database::get_setting('mp_pix_preferencial','1') === '1' ) {
            $body['payment_methods'] = ['excluded_payment_types'=>[],'installments'=>1];
        }

        $resp = wp_remote_post('https://api.mercadopago.com/checkout/preferences', [
            'headers' => ['Content-Type'=>'application/json','Authorization'=>'Bearer '.$access_token],
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ]);
        if ( is_wp_error($resp) ) return ['success'=>false,'message'=>$resp->get_error_message()];

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ( $code !== 201 || empty($data['id']) ) {
            return ['success'=>false,'message'=>'Mercado Pago: '.($data['message']??$data['error']??"Erro HTTP {$code}")];
        }

        $sandbox = BarberPro_Database::get_setting('mp_sandbox','0') === '1';
        $url     = $sandbox ? ($data['sandbox_init_point']??$data['init_point']) : $data['init_point'];

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}barber_bookings SET payment_status='pendente' WHERE id=%d",
            $booking->id
        ));

        return [
            'success'       => true,
            'method'        => 'mercadopago',
            'preference_id' => $data['id'],
            'payment_url'   => $url,
            'amount'        => $amount,
            'instructions'  => 'Clique em "Pagar com Mercado Pago" para concluir.',
        ];
    }

    // =========================================================
    // WEBHOOK MP + POLLING PIX
    // =========================================================

    public static function register_routes(): void {
        register_rest_route('barberpro/v1', '/mp-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_mp_webhook'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('barberpro/v1', '/pix-status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'check_pix_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_mp_webhook( WP_REST_Request $req ): WP_REST_Response {
        $body   = $req->get_json_params();
        $type   = $body['type'] ?? $body['action'] ?? '';
        $pay_id = $body['data']['id'] ?? '';
        if ( ! $pay_id || ! str_contains($type, 'payment') ) return new WP_REST_Response(['ok'=>true],200);

        $token = BarberPro_Database::get_setting('mp_access_token','');
        if ( ! $token ) return new WP_REST_Response(['ok'=>true],200);

        $pr = wp_remote_get("https://api.mercadopago.com/v1/payments/{$pay_id}", [
            'headers'=>['Authorization'=>'Bearer '.$token], 'timeout'=>10,
        ]);
        if ( is_wp_error($pr) ) return new WP_REST_Response(['ok'=>true],200);

        $payment = json_decode(wp_remote_retrieve_body($pr), true);
        $status  = $payment['status']             ?? '';
        $ref     = $payment['external_reference'] ?? '';
        if ( ! $ref ) return new WP_REST_Response(['ok'=>true],200);

        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bookings WHERE booking_code=%s LIMIT 1", $ref
        ));
        if ( ! $booking ) return new WP_REST_Response(['ok'=>true],200);

        if ( $status === 'approved' ) {
            $wpdb->update("{$wpdb->prefix}barber_bookings",
                ['payment_status'=>'pago','status'=>'confirmado','updated_at'=>current_time('mysql')],
                ['id'=>$booking->id]
            );
            $wpdb->update("{$wpdb->prefix}barber_finance",
                ['status'=>'pago','payment_method'=>'mercadopago'],
                ['booking_id'=>$booking->id]
            );
            $booking->status = 'confirmado';
            BarberPro_Notifications::dispatch('confirmation', $booking);
        } elseif ( in_array($status, ['rejected','cancelled','refunded']) ) {
            $wpdb->update("{$wpdb->prefix}barber_bookings",
                ['payment_status'=>'falhou','updated_at'=>current_time('mysql')],
                ['id'=>$booking->id]
            );
        }
        return new WP_REST_Response(['ok'=>true],200);
    }

    public static function check_pix_status( WP_REST_Request $req ): WP_REST_Response {
        $code = sanitize_text_field($req->get_param('booking') ?? '');
        if ( ! $code ) return new WP_REST_Response(['paid'=>false],200);
        global $wpdb;
        $b = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_status, status FROM {$wpdb->prefix}barber_bookings WHERE booking_code=%s LIMIT 1", $code
        ));
        return new WP_REST_Response(['paid'=>$b && $b->payment_status==='pago','status'=>$b->status??''],200);
    }

    // =========================================================
    // AJAX — cria cobrança após agendamento confirmado
    // =========================================================

    public static function ajax_create_payment(): void {
        check_ajax_referer('barberpro_booking','nonce');
        $code   = sanitize_text_field($_POST['booking_code'] ?? '');
        $method = sanitize_key($_POST['payment_method'] ?? '');
        if ( ! $code ) wp_send_json_error(['message'=>'Código inválido.']);

        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bookings WHERE booking_code=%s LIMIT 1", $code
        ));
        if ( ! $booking ) wp_send_json_error(['message'=>'Agendamento não encontrado.']);

        $result = self::create_charge($booking, $method);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    // =========================================================
    // GETTERS DE CONFIGURAÇÃO
    // =========================================================

    public static function get_default_method(): string {
        $mp_ativo = BarberPro_Database::get_setting('payment_mp_ativo','0') === '1'
                 || BarberPro_Database::get_setting('pay_mp_ativo','0') === '1';
        if ( $mp_ativo )                                                               return 'mercadopago';
        if ( BarberPro_Database::get_setting('pay_pix_dynamic_ativo','0') === '1' )   return 'pix_dynamic';
        if ( BarberPro_Database::get_setting('pay_pix_static_ativo','0') === '1' )    return 'pix_static';
        return 'presencial';
    }

    public static function get_enabled_methods(): array {
        $m = [];
        if ( BarberPro_Database::get_setting('pay_presencial_ativo','1') === '1' ) $m['presencial']  = '🏦 Pagar no local';
        if ( BarberPro_Database::get_setting('pay_pix_static_ativo','0') === '1' ) $m['pix_static']  = '⚡ PIX (QR Code)';
        if ( BarberPro_Database::get_setting('pay_pix_dynamic_ativo','0') === '1') $m['pix_dynamic'] = '⚡ PIX (cobrança Efí)';
        $mp_ativo = BarberPro_Database::get_setting('payment_mp_ativo','0') === '1'
                 || BarberPro_Database::get_setting('pay_mp_ativo','0') === '1';
        if ( $mp_ativo ) $m['mercadopago'] = '💳 Mercado Pago';
        return $m ?: ['presencial' => '🏦 Pagar no local'];
    }

    public static function has_online_payment(): bool {
        return BarberPro_Database::get_setting('pay_pix_static_ativo','0') === '1'
            || BarberPro_Database::get_setting('pay_pix_dynamic_ativo','0') === '1'
            || BarberPro_Database::get_setting('pay_mp_ativo','0') === '1'
            || BarberPro_Database::get_setting('payment_pix_ativo','0') === '1'
            || BarberPro_Database::get_setting('payment_mp_ativo','0') === '1';
    }

    /**
     * Retorna gateways online ativos e configurados: ['key' => 'Label']
     * Usado na loja, no agendamento público e nas configurações.
     */
    public static function get_active_gateways(): array {
        $gateways = [];
        $pix_ativo = BarberPro_Database::get_setting('payment_pix_ativo','0') === '1'
                  || BarberPro_Database::get_setting('pay_pix_static_ativo','0') === '1';
        $pix_chave = BarberPro_Database::get_setting('pix_key','');
        if ( $pix_ativo && $pix_chave ) {
            $gateways['pix'] = '⚡ PIX';
        }
        $mp_ativo = BarberPro_Database::get_setting('payment_mp_ativo','0') === '1'
                 || BarberPro_Database::get_setting('pay_mp_ativo','0') === '1';
        $mp_token = BarberPro_Database::get_setting('mp_access_token','');
        if ( $mp_ativo && $mp_token ) {
            $gateways['mercadopago'] = '💳 Mercado Pago';
        }
        return $gateways;
    }

    // =========================================================
    // UTILITÁRIOS PRIVADOS
    // =========================================================

    public static function build_pix_payload( string $chave, string $nome, string $cidade, float $amount, string $txid = '***', string $desc = '' ): string {
        $nome   = mb_substr(self::ascii_normalize($nome),  0, 25);
        $cidade = mb_substr(self::ascii_normalize($cidade),0, 15);
        $txid   = mb_substr(preg_replace('/[^A-Za-z0-9]/','',$txid)?:'***', 0, 25);
        $valor  = number_format($amount, 2, '.', '');

        $gui  = self::tlv('00','BR.GOV.BCB.PIX').self::tlv('01',$chave);
        if ($desc) $gui .= self::tlv('02', mb_substr(self::ascii_normalize($desc),0,72));
        $mai  = self::tlv('26',$gui);

        $p    = self::tlv('00','01').self::tlv('01','12').$mai
               .self::tlv('52','0000').self::tlv('53','986')
               .self::tlv('54',$valor).self::tlv('58','BR')
               .self::tlv('59',$nome).self::tlv('60',$cidade)
               .self::tlv('62',self::tlv('05',$txid)).'6304';
        return $p . strtoupper(self::crc16($p));
    }

    private static function tlv( string $id, string $v ): string {
        return $id . str_pad(strlen($v),2,'0',STR_PAD_LEFT) . $v;
    }

    private static function crc16( string $s ): string {
        $crc = 0xFFFF;
        for($i=0;$i<strlen($s);$i++){
            $crc ^= ord($s[$i])<<8;
            for($j=0;$j<8;$j++) $crc = ($crc&0x8000) ? ($crc<<1)^0x1021 : $crc<<1;
            $crc &= 0xFFFF;
        }
        return str_pad(dechex($crc),4,'0',STR_PAD_LEFT);
    }

    private static function ascii_normalize( string $s ): string {
        $t = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','è'=>'e','ê'=>'e',
              'í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','û'=>'u',
              'ç'=>'c','ñ'=>'n','Á'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E',
              'Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C'];
        return preg_replace('/[^\x20-\x7E]/','',strtr($s,$t));
    }

    private static function fetch_image_base64( string $url ): string {
        $r = wp_remote_get($url, ['timeout'=>8]);
        if ( is_wp_error($r) ) return '';
        $ct = wp_remote_retrieve_header($r,'content-type') ?: 'image/png';
        return 'data:'.$ct.';base64,'.base64_encode(wp_remote_retrieve_body($r));
    }

}
