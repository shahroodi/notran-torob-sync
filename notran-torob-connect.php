<?php
/**
 * Plugin Name: Notran Torob Connect
 * Description: Connect WooCommerce to Torob Product API v3 with variation links, a catalog preview, and optional product update notifications.
 * Version:     1.0.2
 * Author:      Ali Shahroodi – Notran
 * Author URI:  https://shahroodi.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: notran-torob-connect
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 */

defined( 'ABSPATH' ) || exit;

define( 'NTTC_FILE', __FILE__ );
define( 'NTTC_VERSION', '1.0.2' );

foreach ( array( 'settings', 'auth', 'urls', 'catalog', 'store', 'feed', 'worker', 'landing', 'admin', 'plugin' ) as $nttc_module ) {
	require_once __DIR__ . '/includes/' . $nttc_module . '.php';
}
unset( $nttc_module );

register_activation_hook( __FILE__, array( Notran\Torob\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Notran\Torob\Plugin::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
add_action( 'plugins_loaded', array( Notran\Torob\Plugin::class, 'boot' ), 20 );
