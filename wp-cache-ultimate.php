<?php
/**
 * Plugin Name: WP Cache Ultimate
 * Plugin URI:  https://bilgikasabasi.com
* Description: Gerçek sayfa önbelleği, ön yükleme, veritabanı optimizasyonu ve CDN desteği ile sitenizi hızlandırın.
 * Version:     2.2.2
 * Author:      Altay Yazılım
 * Author URI:  https://altayyazilim.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-cache-ultimate
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCU_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCU_URL', plugin_dir_url( __FILE__ ) );
define( 'WCU_VERSION', '2.2.2' );

require_once WCU_DIR . 'includes/Core.php';

register_activation_hook( __FILE__, array( 'WCU\\Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCU\\Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WCU\\Core', 'init' ) );
