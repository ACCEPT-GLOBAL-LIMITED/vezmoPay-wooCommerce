<?php
/**
 * Gateway settings definition (init_form_fields source).
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Static provider of the gateway's form fields and constant defaults.
 */
class Settings {

	/**
	 * Default API hosts per environment (see docs/VEZMOPAY-API-CONTRACT.md).
	 */
	const DEFAULT_LIVE_API      = 'https://api.vezmo.com';
	const DEFAULT_TEST_API      = 'https://api.dev.vezmo.com';
	// Merchant app host (serves the hosted checkout + the Connect consent page).
	// NOTE: dev.vezmo.com is the marketing site; the merchant app dev host is
	// user.dev.vezmo.com (confirmed against the API CORS allowlist).
	const DEFAULT_LIVE_CHECKOUT = 'https://user.vezmo.com';
	const DEFAULT_TEST_CHECKOUT = 'https://user.dev.vezmo.com';

	/**
	 * Currencies Stripe treats as zero-decimal. The VezmoPay platform multiplies all
	 * amounts by 100 unconditionally, which corrupts these — the gateway refuses them.
	 *
	 * @var string[]
	 */
	const ZERO_DECIMAL_CURRENCIES = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Build the WooCommerce settings fields.
	 *
	 * @param string $webhook_url The store's webhook receiver URL (read-only display).
	 * @return array
	 */
	public static function form_fields( $webhook_url ) {
		return array(
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'vezmopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable VezmoPay', 'vezmopay-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'vezmopay-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method title shown to customers at checkout.', 'vezmopay-woocommerce' ),
				'default'     => __( 'VezmoPay', 'vezmopay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'vezmopay-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers at checkout.', 'vezmopay-woocommerce' ),
				'default'     => __( 'Pay securely by card or US bank account via VezmoPay.', 'vezmopay-woocommerce' ),
				'desc_tip'    => true,
			),
			'descriptor'          => array(
				'title'             => __( 'Transaction label', 'vezmopay-woocommerce' ),
				'type'              => 'safe_text',
				'description'       => __(
					'Shown on your VezmoPay transactions so you can tell which of your sites a payment came from — useful when one VezmoPay account takes payments from several stores. Never shown to customers and never sent to the card networks. Leave blank to fall back to your site name.',
					'vezmopay-woocommerce'
				),
				'default'           => '',
				'placeholder'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'custom_attributes' => array( 'maxlength' => 64 ),
				'desc_tip'          => true,
			),
			'integration_mode'    => array(
				'title'       => __( 'Integration mode', 'vezmopay-woocommerce' ),
				'type'        => 'select',
				'default'     => 'element',
				'options'     => array(
					'element' => __( 'Inline payment element (vezmo.js on your pay page)', 'vezmopay-woocommerce' ),
					'iframe'  => __( 'Secure iframe (embedded on your pay page)', 'vezmopay-woocommerce' ),
					'hosted'  => __( 'Hosted checkout (redirect to the VezmoPay paylink page)', 'vezmopay-woocommerce' ),
				),
				'description' => __( 'Inline and iframe both keep the customer on your own pay page — inline lets the VezmoPay SDK drive the form (auto-sizing and payment events), iframe embeds the same page and confirms by polling. Card fields are VezmoPay-hosted either way, keeping you at SAQ-A PCI scope. Each mode falls back on its own when it cannot run: inline drops to the iframe if the SDK is unavailable, and both send the shopper to the VezmoPay secure page — never an empty frame — until this store is one of your VezmoPay trusted origins, which Connect registers automatically. Hosted always redirects, to a VezmoPay paylink page.', 'vezmopay-woocommerce' ),
			),
			'force_hosted'        => array(
				'title'       => __( 'Hosted checkout override', 'vezmopay-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'label'       => __( 'My VezmoPay account is activated for payment links', 'vezmopay-woocommerce' ),
				'description' => __( 'VezmoPay cannot yet confirm this automatically, so hosted checkout otherwise falls back to the embedded payment form. Tick this only if payment links already work on your account — if they do not, customers will be sent to a page where they cannot pay and the order will sit unpaid. Only applies to Hosted checkout.', 'vezmopay-woocommerce' ),
			),
			'environment'         => array(
				'title'       => __( 'Environment', 'vezmopay-woocommerce' ),
				'type'        => 'select',
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test', 'vezmopay-woocommerce' ),
					'live' => __( 'Live', 'vezmopay-woocommerce' ),
				),
				'description' => __( 'VezmoPay marks keys as Test or Live when you create them in the dashboard. Use a Test-environment key here while testing.', 'vezmopay-woocommerce' ),
			),
			'checkout_theme'      => array(
				'title'       => __( 'Checkout appearance', 'vezmopay-woocommerce' ),
				'type'        => 'select',
				'default'     => 'light',
				'options'     => array(
					'light' => __( 'Light', 'vezmopay-woocommerce' ),
					'dark'  => __( 'Dark', 'vezmopay-woocommerce' ),
					'auto'  => __( 'Auto (match the shopper\'s device)', 'vezmopay-woocommerce' ),
				),
				'description' => __( 'Theme for the VezmoPay payment page frame (header, footer, background and messages). The secure card form itself is hosted by VezmoPay and always renders in light mode. Applies to the inline element and iframe modes.', 'vezmopay-woocommerce' ),
			),
			'connection'          => array(
				'title'       => __( 'Connection', 'vezmopay-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Recommended: click "Connect with VezmoPay" to log in and have your API credentials created and saved automatically. Or create an API key manually in the VezmoPay dashboard (Settings → API Keys) with the secure-payment.create, paylink.create, paylink.read, payment.read, account.read and account.update permissions and paste it below. Either way, creating a new key deactivates your previous key.', 'vezmopay-woocommerce' )
					. '<br />'
					. sprintf(
						/* translators: 1: card rate, 2: ACH rate, 3: opening anchor tag to the pricing page, 4: closing anchor tag */
						__( 'Pricing: %1$s per successful card charge, %2$s per bank (ACH) payment, capped at $10. No monthly fee, no setup fee, no minimum. %3$sSee full VezmoPay pricing%4$s.', 'vezmopay-woocommerce' ),
						'<strong>2.79% + $0.29</strong>',
						'<strong>0.9% + $1.00</strong>',
						'<a href="https://vezmo.com/pricing/vezmopay" target="_blank" rel="noopener noreferrer">',
						'</a>'
					),
			),
			'connect'             => array(
				'title' => __( 'Connect', 'vezmopay-woocommerce' ),
				'type'  => 'vezmopay_connect',
			),
			'test_api_key'        => array(
				'title'             => __( 'Test API key', 'vezmopay-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'description'       => __( 'Starts with vzm_. Can also be set via the VEZMOPAY_TEST_API_KEY constant in wp-config.php.', 'vezmopay-woocommerce' ),
			),
			'test_api_secret'     => array(
				'title'             => __( 'Test API secret', 'vezmopay-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'description'       => __( 'Can also be set via the VEZMOPAY_TEST_API_SECRET constant in wp-config.php.', 'vezmopay-woocommerce' ),
			),
			'live_api_key'        => array(
				'title'             => __( 'Live API key', 'vezmopay-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'description'       => __( 'Starts with vzm_. Can also be set via the VEZMOPAY_LIVE_API_KEY constant in wp-config.php.', 'vezmopay-woocommerce' ),
			),
			'live_api_secret'     => array(
				'title'             => __( 'Live API secret', 'vezmopay-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'description'       => __( 'Can also be set via the VEZMOPAY_LIVE_API_SECRET constant in wp-config.php.', 'vezmopay-woocommerce' ),
			),
			'test_connection'     => array(
				'title'       => __( 'Test connection', 'vezmopay-woocommerce' ),
				'type'        => 'vezmopay_test_connection',
				'description' => '',
			),
			'account_settings'    => array(
				'title'       => __( 'VezmoPay account settings', 'vezmopay-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'These control your VezmoPay account itself — the same switches as your VezmoPay console. Changes apply everywhere you charge customers (this store, payment links, invoices), immediately.', 'vezmopay-woocommerce' ),
			),
			'account_panel'       => array(
				'title' => '',
				'type'  => 'vezmopay_account',
			),
			'advanced'            => array(
				'title'       => __( 'Advanced', 'vezmopay-woocommerce' ),
				'type'        => 'title',
				'description' => '',
			),
			'test_api_base'       => array(
				'title'       => __( 'Test API base URL', 'vezmopay-woocommerce' ),
				'type'        => 'url',
				'default'     => self::DEFAULT_TEST_API,
				'custom_attributes' => array( 'pattern' => 'https://.*' ),
				'description' => __( 'Only change this if VezmoPay gives you a different host.', 'vezmopay-woocommerce' ),
				'desc_tip'    => true,
			),
			'live_api_base'       => array(
				'title'    => __( 'Live API base URL', 'vezmopay-woocommerce' ),
				'type'     => 'url',
				'default'  => self::DEFAULT_LIVE_API,
				'custom_attributes' => array( 'pattern' => 'https://.*' ),
				'desc_tip' => true,
			),
			'test_checkout_base'  => array(
				'title'       => __( 'Test hosted checkout URL', 'vezmopay-woocommerce' ),
				'type'        => 'url',
				'default'     => self::DEFAULT_TEST_CHECKOUT,
				'description' => __( 'Host of the VezmoPay hosted payment page (hosted mode only).', 'vezmopay-woocommerce' ),
				'desc_tip'    => true,
			),
			'live_checkout_base'  => array(
				'title'    => __( 'Live hosted checkout URL', 'vezmopay-woocommerce' ),
				'type'     => 'url',
				'default'  => self::DEFAULT_LIVE_CHECKOUT,
				'desc_tip' => true,
			),
			'webhook'             => array(
				'title'       => __( 'Webhooks', 'vezmopay-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'When you use "Connect with VezmoPay", your webhook (URL %s, events payment.success / payment.failed) is registered automatically and its secret is filled in below. For manual setup, register that endpoint in your VezmoPay dashboard and paste the whsec_ secret (shown once). Either way, webhooks drive order completion — and the plugin independently re-verifies every event against the VezmoPay API before updating an order.', 'vezmopay-woocommerce' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				),
			),
			'webhook_secret'      => array(
				'title'             => __( 'Webhook secret', 'vezmopay-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'description'       => __( 'Filled in automatically when you use "Connect with VezmoPay". Only needed for manual setup: paste the whsec_… value shown once when you register a webhook in the VezmoPay dashboard.', 'vezmopay-woocommerce' ),
			),
			'debug'               => array(
				'title'       => __( 'Debug logging', 'vezmopay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API requests and webhook activity (secrets are redacted)', 'vezmopay-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'View logs under WooCommerce → Status → Logs (source: vezmopay).', 'vezmopay-woocommerce' ),
			),
		);
	}
}
