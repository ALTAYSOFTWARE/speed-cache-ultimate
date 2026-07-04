<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class ServerConfig {

    /**
     * "Profesyonel Performans Denetimi" panelinde gösterilen sunucu sağlık
     * kontrolleri. Her biri label / ok (bool) / detail döner.
     */
    public static function health_checks() {
        $checks = array();

        $gzip_ok = extension_loaded( 'zlib' ) && function_exists( 'gzencode' );
        $checks['gzip'] = array(
            'label'  => 'GZIP Sıkıştırma',
            'ok'     => $gzip_ok,
            'detail' => $gzip_ok ? 'Sunucunuz Gzip destekliyor ve aktif.' : 'zlib eklentisi bulunamadı.',
        );

        $brotli_ok = function_exists( 'brotli_compress' ) || extension_loaded( 'brotli' );
        $checks['brotli'] = array(
            'label'  => 'Brotli',
            'ok'     => $brotli_ok,
            'detail' => $brotli_ok ? 'Brotli sıkıştırma kullanılabilir.' : 'Brotli PHP eklentisi kurulu değil (sunucu seviyesinde hâlâ aktif olabilir).',
        );

        $opcache_ok = function_exists( 'opcache_get_status' ) && @opcache_get_status() !== false;
        $checks['opcache'] = array(
            'label'  => 'PHP OPcache',
            'ok'     => $opcache_ok,
            'detail' => $opcache_ok ? 'OPcache aktif, PHP betikleri önbelleklenip derleniyor.' : 'OPcache aktif değil; php.ini üzerinden etkinleştirin.',
        );

        $cache_dir = PageCache::cache_root();
        $writable  = is_dir( $cache_dir ) ? is_writable( $cache_dir ) : is_writable( dirname( $cache_dir ) );
        $checks['cache_dir'] = array(
            'label'  => 'Önbellek Dizini Yazma İzni',
            'ok'     => (bool) $writable,
            'detail' => $writable ? 'Önbellek dizini yazılabilir.' : 'wp-content/cache dizinine yazma izni yok.',
        );

        $dropin_ok = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
        $checks['object_cache_dropin'] = array(
            'label'  => 'Nesne Önbellek Drop-in',
            'ok'     => $dropin_ok,
            'detail' => $dropin_ok ? 'object-cache.php kurulu (Redis/Memcached).' : 'Harici nesne önbellek drop-in bulunamadı.',
        );

        $adv_ok = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) && defined( 'WP_CACHE' ) && WP_CACHE;
        $checks['advanced_cache'] = array(
            'label'  => 'Erken Servis (advanced-cache.php)',
            'ok'     => $adv_ok,
            'detail' => $adv_ok ? 'PHP seviyesinde en erken önbellek servisi aktif.' : 'advanced-cache.php kurulu değil veya WP_CACHE tanımlı değil.',
        );

        return $checks;
    }

    public static function apache_snippet() {
        return <<<'HTACCESS'
# BEGIN WP Cache Ultimate
<IfModule mod_rewrite.c>
RewriteEngine On

# Serve pre-gzipped static cache directly (fastest path, bypasses PHP)
RewriteCond %{REQUEST_METHOD} GET
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|comment_author_) [NC]
RewriteCond %{HTTP:Accept-Encoding} gzip
RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/wcu-page-cache/%{HTTP_HOST}%{REQUEST_URI}/desktop.html.gz -f
RewriteRule ^(.*)$ /wp-content/cache/wcu-page-cache/%{HTTP_HOST}/$1/desktop.html.gz [L,T=text/html,E=no-gzip:1]

# Fallback to plain static cache
RewriteCond %{REQUEST_METHOD} GET
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|comment_author_) [NC]
RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/wcu-page-cache/%{HTTP_HOST}%{REQUEST_URI}/desktop.html -f
RewriteRule ^(.*)$ /wp-content/cache/wcu-page-cache/%{HTTP_HOST}/$1/desktop.html [L]
</IfModule>

<IfModule mod_headers.c>
  <FilesMatch "\.html\.gz$">
    Header set Content-Encoding gzip
    Header append Vary Accept-Encoding
  </FilesMatch>
</IfModule>
# END WP Cache Ultimate
HTACCESS;
    }

    public static function nginx_snippet() {
        return <<<'NGINX'
# WP Cache Ultimate - place inside your server { } block, above the main
# `location / { try_files ... }` block that routes to index.php
set $wcu_cache_file "";
if ($request_method = GET) {
    set $wcu_cache_file "/wp-content/cache/wcu-page-cache/$host$uri/desktop.html";
}
if ($http_cookie ~* "(wordpress_logged_in_|comment_author_)") {
    set $wcu_cache_file "";
}
if ($args != "") {
    set $wcu_cache_file "";
}

location / {
    gzip_static on;
    try_files $wcu_cache_file $uri $uri/ /index.php?$args;
}
NGINX;
    }
}
