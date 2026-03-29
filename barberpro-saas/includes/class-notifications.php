<?php
/**
 * BarberPro – Gerenciador de Notificações
 *
 * Centraliza todos os disparos de notificação do sistema.
 * Suporta 3 métodos configuráveis de forma independente:
 *
 *  Método 1 — E-mail
 *    Agendamento por site + confirmações/lembretes por e-mail
 *    Usa wp_mail() nativo do WordPress
 *
 *  Método 2 — WhatsApp Automático
 *    Confirmações e lembretes via WhatsApp (sem bot/IA)
 *    Usa o provider já configurado (Z-API, Cloud API, Twilio)
 *
 *  Método 3 — Bot IA com WhatsApp
 *    O cliente agenda conversando com o bot
 *    + recebe confirmações e lembretes via WhatsApp
 *
 * Os métodos podem ser ativados simultaneamente.
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Notifications {

    // =========================================================
    // PONTO CENTRAL — dispara notificação conforme métodos ativos
    // =========================================================

    /**
     * @param string $event  'confirmation' | 'reminder' | 'reminder2' | 'cancellation' | 'review'
     * @param object $booking
     */
    public static function dispatch( string $event, object $booking ): void {
        $email_ativo = self::metodo_ativo('email');
        $wa_ativo    = self::metodo_ativo('whatsapp');
        $bot_ativo   = self::metodo_ativo('bot');

        // E-mail (método 1 ou combinado)
        if ( $email_ativo ) {
            self::send_email( $event, $booking );
        }

        // WhatsApp (método 2 ou método 3)
        if ( $wa_ativo || $bot_ativo ) {
            self::send_whatsapp( $event, $booking );
        }
    }

    public static function metodo_ativo( string $metodo ): bool {
        return BarberPro_Database::get_setting( "notify_{$metodo}_ativo", '0' ) === '1';
    }

    // =========================================================
    // MÉTODO 1 — E-MAIL
    // =========================================================

    public static function send_email( string $event, object $booking ): void {
        $email = trim( $booking->client_email ?? '' );
        if ( empty($email) || ! is_email($email) ) return;

        $template_key = self::email_template_key( $event );
        if ( ! $template_key ) return;

        $assunto  = self::parse_template( BarberPro_Database::get_setting("{$template_key}_assunto", self::default_assunto($event)), $booking );
        $corpo    = self::parse_template( BarberPro_Database::get_setting("{$template_key}_corpo",   self::default_corpo($event)),   $booking );
        $nome_neg = BarberPro_Database::get_setting('email_nome_remetente', get_bloginfo('name'));
        $from     = BarberPro_Database::get_setting('email_remetente',      get_bloginfo('admin_email'));

        // HTML wrapper opcional
        $usa_html = BarberPro_Database::get_setting('email_html', '1') === '1';
        if ( $usa_html ) {
            $corpo = self::wrap_html( $corpo, $nome_neg, $booking );
        }

        $headers = [
            'Content-Type: ' . ( $usa_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
            "From: {$nome_neg} <{$from}>",
        ];

        // E-mail da empresa em cópia (BCC)
        $bcc = BarberPro_Database::get_setting('email_bcc', '');
        if ( $bcc ) $headers[] = "Bcc: {$bcc}";

        wp_mail( $email, $assunto, $corpo, $headers );
    }

    private static function email_template_key( string $event ): ?string {
        $map = [
            'confirmation' => 'email_confirmation',
            'reminder'     => 'email_reminder',
            'reminder2'    => 'email_reminder2',
            'cancellation' => 'email_cancellation',
            'review'       => 'email_review',
        ];
        return $map[$event] ?? null;
    }

    private static function default_assunto( string $event ): string {
        $nome = get_bloginfo('name');
        return match($event) {
            'confirmation' => "✅ Agendamento confirmado — {nome} | {$nome}",
            'reminder'     => "⏰ Lembrete do seu agendamento — {$nome}",
            'reminder2'    => "📅 Seu agendamento é amanhã — {$nome}",
            'cancellation' => "❌ Agendamento cancelado — {$nome}",
            'review'       => "⭐ Como foi seu atendimento? — {$nome}",
            default        => "Notificação de agendamento — {$nome}",
        };
    }

    private static function default_corpo( string $event ): string {
        return match($event) {
            'confirmation' => "Olá, {nome}!\n\nSeu agendamento foi confirmado com sucesso.\n\n📅 Data: {data}\n⏰ Hora: {hora}\n✂️ Serviço: {servico}\n👤 Profissional: {profissional}\n📋 Código: {codigo}\n\nTe esperamos!",
            'reminder'     => "Olá, {nome}!\n\nEste é um lembrete do seu agendamento.\n\n📅 Data: {data}\n⏰ Hora: {hora}\n✂️ Serviço: {servico}\n👤 Profissional: {profissional}\n\nAté logo!",
            'reminder2'    => "Olá, {nome}!\n\nSeu agendamento é amanhã!\n\n📅 {data} às {hora}\n✂️ {servico} com {profissional}\n\nTe esperamos!",
            'cancellation' => "Olá, {nome}!\n\nSeu agendamento do dia {data} às {hora} foi cancelado.\n\nSe quiser remarcar, acesse nosso site ou entre em contato.",
            'review'       => "Olá, {nome}!\n\nEsperamos que tenha gostado do seu atendimento!\n\nAvalie sua experiência: {link}\n\nObrigado pela preferência!",
            default        => "Olá, {nome}! Você tem uma notificação de agendamento.",
        };
    }

    private static function wrap_html( string $corpo, string $nome_neg, object $booking ): string {
        $cor      = BarberPro_Database::get_setting('email_cor_primaria', '#1a1a2e');
        $logo_url = BarberPro_Database::get_setting('email_logo_url', '');
        $corpo_html = nl2br( esc_html($corpo) );

        $logo_tag = $logo_url
            ? "<img src=\"{$logo_url}\" alt=\"{$nome_neg}\" style=\"max-height:60px;max-width:200px;margin-bottom:16px\">"
            : "<span style=\"font-size:1.4rem;font-weight:800;color:{$cor}\">{$nome_neg}</span>";

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 16px">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:100%">
      <!-- Header -->
      <tr><td style="background:{$cor};padding:28px 32px;text-align:center">
        {$logo_tag}
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:32px;font-size:15px;line-height:1.7;color:#333333">
        {$corpo_html}
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f8f8f8;padding:20px 32px;text-align:center;font-size:12px;color:#999999;border-top:1px solid #eeeeee">
        {$nome_neg} · <a href="mailto:{$booking->client_email}" style="color:#999999">Cancelar notificações</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }

    // =========================================================
    // MÉTODO 2 / 3 — WHATSAPP
    // =========================================================

    public static function send_whatsapp( string $event, object $booking ): void {
        $phone = trim( $booking->client_phone ?? '' );
        if ( empty($phone) ) return;

        $template_key = self::wa_template_key( $event );
        if ( ! $template_key ) return;

        $ativo = BarberPro_Database::get_setting("{$template_key}_active", '1');
        if ( $ativo !== '1' ) return;

        $template = BarberPro_Database::get_setting( $template_key, self::default_wa($event) );
        if ( empty($template) ) return;

        // Variáveis extras
        $extra = [];
        if ( $event === 'review' ) {
            $extra['{link}'] = home_url('?barberpro_review=' . $booking->booking_code);
        }

        $message = self::parse_template( $template, $booking, $extra );
        BarberPro_WhatsApp::send( $phone, $message );
    }

    private static function wa_template_key( string $event ): ?string {
        $map = [
            'confirmation' => 'msg_confirmation',
            'reminder'     => 'msg_reminder',
            'reminder2'    => 'msg_reminder2',
            'cancellation' => 'msg_cancellation',
            'review'       => 'msg_review',
        ];
        return $map[$event] ?? null;
    }

    private static function default_wa( string $event ): string {
        return match($event) {
            'confirmation' => "Olá {nome}! ✅ Seu agendamento foi confirmado.\n📅 {data} às {hora}\n✂️ {servico} com {profissional}\n📋 Código: {codigo}",
            'reminder'     => "Lembrete, {nome}! ⏰ Você tem um agendamento hoje às {hora}.\n✂️ {servico} com {profissional}",
            'reminder2'    => "Oi {nome}! 📅 Seu agendamento é amanhã às {hora}.\n✂️ {servico} com {profissional}. Te esperamos!",
            'cancellation' => "Olá {nome}! Seu agendamento do dia {data} às {hora} foi cancelado.",
            'review'       => "Olá {nome}! Como foi seu atendimento? Avalie aqui: {link} ⭐",
            default        => "",
        };
    }

    // =========================================================
    // PARSE DE TEMPLATE (compartilhado email + whatsapp)
    // =========================================================

    public static function parse_template( string $template, object $booking, array $extra = [] ): string {
        $professional = BarberPro_Database::get_professional( (int)($booking->professional_id ?? 0) );
        $service      = BarberPro_Database::get_service( (int)($booking->service_id ?? 0) );

        $vars = [
            '{nome}'          => $booking->client_name ?? '',
            '{data}'          => date_i18n('d/m/Y', strtotime($booking->booking_date ?? 'now')),
            '{hora}'          => substr($booking->booking_time ?? '', 0, 5),
            '{profissional}'  => $professional ? $professional->name : '',
            '{servico}'       => $service      ? $service->name      : '',
            '{codigo}'        => $booking->booking_code ?? '',
            '{link}'          => home_url('?barberpro_review=' . ($booking->booking_code ?? '')),
            '{email}'         => $booking->client_email ?? '',
            '{telefone}'      => $booking->client_phone ?? '',
        ];

        return str_replace(
            array_merge(array_keys($vars), array_keys($extra)),
            array_merge(array_values($vars), array_values($extra)),
            $template
        );
    }
}
