<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class PageCache {
    const CACHE_SUBDIR = 'cache/wcu-page-cache';

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 0 );

        // Smart invalidation instead of full flush on content changes.
        add_action( 'save_post', array( __CLASS__, 'invalidate_post' ), 20, 1 );
        add_action( 'comment_post', array( __CLASS__, 'invalidate_comment' ), 20, 1 );
        add_action( 'wp_trash_post', array( __CLASS__, 'invalidate_post' ), 20, 1 );
        add_action( 'switch_theme', array( __CLASS__, 'purge_all' ) );
        add_action( 'wp_update_nav_menu', array( __CLASS__, 'purge_all' ) );
    }

    public static function cache_root() {
        return WP_CONTENT_DIR . '/' . self::CACHE_SUBDIR;
    }

    public static function is_cacheable_request() {
        if ( is_admin() ) return false;
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) return false;
        if ( is_user_logged_in() ) return false;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( function_exists( 'is_feed' ) && is_feed() ) return false;
        if ( function_exists( 'is_search' ) && is_search() ) return false;
        if ( function_exists( 'is_404' ) && is_404() && empty( Settings::get()['cache_404'] ) ) return false;
        if ( ! empty( $_GET ) && empty( Settings::get()['cache_query_strings'] ) ) return false;
        if ( self::is_excluded_uri() ) return false;
        if ( self::has_excluded_cookie() ) return false;
        return (bool) apply_filters( 'wcu_is_cacheable_request', true );
    }

    public static function is_excluded_uri() {
        $settings = Settings::get();
        $patterns = array_filter( array_map( 'trim', explode( "\n", (string) ( $settings['exclude_uris'] ?? '' ) ) ) );
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ( $patterns as $p ) {
            if ( $p === '' ) continue;
            $regex = '#' . str_replace( '\*', '.*', preg_quote( $p, '#' ) ) . '#i';
            if ( @preg_match( $regex, $uri ) ) return true;
        }
        return false;
    }

    public static function has_excluded_cookie() {
        $settings = Settings::get();
        $names = array_filter( array_map( 'trim', explode( "\n", (string) ( $settings['exclude_cookies'] ?? '' ) ) ) );
        foreach ( $_COOKIE as $k => $v ) {
            if ( strpos( $k, 'wordpress_logged_in_' ) === 0 ) return true;
            if ( strpos( $k, 'comment_author_' ) === 0 ) return true;
            foreach ( $names as $n ) {
                if ( $n !== '' && stripos( $k, $n ) !== false ) return true;
            }
        }
        return false;
    }

    public static function is_mobile() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return (bool) preg_match( '/Mobile|Android|iP(hone|od|ad)/i', $ua );
    }

    public static function build_paths( $url = null ) {
        if ( $url ) {
            $parts = parse_url( $url );
            $host  = preg_replace( '/[^a-z0-9\.\-]/i', '_', $parts['host'] ?? ( $_SERVER['HTTP_HOST'] ?? 'default' ) );
            $uri   = rtrim( $parts['path'] ?? '/', '/' );
        } else {
            $host = preg_replace( '/[^a-z0-9\.\-]/i', '_', $_SERVER['HTTP_HOST'] ?? 'default' );
            $uri  = rtrim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
        }
        if ( $uri === '' ) $uri = '/index';
        $variant = self::is_mobile() ? 'mobile' : 'desktop';
        $dir  = self::cache_root() . '/' . $host . $uri;
        return array(
            'dir'  => $dir,
            'file' => $dir . '/' . $variant . '.html',
            'gz'   => $dir . '/' . $variant . '.html.gz',
            'meta' => $dir . '/' . $variant . '.meta.json',
        );
    }

    public static function maybe_buffer() {
        if ( ! self::is_cacheable_request() ) return;
        ob_start( array( __CLASS__, 'save_buffer' ) );
    }

    public static function save_buffer( $html ) {
        if ( strlen( $html ) < 255 ) return $html;
        if ( function_exists( 'http_response_code' ) && http_response_code() && http_response_code() !== 200 ) return $html;

        $settings = Settings::get();
        $paths    = self::build_paths();

        if ( ! is_dir( $paths['dir'] ) ) {
            wp_mkdir_p( $paths['dir'] );
        }

        $output = $html;
        if ( ! empty( $settings['minify_html'] ) ) {
            $output = self::minify_html( $output );
        }
        $final = $output . "\n<!-- WP Cache Ultimate: cached " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";

        @file_put_contents( $paths['file'], $final );
        if ( ! empty( $settings['gzip_static'] ) && function_exists( 'gzencode' ) ) {
            @file_put_contents( $paths['gz'], gzencode( $final, 6 ) );
        } elseif ( file_exists( $paths['gz'] ) ) {
            @unlink( $paths['gz'] );
        }
        @file_put_contents( $paths['meta'], wp_json_encode( array(
            'created' => time(),
            'ttl'     => (int) ( $settings['cache_ttl'] ?? 3600 ),
            'url'     => home_url( $_SERVER['REQUEST_URI'] ?? '/' ),
        ) ) );

        Logger::log( 'page_cache', 'stored', $_SERVER['REQUEST_URI'] ?? '/' );

        return $html;
    }

    public static function minify_html( $html ) {
        $placeholders = array();
        $html = preg_replace_callback( '#<(pre|textarea|script|style)\b.*?</\1>#is', function ( $m ) use ( &$placeholders ) {
            $key = '@@WCU_' . count( $placeholders ) . '@@';
            $placeholders[ $key ] = $m[0];
            return $key;
        }, $html );
        $html = preg_replace( '/<!--(?!\[if).*?-->/s', '', (string) $html );
        $html = preg_replace( '/\s{2,}/', ' ', $html );
        $html = preg_replace( '/>\s+</', '><', $html );
        foreach ( $placeholders as $key => $val ) {
            $html = str_replace( $key, $val, $html );
        }
        return trim( $html );
    }

    public static function invalidate_post( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        $url = get_permalink( $post_id );
        if ( $url ) self::purge_url( $url );
        self::purge_url( home_url( '/' ) );
    }

    public static function invalidate_comment( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( $comment ) self::invalidate_post( $comment->comment_post_ID );
    }

    public static function purge_all() {
        $root = self::cache_root();
        if ( is_dir( $root ) ) {
            Cleaner::rrmdir_contents( $root );
        }
        Logger::log( 'page_cache', 'purge_all', '-' );
        return true;
    }

    public static function purge_url( $url ) {
        $paths = self::build_paths( $url );
        if ( is_dir( $paths['dir'] ) ) {
            Cleaner::rrmdir_contents( $paths['dir'] );
            @rmdir( $paths['dir'] );
        }
        Logger::log( 'page_cache', 'purge_url', $url );
        return true;
    }

    /**
     * meta.json dosyalarını tarayarak önbelleğe alınmış sayfaların listesini döner.
     * Dashboard > Önbellek Listesi panelinde gösterilir.
     */
    public static function list_cached( $limit = 200 ) {
        $root = self::cache_root();
        $rows = array();
        if ( ! is_dir( $root ) ) return $rows;

        $iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iter as $f ) {
            if ( ! $f->isFile() || substr( $f->getFilename(), -10 ) !== '.meta.json' ) continue;

            $meta = json_decode( (string) @file_get_contents( $f->getPathname() ), true );
            if ( ! is_array( $meta ) ) continue;

            $html_path = substr( $f->getPathname(), 0, -10 ) . '.html';
            $size      = file_exists( $html_path ) ? filesize( $html_path ) : 0;
            $created   = (int) ( $meta['created'] ?? 0 );
            $ttl       = (int) ( $meta['ttl'] ?? 0 );

            $rows[] = array(
                'url'         => $meta['url'] ?? '',
                'size'        => $size,
                'size_human'  => function_exists( 'size_format' ) ? size_format( $size, 1 ) : round( $size / 1024, 1 ) . ' KB',
                'created'     => $created,
                'expires_at'  => $created && $ttl ? $created + $ttl : 0,
                'variant'     => strpos( $f->getFilename(), 'mobile' ) === 0 ? 'mobile' : 'desktop',
            );

            if ( count( $rows ) >= $limit ) break;
        }

        usort( $rows, function ( $a, $b ) { return $b['created'] - $a['created']; } );
        return $rows;
    }

    public static function stats() {
        $root  = self::cache_root();
        $count = 0;
        $size  = 0;
        if ( is_dir( $root ) ) {
            $iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $iter as $f ) {
                if ( $f->isFile() ) {
                    $size += $f->getSize();
                    if ( substr( $f->getFilename(), -5 ) === '.html' ) $count++;
                }
            }
        }
        return array(
            'count'      => $count,
            'size'       => $size,
            'size_human' => function_exists( 'size_format' ) ? size_format( $size, 2 ) : round( $size / 1024 / 1024, 2 ) . ' MB',
        );
    }
}
