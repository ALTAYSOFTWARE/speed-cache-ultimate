<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'wcu_settings' );
delete_option( 'wcu_job' );
delete_option( 'wcu_logs' );
delete_option( 'wcu_preload_queue' );
delete_option( 'wcu_preload_state' );
delete_option( 'wcu_db_last_run' );

// Remove the static page cache directory.
$wcu_cache_dir = WP_CONTENT_DIR . '/cache/wcu-page-cache';
if ( is_dir( $wcu_cache_dir ) ) {
    $wcu_it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $wcu_cache_dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $wcu_it as $wcu_file ) {
        $wcu_file->isDir() ? @rmdir( $wcu_file->getRealPath() ) : @unlink( $wcu_file->getRealPath() );
    }
    @rmdir( $wcu_cache_dir );
}

// Remove the advanced-cache.php drop-in only if it's ours.
$wcu_adv = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $wcu_adv ) ) {
    $wcu_contents = file_get_contents( $wcu_adv );
    if ( $wcu_contents !== false && strpos( $wcu_contents, 'WP Cache Ultimate' ) !== false ) {
        @unlink( $wcu_adv );
    }
}
