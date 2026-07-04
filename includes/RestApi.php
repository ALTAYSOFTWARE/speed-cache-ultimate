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

        return rest_ensure_response( array(
            'page_cache'  => PageCache::stats(),
            'job'         => get_option( Cleaner::JOB_OPTION, null ),
            'preload'     => Preloader::status(),
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
        $n = Preloader::start();
        return rest_ensure_response( array( 'ok' => true, 'queued' => $n ) );
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
