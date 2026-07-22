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
	 * Cached gateway instance.
	 *
	 * @var Gateway|null
	 */
	private $gateway_instance;

	/**
	 * Load gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Resolve a gateway instance WITHOUT depending on WC()->payment_gateways().
	 *
	 * The WC gateway registry is populated in admin but may be empty during the
	 * frontend Store API request that lists Block-checkout payment methods — in
	 * which case Plugin::instance()->gateway() returns null and the method wrongly
	 * disappears. Instantiating the gateway directly always works.
	 *
	 * @return Gateway
	 */
	private function gateway() {
		if ( null === $this->gateway_instance ) {
			$registered = Plugin::instance()->gateway();
			$this->gateway_instance = $registered instanceof Gateway ? $registered : new Gateway();
		}
		return $this->gateway_instance;
	}

	/**
	 * Availability mirrors the gateway.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway()->is_available();
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
		$gateway = $this->gateway();
		return array(
			'title'       => $this->get_setting( 'title', __( 'VezmoPay', 'vezmopay-woocommerce' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'icon'        => VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg',
			'testMode'    => $gateway->is_test_mode(),
			'supports'    => array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ),
		);
	}
}
