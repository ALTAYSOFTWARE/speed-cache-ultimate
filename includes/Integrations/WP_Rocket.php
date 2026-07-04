<?php
namespace WCU\Integrations;
if ( ! defined( 'ABSPATH' ) ) exit;
class WP_Rocket {
    public static function purge_cache() {
        if ( function_exists( 'rocket_clean_domain' ) ) {
            try { rocket_clean_domain(); return 'ok'; } catch ( \Throwable $e ) { return 'err:'.$e->getMessage(); }
        }
        if ( function_exists( 'rocket_clean_cache' ) ) {
            try { rocket_clean_cache(); return 'ok'; } catch ( \Throwable $e ) { return 'err:'.$e->getMessage(); }
        }
        return 'not_available';
    }
}
