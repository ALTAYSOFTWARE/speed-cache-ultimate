<?php
namespace WCU\Integrations;
if ( ! defined( 'ABSPATH' ) ) exit;
class RedisAdapter {
    protected $host;
    protected $port;
    protected $password;
    protected $enabled;

    public function __construct() {
        $s = get_option( 'wcu_settings', array() );
        $this->enabled  = ! empty( $s['redis_enabled'] );
        $this->host = $s['redis_host'] ?? '127.0.0.1';
        $this->port = $s['redis_port'] ?? 6379;
        $this->password = $s['redis_password'] ?? '';
    }

    public function flush() {
        if ( ! $this->enabled ) return false;

        global $redis;
        if ( isset( $redis ) && is_object( $redis ) ) {
            try {
                if ( method_exists( $redis, 'flushAll' ) ) $redis->flushAll();
                elseif ( method_exists( $redis, 'flushdb' ) ) $redis->flushdb();
                else return false;
                return true;
            } catch ( \Throwable $e ) {
                return false;
            }
        }

        if ( class_exists( '\\Redis' ) ) {
            try {
                $r = new \Redis();
                $r->connect( $this->host, $this->port, 1 );
                if ( ! empty( $this->password ) ) $r->auth( $this->password );
                $r->flushAll();
                $r->close();
                return true;
            } catch ( \Throwable $e ) {
                return false;
            }
        }
        return false;
    }
}
