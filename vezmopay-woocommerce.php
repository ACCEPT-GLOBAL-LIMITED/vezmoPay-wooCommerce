<?php
/**
 * Plugin Name:       VezmoPay for WooCommerce
 * Plugin URI:        https://github.com/ACCEPT-GLOBAL-LIMITED/vezmoPay-wooCommerce
 * Description:       Accept payments through VezmoPay — hosted checkout, inline payment element, or secure iframe. Cards, US bank (ACH) and more, with 3-D Secure handled by VezmoPay.
 * Version:           0.2.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            ACCEPT GLOBAL LIMITED
 * Author URI:        https://vezmo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vezmopay-woocommerce
 * Domain Path:       /languages
 * Update URI:        https://github.com/ACCEPT-GLOBAL-LIMITED/vezmoPay-wooCommerce
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package VezmoPay
 */

defined( 'ABSPATH' ) || exit;

define( 'VEZMOPAY_WC_VERSION', '0.2.5' );
define( 'VEZMOPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'VEZMOPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VEZMOPAY_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload VezmoPay\WooCommerce classes from the includes/ directory.
 *
 * Maps e.g. VezmoPay\WooCommerce\Api_Client to includes/class-vezmopay-api-client.php
 * and VezmoPay\WooCommerce\Blocks_Support to includes/blocks/class-vezmopay-blocks-support.php.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'VezmoPay\\WooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
		$file = VEZMOPAY_WC_PLUGIN_DIR . 'includes/class-vezmopay-' . $name . '.php';
		if ( ! file_exists( $file ) ) {
			$file = VEZMOPAY_WC_PLUGIN_DIR . 'includes/blocks/class-vezmopay-' . $name . '.php';
		}
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Stop the background reconciliation task when the plugin is deactivated.
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'vezmopay_reconcile_pending' );
	}
);

// Declare compatibility with HPOS and Cart & Checkout Blocks.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded (WooCommerce must exist).
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'vezmopay-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'VezmoPay for WooCommerce requires WooCommerce to be installed and active.', 'vezmopay-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		\VezmoPay\WooCommerce\Plugin::instance();
	},
	11
);
