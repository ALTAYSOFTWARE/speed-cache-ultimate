<?php
/**
 * Plugin Name: Speed Cache Ultimate
 * Plugin URI:  https://bilgikasabasi.com
 * Description: Gerçek sayfa önbellekleme motoru (disk cache + advanced-cache.php erken servis), ön yükleme, veritabanı optimizasyonu, hariç tutma kuralları, CDN URL yeniden yazma, güvenlik başlıkları (Toolbox), medya optimizasyonu (WebP/CLS), REST API, Cloudflare/Redis/WP Rocket entegrasyonları, WP-CLI ve modern canlı istatistikli yönetim paneli.
 * Version:     2.2.0
 * Author:      Altay Yazılım
 * Text Domain: speed-cache-ultimate
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCU_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCU_URL', plugin_dir_url( __FILE__ ) );
define( 'WCU_VERSION', '2.2.0' );

require_once WCU_DIR . 'includes/Core.php';

register_activation_hook( __FILE__, array( 'WCU\\Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCU\\Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WCU\\Core', 'init' ) );
