<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Toolbox → Güvenlik Başlıkları.
 *
 * Sunucu yapılandırmasına (.htaccess/nginx) dokunmadan, doğrudan PHP
 * seviyesinde temel HTTP güvenlik başlıkları ekler. Yalnızca ön yüzde
 * (frontend) çalışır; yönetici panelini veya REST/AJAX isteklerini
 * etkilemez.
 */
class SecurityHeaders {

    public static function init() {
        if ( is_admin() ) return;

        $s = Settings::get();
        if ( empty( $s['security_headers_enabled'] ) ) return;

        add_action( 'send_headers', array( __CLASS__, 'send' ) );
    }

    public static function send() {
        if ( headers_sent() ) return;

        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
    }
}
