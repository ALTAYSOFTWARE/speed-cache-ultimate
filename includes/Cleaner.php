<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Cleaner {
    const JOB_OPTION      = 'wcu_job';
    const SETTINGS_OPTION = 'wcu_settings';

    public static function ajax_start_job() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
        check_admin_referer( 'wcu_clear_nonce', 'nonce' );

        $settings = Settings::get();
        $batch    = (int) ( $settings['transient_batch'] ?? 500 );

        $job = array(
            'status'   => 'running',
            'stage'    => 'init',
            'progress' => 0,
            'results'  => array(),
            'meta'     => array(
                'batch'               => $batch,
                'transients_deleted'  => 0,
            ),
            'started_at' => time(),
        );

        update_option( self::JOB_OPTION, $job );
        wp_schedule_single_event( time() + 1, 'wcu_process_job_cron' );

        wp_send_json_success( array( 'job' => $job ) );
    }

    public static function ajax_quick_action() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
        check_admin_referer( 'wcu_clear_nonce', 'nonce' );
        $action = sanitize_text_field( $_POST['quick'] ?? '' );

        $res = array( 'action' => $action, 'result' => null );
        try {
            switch ( $action ) {
                case 'clear_object':
                    $res['result'] = self::clear_object_cache();
                    break;
                case 'clear_transients':
                    $res['result'] = self::delete_transients_batch( 1000 );
                    break;
                case 'clear_cache_dirs':
                    $res['result'] = self::clear_cache_dirs();
                    break;
                case 'clear_page_cache':
                    $res['result'] = PageCache::purge_all();
                    break;
                case 'db_optimize':
                    $res['result'] = DbOptimizer::run();
                    break;
                default:
                    $res['result'] = 'unknown_action';
            }
            wp_send_json_success( $res );
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage(), 500 );
        }
    }

    public static function ajax_process_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
        check_admin_referer( 'wcu_clear_nonce', 'nonce' );
        $res = self::process_step();
        wp_send_json_success( $res );
    }

    public static function ajax_get_job() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
        $job = get_option( self::JOB_OPTION, null );
        wp_send_json_success( $job );
    }

    public static function cron_process() {
        self::process_step();
    }

    public static function process_step() {
        $job = get_option( self::JOB_OPTION, null );
        if ( ! $job || ! is_array( $job ) ) {
            return array( 'note' => 'no_job' );
        }

        try {
            switch ( $job['stage'] ) {
                case 'init':
                    $job['results']['pre_purge'] = Integrations\CommonPurge::run_all();
                    $job['stage']    = 'page_cache';
                    $job['progress'] = 5;
                    update_option( self::JOB_OPTION, $job );
                    return array( 'stage' => 'page_cache' );

                case 'page_cache':
                    $job['results']['page_cache'] = PageCache::purge_all();
                    $job['stage']    = 'transients';
                    $job['progress'] = 15;
                    update_option( self::JOB_OPTION, $job );
                    return array( 'stage' => 'transients' );

                case 'transients':
                    $batch   = max( 1, (int) ( $job['meta']['batch'] ?? 500 ) );
                    $deleted = self::delete_transients_batch( $batch );
                    $job['meta']['transients_deleted']   += $deleted;
                    $job['results']['transients_deleted'] = $job['meta']['transients_deleted'];

                    if ( $deleted < $batch ) {
                        $job['stage']    = 'cache_dirs';
                        $job['progress'] = 55;
                    } else {
                        $job['progress'] = min( 90, $job['meta']['transients_deleted'] / max( 1, $batch ) * 5 + 20 );
                    }

                    update_option( self::JOB_OPTION, $job );
                    return array( 'deleted_this_round' => $deleted, 'total_deleted' => $job['meta']['transients_deleted'] );

                case 'cache_dirs':
                    $job['results']['cache_dirs'] = self::clear_cache_dirs();
                    $job['stage']    = 'object_cache';
                    $job['progress'] = 80;
                    update_option( self::JOB_OPTION, $job );
                    return array( 'stage' => 'object_cache' );

                case 'object_cache':
                    $job['results']['object_cache'] = self::clear_object_cache();
                    $job['stage']    = 'opcache';
                    $job['progress'] = 90;
                    update_option( self::JOB_OPTION, $job );
                    return array( 'stage' => 'opcache' );

                case 'opcache':
                    $job['results']['opcache'] = self::clear_opcache();
                    $job['stage']    = 'final_purge';
                    $job['progress'] = 95;
                    update_option( self::JOB_OPTION, $job );
                    return array( 'stage' => 'final_purge' );

                case 'final_purge':
                    $job['results']['final_purge'] = Integrations\CommonPurge::run_all();
                    $job['stage']       = 'done';
                    $job['status']      = 'done';
                    $job['progress']    = 100;
                    $job['finished_at'] = time();
                    update_option( self::JOB_OPTION, $job );
                    return array( 'note' => 'done', 'results' => $job['results'] );

                case 'done':
                    return array( 'note' => 'already_done', 'results' => $job['results'] );
            }
        } catch ( \Throwable $e ) {
            $job['status'] = 'error';
            $job['stage']  = 'error';
            $job['error']  = $e->getMessage();
            update_option( self::JOB_OPTION, $job );
            return array( 'error' => $e->getMessage() );
        }

        return array( 'note' => 'no-op' );
    }

    public static function run_all_blocking() {
        $res = array();
        $res['pre_purge']   = Integrations\CommonPurge::run_all();
        $res['page_cache']  = PageCache::purge_all();
        $settings = Settings::get();
        do {
            $deleted             = self::delete_transients_batch( (int) ( $settings['transient_batch'] ?? 500 ) );
            $res['deleted_rounds'][] = $deleted;
        } while ( $deleted > 0 );
        $res['cache_dir']     = self::clear_cache_dirs();
        $res['object_cache']  = self::clear_object_cache();
        $res['opcache']       = self::clear_opcache();
        $res['db_optimize']   = DbOptimizer::run();
        $res['final_purge']   = Integrations\CommonPurge::run_all();
        return $res;
    }

    public static function delete_transients_batch( $limit = 500 ) {
        global $wpdb;
        $options_table = $wpdb->options;
        $like1 = $wpdb->esc_like( '_transient_' ) . '%';
        $rows  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$options_table} WHERE option_name LIKE %s LIMIT %d", $like1, $limit ) );
        $deleted = 0;
        if ( $rows ) {
            foreach ( $rows as $name ) {
                $wpdb->delete( $options_table, array( 'option_name' => $name ), array( '%s' ) );
                $deleted++;
            }
        }
        $like2 = $wpdb->esc_like( '_site_transient_' ) . '%';
        if ( $deleted < $limit ) {
            $rem   = $limit - $deleted;
            $rows2 = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$options_table} WHERE option_name LIKE %s LIMIT %d", $like2, $rem ) );
            if ( $rows2 ) {
                foreach ( $rows2 as $name ) {
                    $wpdb->delete( $options_table, array( 'option_name' => $name ), array( '%s' ) );
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    public static function clear_cache_dirs() {
        $dirs = array(
            WP_CONTENT_DIR . '/cache',
            WP_CONTENT_DIR . '/uploads/cache',
            WP_CONTENT_DIR . '/wp-rocket-cache',
        );
        $results = array();
        foreach ( $dirs as $d ) {
            if ( is_dir( $d ) ) {
                $results[ $d ] = self::rrmdir_contents( $d ) ? 'cleared' : 'failed';
            } else {
                $results[ $d ] = 'not_found';
            }
        }
        return $results;
    }

    /**
     * Empties a directory's contents recursively but keeps the directory itself.
     * Public so PageCache and other modules can reuse the same safe recursive delete.
     */
    public static function rrmdir_contents( $dir ) {
        if ( ! is_dir( $dir ) ) return false;
        $items = scandir( $dir );
        $ok    = true;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $ok = self::rrmdir_full( $path ) && $ok;
            } else {
                $ok = @unlink( $path ) && $ok;
            }
        }
        return $ok;
    }

    private static function rrmdir_full( $dir ) {
        if ( ! is_dir( $dir ) ) return false;
        $items = scandir( $dir );
        $ok    = true;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $ok = self::rrmdir_full( $path ) && $ok;
            } else {
                $ok = @unlink( $path ) && $ok;
            }
        }
        return @rmdir( $dir ) && $ok;
    }

    public static function clear_object_cache() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            try {
                return wp_cache_flush() ? 'ok' : 'failed';
            } catch ( \Throwable $e ) {
                return 'failed:' . $e->getMessage();
            }
        }
        if ( class_exists( 'WCU\\Integrations\\RedisAdapter' ) ) {
            try {
                $ra = new Integrations\RedisAdapter();
                return $ra->flush() ? 'redis_ok' : 'redis_failed';
            } catch ( \Throwable $e ) {
                return 'redis_failed:' . $e->getMessage();
            }
        }
        return 'not_available';
    }

    public static function clear_opcache() {
        if ( function_exists( 'opcache_reset' ) ) {
            try {
                opcache_reset();
                return 'ok';
            } catch ( \Throwable $e ) {
                return 'failed:' . $e->getMessage();
            }
        }
        return 'not_available';
    }
}
