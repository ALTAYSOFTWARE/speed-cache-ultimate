<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Preloader {
    const QUEUE_OPTION = 'wcu_preload_queue';
    const STATE_OPTION = 'wcu_preload_status';

    public static function init() {
        add_action( 'wcu_preload_tick', array( __CLASS__, 'tick' ) );
    }

    public static function start() {
        self::reset();
        
        $urls = self::collect_urls();
        
        // ✅ FIX: collect_urls() sonucu kontrol et
        if ( empty( $urls ) ) {
            Logger::log( 'preload', 'error', 'No URLs collected from sitemap or posts' );
            return 0;
        }
        
        $update_queue = update_option( self::QUEUE_OPTION, $urls, false );
        $update_state = update_option( self::STATE_OPTION, array(
            'status'     => 'running',
            'total'      => count( $urls ),
            'done'       => 0,
            'started_at' => time(),
        ), false );
        
        // ✅ FIX: update_option() başarısını kontrol et
        if ( ! $update_queue || ! $update_state ) {
            Logger::log( 'preload', 'error', 'Failed to save queue or state to database' );
            return 0;
        }

        if ( ! wp_next_scheduled( 'wcu_preload_tick' ) ) {
            $scheduled = wp_schedule_event( time() + 5, 'wcu_every_minute', 'wcu_preload_tick' );
            if ( ! $scheduled ) {
                Logger::log( 'preload', 'error', 'Failed to schedule wcu_preload_tick event' );
                // Yine devam et, cron manuel çalıştırılabilir
            }
        }
        Logger::log( 'preload', 'started', count( $urls ) . ' url' );
        return count( $urls );
    }

    public static function collect_urls() {
        $urls = array( home_url( '/' ) );

        $resp = wp_remote_get( home_url( '/sitemap.xml' ), array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $body = wp_remote_retrieve_body( $resp );
            if ( preg_match_all( '#<loc>(.*?)</loc>#i', $body, $m ) ) {
                foreach ( $m[1] as $loc ) {
                    $loc = trim( html_entity_decode( $loc ) );
                    if ( filter_var( $loc, FILTER_VALIDATE_URL ) ) $urls[] = $loc;
                }
            }
        }

        $q = new \WP_Query( array(
            'post_type'      => array( 'post', 'page' ),
            'posts_per_page' => 300,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        foreach ( $q->posts as $id ) {
            $urls[] = get_permalink( $id );
        }

        return array_values( array_unique( array_filter( $urls ) ) );
    }

    public static function tick() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        $state = get_option( self::STATE_OPTION, array( 'status' => 'idle' ) );

        if ( ( $state['status'] ?? 'idle' ) !== 'running' ) return;

        // DÜZELTME: Kuyruk boşsa HEMEN bitir, başka işlem yapma!
        if ( empty( $queue ) ) {
            $state['status']      = 'done';
            $state['finished_at'] = time();
            update_option( self::STATE_OPTION, $state, false );
            $ts = wp_next_scheduled( 'wcu_preload_tick' );
            if ( $ts ) wp_unschedule_event( $ts, 'wcu_preload_tick' );
            Logger::log( 'preload', 'finished', ( $state['done'] ?? 0 ) . ' url' );
            return;
        }

        $batch = array_splice( $queue, 0, 5 );
        foreach ( $batch as $url ) {
            // ✅ FIX: wp_remote_get() dönüş değerini kontrol et
            $response = wp_remote_get( $url, array(
                'timeout'   => 15,
                'blocking'  => true,
                'sslverify' => false,
                'headers'   => array( 'X-WCU-Preload' => '1' ),
            ) );
            
            // Hata durumunu kontrol et
            if ( is_wp_error( $response ) ) {
                error_log( 'WCU Preload Error for ' . $url . ': ' . $response->get_error_message() );
                Logger::log( 'preload', 'fetch_error', $url . ' - ' . $response->get_error_message() );
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                if ( $code !== 200 ) {
                    error_log( 'WCU Preload HTTP ' . $code . ' for ' . $url );
                    Logger::log( 'preload', 'http_error', $url . ' - HTTP ' . $code );
                }
            }
            
            $state['done'] = ( $state['done'] ?? 0 ) + 1;
        }
        
        // DÜZELTME: İşlem bittiyse (kuyruk boşaldıysa) status'u "done" yap
        if ( empty( $queue ) ) {
            $state['status']      = 'done';
            $state['finished_at'] = time();
            update_option( self::STATE_OPTION, $state, false );
            $ts = wp_next_scheduled( 'wcu_preload_tick' );
            if ( $ts ) wp_unschedule_event( $ts, 'wcu_preload_tick' );
            Logger::log( 'preload', 'finished', ( $state['done'] ?? 0 ) . ' url' );
            return;
        }
        
        update_option( self::QUEUE_OPTION, $queue, false );
        update_option( self::STATE_OPTION, $state, false );
    }

    public static function status() {
        return get_option( self::STATE_OPTION, array( 'status' => 'idle', 'total' => 0, 'done' => 0 ) );
    }
    
    public static function reset() {
        delete_option( 'wcu_preload_state' );
        delete_option( self::STATE_OPTION );
        delete_option( self::QUEUE_OPTION );
        $ts = wp_next_scheduled( 'wcu_preload_tick' );
        if ( $ts ) wp_unschedule_event( $ts, 'wcu_preload_tick' );
    }
}