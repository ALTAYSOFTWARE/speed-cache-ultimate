<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Core {
    public static function init() {
        require_once WCU_DIR . 'includes/Logger.php';
        require_once WCU_DIR . 'includes/Cleaner.php';
        require_once WCU_DIR . 'includes/PageCache.php';
        require_once WCU_DIR . 'includes/Optimizer.php';
        require_once WCU_DIR . 'includes/Preloader.php';
        require_once WCU_DIR . 'includes/DbOptimizer.php';
        require_once WCU_DIR . 'includes/ServerConfig.php';
        require_once WCU_DIR . 'includes/RestApi.php';
        require_once WCU_DIR . 'admin/Dashboard.php';
        require_once WCU_DIR . 'admin/Settings.php';
        require_once WCU_DIR . 'includes/Integrations/CommonPurge.php';
        require_once WCU_DIR . 'includes/Integrations/Cloudflare.php';
        require_once WCU_DIR . 'includes/Integrations/WP_Rocket.php';
        require_once WCU_DIR . 'includes/Integrations/RedisAdapter.php';
        require_once WCU_DIR . 'includes/Cdn.php';
        require_once WCU_DIR . 'includes/SecurityHeaders.php';

        // Page caching engine (buffers + serves via advanced-cache.php drop-in).
        PageCache::init();
        Optimizer::init();
        Preloader::init();
        RestApi::init();
        Cdn::init();
        SecurityHeaders::init();

        // Admin UI
        add_action( 'admin_menu', array( 'WCU\\Dashboard', 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( 'WCU\\Dashboard', 'enqueue_assets' ) );

        // Settings registration (no submenu duplication)
        add_action( 'admin_init', array( 'WCU\\Settings', 'register_settings' ) );

        // AJAX endpoints (kept for legacy compatibility; REST API in RestApi.php is the modern path)
        add_action( 'wp_ajax_wcu_start_job', array( 'WCU\\Cleaner', 'ajax_start_job' ) );
        add_action( 'wp_ajax_wcu_process_step', array( 'WCU\\Cleaner', 'ajax_process_step' ) );
        add_action( 'wp_ajax_wcu_get_job', array( 'WCU\\Cleaner', 'ajax_get_job' ) );
        add_action( 'wp_ajax_wcu_quick_action', array( 'WCU\\Cleaner', 'ajax_quick_action' ) );

        // Cron processing
        add_action( 'wcu_process_job_cron', array( 'WCU\\Cleaner', 'cron_process' ) );
        add_action( 'wcu_weekly_db_optimize', array( __CLASS__, 'maybe_run_scheduled_db_optimize' ) );

        // WP-CLI
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            self::register_cli_commands();
        }
    }

    protected static function register_cli_commands() {
        \WP_CLI::add_command( 'wcu clear', function () {
            $res = Cleaner::run_all_blocking();
            \WP_CLI::success( 'WCU: clear finished' );
            \WP_CLI::log( wp_json_encode( $res ) );
        } );
        \WP_CLI::add_command( 'wcu preload', function () {
            $n = Preloader::start();
            \WP_CLI::success( "WCU: preload queued ({$n} url)" );
        } );
        \WP_CLI::add_command( 'wcu db-optimize', function () {
            $res = DbOptimizer::run();
            \WP_CLI::success( 'WCU: db optimize finished' );
            \WP_CLI::log( wp_json_encode( $res ) );
        } );
        \WP_CLI::add_command( 'wcu stats', function () {
            require_once WCU_DIR . 'includes/PageCache.php';
            \WP_CLI::log( wp_json_encode( PageCache::stats() ) );
        } );
    }

    public static function maybe_run_scheduled_db_optimize() {
        $settings = Settings::get();
        if ( ! empty( $settings['db_auto_optimize'] ) ) {
            DbOptimizer::run();
        }
    }

    public static function activate() {
        if ( ! get_option( 'wcu_settings' ) ) {
            require_once WCU_DIR . 'admin/Settings.php';
            add_option( 'wcu_settings', Settings::defaults() );
        }

        self::register_cron_interval();

        if ( ! wp_next_scheduled( 'wcu_process_job_cron' ) ) {
            wp_schedule_event( time(), 'wcu_every_minute', 'wcu_process_job_cron' );
        }
        if ( ! wp_next_scheduled( 'wcu_weekly_db_optimize' ) ) {
            wp_schedule_event( time() + 3600, 'weekly', 'wcu_weekly_db_optimize' );
        }

        self::install_advanced_cache();
        wp_mkdir_p( WP_CONTENT_DIR . '/cache/wcu-page-cache' );
    }

    public static function deactivate() {
        $ts = wp_next_scheduled( 'wcu_process_job_cron' );
        if ( $ts ) wp_unschedule_event( $ts, 'wcu_process_job_cron' );

        $ts2 = wp_next_scheduled( 'wcu_weekly_db_optimize' );
        if ( $ts2 ) wp_unschedule_event( $ts2, 'wcu_weekly_db_optimize' );

        $ts3 = wp_next_scheduled( 'wcu_preload_tick' );
        if ( $ts3 ) wp_unschedule_event( $ts3, 'wcu_preload_tick' );
    }

    protected static function register_cron_interval() {
        add_filter( 'cron_schedules', function ( $s ) {
            if ( ! isset( $s['wcu_every_minute'] ) ) {
                $s['wcu_every_minute'] = array( 'interval' => 60, 'display' => 'Her Dakika (WCU)' );
            }
            return $s;
        } );
    }

    /**
     * Installs the advanced-cache.php drop-in and attempts to enable
     * define('WP_CACHE', true) in wp-config.php. Both are best-effort:
     * if the filesystem isn't writable the plugin still works, just without
     * the earliest-possible PHP-level cache hit (see Settings > Sunucu Yapılandırması).
     */
    protected static function install_advanced_cache() {
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        $source = WCU_DIR . 'includes/drop-ins/advanced-cache.php';

        if ( file_exists( $source ) && is_writable( WP_CONTENT_DIR ) ) {
            @copy( $source, $target );
        }

        $config_path = ABSPATH . 'wp-config.php';
        if ( file_exists( $config_path ) && is_writable( $config_path ) ) {
            $contents = file_get_contents( $config_path );
            if ( $contents !== false
                && strpos( $contents, "define( 'WP_CACHE'" ) === false
                && strpos( $contents, 'define("WP_CACHE"' ) === false
                && strpos( $contents, "define('WP_CACHE'" ) === false
            ) {
                $contents = preg_replace(
                    '/(<\?php)/',
                    "$1\ndefine( 'WP_CACHE', true ); // Added by WP Cache Ultimate\n",
                    $contents,
                    1
                );
                if ( $contents !== null ) {
                    @file_put_contents( $config_path, $contents );
                }
            }
        }
    }
}
