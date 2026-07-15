<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * All three VezmoPay modes complete payment after a server-side redirect (to the pay
 * page or the hosted checkout), so the Blocks payment method is an express "choose and
 * continue" tile — no client-side tokenization happens in the checkout form itself.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks payment method type for VezmoPay.
 */
class Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = Plugin::GATEWAY_ID;

	/**
	 * Load gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Availability mirrors the gateway.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = Plugin::instance()->gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register and return the checkout script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'vezmopay-blocks',
			VEZMOPAY_WC_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			VEZMOPAY_WC_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'vezmopay-blocks', 'vezmopay-woocommerce', VEZMOPAY_WC_PLUGIN_DIR . 'languages' );
		}
		return array( 'vezmopay-blocks' );
	}

	/**
	 * Data exposed to the Blocks client.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = Plugin::instance()->gateway();
		return array(
			'title'       => $this->get_setting( 'title', __( 'VezmoPay', 'vezmopay-woocommerce' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'testMode'    => $gateway ? $gateway->is_test_mode() : true,
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}
}
