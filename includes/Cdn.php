<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CDN URL Yeniden Yazma.
 *
 * Statik dosyaları (görsel/CSS/JS) ayarlanan bir CDN adresi üzerinden
 * sunmak için WordPress'in standart asset/medya filtrelerini kullanır.
 * Sadece kendi sitemizin URL'lerini değiştirir; harici kaynaklara (Google
 * Fonts, üçüncü parti scriptler vb.) dokunmaz. Yalnızca ön yüzde (frontend)
 * çalışır — yönetici panelinde asla devreye girmez.
 *
 * Tulpar Cache'teki CDN modülünden esinlenilmiştir.
 */
class Cdn {

    public static function init() {
        if ( is_admin() ) return;

        $s = Settings::get();
        if ( empty( $s['cdn_enabled'] ) || empty( $s['cdn_url'] ) ) return;

        if ( ! empty( $s['cdn_include_css'] ) ) {
            add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_url' ), 20 );
        }
        if ( ! empty( $s['cdn_include_js'] ) ) {
            add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_url' ), 20 );
        }
        if ( ! empty( $s['cdn_include_images'] ) ) {
            add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 20 );
            add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'rewrite_srcset' ), 20 );
            add_filter( 'the_content', array( __CLASS__, 'rewrite_content_images' ), 30 );
        }
    }

    protected static function site_host() {
        return wp_parse_url( home_url(), PHP_URL_HOST );
    }

    /**
     * Kendi sitemize ait mutlak URL'lerin başındaki protokol+host kısmını
     * CDN adresiyle değiştirir. Farklı bir host'a ait URL'ler dokunulmadan
     * döner (harici scriptler, Google Fonts vb. asla etkilenmez).
     */
    public static function rewrite_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) return $url;

        $s   = Settings::get();
        $cdn = rtrim( (string) $s['cdn_url'], '/' );
        if ( '' === $cdn ) return $url;

        $host = self::site_host();
        if ( ! $host ) return $url;

        return preg_replace( '#^https?://' . preg_quote( $host, '#' ) . '#i', $cdn, $url );
    }

    public static function rewrite_srcset( $sources ) {
        if ( ! is_array( $sources ) ) return $sources;
        foreach ( $sources as $width => $data ) {
            if ( isset( $data['url'] ) ) {
                $sources[ $width ]['url'] = self::rewrite_url( $data['url'] );
            }
        }
        return $sources;
    }

    /**
     * İçerik (post_content) HTML'i içinde elle yazılmış <img> etiketlerindeki
     * wp-content/uploads yollarını CDN'e taşır. Tema/eklenti dosyalarına
     * (wp-content/themes, wp-content/plugins) dokunmaz — sadece medya.
     */
    public static function rewrite_content_images( $content ) {
        if ( empty( $content ) ) return $content;

        $s   = Settings::get();
        $cdn = rtrim( (string) $s['cdn_url'], '/' );
        if ( '' === $cdn ) return $content;

        $host = self::site_host();
        if ( ! $host ) return $content;

        $pattern = '#https?://' . preg_quote( $host, '#' ) . '(/[^"\'\s]*wp-content/uploads[^"\'\s]*)#i';
        return preg_replace( $pattern, $cdn . '$1', $content );
    }
}
