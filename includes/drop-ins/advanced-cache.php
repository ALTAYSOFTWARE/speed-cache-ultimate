<?php
/**
 * WP Cache Ultimate - advanced-cache.php drop-in
 * Auto-installed on plugin activation. Runs before WordPress core loads,
 * so it must be fully standalone (no namespaces, no plugin includes).
 * Regenerate via Dashboard > Sunucu Yapılandırması if you edit it by hand.
 */
if ( php_sapi_name() === 'cli' ) return;
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) return;
if ( ! empty( $_GET ) ) return;
if ( ! empty( $_COOKIE ) ) {
    foreach ( $_COOKIE as $wcu_ck => $wcu_cv ) {
        if ( strpos( $wcu_ck, 'wordpress_logged_in_' ) === 0 || strpos( $wcu_ck, 'comment_author_' ) === 0 ) {
            return;
        }
    }
}

$wcu_host = preg_replace( '/[^a-z0-9\.\-]/i', '_', $_SERVER['HTTP_HOST'] ?? 'default' );
$wcu_uri  = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$wcu_uri  = rtrim( (string) $wcu_uri, '/' );
if ( $wcu_uri === '' ) $wcu_uri = '/index';
$wcu_ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$wcu_variant = preg_match( '/Mobile|Android|iP(hone|od|ad)/i', $wcu_ua ) ? 'mobile' : 'desktop';

$wcu_base = WP_CONTENT_DIR . '/cache/wcu-page-cache/' . $wcu_host . $wcu_uri . '/' . $wcu_variant;
$wcu_meta = $wcu_base . '.meta.json';
$wcu_html = $wcu_base . '.html';

if ( is_readable( $wcu_meta ) && is_readable( $wcu_html ) ) {
    $wcu_meta_data = json_decode( (string) file_get_contents( $wcu_meta ), true );
    $wcu_ttl       = (int) ( $wcu_meta_data['ttl'] ?? 3600 );
    $wcu_created   = (int) ( $wcu_meta_data['created'] ?? 0 );

    if ( $wcu_created && ( time() - $wcu_created ) < $wcu_ttl ) {
        $wcu_accepts_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;
        $wcu_gz = $wcu_base . '.html.gz';

        header( 'X-WCU-Cache: HIT' );
        header( 'X-WCU-Cache-Age: ' . ( time() - $wcu_created ) );

        if ( $wcu_accepts_gzip && is_readable( $wcu_gz ) ) {
            header( 'Content-Encoding: gzip' );
            header( 'Content-Type: text/html; charset=UTF-8' );
            readfile( $wcu_gz );
        } else {
            header( 'Content-Type: text/html; charset=UTF-8' );
            readfile( $wcu_html );
        }
        exit;
    }
}
