<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * Element and iframe modes render the VezmoPay form INSIDE this payment method and
 * charge it after the Store API has created the order (see blocks.js's InlineContent
 * and window.VezmoPayInline) — the shopper never leaves the checkout. Only hosted mode
 * is a "choose and continue" tile, where process_payment() redirects server-side.
 * No client-side tokenization happens either way: VezmoPay's embed owns the card
 * fields and the charge, so the plugin never handles card data.
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
			Plugin::asset_version( 'assets/js/blocks.js' ),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'vezmopay-blocks', 'vezmopay-woocommerce', VEZMOPAY_WC_PLUGIN_DIR . 'languages' );
		}
		// The Blocks tile drives the same session/charge machinery as the classic
		// checkout, so its script must be present before ours runs.
		$handles = array( 'vezmopay-blocks' );
		// …but NOT on the pay page or the order-received page. WooCommerce loads
		// a payment method's script handles on those endpoints too, which put the
		// checkout-page driver on a page that has its own (checkout-element.js /
		// checkout-iframe.js): two pollers and two message areas, one of them
		// idle. Plugin::enqueue_checkout_scripts() already excludes those
		// endpoints; this is the other way in.
		$pay_page = function_exists( 'is_wc_endpoint_url' )
			&& ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) );
		if ( ! $pay_page && 'hosted' !== $this->gateway()->integration_mode() ) {
			Plugin::register_checkout_inline_script();
			if ( wp_script_is( 'vezmopay-checkout-inline', 'registered' ) ) {
				$handles[] = 'vezmopay-checkout-inline';
			}
		}
		return $handles;
	}

	/**
	 * Data exposed to the Blocks client.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		return array(
			'mode'        => $gateway->integration_mode(),
			'title'       => $this->get_setting( 'title', __( 'VezmoPay', 'vezmopay-woocommerce' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'icon'        => VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg',
			'testMode'    => $gateway->is_test_mode(),
			'supports'    => array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ),
		);
	}
}
