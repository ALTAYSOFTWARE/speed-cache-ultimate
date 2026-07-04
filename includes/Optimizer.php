<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Front-end "hızlı kazanım" optimizasyonları.
 *
 * Not: JS defer / CSS async için ham HTML çıkışı üzerinde regex çalıştırmak
 * yerine WordPress'in kendi `script_loader_tag` / `style_loader_tag`
 * filtrelerini kullanıyoruz — bu, sadece wp_enqueue ile kayıtlı asset'leri
 * etkiler, temanın elle yazdığı satır içi scriptlere dokunmaz ve çok daha
 * güvenlidir. Lazy load için de `the_content` gibi standart WP kancalarını
 * kullanıp data-src + IntersectionObserver tekniğiyle (Tulpar Cache'teki
 * kanıtlanmış yaklaşımla aynı mantık) tam tarayıcı desteği sağlıyoruz.
 */
class Optimizer {

    const PLACEHOLDER = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxIiBoZWlnaHQ9IjEiPjwvc3ZnPg==';

    public static function init() {
        $s = Settings::get();

        if ( ! empty( $s['remove_emojis'] ) ) {
            add_action( 'init', array( __CLASS__, 'disable_emojis' ) );
        }

        if ( ! empty( $s['remove_query_strings'] ) && ! is_admin() ) {
            add_filter( 'script_loader_src', array( __CLASS__, 'remove_query_strings' ), 999 );
            add_filter( 'style_loader_src', array( __CLASS__, 'remove_query_strings' ), 999 );
        }

        if ( ! empty( $s['heartbeat_control'] ) && 'default' !== $s['heartbeat_control'] ) {
            add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_settings' ) );
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_deregister_heartbeat' ), 100 );
            if ( 'disabled' === $s['heartbeat_control'] ) {
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_deregister_heartbeat' ), 100 );
            }
        }

        if ( is_admin() ) return; // Aşağıdakiler yalnızca ön yüz çıkışını ilgilendirir.

        if ( ! empty( $s['defer_js'] ) ) {
            add_filter( 'script_loader_tag', array( __CLASS__, 'defer_js' ), 10, 3 );
        }

        if ( ! empty( $s['async_css'] ) ) {
            add_filter( 'style_loader_tag', array( __CLASS__, 'async_css' ), 10, 4 );
        }

        if ( ! empty( $s['media_webp'] ) || ! empty( $s['media_missing_dims'] ) || ! empty( $s['media_responsive_placeholder'] ) ) {
            add_filter( 'the_content', array( __CLASS__, 'image_enhancements' ), 900 );
            add_filter( 'post_thumbnail_html', array( __CLASS__, 'image_enhancements' ), 900 );
        }

        if ( ! empty( $s['lazy_load_images'] ) ) {
            add_filter( 'wp_lazy_loading_enabled', '__return_false' ); // kendi lazy load'umuzla çift işlem olmasın
            add_filter( 'the_content', array( __CLASS__, 'lazy_load_images' ), 999 );
            add_filter( 'post_thumbnail_html', array( __CLASS__, 'lazy_load_images' ), 999 );
            add_filter( 'widget_text', array( __CLASS__, 'lazy_load_images' ), 999 );
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_lazyload' ) );
            add_action( 'wp_head', array( __CLASS__, 'print_helper_css' ), 1 );
        }

        if ( ! empty( $s['lazy_load_iframes'] ) ) {
            add_filter( 'the_content', array( __CLASS__, 'lazy_load_iframes' ), 999 );
            add_filter( 'widget_text', array( __CLASS__, 'lazy_load_iframes' ), 999 );
        }

        if ( ! empty( $s['instant_click'] ) ) {
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_instant_click' ) );
        }
    }

    /* ---------------------------------------------------------------- *
     * Emoji script/style kaldırma
     * ---------------------------------------------------------------- */

    public static function disable_emojis() {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_action( 'embed_head', 'print_emoji_detection_script' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

        add_filter( 'tiny_mce_plugins', array( __CLASS__, 'disable_emojis_tinymce' ) );
        add_filter( 'wp_resource_hints', array( __CLASS__, 'disable_emojis_dns_prefetch' ), 10, 2 );
    }

    public static function disable_emojis_tinymce( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
    }

    public static function disable_emojis_dns_prefetch( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type ) {
            $urls = array_filter( $urls, function ( $url ) {
                return false === strpos( $url, 'https://s.w.org/images/core/emoji/' );
            } );
        }
        return $urls;
    }

    /* ---------------------------------------------------------------- *
     * Statik dosya sürüm (?ver=) sorgu dizesini kaldırma
     * ---------------------------------------------------------------- */

    public static function remove_query_strings( $src ) {
        if ( $src && false !== strpos( $src, 'ver=' ) ) {
            $src = remove_query_arg( 'ver', $src );
        }
        return $src;
    }

    /* ---------------------------------------------------------------- *
     * Heartbeat API kontrolü
     * ---------------------------------------------------------------- */

    public static function heartbeat_settings( $settings ) {
        $s = Settings::get();
        $settings['interval'] = max( 15, (int) ( $s['heartbeat_interval'] ?? 60 ) );
        return $settings;
    }

    public static function maybe_deregister_heartbeat() {
        $s = Settings::get();
        if ( 'disabled' === $s['heartbeat_control'] ) {
            wp_deregister_script( 'heartbeat' );
            return;
        }
        if ( 'admin_only' === $s['heartbeat_control'] && ! is_admin() ) {
            wp_deregister_script( 'heartbeat' );
        }
    }

    /* ---------------------------------------------------------------- *
     * JS Erteleme (defer) — script_loader_tag üzerinden, güvenli yol
     * ---------------------------------------------------------------- */

    public static function defer_js( $tag, $handle, $src ) {
        if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) return $tag;

        // Kritik bağımlılıkları asla erteleme (jQuery, çekirdek WP scriptleri, kendi scriptlerimiz).
        if ( in_array( $handle, array( 'jquery-core', 'jquery', 'wcu-instant-click', 'wcu-lazyload' ), true ) ) return $tag;
        if ( 0 === strpos( $handle, 'wp-' ) ) return $tag;

        $s       = Settings::get();
        $exclude = array_filter( array_map( 'trim', explode( "\n", (string) ( $s['exclude_js'] ?? '' ) ) ) );
        $exclude = array_merge( $exclude, array( 'wc-cart', 'woocommerce', 'cart', 'checkout' ) );
        foreach ( $exclude as $ex ) {
            if ( '' !== $ex && false !== stripos( $src, $ex ) ) return $tag;
        }

        return str_replace( ' src=', ' defer src=', $tag );
    }

    /* ---------------------------------------------------------------- *
     * CSS Async — style_loader_tag üzerinden preload+onload tekniği
     * ---------------------------------------------------------------- */

    public static function async_css( $tag, $handle, $href, $media ) {
        if ( false !== stripos( $handle, 'admin-bar' ) ) return $tag;

        $s       = Settings::get();
        $exclude = array_filter( array_map( 'trim', explode( "\n", (string) ( $s['exclude_css'] ?? '' ) ) ) );
        foreach ( $exclude as $ex ) {
            if ( '' !== $ex && false !== stripos( $href, $ex ) ) return $tag;
        }

        $effective_media = $media ?: 'all';
        $noscript = '<noscript>' . $tag . '</noscript>';
        $async = str_replace( "media='{$effective_media}'", "media='print' onload=\"this.media='{$effective_media}'\"", $tag );
        $async = str_replace( "media=\"{$effective_media}\"", "media=\"print\" onload=\"this.media='{$effective_media}'\"", $async );

        if ( $async === $tag ) {
            $async = str_replace( '<link ', '<link media="print" onload="this.media=\'' . esc_attr( $effective_media ) . '\'" ', $tag );
        }
        if ( $async === $tag ) return $tag; // dönüşüm başarısız oldu, çifte yüklemeyi engellemek için orijinali döndür

        return $async . $noscript;
    }

    /* ---------------------------------------------------------------- *
     * Görsel Tembel Yükleme — data-src + IntersectionObserver
     * ---------------------------------------------------------------- */

    public static function lazy_load_images( $content ) {
        if ( empty( $content ) || false === strpos( $content, '<img' ) ) return $content;
        if ( is_feed() ) return $content;

        $s          = Settings::get();
        $skip_first = max( 0, (int) ( $s['lazy_load_skip_first'] ?? 2 ) );

        preg_match_all( '#<img[^>]+>#i', $content, $matches );
        if ( empty( $matches[0] ) ) return $content;

        $i = 0;
        foreach ( $matches[0] as $img_tag ) {
            $i++;

            if ( stripos( $img_tag, 'data-no-lazy' ) !== false || stripos( $img_tag, 'wcu-lazy' ) !== false ) continue;

            // İlk N görsel (above-the-fold / LCP): tembel yüklenmez, hemen ve öncelikli yüklenir.
            if ( $i <= $skip_first ) {
                $new_tag = $img_tag;
                if ( 1 === $i && false === stripos( $new_tag, 'fetchpriority' ) ) {
                    $new_tag = str_replace( '<img', '<img fetchpriority="high"', $new_tag );
                }
                if ( false === stripos( $new_tag, 'loading=' ) )  $new_tag = str_replace( '<img', '<img loading="eager"', $new_tag );
                if ( false === stripos( $new_tag, 'decoding=' ) ) $new_tag = str_replace( '<img', '<img decoding="async"', $new_tag );
                if ( $new_tag !== $img_tag ) $content = str_replace( $img_tag, $new_tag, $content );
                continue;
            }

            if ( stripos( $img_tag, 'data-src' ) !== false ) continue;
            if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) continue;

            $src     = $src_match[1];
            $new_tag = str_replace( 'src="' . $src . '"', 'src="' . self::PLACEHOLDER . '" data-src="' . esc_attr( $src ) . '"', $img_tag );
            $new_tag = str_replace( "src='" . $src . "'", "src='" . self::PLACEHOLDER . "' data-src='" . esc_attr( $src ) . "'", $new_tag );

            if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $new_tag, $srcset_match ) ) {
                $new_tag = str_replace( 'srcset="' . $srcset_match[1] . '"', 'data-srcset="' . $srcset_match[1] . '"', $new_tag );
                $new_tag = str_replace( "srcset='" . $srcset_match[1] . "'", "data-srcset='" . $srcset_match[1] . "'", $new_tag );
            }

            if ( preg_match( '/class=["\']([^"\']+)["\']/i', $new_tag, $class_match ) ) {
                $new_tag = str_replace( $class_match[1], $class_match[1] . ' wcu-lazy', $new_tag );
            } else {
                $new_tag = str_replace( '<img', '<img class="wcu-lazy"', $new_tag );
            }

            $new_tag = str_replace( '<img', '<img data-no-lazy="1"', $new_tag );
            if ( false === stripos( $new_tag, 'loading=' ) )  $new_tag = str_replace( '<img', '<img loading="lazy"', $new_tag );
            if ( false === stripos( $new_tag, 'decoding=' ) ) $new_tag = str_replace( '<img', '<img decoding="async"', $new_tag );

            $content = str_replace( $img_tag, $new_tag, $content );
        }

        return $content;
    }

    /* ---------------------------------------------------------------- *
     * Medya Optimizasyonu — WebP değişimi, eksik boyutları tamamlama,
     * responsive yer tutucu (Tulpar Cache'teki Media modülünden esinlenildi)
     * ---------------------------------------------------------------- */

    public static function image_enhancements( $content ) {
        if ( empty( $content ) || false === strpos( $content, '<img' ) ) return $content;
        if ( is_feed() ) return $content;

        $s              = Settings::get();
        $do_webp        = ! empty( $s['media_webp'] );
        $do_dims        = ! empty( $s['media_missing_dims'] );
        $do_placeholder = ! empty( $s['media_responsive_placeholder'] );
        if ( ! $do_webp && ! $do_dims && ! $do_placeholder ) return $content;

        $accepts_webp = isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' );

        preg_match_all( '#<img[^>]+>#i', $content, $matches );
        if ( empty( $matches[0] ) ) return $content;

        foreach ( $matches[0] as $img_tag ) {
            $new_tag = $img_tag;

            if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $new_tag, $m ) ) continue;

            $src  = $m[1];
            $file = self::url_to_path( $src );

            // WebP değişimi: diskte aynı dosyanın .webp versiyonu varsa ve tarayıcı destekliyorsa kullan.
            if ( $do_webp && $accepts_webp && $file ) {
                $webp_file = $file . '.webp';
                if ( file_exists( $webp_file ) ) {
                    $webp_url = $src . '.webp';
                    $new_tag  = str_replace( $src, $webp_url, $new_tag );
                    $file     = $webp_file;
                }
            }

            // Eksik width/height ve responsive placeholder için gerçek boyutları oku.
            if ( ( $do_dims || $do_placeholder ) && false === stripos( $new_tag, 'width=' ) && $file && file_exists( $file ) ) {
                $size = @getimagesize( $file );
                if ( $size && $size[0] > 0 && $size[1] > 0 ) {
                    list( $w, $h ) = $size;

                    if ( $do_dims ) {
                        $new_tag = str_replace( '<img', '<img width="' . (int) $w . '" height="' . (int) $h . '"', $new_tag );
                    }

                    if ( $do_placeholder ) {
                        $ratio     = round( $w / $h, 4 );
                        $style_add = 'aspect-ratio:' . $ratio . ';background:#eee;';
                        if ( preg_match( '/style=["\']([^"\']*)["\']/i', $new_tag, $sm ) ) {
                            $new_tag = str_replace( $sm[0], 'style="' . esc_attr( rtrim( $sm[1], ';' ) . ';' . $style_add ) . '"', $new_tag );
                        } else {
                            $new_tag = str_replace( '<img', '<img style="' . esc_attr( $style_add ) . '"', $new_tag );
                        }
                    }
                }
            }

            if ( $new_tag !== $img_tag ) {
                $content = str_replace( $img_tag, $new_tag, $content );
            }
        }

        return $content;
    }

    /**
     * Bir medya/asset URL'sini diskteki dosya yoluna çevirir. Yalnızca
     * uploads ve genel wp-content dizinleri desteklenir; eşleşme yoksa
     * false döner (harici/CDN URL'leri için güvenli varsayılan).
     */
    protected static function url_to_path( $url ) {
        $upload_dir = wp_get_upload_dir();
        if ( ! empty( $upload_dir['baseurl'] ) && 0 === strpos( $url, $upload_dir['baseurl'] ) ) {
            return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
        }
        if ( 0 === strpos( $url, content_url() ) ) {
            return str_replace( content_url(), WP_CONTENT_DIR, $url );
        }
        return false;
    }

    public static function lazy_load_iframes( $content ) {
        if ( empty( $content ) ) return $content;

        preg_match_all( '#<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>#i', $content, $matches );
        if ( empty( $matches[0] ) ) return $content;

        foreach ( $matches[0] as $k => $iframe ) {
            if ( stripos( $iframe, 'data-src' ) !== false ) continue;

            $src = $matches[1][ $k ];
            $new_iframe = str_replace( 'src="' . $src . '"', 'src="about:blank" data-src="' . esc_attr( $src ) . '" loading="lazy"', $iframe );
            $new_iframe = str_replace( "src='" . $src . "'", "src='about:blank' data-src='" . esc_attr( $src ) . "' loading='lazy'", $new_iframe );

            if ( preg_match( '/class=["\']([^"\']+)["\']/i', $new_iframe, $class_match ) ) {
                $new_iframe = str_replace( $class_match[1], $class_match[1] . ' wcu-lazy', $new_iframe );
            } else {
                $new_iframe = str_replace( '<iframe', '<iframe class="wcu-lazy"', $new_iframe );
            }

            $content = str_replace( $iframe, $new_iframe, $content );
        }

        return $content;
    }

    public static function enqueue_lazyload() {
        wp_enqueue_script( 'wcu-lazyload', WCU_URL . 'assets/js/wcu-lazyload.js', array(), WCU_VERSION, true );
    }

    public static function print_helper_css() {
        echo '<style id="wcu-lazy-helper-css">.wcu-lazy{display:inline-block;max-width:100%;height:auto}</style>' . "\n";
    }

    /* ---------------------------------------------------------------- *
     * Instant Click — bağlantı üzerine gelince arka planda prefetch
     * ---------------------------------------------------------------- */

    public static function enqueue_instant_click() {
        wp_register_script( 'wcu-instant-click', '', array(), WCU_VERSION, true );
        wp_enqueue_script( 'wcu-instant-click' );
        wp_add_inline_script( 'wcu-instant-click', self::instant_click_js() );
    }

    protected static function instant_click_js() {
        // WooCommerce sepet/ödeme/çıkış gibi eylem tetikleyen URL'leri prefetch etmez.
        return <<<'JS'
(function(){
    'use strict';
    var prefetched = {};
    var excludePatterns = ['add-to-cart','add_to_cart','remove_item','removed_item','empty-cart','empty_cart','wc-ajax','wp-login','wp-admin','logout','loggedout','add-payment-method','order-pay','wp-cron','ajax','cart','checkout','sepet','odeme'];

    function shouldExclude(url) {
        for (var i = 0; i < excludePatterns.length; i++) {
            if (url.indexOf(excludePatterns[i]) !== -1) return true;
        }
        return false;
    }

    function prefetch(url) {
        if (prefetched[url]) return;
        prefetched[url] = true;
        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        document.head.appendChild(link);
    }

    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest && e.target.closest('a');
        if (!a || !a.href) return;
        var url = a.href;
        if (url.indexOf(location.origin) !== 0) return;
        if (url.indexOf('#') !== -1 && url.split('#')[0] === location.href.split('#')[0]) return;
        if (a.hasAttribute('download') || a.target === '_blank') return;
        if (shouldExclude(url)) return;
        if (a.classList && (a.classList.contains('add_to_cart_button') || a.classList.contains('ajax_add_to_cart') || a.classList.contains('remove'))) return;
        prefetch(url);
    }, { passive: true });
})();
JS;
    }
}
