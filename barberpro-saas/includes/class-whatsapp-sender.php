<?php
/**
 * BarberPro – WhatsApp Sender
 *
 * Camada central de envio de mensagens WhatsApp.
 * Suporta texto, imagem, vídeo e documento.
 * Integra W-API, Z-API e Cloud API.
 * Multi-empresa: usa credenciais do company_id correto.
 *
 * @package BarberProSaaS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_WhatsApp_Sender {

    // =========================================================
    // MÉTODO PRINCIPAL
    // =========================================================

    /**
     * Envia mensagem via WhatsApp.
     *
     * @param int         $company_id  ID da empresa (nunca mistura dados)
     * @param string      $phone       Número destino (qualquer formato)
     * @param string      $message     Texto da mensagem
     * @param string|null $media_url   URL de mídia (imagem, vídeo, doc)
     * @param string      $type        'text'|'image'|'video'|'document'
     * @return bool
     */
    public static function send(
        int $company_id,
        string $phone,
        string $message,
        ?string $media_url = null,
        string $type = 'text'
    ): bool {
        if ( empty($phone) || empty($message) ) return false;

        $phone    = self::normalize($phone);
        $provider = self::get_provider($company_id);

        if ( ! $provider ) {
            error_log("[BarberPro] WhatsApp Sender: nenhum provider configurado para company_id={$company_id}");
            return false;
        }

        $result = match($provider) {
            'wapi'      => self::send_wapi($company_id, $phone, $message, $media_url, $type),
            'zapi'      => self::send_zapi($company_id, $phone, $message, $media_url, $type),
            'cloud_api' => self::send_cloud($company_id, $phone, $message, $media_url, $type),
            default     => apply_filters('barberpro_whatsapp_custom_send', false, $company_id, $phone, $message, $media_url, $type),
        };

        do_action('barberpro_whatsapp_sent', $company_id, $phone, $message, $result);
        return (bool) $result;
    }

    // =========================================================
    // W-API
    // =========================================================

    private static function send_wapi( int $cid, string $phone, string $msg, ?string $media, string $type ): bool {
        $instance = self::setting($cid, 'wapi_instance');
        $token    = self::setting($cid, 'wapi_token');
        if ( ! $instance || ! $token ) return false;

        $delay = (int) self::setting($cid, 'queue_delay_seconds', 3);
        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        if ( $media && in_array($type, ['image','video','document'], true) ) {
            $endpoints = [
                'image'    => 'send-image',
                'video'    => 'send-video',
                'document' => 'send-document',
            ];
            $endpoint = $endpoints[$type] ?? 'send-image';
            $url  = "https://api.w-api.app/v1/message/{$endpoint}?instanceId={$instance}";
            $body = wp_json_encode([
                'phone'        => $phone,
                'caption'      => $msg,
                'media'        => $media,
                'delayMessage' => $delay,
            ]);
        } else {
            $url  = "https://api.w-api.app/v1/message/send-text?instanceId={$instance}";
            $body = wp_json_encode([
                'phone'        => $phone,
                'message'      => $msg,
                'delayMessage' => $delay,
            ]);
        }

        $resp = wp_remote_post($url, [ 'headers'=>$headers, 'body'=>$body, 'timeout'=>15 ]);
        if ( is_wp_error($resp) ) {
            error_log('[BarberPro] W-API error: ' . $resp->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($resp);
        return in_array($code, [200, 201], true);
    }

    // =========================================================
    // Z-API
    // =========================================================

    private static function send_zapi( int $cid, string $phone, string $msg, ?string $media, string $type ): bool {
        $instance = self::setting($cid, 'zapi_instance');
        $token    = self::setting($cid, 'zapi_token');
        if ( ! $instance || ! $token ) return false;

        $base = "https://api.z-api.io/instances/{$instance}/token/{$token}";

        if ( $media && in_array($type, ['image','video','document'], true) ) {
            $endpoints = [ 'image'=>'send-image', 'video'=>'send-video', 'document'=>'send-document' ];
            $url  = $base . '/' . ($endpoints[$type] ?? 'send-image');
            $body = wp_json_encode([ 'phone'=>$phone, 'caption'=>$msg, 'image'=>$media ]);
        } else {
            $url  = $base . '/send-text';
            $body = wp_json_encode([ 'phone'=>$phone, 'message'=>$msg ]);
        }

        $resp = wp_remote_post($url, [
            'headers' => ['Content-Type'=>'application/json'],
            'body'    => $body,
            'timeout' => 15,
        ]);

        if ( is_wp_error($resp) ) {
            error_log('[BarberPro] Z-API error: ' . $resp->get_error_message());
            return false;
        }
        return wp_remote_retrieve_response_code($resp) === 200;
    }

    // =========================================================
    // CLOUD API (Meta)
    // =========================================================

    private static function send_cloud( int $cid, string $phone, string $msg, ?string $media, string $type ): bool {
        $token    = self::setting($cid, 'whatsapp_cloud_token');
        $phone_id = self::setting($cid, 'whatsapp_phone_id');
        if ( ! $token || ! $phone_id ) return false;

        $url = "https://graph.facebook.com/v19.0/{$phone_id}/messages";

        if ( $media && in_array($type, ['image','video','document'], true) ) {
            $body = wp_json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => $type,
                $type               => [ 'link' => $media, 'caption' => $msg ],
            ]);
        } else {
            $body = wp_json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => 'text',
                'text'              => ['body' => $msg],
            ]);
        }

        $resp = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 15,
        ]);

        if ( is_wp_error($resp) ) {
            error_log('[BarberPro] Cloud API error: ' . $resp->get_error_message());
            return false;
        }
        return wp_remote_retrieve_response_code($resp) === 200;
    }

    // =========================================================
    // HELPERS MULTI-EMPRESA
    // =========================================================

    /**
     * Detecta provider configurado para o company_id.
     * Cada empresa pode ter seu próprio provider no futuro.
     * Por ora usa setting global (company_id=1).
     */
    private static function get_provider( int $company_id ): string {
        // Prioridade: wapi > zapi > cloud_api
        if ( self::setting($company_id, 'wapi_instance') && self::setting($company_id, 'wapi_token') ) {
            return 'wapi';
        }
        if ( self::setting($company_id, 'zapi_instance') && self::setting($company_id, 'zapi_token') ) {
            return 'zapi';
        }
        if ( self::setting($company_id, 'whatsapp_cloud_token') ) {
            return 'cloud_api';
        }
        return '';
    }

    /**
     * Lê setting — por enquanto usa o global (company_id=1).
     * Preparado para multi-tenant futuramente.
     */
    private static function setting( int $company_id, string $key, string $default = '' ): string {
        // Futuramente: buscar por company_id específico
        // Por ora usa settings globais (compatível com sistema atual)
        return BarberPro_Database::get_setting($key, $default);
    }

    /**
     * Normaliza número: remove não-dígitos, garante DDI 55 se BR.
     */
    public static function normalize( string $phone ): string {
        $clean = preg_replace('/\D/', '', $phone);
        if ( strlen($clean) <= 11 ) {
            $clean = '55' . $clean;
        }
        return $clean;
    }
}
