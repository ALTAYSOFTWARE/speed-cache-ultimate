<?php
namespace WCU\Integrations;
if ( ! defined( 'ABSPATH' ) ) exit;
class Cloudflare {
    protected $token;
    protected $zone;

    public function __construct() {
        $s = get_option( 'wcu_settings', array() );
        $this->token = $s['cloudflare_token'] ?? '';
        $this->zone  = $s['cloudflare_zone'] ?? '';
    }

    public function purge_all() {
        if ( empty( $this->token ) || empty( $this->zone ) ) {
            return 'not_configured';
        }
        $url = "https://api.cloudflare.com/client/v4/zones/{$this->zone}/purge_cache";
        $body = json_encode( array( 'purge_everything' => true ) );
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ),
            'body' => $body,
            'timeout' => 20,
        );
        $resp = wp_remote_post( $url, $args );
        if ( is_wp_error( $resp ) ) return 'http_error:' . $resp->get_error_message();
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 200 && $code < 300 ) return 'ok';
        return 'failed_http:' . $code . ' ' . substr( wp_remote_retrieve_body( $resp ), 0, 200 );
    }
}
