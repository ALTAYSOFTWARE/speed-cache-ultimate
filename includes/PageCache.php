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
        
        // ✅ FIX: Kontrol et - cache genel olarak etkin mi?
        $settings = Settings::get();
        if ( empty( $settings['page_cache_enabled'] ) ) return false;
        
        // ✅ FIX: Properly sanitize and unslash $_SERVER['REQUEST_METHOD']
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
        if ( $request_method !== 'GET' ) return false;
        
        // ✅ FIX: Ön yükleme istekleri de cache edilmeli (X-WCU-Preload header'ı)
        // Eğer bu header varsa, user logged in değil ve normal cache kurallarına uyuyorsa cache'le
        
        if ( is_user_logged_in() ) return false;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( function_exists( 'is_feed' ) && is_feed() ) return false;
        if ( function_exists( 'is_search' ) && is_search() ) return false;
        if ( function_exists( 'is_404' ) && is_404() && empty( $settings['cache_404'] ) ) return false;
        
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $has_query = strpos( $request_uri, '?' ) !== false;
        if ( $has_query && empty( $settings['cache_query_strings'] ) ) return false;
        
        if ( self::is_excluded_uri() ) return false;
        if ( self::has_excluded_cookie() ) return false;
        return (bool) apply_filters( 'wcu_is_cacheable_request', true );
    }

    public static function is_excluded_uri() {
        $settings = Settings::get();
        $patterns = array_filter( array_map( 'trim', explode( "\n", (string) ( $settings['exclude_uris'] ?? '' ) ) ) );
        
        // ✅ FIX: Properly sanitize and unslash $_SERVER['REQUEST_URI']
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        
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
        
        $cookies = isset( $_COOKIE ) ? wp_unslash( $_COOKIE ) : array();
        foreach ( $cookies as $k => $v ) {
            $cookie_name = sanitize_key( $k );
            if ( strpos( $cookie_name, 'wordpress_logged_in_' ) === 0 ) return true;
            if ( strpos( $cookie_name, 'comment_author_' ) === 0 ) return true;
            foreach ( $names as $n ) {
                if ( $n !== '' && stripos( $cookie_name, $n ) !== false ) return true;
            }
        }
        return false;
    }

    public static function is_mobile() {
        // ✅ FIX: Properly sanitize and unslash $_SERVER['HTTP_USER_AGENT']
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        return (bool) preg_match( '/Mobile|Android|iP(hone|od|ad)/i', $ua );
    }

    public static function build_paths( $url = null ) {
        if ( $url ) {
            // ✅ FIX: Use wp_parse_url() instead of parse_url()
            $parts = wp_parse_url( $url );
            $host  = preg_replace( '/[^a-z0-9\.\-]/i', '_', $parts['host'] ?? ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'default' ) );
            $uri   = rtrim( $parts['path'] ?? '/', '/' );
        } else {
            $http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'default';
            $host = preg_replace( '/[^a-z0-9\.\-]/i', '_', $http_host );
            
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
            // ✅ FIX: Use wp_parse_url() instead of parse_url()
            $uri  = rtrim( wp_parse_url( $request_uri, PHP_URL_PATH ) ?? '/', '/' );
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

        // ✅ FIX: Kontrol et, eğer dizin oluşturulamazsa log yap ve döndür
        if ( ! is_dir( $paths['dir'] ) ) {
            if ( ! wp_mkdir_p( $paths['dir'] ) ) {
                Logger::log( 'page_cache', 'error', 'Failed to create directory: ' . $paths['dir'] );
                return $html;
            }
            // Yeni oluşturulan dizine yazma izni ver
            @chmod( $paths['dir'], 0755 );
        }

        $output = $html;
        if ( ! empty( $settings['minify_html'] ) ) {
            $output = self::minify_html( $output );
        }
        $final = $output . "\n<!-- WP Cache Ultimate: cached " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";

        // BUG FIX: $wp_filesystem was used here without being declared global or
        // initialized via WP_Filesystem(). Since this callback runs on the front-end
        // (template_redirect output buffer) for anonymous visitors, $wp_filesystem
        // was always undefined/null, so every put_contents() call fatally errored
        // ("Call to a member function put_contents() on null") and NOTHING was ever
        // written to disk. This is why preload always reported "done" (it only counts
        // dispatched requests) but the dashboard cache-size/cache-count cards never
        // updated: PageCache::stats() scans the cache directory, which stayed empty.
        //
        // Also, calling WP_Filesystem() here would be unsafe/slow on a hot front-end
        // path (it can require FTP/SSH credentials on some hosts), so we use native
        // filesystem functions instead, which is the correct approach for a page
        // cache writing plain files it fully owns.
        $write_ok = self::write_file( $paths['file'], $final );
        if ( ! $write_ok ) {
            Logger::log( 'page_cache', 'error', 'Failed to write cache file: ' . $paths['file'] );
            return $html;
        }
        
        if ( ! empty( $settings['gzip_static'] ) && function_exists( 'gzencode' ) ) {
            self::write_file( $paths['gz'], gzencode( $final, 6 ) );
        } elseif ( file_exists( $paths['gz'] ) ) {
            // ✅ FIX: Use wp_delete_file() instead of unlink()
            wp_delete_file( $paths['gz'] );
        }
        self::write_file( $paths['meta'], wp_json_encode( array(
            'created' => time(),
            'ttl'     => (int) ( $settings['cache_ttl'] ?? 3600 ),
            'url'     => home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ),
        ) ) );

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        Logger::log( 'page_cache', 'stored', $request_uri );

        return $html;
    }

    /**
     * Yazma yardımcı fonksiyonu. Front-end'de her sayfa yüklemesinde çalıştığı için
     * WP_Filesystem() kullanılmaz (FTP/SSH kimlik bilgisi isteyebilir, yavaştır);
     * bunun yerine bu dizinlerin sahibi olan doğrudan dosya fonksiyonları kullanılır.
     * 
     * ✅ FIX: Daha robust yazma işlemi - @ operator'ü kaldır, hata kontrol ekle
     */
    private static function write_file( $path, $contents ) {
        // Dizin kontrolü ve oluşturma
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            @chmod( $dir, 0755 );
        }

        // Dosyaya yazma izni kontrolü
        if ( is_dir( $dir ) && ! is_writable( $dir ) ) {
            // Yazma iznini ayarlamaya çalış
            @chmod( $dir, 0755 );
        }

        // Dosyayı aç - hataları yakala
        $fp = fopen( $path, 'wb' );
        if ( ! $fp ) {
            error_log( 'WCU: fopen başarısız: ' . $path );
            return false;
        }

        // Dosyayı kilitle ve yaz
        if ( ! flock( $fp, LOCK_EX ) ) {
            error_log( 'WCU: flock başarısız: ' . $path );
            fclose( $fp );
            return false;
        }

        $bytes = fwrite( $fp, $contents );
        flock( $fp, LOCK_UN );
        fclose( $fp );

        // Yazma başarıyla kontrol et
        if ( $bytes === false || $bytes === 0 ) {
            error_log( 'WCU: fwrite başarısız: ' . $path . ' (bytes: ' . var_export( $bytes, true ) . ')' );
            return false;
        }

        // Dosya iznini ayarla (yazılamadığı durumları engelle)
        @chmod( $path, 0644 );

        return true;
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
            
            // ✅ FIX: Use WP_Filesystem to remove directory
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                WP_Filesystem();
            }
            if ( ! empty( $wp_filesystem ) ) {
                $wp_filesystem->delete( $paths['dir'], true );
            }
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