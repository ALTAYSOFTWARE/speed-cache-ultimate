<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class RestApi {
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'wcu/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'status' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/purge', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'purge' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/preload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'preload' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/db-optimize', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'db_optimize' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/logs', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'logs' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/logs', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'clear_logs' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/cache-list', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'cache_list' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
        register_rest_route( 'wcu/v1', '/preset', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'apply_preset' ),
            'permission_callback' => array( __CLASS__, 'permission_admin' ),
        ) );
    }

    public static function permission_admin() {
        return current_user_can( 'manage_options' );
    }

    public static function status( \WP_REST_Request $req ) {
        global $wpdb;

        $disk_total = @disk_total_space( ABSPATH );
        $disk_free  = @disk_free_space( ABSPATH );
        $disk_used  = ( $disk_total && $disk_free ) ? ( $disk_total - $disk_free ) : null;

        // ================================================================
        // DÜZELTME: Preload status'ü tutarlı bir şekilde al
        // ================================================================
        $preload_status = Preloader::status();
        
        // Eğer status null veya boşsa, option'dan doğrudan oku
        if (empty($preload_status)) {
            $preload_status = get_option('wcu_preload_status', array());
            $preload_queue = get_option('wcu_preload_queue', array());
            
            if (!empty($preload_status)) {
                $done = isset($preload_status['done']) ? intval($preload_status['done']) : 0;
                $total = isset($preload_status['total']) ? intval($preload_status['total']) : 0;
                
                // total 0 ise ve kuyruk varsa, total'ı hesapla
                if ($total === 0 && !empty($preload_queue)) {
                    $total = $done + count($preload_queue);
                    $preload_status['total'] = $total;
                    update_option('wcu_preload_status', $preload_status);
                }
                
                $preload_status['status'] = empty($preload_queue) && $done > 0 ? 'done' : 'running';
                $preload_status['done'] = $done;
                $preload_status['remaining'] = count($preload_queue);
            }
        }

        return rest_ensure_response( array(
            'page_cache'  => PageCache::stats(),
            'job'         => get_option( Cleaner::JOB_OPTION, null ),
            'preload'     => $preload_status,
            'db_last_run' => get_option( 'wcu_db_last_run', 0 ),
            'log_counts'  => Logger::counts_by_channel(),
            'environment' => array(
                'php'    => PHP_VERSION,
                'mysql'  => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '-',
                'server' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) : '-',
                'wp'     => get_bloginfo( 'version' ),
                'object_cache' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
                'wp_cache_const' => defined( 'WP_CACHE' ) && WP_CACHE,
                'advanced_cache_installed' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
            ),
            'health' => ServerConfig::health_checks(),
            'disk' => array(
                'total'      => $disk_total,
                'free'       => $disk_free,
                'used'       => $disk_used,
                'percent'    => ( $disk_total && $disk_used !== null ) ? round( ( $disk_used / $disk_total ) * 100, 1 ) : null,
                'total_human'=> $disk_total ? size_format( $disk_total, 1 ) : '-',
                'used_human' => $disk_used !== null ? size_format( $disk_used, 1 ) : '-',
            ),
        ) );
    }

    public static function purge( \WP_REST_Request $req ) {
        PageCache::purge_all();
        $res = Integrations\CommonPurge::run_all();
        return rest_ensure_response( array( 'ok' => true, 'integrations' => $res ) );
    }

    public static function preload( \WP_REST_Request $req ) {
        // ================================================================
        // DÜZELTME: Preloader başlatıldıktan sonra status bilgisini de döndür
        // ================================================================
        $n = Preloader::start();
        
        // Başlatma sonrası status'ü al ve total'ın set edildiğinden emin ol
        $status = Preloader::status();
        
        // Eğer status boşsa veya total yoksa, manuel oluştur
        if (empty($status) || !isset($status['total']) || $status['total'] === 0) {
            $preload_status = get_option('wcu_preload_status', array());
            $preload_queue = get_option('wcu_preload_queue', array());
            
            $total = count($preload_queue);
            $done = 0;
            
            // total'ı option'a kaydet (sabit kalacak)
            $preload_status['total'] = $total;
            $preload_status['done'] = $done;
            $preload_status['status'] = 'running';
            update_option('wcu_preload_status', $preload_status);
            
            $status = $preload_status;
        }
        
        return rest_ensure_response( array( 
            'ok' => true, 
            'queued' => $n,
            'status' => $status
        ) );
    }

    public static function db_optimize( \WP_REST_Request $req ) {
        $tasks = $req->get_param( 'tasks' );
        $res   = DbOptimizer::run( is_array( $tasks ) ? $tasks : array() );
        return rest_ensure_response( array( 'ok' => true, 'results' => $res ) );
    }

    public static function logs( \WP_REST_Request $req ) {
        return rest_ensure_response( Logger::all() );
    }

    public static function clear_logs( \WP_REST_Request $req ) {
        Logger::clear();
        return rest_ensure_response( array( 'ok' => true ) );
    }

    public static function cache_list( \WP_REST_Request $req ) {
        return rest_ensure_response( PageCache::list_cached( 300 ) );
    }

    public static function apply_preset( \WP_REST_Request $req ) {
        $name = sanitize_key( (string) $req->get_param( 'name' ) );
        $ok   = Settings::apply_preset( $name );
        if ( ! $ok ) {
            return new \WP_Error( 'wcu_invalid_preset', 'Geçersiz profil adı', array( 'status' => 400 ) );
        }
        PageCache::purge_all();
        Logger::log( 'optimize', 'preset_applied', $name );
        return rest_ensure_response( array( 'ok' => true, 'preset' => $name, 'settings' => Settings::get() ) );
    }
}