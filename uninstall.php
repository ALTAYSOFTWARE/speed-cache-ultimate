<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'wcu_settings' );
delete_option( 'wcu_job' );
delete_option( 'wcu_logs' );
delete_option( 'wcu_preload_queue' );
delete_option( 'wcu_preload_state' );
delete_option( 'wcu_db_last_run' );

// Remove the static page cache directory using WP_Filesystem.
$wcu_cache_dir = WP_CONTENT_DIR . '/cache/wcu-page-cache';
if ( is_dir( $wcu_cache_dir ) ) {
    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
    }

    if ( ! empty( $wp_filesystem ) ) {
        $wp_filesystem->delete( $wcu_cache_dir, true );
    }
}

// Remove the advanced-cache.php drop-in only if it's ours.
$wcu_adv = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $wcu_adv ) ) {
    $wcu_contents = file_get_contents( $wcu_adv );
    if ( $wcu_contents !== false && strpos( $wcu_contents, 'WP Cache Ultimate' ) !== false ) {
        wp_delete_file( $wcu_adv );
    }
}
