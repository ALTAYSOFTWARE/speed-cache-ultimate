<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Core {
    public static function init() {
        // ✅ FIX: Custom cron interval'lar WordPress tarafından HER request'te
        // yeniden tanınması gerekir (sadece activation anında değil). Bu filtre
        // önceden sadece activate() içindeydi; bu yüzden aktivasyondan sonraki
        // herhangi bir istekte (örn. panelden "Önyükle" tetiklendiğinde)
        // 'wcu_every_minute' zamanlaması WordPress'e tanımsız geliyor ve
        // wp_schedule_event() sessizce false dönüyordu ("Failed to schedule
        // wcu_preload_tick event"). Şimdi her yüklemede kaydediliyor.
        self::register_cron_interval();

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

        PageCache::init();
        Optimizer::init();
        Preloader::init();
        RestApi::init();
        Cdn::init();
        SecurityHeaders::init();

        add_action( 'admin_menu', array( 'WCU\\Dashboard', 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( 'WCU\\Dashboard', 'enqueue_assets' ) );
        add_action( 'admin_init', array( 'WCU\\Settings', 'register_settings' ) );
        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dir_error_notice' ) );

        add_action( 'wp_ajax_wcu_start_job', array( 'WCU\\Cleaner', 'ajax_start_job' ) );
        add_action( 'wp_ajax_wcu_process_step', array( 'WCU\\Cleaner', 'ajax_process_step' ) );
        add_action( 'wp_ajax_wcu_get_job', array( 'WCU\\Cleaner', 'ajax_get_job' ) );
        add_action( 'wp_ajax_wcu_quick_action', array( 'WCU\\Cleaner', 'ajax_quick_action' ) );

        add_action( 'wcu_process_job_cron', array( 'WCU\\Cleaner', 'cron_process' ) );
        add_action( 'wcu_weekly_db_optimize', array( __CLASS__, 'maybe_run_scheduled_db_optimize' ) );

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

        // Cache directory is created in content folder to match PageCache::cache_root()
        $cache_base   = WP_CONTENT_DIR . '/cache';
        $cache_target = $cache_base . '/wcu-page-cache';
        $created      = wp_mkdir_p( $cache_target );

        // ✅ FIX: wp_mkdir_p() failures were previously silent. If activation
        // cannot create the cache directory (permissions, open_basedir,
        // read-only content dir, etc.) we now log the exact reason and store
        // an admin notice, instead of leaving no trace at all.
        if ( ! $created || ! is_dir( $cache_target ) ) {
            $error = error_get_last();
            $msg   = sprintf(
                'WCU: activation could not create cache directory "%s". is_dir(WP_CONTENT_DIR)=%s, is_writable(WP_CONTENT_DIR)=%s, is_dir(cache_base)=%s, is_writable(cache_base)=%s. Last PHP error: %s',
                $cache_target,
                is_dir( WP_CONTENT_DIR ) ? 'yes' : 'no',
                is_writable( WP_CONTENT_DIR ) ? 'yes' : 'no',
                is_dir( $cache_base ) ? 'yes' : 'no',
                is_dir( $cache_base ) ? ( is_writable( $cache_base ) ? 'yes' : 'no' ) : 'n/a',
                $error ? $error['message'] : 'none'
            );
            error_log( $msg );
            update_option( 'wcu_activation_dir_error', $msg, false );
        } else {
            delete_option( 'wcu_activation_dir_error' );
        }

        // ✅ FIX: install_advanced_cache() var olmasına rağmen hiçbir yerden
        // çağrılmıyordu ve panelde de bunu tetikleyecek bir buton yoktu; bu
        // yüzden advanced-cache.php hiçbir zaman otomatik kurulmuyordu.
        // Diğer önbellek eklentileriyle çakışmayı önlemek için sadece
        // wp-content/advanced-cache.php boşsa veya daha önce bu eklenti
        // tarafından kurulmuşsa otomatik kurulum yapılır; yabancı bir
        // drop-in tespit edilirse dokunulmaz ve admin'e bildirim gösterilir.
        $adv_result = self::install_advanced_cache();
        if ( empty( $adv_result['success'] ) ) {
            update_option( 'wcu_activation_adv_cache_error', $adv_result['message'], false );
        } else {
            delete_option( 'wcu_activation_adv_cache_error' );
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled( 'wcu_process_job_cron' );
        if ( $ts ) wp_unschedule_event( $ts, 'wcu_process_job_cron' );

        $ts2 = wp_next_scheduled( 'wcu_weekly_db_optimize' );
        if ( $ts2 ) wp_unschedule_event( $ts2, 'wcu_weekly_db_optimize' );

        $ts3 = wp_next_scheduled( 'wcu_preload_tick' );
        if ( $ts3 ) wp_unschedule_event( $ts3, 'wcu_preload_tick' );
    }

    public static function maybe_show_dir_error_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $msg = get_option( 'wcu_activation_dir_error' );
        if ( $msg ) {
            echo '<div class="notice notice-error"><p><strong>WP Cache Ultimate:</strong> ' . esc_html( $msg ) . '</p></div>';
        }

        $adv_msg = get_option( 'wcu_activation_adv_cache_error' );
        if ( $adv_msg ) {
            echo '<div class="notice notice-warning"><p><strong>WP Cache Ultimate:</strong> ' . esc_html( $adv_msg ) . '</p></div>';
        }
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
     * Installs the advanced-cache.php drop-in using WP_Filesystem.
     * Called automatically on plugin activation (see Core::activate()).
     *
     * Safe to call again later too (e.g. from a future "Sunucu Yapılandırması"
     * panel action) since it no-ops when a foreign (non-WCU) drop-in is
     * already present, instead of silently overwriting another cache plugin.
     */
    public static function install_advanced_cache() {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            WP_Filesystem();
        }

        if ( empty( $wp_filesystem ) ) {
            return array( 'success' => false, 'message' => 'WP_Filesystem could not be initialized.' );
        }

        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        $source = WCU_DIR . 'includes/drop-ins/advanced-cache.php';

        if ( ! file_exists( $source ) ) {
            return array( 'success' => false, 'message' => 'Drop-in source file not found: ' . $source );
        }

        // ✅ FIX: Don't blindly overwrite an existing advanced-cache.php.
        // Only skip installing if the file exists AND belongs to a different
        // (non-WCU) caching plugin — our own drop-in is always safe to refresh.
        if ( $wp_filesystem->exists( $target ) ) {
            $existing = $wp_filesystem->get_contents( $target );
            $is_ours  = $existing !== false && strpos( $existing, 'WP Cache Ultimate' ) !== false;
            if ( ! $is_ours ) {
                return array(
                    'success' => false,
                    'message' => 'wp-content/advanced-cache.php already exists and belongs to another caching plugin. Devre dışı bırakıp tekrar deneyin veya dosyayı manuel silin.',
                );
            }
        }

        $wp_filesystem->copy( $source, $target, true );

        $config_path = get_home_path() . 'wp-config.php';
        if ( file_exists( $config_path ) ) {
            $contents = $wp_filesystem->get_contents( $config_path );
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
                    $wp_filesystem->put_contents( $config_path, $contents );
                }
            }
        }

        return array( 'success' => true, 'message' => 'advanced-cache.php installed.' );
    }
}
