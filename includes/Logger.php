<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Logger {
    const OPTION = 'wcu_logs';
    const MAX    = 200;

    public static function log( $channel, $action, $detail = '' ) {
        $logs   = get_option( self::OPTION, array() );
        $logs[] = array(
            't'       => time(),
            'channel' => $channel,
            'action'  => $action,
            'detail'  => is_string( $detail ) ? $detail : wp_json_encode( $detail ),
        );
        if ( count( $logs ) > self::MAX ) {
            $logs = array_slice( $logs, -self::MAX );
        }
        update_option( self::OPTION, $logs, false );
    }

    public static function all() {
        return array_reverse( get_option( self::OPTION, array() ) );
    }

    public static function clear() {
        delete_option( self::OPTION );
    }

    public static function counts_by_channel() {
        $logs   = get_option( self::OPTION, array() );
        $counts = array();
        foreach ( $logs as $l ) {
            $c = $l['channel'] ?? 'other';
            $counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1;
        }
        return $counts;
    }
}
