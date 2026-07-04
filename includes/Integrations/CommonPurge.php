<?php
namespace WCU\Integrations;
if ( ! defined( 'ABSPATH' ) ) exit;
class CommonPurge {
    public static function run_all() {
        $results = array();

        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            try { wp_cache_clear_cache(); $results['wp_super_cache'] = 'flushed'; } catch ( \Throwable $e ) { $results['wp_super_cache'] = 'err:'.$e->getMessage(); }
        } else $results['wp_super_cache'] = 'not_available';

        if ( function_exists( 'w3tc_flush_all' ) ) {
            try { w3tc_flush_all(); $results['w3_total_cache'] = 'flushed_all'; } catch ( \Throwable $e ) { $results['w3_total_cache'] = 'err:'.$e->getMessage(); }
        } else $results['w3_total_cache'] = 'not_available';

        if ( has_action( 'litespeed_purge_all' ) || function_exists( 'litespeed_purge_all' ) ) {
            try { do_action( 'litespeed_purge_all' ); if ( function_exists( 'litespeed_purge_all' ) ) litespeed_purge_all(); $results['litespeed'] = 'purged'; } catch ( \Throwable $e ) { $results['litespeed'] = 'err:'.$e->getMessage(); }
        } else $results['litespeed'] = 'not_available';

        if ( class_exists( 'WCU\\Integrations\\WP_Rocket' ) ) {
            $results['wp_rocket'] = WP_Rocket::purge_cache();
        } else $results['wp_rocket'] = 'adapter_not_loaded';

        if ( class_exists( 'WCU\\Integrations\\Cloudflare' ) ) {
            $cf = new Cloudflare();
            $results['cloudflare'] = $cf->purge_all();
        } else $results['cloudflare'] = 'adapter_not_loaded';

        return $results;
    }
}
