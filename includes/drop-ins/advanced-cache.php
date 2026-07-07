<?php
/**
 * WP Cache Ultimate - advanced-cache.php drop-in
 * Auto-installed on plugin activation. Runs BEFORE WordPress core finishes
 * loading (specifically before wp-includes/formatting.php), so it must be
 * fully standalone: plain PHP only, NO WordPress functions
 * (sanitize_*, wp_unslash, wp_parse_url, esc_*, etc. are not defined yet
 * at this point and calling them causes a fatal "Call to undefined
 * function" error on every single request).
 * Regenerate via Dashboard > Sunucu Yapılandırması if you edit it by hand.
 *
 * SECURITY: Direct file access protection added
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Direct script access is not permitted.' );
}

if ( php_sapi_name() === 'cli' ) return;

$wcu_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
if ( $wcu_request_method !== 'GET' ) return;

if ( ! empty( $_GET ) ) return;

if ( ! empty( $_COOKIE ) ) {
    foreach ( $_COOKIE as $wcu_ck => $wcu_cv ) {
        $wcu_cookie_name = strtolower( (string) $wcu_ck );
        if ( strpos( $wcu_cookie_name, 'wordpress_logged_in_' ) === 0 || strpos( $wcu_cookie_name, 'comment_author_' ) === 0 ) {
            return;
        }
    }
}

// Sanitize and normalize host
$wcu_host_raw = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'default';
// Strip optional port
$wcu_host_raw = preg_replace( '/:\d+$/', '', $wcu_host_raw );
$wcu_host     = preg_replace( '/[^a-z0-9\.\-]/i', '_', $wcu_host_raw );
if ( strlen( $wcu_host ) > 64 ) $wcu_host = substr( $wcu_host, 0, 64 );

// Request URI handling with decoding & normalization
$wcu_request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
$wcu_uri_path     = parse_url( $wcu_request_uri, PHP_URL_PATH );
$wcu_uri_path     = $wcu_uri_path === null ? '/' : (string) $wcu_uri_path;
// Decode percent-encoding to detect encoded traversal like %2e%2e
$wcu_uri_path     = rawurldecode( $wcu_uri_path );
// Remove null bytes
$wcu_uri_path     = str_replace( "\0", '', $wcu_uri_path );
// Normalize repeated slashes
$wcu_uri_path     = preg_replace( '#/+#', '/', $wcu_uri_path );
// Trim and limit length
$wcu_uri          = rtrim( (string) $wcu_uri_path, '/' );
if ( $wcu_uri === '' ) $wcu_uri = '/index';
if ( strlen( $wcu_uri ) > 200 ) $wcu_uri = substr( $wcu_uri, 0, 200 );

// Path-traversal guard after decoding
if ( strpos( $wcu_uri, '..' ) !== false ) return;

$wcu_ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
$wcu_variant = preg_match( '/Mobile|Android|iP(hone|od|ad)/i', $wcu_ua ) ? 'mobile' : 'desktop';

// Use a deterministic short fragment (hash) for filesystem safety and length control
$wcu_frag = md5( $wcu_host . '|' . $wcu_uri );
$wcu_base = WP_CONTENT_DIR . '/cache/wcu-page-cache/' . $wcu_host . '/' . $wcu_frag . '/' . $wcu_variant;
$wcu_meta = $wcu_base . '.meta.json';
$wcu_html = $wcu_base . '.html';

if ( is_readable( $wcu_meta ) && is_readable( $wcu_html ) ) {
    $wcu_meta_data = json_decode( (string) file_get_contents( $wcu_meta ), true );
    $wcu_ttl       = isset( $wcu_meta_data['ttl'] ) ? (int) $wcu_meta_data['ttl'] : 3600;
    $wcu_created   = isset( $wcu_meta_data['created'] ) ? (int) $wcu_meta_data['created'] : 0;

    if ( $wcu_created && ( time() - $wcu_created ) < $wcu_ttl ) {
        $wcu_accept_encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $wcu_accepts_gzip    = ( $wcu_accept_encoding !== '' ) && strpos( $wcu_accept_encoding, 'gzip' ) !== false;
        $wcu_gz              = $wcu_base . '.html.gz';

        header( 'X-WCU-Cache: HIT' );
        header( 'X-WCU-Cache-Age: ' . intval( time() - $wcu_created ) );

        if ( $wcu_accepts_gzip && is_readable( $wcu_gz ) ) {
            // Serve gzipped file and set Vary
            header( 'Content-Encoding: gzip' );
            header( 'Vary: Accept-Encoding' );
            header( 'Content-Type: text/html; charset=UTF-8' );
            if ( ! @readfile( $wcu_gz ) ) {
                // Remove Content-Encoding header if fallback
                if ( function_exists( 'header_remove' ) ) {
                    header_remove( 'Content-Encoding' );
                } else {
                    header( 'Content-Encoding:' );
                }
                @readfile( $wcu_html );
            }
        } else {
            header( 'Content-Type: text/html; charset=UTF-8' );
            if ( ! @readfile( $wcu_html ) ) {
                return;
            }
        }
        exit;
    }
}
