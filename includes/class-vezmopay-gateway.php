<?php
/**
 * The VezmoPay payment gateway.
 *
 * Three integration modes (setting-selectable):
 *  - element: creates a VezmoPay secure payment, renders the vezmo.js SDK on the pay page
 *             (VezmoPay-hosted iframe + postMessage events, polling fallback).
 *  - iframe:  same secure payment, raw iframe embed, reconciliation by status polling.
 *  - hosted:  creates a VezmoPay paylink and redirects the customer to the VezmoPay
 *             hosted checkout page; the order is completed via webhook (the platform
 *             has no return-URL support — see docs/VEZMOPAY-API-CONTRACT.md).
 *
 * In every mode card data is entered on VezmoPay-hosted surfaces only (SAQ-A scope);
 * 3-D Secure/SCA is handled inside VezmoPay's Stripe Payment Element.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Payment_Gateway implementation for VezmoPay.
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * Secure-payment token lifetime requested from the API, in minutes (range 5–1440).
	 */
	const TOKEN_TTL_MINUTES = 60;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Orders already read from the API this request, keyed by id.
	 *
	 * @var array<int,bool>
	 */
	private $reconciled = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Plugin::GATEWAY_ID;
		$this->icon               = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay-icon.png';
		$this->method_title       = __( 'VezmoPay', 'vezmopay-woocommerce' );
		$this->method_description = __( 'Accept payments through VezmoPay — hosted checkout, inline payment element, or secure iframe. Card data never touches your server.', 'vezmopay-woocommerce' );
		// 'refunds' is declared so WooCommerce actually CALLS process_refund(),
		// which explains that VezmoPay has no refund API. Without it the method
		// was unreachable and the limitation was silently absent — the merchant
		// saw no refund control and no reason why.
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->logger      = new Logger( 'yes' === $this->get_option( 'debug' ) );

		// AFTER init_settings(). get_option() reads $this->settings, which
		// init_settings() populates — computing this above it meant
		// integration_mode() always saw the default 'element', so has_fields was
		// always true and WooCommerce asked hosted mode for payment fields it
		// has none of. Nothing above this line may call get_option().
		//
		// Inline and iframe render the VezmoPay form in the payment box on the
		// checkout page itself, the way Stripe's plugin does, so WooCommerce must
		// ask us for fields. Hosted mode has nothing to show there.
		$this->has_fields = 'hosted' !== $this->integration_mode();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		// A saved change of mode, credentials or the hosted override must re-ask,
		// not wait out the TTL.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function () {
				delete_transient( 'vezmopay_paylink_capable_test' );
				delete_transient( 'vezmopay_paylink_capable_live' );
			},
			20
		);
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = Settings::form_fields( Webhook::url() );
	}

	/**
	 * Logger accessor (used by the webhook controller).
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Cart-time payment session helper.
	 *
	 * @return Checkout_Session
	 */
	public function checkout_session() {
		return new Checkout_Session( $this );
	}

	/**
	 * The VezmoPay form, rendered inside WooCommerce's payment box on the
	 * checkout page. The session (and therefore the frame) is created by
	 * assets/js/checkout-inline.js over AJAX, so an abandoned checkout mints
	 * nothing until the shopper actually picks VezmoPay.
	 */
	public function payment_fields() {
		// Belt and braces: hosted mode has no embedded form, and the inline script
		// is deliberately not enqueued for it — so rendering the scaffolding here
		// would leave a "Loading secure payment fields…" spinner that nothing
		// ever resolves. Guard it here too, so a wrong has_fields cannot produce
		// that again.
		if ( 'hosted' === $this->integration_mode() ) {
			$description = $this->get_description();
			if ( $description ) {
				echo '<p class="vezmopay-inline-description">' . wp_kses_post( wpautop( wptexturize( $description ) ) ) . '</p>';
			}
			return;
		}

		$description = $this->get_description();
		if ( $description ) {
			echo '<p class="vezmopay-inline-description">' . wp_kses_post( wpautop( wptexturize( $description ) ) ) . '</p>';
		}
		if ( $this->is_test_mode() ) {
			echo '<p class="vezmopay-inline-test">' . esc_html__( 'Test mode — no real money will move.', 'vezmopay-woocommerce' ) . '</p>';
		}

		echo '<div id="vezmopay-inline" class="vezmopay-inline" data-mode="' . esc_attr( $this->integration_mode() ) . '" data-theme="' . esc_attr( $this->checkout_theme() ) . '">';
		echo '<div class="vezmopay-inline-loading"><span class="vezmopay-spinner"></span>' . esc_html__( 'Loading secure payment fields…', 'vezmopay-woocommerce' ) . '</div>';
		echo '<div id="vezmopay-inline-container" class="vezmopay-inline-container"></div>';
		// The embedded form hides its own submit button, so give the shopper one
		// here, beside the fields. It places the order — the same thing
		// WooCommerce's "Place order" button does.
		echo '<button type="button" class="vezmopay-inline-pay" hidden>';
		echo '<span class="vezmopay-inline-pay-label">' . esc_html__( 'Pay', 'vezmopay-woocommerce' ) . '</span>';
		echo '</button>';
		echo '<p id="vezmopay-inline-message" class="vezmopay-inline-message" role="status" aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Checkout icon. Renders the mark before the title on the classic checkout
	 * payment-method row and vertically centres both. The scoped <style> ships
	 * with the icon markup so no extra stylesheet has to be enqueued on checkout.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = sprintf(
			'<img src="%1$s" alt="%2$s" class="vezmopay-checkout-icon" />',
			esc_url( $this->icon ),
			esc_attr( $this->get_title() )
		);

		$style = '<style>'
			. '.wc_payment_method.payment_method_' . esc_attr( $this->id ) . ' > label{'
			. 'display:flex;align-items:center;gap:8px;font-weight:600;}'
			. '.wc_payment_method.payment_method_' . esc_attr( $this->id ) . ' > label img.vezmopay-checkout-icon{'
			. 'order:-1;max-height:42px;width:auto;margin:0;float:none;}'
			. '</style>';

		return apply_filters( 'woocommerce_gateway_icon', $icon . $style, $this->id );
	}

	/* ---------------------------------------------------------------------
	 * Configuration helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Selected environment.
	 *
	 * @return string 'test'|'live'
	 */
	public function environment() {
		return 'live' === $this->get_option( 'environment' ) ? 'live' : 'test';
	}

	/**
	 * The merchant's own label for this store, sent with every payment session so
	 * their VezmoPay transactions say WHICH site the money came from. Empty when
	 * unset — the API then falls back to the session title, which already carries
	 * the site name, so a blank setting is never worse than no setting.
	 *
	 * Capped to the 64 characters the API accepts, so an over-long label is
	 * shortened here rather than failing the session-create request.
	 *
	 * @return string Label, or '' when the merchant set none.
	 */
	public function descriptor() {
		$value = trim( (string) $this->get_option( 'descriptor', '' ) );
		if ( '' === $value ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 64 ) : substr( $value, 0, 64 );
	}

	/**
	 * Whether the gateway runs in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'live' !== $this->environment();
	}

	/**
	 * Selected integration mode.
	 *
	 * 'element' drives the embed with vezmo.js, 'iframe' embeds the same page and
	 * polls, 'hosted' redirects. Each degrades on its own where it cannot run —
	 * see render_embedded_checkout() and receipt_page(). The 0.2.13-only
	 * 'embedded' value normalises to 'element', the richer of the two embeds.
	 *
	 * @return string 'element'|'iframe'|'hosted'
	 */
	public function integration_mode() {
		$mode = $this->get_option( 'integration_mode', 'element' );
		if ( 'embedded' === $mode ) {
			$mode = 'element';
		}
		if ( ! in_array( $mode, array( 'element', 'iframe', 'hosted' ), true ) ) {
			$mode = 'element';
		}

		// Hosted mode is a one-way trip: the order is created, the cart emptied,
		// stock reduced and the shopper redirected. An account that cannot take a
		// paylink payment sends them to "No payment method available" and the
		// order strands behind a webhook that never comes — so hosted runs only
		// when the account is CONFIRMED capable. Cache only: checkout must never
		// wait on an API call to pick a mode.
		$capable = get_transient( $this->capability_key() );

		// The merchant's own override, because the only people who can answer
		// "is this account activated?" today are merchants, and a settings
		// checkbox is something a store owner can actually reach — a PHP filter
		// in a child theme is not.
		$forced = 'yes' === $this->get_option( 'force_hosted', 'no' );

		/**
		 * Force hosted checkout on when the platform cannot yet confirm the account
		 * is activated to accept payment-link payments. Defaults to the merchant's
		 * "Hosted checkout override" setting.
		 *
		 * @param bool $force Whether to run hosted mode regardless.
		 */
		$forced = (bool) apply_filters( 'vezmopay_force_hosted_mode', $forced );

		if ( 'hosted' === $mode && '1' !== $capable && ! $forced ) {
			return 'element';
		}
		return $mode;
	}

	/**
	 * Transient key for the paylink capability answer.
	 *
	 * @return string
	 */
	private function capability_key() {
		return 'vezmopay_paylink_capable_' . $this->environment();
	}

	/**
	 * The mode the merchant actually chose, before any capability downgrade.
	 *
	 * @return string
	 */
	public function configured_mode() {
		$mode = $this->get_option( 'integration_mode', 'element' );
		if ( 'embedded' === $mode ) {
			return 'element';
		}
		return in_array( $mode, array( 'element', 'iframe', 'hosted' ), true ) ? $mode : 'element';
	}

	/**
	 * Selected checkout theme for the pay-page frame.
	 *
	 * @return string 'light'|'dark'|'auto'
	 */
	public function checkout_theme() {
		$theme = $this->get_option( 'checkout_theme', 'light' );
		return in_array( $theme, array( 'light', 'dark', 'auto' ), true ) ? $theme : 'light';
	}

	/**
	 * Credential for an environment, preferring wp-config constants over saved options
	 * so secrets can be kept out of the database entirely.
	 *
	 * @param string $environment 'test'|'live'.
	 * @param string $which       'key'|'secret'.
	 * @return string
	 */
	private function credential( $environment, $which ) {
		$constant = 'VEZMOPAY_' . strtoupper( $environment ) . '_API_' . strtoupper( $which );
		if ( defined( $constant ) && '' !== constant( $constant ) ) {
			return (string) constant( $constant );
		}
		return (string) $this->get_option( $environment . '_api_' . $which );
	}

	/**
	 * Build an API client for an environment.
	 *
	 * @param string|null $environment 'test'|'live'|null for the active one.
	 * @return Api_Client
	 */
	public function api_client( $environment = null ) {
		$environment = in_array( $environment, array( 'test', 'live' ), true ) ? $environment : $this->environment();
		$default     = 'live' === $environment ? Settings::DEFAULT_LIVE_API : Settings::DEFAULT_TEST_API;
		$base        = $this->validated_api_base( (string) $this->get_option( $environment . '_api_base', $default ), $default );
		return new Api_Client( $base, $this->credential( $environment, 'key' ), $this->credential( $environment, 'secret' ), $environment, $this->logger );
	}

	/**
	 * Keep the API base to https on a VezmoPay host.
	 *
	 * These are free-text settings fields, and the secure-payment clientToken is
	 * sent in a URL PATH to whatever host they name — so a mistyped or tampered
	 * value leaks a payment credential to a third party in plain sight. A base
	 * that fails the check falls back to the shipped default rather than being
	 * used. Self-hosted deployments can allow their own host through the filter.
	 *
	 * @param string $base    Configured base.
	 * @param string $default Shipped default for this environment.
	 * @return string
	 */
	private function validated_api_base( $base, $default ) {
		return $this->validated_vezmo_base( $base, $default, 'API base' );
	}

	/**
	 * Keep a configured VezmoPay base URL to https on a VezmoPay host.
	 *
	 * Governs BOTH bases, because both name the same vendor and both are now
	 * security-load-bearing: the API base carries the secure-payment clientToken
	 * in a URL path, and the checkout base became the first entry in the set of
	 * origins allowed to report a payment outcome (and the default target for
	 * outbound postMessage) when 0.3.2 started deriving checkout_origin() from
	 * it. A base that fails the check falls back to the shipped default rather
	 * than being used. Self-hosted deployments allow their own host through the
	 * filter.
	 *
	 * @param string $base    Configured base.
	 * @param string $default Shipped default for this environment.
	 * @param string $context Human label for the log line ('API base', 'checkout base').
	 * @return string
	 */
	private function validated_vezmo_base( $base, $default, $context ) {
		$base = untrailingslashit( trim( (string) $base ) );
		if ( '' === $base ) {
			return $default;
		}

		/**
		 * Host suffixes a VezmoPay base URL may use — API and checkout alike. Add
		 * your own for a self-hosted deployment; https is required regardless.
		 *
		 * @param string[] $suffixes Allowed host suffixes.
		 */
		$allowed = (array) apply_filters( 'vezmopay_allowed_api_hosts', array( 'vezmo.com' ) );

		if ( ! self::host_matches_allowed( $base, $allowed ) ) {
			$this->logger->error(
				'Ignoring ' . $context . ' "' . $base . '": it must be an https URL on an allowed VezmoPay host. Using ' . $default . ' instead.'
			);
			return $default;
		}
		return $base;
	}

	/**
	 * Whether a URL is https on one of the allowed host suffixes.
	 *
	 * Static so the settings-field validators can reuse it without a gateway
	 * instance, and so the same comparison decides both save-time rejection and
	 * runtime fallback.
	 *
	 * @param string   $url     URL to check.
	 * @param string[] $allowed Allowed host suffixes.
	 * @return bool
	 */
	public static function host_matches_allowed( $url, array $allowed ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}
		foreach ( $allowed as $suffix ) {
			$suffix = strtolower( ltrim( (string) $suffix, '.' ) );
			if ( '' === $suffix ) {
				continue;
			}
			if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hosted checkout / dashboard app base for the active environment
	 * (also hosts the Connect-with-VezmoPay consent page).
	 *
	 * @return string
	 */
	public function checkout_base() {
		$environment = $this->environment();
		$default     = 'live' === $environment ? Settings::DEFAULT_LIVE_CHECKOUT : Settings::DEFAULT_TEST_CHECKOUT;
		$base        = untrailingslashit( (string) $this->get_option( $environment . '_checkout_base', $default ) );

		// Self-heal installs that persisted the earlier wrong default: dev.vezmo.com
		// is the marketing site, not the merchant app — the app dev host is
		// user.dev.vezmo.com. Never a legitimate checkout host, so safe to correct.
		// Runs BEFORE validation, so a self-healed value is still checked.
		if ( 'https://dev.vezmo.com' === $base || 'http://dev.vezmo.com' === $base ) {
			$base = Settings::DEFAULT_TEST_CHECKOUT;
		}

		// Same allow-list as the API base. This value decides which origin may
		// report a payment outcome to the checkout page, so it cannot be the one
		// setting that goes unchecked.
		return $this->validated_vezmo_base( $base, $default, 'checkout base' );
	}

	/**
	 * Transient TTL for the trusted-origin lookup.
	 */
	const EMBED_CHECK_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Transient TTL for the account capability lookup behind hosted mode.
	 */
	const CAPABILITY_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * This store's origin, in the form VezmoPay stores trusted origins as.
	 *
	 * @return string e.g. https://shop.example (no path, no trailing slash).
	 */
	private function store_origin() {
		$parts = wp_parse_url( home_url() );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Scheme + host (+ port) of a URL, for postMessage targeting.
	 *
	 * @param string $url URL.
	 * @return string Origin, or '' when unparsable.
	 */
	private function url_origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Whether VezmoPay will actually let this store embed the secure payment page.
	 *
	 * The platform serves that page with a per-merchant `frame-ancestors` CSP built
	 * from the merchant's trusted origins, defaulting to 'none' — so embedding from
	 * an unregistered origin renders a blank frame, and even the confirm call is
	 * origin-checked. Rather than gamble and show the shopper an empty box, ask the
	 * platform's own public frame-ancestors endpoint whether this origin is on the
	 * list, and embed only if it is.
	 *
	 * Deliberately fail-CLOSED (redirect instead of embed) on any error, timeout or
	 * empty list: a redirect always completes a payment, a blocked iframe never does.
	 *
	 * @param \WC_Order $order Order carrying the secure-payment client token.
	 * @return bool
	 */
	public function embed_allowed( $order ) {
		$origin = $this->store_origin();
		$token  = (string) $order->get_meta( '_vezmopay_client_token' );
		if ( '' === $origin || '' === $token ) {
			return false;
		}

		$cache_key = 'vezmopay_embed_ok_' . $this->environment() . '_' . md5( $origin );
		$cached    = get_transient( $cache_key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}

		$response = wp_remote_get(
			$this->api_client()->host() . '/api/v1/secure-payments/' . rawurlencode( $token ) . '/frame-ancestors',
			array(
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
			)
		);

		$allowed = false;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			// The endpoint returns { origins: [...] }; tolerate the API's
			// { data: { origins } } success envelope too.
			$origins = array();
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['origins'] ) && is_array( $decoded['origins'] ) ) {
					$origins = $decoded['origins'];
				} elseif ( isset( $decoded['data']['origins'] ) && is_array( $decoded['data']['origins'] ) ) {
					$origins = $decoded['data']['origins'];
				}
			}
			foreach ( $origins as $candidate ) {
				if ( strtolower( untrailingslashit( (string) $candidate ) ) === $origin ) {
					$allowed = true;
					break;
				}
			}
		}

		set_transient( $cache_key, $allowed ? '1' : '0', self::EMBED_CHECK_TTL );
		if ( ! $allowed ) {
			$this->logger->debug( 'Embedded checkout disabled: ' . $origin . ' is not a VezmoPay trusted origin.' );
		}
		return $allowed;
	}

	/**
	 * Reject a bad checkout base at the settings screen instead of silently
	 * falling back at runtime.
	 *
	 * WooCommerce calls validate_{key}_field() on save and surfaces a thrown
	 * exception as an admin error, keeping the previous stored value.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 * @throws \Exception When the value is not an https URL on an allowed host.
	 */
	public function validate_test_checkout_base_field( $key, $value ) {
		return $this->validate_checkout_base_field( $key, $value, Settings::DEFAULT_TEST_CHECKOUT );
	}

	/**
	 * Live counterpart of validate_test_checkout_base_field().
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 * @throws \Exception When the value is not an https URL on an allowed host.
	 */
	public function validate_live_checkout_base_field( $key, $value ) {
		return $this->validate_checkout_base_field( $key, $value, Settings::DEFAULT_LIVE_CHECKOUT );
	}

	/**
	 * Shared checkout-base validation for both environments.
	 *
	 * @param string $key      Field key.
	 * @param string $value    Submitted value.
	 * @param string $fallback Shipped default, shown in the error message.
	 * @return string
	 * @throws \Exception When the value is not an https URL on an allowed host.
	 */
	private function validate_checkout_base_field( $key, $value, $fallback ) {
		$clean = untrailingslashit( trim( (string) $this->validate_text_field( $key, $value ) ) );
		if ( '' === $clean ) {
			return '';
		}

		/** This filter is documented in validated_vezmo_base(). */
		$allowed = (array) apply_filters( 'vezmopay_allowed_api_hosts', array( 'vezmo.com' ) );
		if ( ! self::host_matches_allowed( $clean, $allowed ) ) {
			throw new \Exception(
				esc_html(
					sprintf(
						/* translators: 1: submitted URL, 2: shipped default URL */
						__( '“%1$s” is not a valid VezmoPay checkout URL. It must be an https address on a VezmoPay host (for example %2$s). This address decides which origin may report a payment result to your checkout, so it was not saved.', 'vezmopay-woocommerce' ),
						$clean,
						$fallback
					)
				)
			);
		}
		return $clean;
	}

	/**
	 * Origin of the Vezmo-hosted checkout, which is where an embedded frame ends
	 * up: its src is the API origin and the API redirects it here.
	 *
	 * @return string Origin, or '' when the configured base is unparsable.
	 */
	public function checkout_origin() {
		return $this->url_origin( $this->checkout_base() );
	}

	/**
	 * Availability: configured credentials and a currency the platform handles correctly.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! $this->api_client()->is_configured() ) {
			return false;
		}
		// The VezmoPay platform converts amounts with an unconditional ×100, which
		// corrupts zero-decimal currencies — refuse to offer the gateway for them.
		if ( in_array( get_woocommerce_currency(), Settings::ZERO_DECIMAL_CURRENCIES, true ) ) {
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Admin.
	 * ------------------------------------------------------------------- */

	/**
	 * Form fields, minus anything that does not apply to this configuration.
	 *
	 * Filtering here rather than in admin_options() means a hidden field is
	 * neither rendered NOR processed on save, so its stored value survives —
	 * unsetting it at render time only would have let the next save read the
	 * absent checkbox as "no" and silently drop a merchant's override.
	 *
	 * @return array
	 */
	public function get_form_fields() {
		$fields = parent::get_form_fields();
		if ( isset( $fields['force_hosted'] ) && ! $this->show_force_hosted() ) {
			unset( $fields['force_hosted'] );
		}
		return $fields;
	}

	/**
	 * Whether the hosted-checkout override is worth showing: hosted is the
	 * configured mode, and the platform has not confirmed the account can take
	 * payment-link payments.
	 *
	 * @return bool
	 */
	private function show_force_hosted() {
		return 'hosted' === $this->configured_mode() && '1' !== get_transient( $this->capability_key() );
	}

	/**
	 * Settings screen with an unmistakable environment banner.
	 */
	public function admin_options() {
		$test = $this->is_test_mode();

		echo '<div class="vezmopay-admin">';

		// Branded pill-banner hero: the VezmoPay lockup on a lavender pill,
		// a short tagline, and the current-environment chip.
		echo '<header class="vezmopay-admin-hero">';
		printf(
			'<span class="vezmopay-admin-brandpill"><img src="%s" alt="%s" /></span>',
			esc_url( VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay.svg' ),
			esc_attr__( 'VezmoPay', 'vezmopay-woocommerce' )
		);
		echo '<p class="vezmopay-admin-tagline">' . esc_html__( 'Modern payments for your WooCommerce store.', 'vezmopay-woocommerce' ) . '</p>';
		printf(
			'<span class="vezmopay-admin-envchip %1$s">%2$s</span>',
			$test ? 'is-test' : 'is-live',
			$test ? esc_html__( 'Test mode', 'vezmopay-woocommerce' ) : esc_html__( 'Live mode', 'vezmopay-woocommerce' )
		);
		echo '</header>';

		// Status notices — the scoped stylesheet renders each as a card.
		echo '<div class="vezmopay-admin-notices">';
		Connect::maybe_render_connect_notices( $this );
		if ( $test ) {
			echo '<div class="notice notice-warning inline"><p><strong>';
			echo esc_html__( 'VezmoPay is in TEST mode.', 'vezmopay-woocommerce' );
			echo '</strong> ';
			echo esc_html__( 'No real money will move. Switch the Environment setting to Live when you are ready.', 'vezmopay-woocommerce' );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-info inline"><p><strong>';
			echo esc_html__( 'VezmoPay is in LIVE mode.', 'vezmopay-woocommerce' );
			echo '</strong></p></div>';
		}
		if ( in_array( get_woocommerce_currency(), Settings::ZERO_DECIMAL_CURRENCIES, true ) ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'Your store currency is a zero-decimal currency (e.g. JPY, KRW). VezmoPay does not currently handle these correctly, so the gateway will not be offered at checkout.', 'vezmopay-woocommerce' );
			echo '</p></div>';
		}

		// Hosted mode selected but the account cannot take a paylink payment: this
		// is the one check worth an API call, because the alternative is orders
		// that strand. Warmed here so checkout only ever reads the cache.
		if ( 'hosted' === $this->configured_mode() && ! $this->paylink_capable( true ) ) {
			echo '<div class="notice notice-error inline"><p><strong>';
			echo esc_html__( 'Hosted checkout is not active.', 'vezmopay-woocommerce' );
			echo '</strong> ';
			echo esc_html__( 'VezmoPay does not yet report whether this account is activated for payment-link payments, so customers are being served the embedded payment form instead of a page they may not be able to pay on. If payment links already work on your account, tick “My VezmoPay account is activated for payment links” below to use the redirect anyway.', 'vezmopay-woocommerce' );
			echo '</p></div>';
		}

		// Explain a silent downgrade: element/iframe selected, but this origin is
		// not registered with VezmoPay, so shoppers get the redirect instead.
		$embed_reason = $this->embed_downgrade_reason();
		if ( '' !== $embed_reason ) {
			echo '<div class="notice notice-warning inline"><p><strong>';
			echo esc_html__( 'Embedded checkout is not active.', 'vezmopay-woocommerce' );
			echo '</strong> ' . esc_html( $embed_reason ) . '</p></div>';
		}

		// Explain a silent hide: enabled but not appearing at checkout.
		$reason = $this->unavailable_reason();
		if ( '' !== $reason ) {
			echo '<div class="notice notice-warning inline"><p><strong>';
			echo esc_html__( 'VezmoPay will not appear at checkout yet.', 'vezmopay-woocommerce' );
			echo '</strong> ' . esc_html( $reason ) . '</p></div>';
		} elseif ( 'yes' === $this->get_option( 'enabled' ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'VezmoPay is active and will appear at checkout.', 'vezmopay-woocommerce' );
			echo '</p></div>';
		}
		echo '</div>';

		// WooCommerce's own heading + description + settings table, wrapped
		// in a card surface by the scoped stylesheet.
		echo '<div class="vezmopay-admin-fields">';
		parent::admin_options();
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Why element/iframe mode is falling back to the redirect, or '' when it is
	 * not. Read from the cached trusted-origin check, so this costs nothing and
	 * reflects exactly what a shopper would get.
	 *
	 * @return string
	 */
	private function embed_downgrade_reason() {
		if ( 'hosted' === $this->integration_mode() || ! $this->api_client()->is_configured() ) {
			return '';
		}
		$origin = $this->store_origin();
		if ( '' === $origin ) {
			return '';
		}
		// Only speak up once the check has actually run for a real payment —
		// otherwise a freshly connected store is warned about nothing.
		if ( '0' !== get_transient( 'vezmopay_embed_ok_' . $this->environment() . '_' . md5( $origin ) ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: this store's origin, e.g. https://shop.example */
			__( '%s is not one of your VezmoPay trusted origins, so the payment form cannot be embedded and shoppers are sent to the VezmoPay secure page instead. Click "Connect with VezmoPay" above to register this store, then place a test order.', 'vezmopay-woocommerce' ),
			$origin
		);
	}

	/**
	 * Human-readable reason the gateway would be hidden at checkout, or '' when
	 * it will show. Mirrors the checks in is_available() so merchants aren't left
	 * guessing why an enabled gateway is missing.
	 *
	 * @return string
	 */
	private function unavailable_reason() {
		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return __( 'Tick “Enable VezmoPay” above and save.', 'vezmopay-woocommerce' );
		}
		if ( in_array( get_woocommerce_currency(), Settings::ZERO_DECIMAL_CURRENCIES, true ) ) {
			return __( 'Your store currency is not supported by VezmoPay yet.', 'vezmopay-woocommerce' );
		}
		if ( ! $this->api_client()->is_configured() ) {
			return sprintf(
				/* translators: %s: environment name (test/live) */
				__( 'No API credentials are saved for the %s environment. Click “Connect with VezmoPay”, or paste your key and secret, then save.', 'vezmopay-woocommerce' ),
				$this->environment()
			);
		}
		return '';
	}

	/**
	 * Render the "Connect with VezmoPay" settings row.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_vezmopay_connect_html( $key, $data ) {
		$connected = $this->api_client()->is_configured();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<a href="<?php echo esc_url( Connect::connect_url( $this ) ); ?>" class="button button-primary vezmopay-connect-button">
					<img src="<?php echo esc_url( VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg' ); ?>" alt="" aria-hidden="true" />
					<?php esc_html_e( 'Connect with VezmoPay', 'vezmopay-woocommerce' ); ?>
				</a>
				<?php if ( $connected ) : ?>
					<span class="vezmopay-status-pill">
						<?php
						/* translators: %s: environment name */
						echo esc_html( sprintf( __( 'Credentials saved (%s environment)', 'vezmopay-woocommerce' ), $this->environment() ) );
						?>
					</span>
				<?php endif; ?>
				<a href="<?php echo esc_url( $this->checkout_base() . '/vezmopay/activate' ); ?>" class="button vezmopay-prod-button" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Request production access', 'vezmopay-woocommerce' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Log in to your VezmoPay account and your API credentials — and your webhook — are created and filled in automatically. Connecting deactivates any previous API key on your account. Prefer manual setup? Paste a key and secret below instead.', 'vezmopay-woocommerce' ); ?>
					<br />
					<?php esc_html_e( 'Going live: click "Request production access" to complete VezmoPay account verification. Once approved, switch Environment to Live and connect again to get your live credentials.', 'vezmopay-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the live account-settings panel (payment methods + 3-D Secure),
	 * populated by assets/js/admin-account.js from the VezmoPay API, plus a
	 * links card for the console-only settings.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_vezmopay_account_html( $key, $data ) {
		unset( $key, $data );
		$console_url = $this->checkout_base() . '/vezmopay/settings';
		$configured  = $this->api_client()->is_configured();

		ob_start();
		?>
		<tr valign="top">
			<td class="forminp" colspan="2" style="padding-left:0;">
				<div id="vezmopay-account-panel" class="vezmopay-account-panel" data-configured="<?php echo esc_attr( $configured ? '1' : '0' ); ?>">
					<?php if ( ! $configured ) : ?>
						<p class="description"><?php esc_html_e( 'Connect your VezmoPay account (above) to manage these settings here.', 'vezmopay-woocommerce' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="vezmopay-settings-card vezmopay-more-card">
					<div class="vezmopay-settings-card-title"><?php esc_html_e( 'More account settings', 'vezmopay-woocommerce' ); ?></div>
					<p class="description"><?php esc_html_e( 'Account verification, payout bank, reserve, checkout branding, Pre-Dispute Protection, Dispute Auto-Resolution and your fee schedule are managed in your VezmoPay console.', 'vezmopay-woocommerce' ); ?></p>
					<a class="vezmopay-manage-link" href="<?php echo esc_url( $console_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open VezmoPay console settings →', 'vezmopay-woocommerce' ); ?>
					</a>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the custom "Test connection" settings row.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_vezmopay_test_connection_html( $key, $data ) {
		$nonce = wp_create_nonce( 'vezmopay-admin' );
		wc_enqueue_js(
			"jQuery(function($){
				var btn=$('#vezmopay-test-connection');
				btn.on('click', function(e){
					e.preventDefault();
					var out=$('#vezmopay-test-connection-result');
					btn.prop('disabled',true).addClass('is-testing');
					out.hide().removeClass('is-success is-error').empty();
					$.post(ajaxurl,{action:'vezmopay_test_connection',nonce:'" . esc_js( $nonce ) . "',environment:$('#woocommerce_vezmopay_environment').val()},function(r){
						var ok=!!r.success;
						var msg=(r.data&&r.data.message)?r.data.message:(ok?'Connection successful.':'Error');
						out.addClass(ok?'is-success':'is-error').text(msg).show();
					}).fail(function(x){
						var m=(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message)?x.responseJSON.data.message:'Request failed';
						out.addClass('is-error').text(m).show();
					}).always(function(){ btn.prop('disabled',false).removeClass('is-testing'); });
				});
			});"
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button" id="vezmopay-test-connection">
					<?php esc_html_e( 'Test connection', 'vezmopay-woocommerce' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Validates the saved API key and secret for the selected environment against the VezmoPay API. Save your changes first.', 'vezmopay-woocommerce' ); ?>
				</p>
				<div id="vezmopay-test-connection-result" class="vezmopay-test-result" role="status" aria-live="polite"></div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Payment flow.
	 * ------------------------------------------------------------------- */

	/**
	 * Kick off payment. All modes create the provider resource server-side, then redirect.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'vezmopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = (float) $order->get_total();
		if ( $amount < 0.01 || $amount > 1000000 ) {
			wc_add_notice( __( 'This order total cannot be processed by VezmoPay.', 'vezmopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_vezmopay_environment', $this->environment() );
		$order->update_meta_data( '_vezmopay_mode', $this->integration_mode() );

		if ( 'hosted' === $this->integration_mode() ) {
			return $this->process_payment_hosted( $order );
		}

		// Preferred path, and the one that behaves like Stripe's plugin: the
		// shopper filled the VezmoPay form in the payment box, so the payment
		// session already exists. Bind it to the order and hand a marker back to
		// our checkout script, which tells the mounted form to charge and then
		// confirms server-side. No extra page, no second button.
		//
		// bind_to_order() refuses a session whose amount, currency or lifetime no
		// longer matches the order, and with no usable session at all (JS off,
		// blocked, or the shopper never selected the method) this falls through to
		// the pay-page flow below — which still works without JavaScript.
		//
		// A RETRY takes the second branch. bind_to_order() forgets the session
		// once it is bound, so a shopper whose card was declined had no session
		// left and fell through to the pay page — a redirect, and a payment form
		// they had to fill in again. The payment already on the order is still
		// chargeable (a declined card leaves it INITIATED), and the shopper's
		// browser still has that exact form mounted with their details in it, so
		// the right answer is to hand back the same marker and let them charge it
		// again where they are.
		$session = $this->checkout_session();
		if ( $session->bind_to_order( $order ) || $this->can_recharge_bound_payment( $order ) ) {
			// Keep the cart. Every other route hands the shopper to another page,
			// so emptying it there is right; here the shopper stays on the
			// checkout and the charge has not happened yet. Emptying it at this
			// point meant a declined card left them on the checkout with an empty
			// cart, and pressing Place order again answered "Cannot place an
			// order, your cart is empty" — a decline was unrecoverable. The cart
			// is emptied when the payment actually settles (see the confirm and
			// status endpoints) and again by the order-received page.
			$this->await_payment( $order, __( 'Awaiting VezmoPay payment on the checkout page.', 'vezmopay-woocommerce' ), false );
			$order->update_meta_data( '_vezmopay_effective_mode', $this->integration_mode() . '-inline' );
			$order->save_meta_data();

			// Everything the checkout script needs, including the REAL success and
			// pay-page URLs. Building those in the browser meant assuming pretty
			// permalinks and English endpoint slugs; WooCommerce knows them.
			$marker = rtrim(
				strtr(
					base64_encode(
						wp_json_encode(
							array(
								'id'  => $order->get_id(),
								'key' => $order->get_order_key(),
								'ret' => $this->get_return_url( $order ),
								'pay' => $order->get_checkout_payment_url( true ),
							)
						)
					),
					'+/',
					'-_'
				),
				'='
			);

			return array(
				'result'   => 'success',
				// Hash-only: WooCommerce assigns it to window.location, which fires
				// hashchange without navigating, and checkout-inline.js picks it up.
				'redirect' => '#vezmopay-charge:' . $marker,
			);
		}

		$result = $this->ensure_secure_payment( $order );
		if ( is_wp_error( $result ) ) {
			$this->handle_start_failure( $order, $result );
			return array( 'result' => 'failure' );
		}

		// Element and iframe modes keep the shopper on the store's own pay page,
		// where receipt_page() mounts the VezmoPay form. That only works when this
		// store's origin is one of the merchant's VezmoPay trusted origins (the
		// secure page's frame-ancestors CSP defaults to 'none'), so when it is not,
		// send the shopper to VezmoPay's own secure page instead of an empty frame.
		// Either way the order is completed by the confirm/poll endpoints, the
		// webhook and the reconciliation cron.
		if ( $this->embed_allowed( $order ) ) {
			return $this->redirect_to_pay_page( $order );
		}

		return $this->redirect_to_external( $order, (string) $order->get_meta( '_vezmopay_iframe_url' ) );
	}

	/**
	 * Mark the order awaiting external payment, tidy the cart/stock, and return
	 * the WooCommerce redirect result to the given VezmoPay URL.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $url   External VezmoPay checkout URL.
	 * @return array
	 */
	private function redirect_to_external( $order, $url ) {
		if ( '' === $url ) {
			wc_add_notice( __( 'VezmoPay did not return a checkout URL. Please try again.', 'vezmopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$this->await_payment( $order, __( 'Awaiting payment on the VezmoPay secure checkout page.', 'vezmopay-woocommerce' ) );

		return array(
			'result'   => 'success',
			'redirect' => $url,
		);
	}

	/**
	 * Keep the shopper on the store: send them to WooCommerce's own order-pay page,
	 * where receipt_page() mounts the VezmoPay element/iframe.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private function redirect_to_pay_page( $order ) {
		$this->await_payment( $order, __( 'Awaiting VezmoPay payment on the store pay page.', 'vezmopay-woocommerce' ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Mark the order as awaiting payment and tidy up cart/stock. Shared by both
	 * hand-off routes so on-site and redirect payments leave identical state.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $note       Status note.
	 * @param bool      $empty_cart Whether to empty the cart now. False for the
	 *                              on-page (inline) charge, which has not taken
	 *                              the money yet and must stay retryable.
	 */
	private function await_payment( $order, $note, $empty_cart = true ) {
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status( 'pending', $note );
		}
		$order->save();

		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
		if ( $empty_cart && isset( WC()->cart ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Whether the payment already bound to this order may be charged again.
	 *
	 * This is what makes "try again" work in the payment box after a decline
	 * without resetting the form: nothing is created and nothing is re-bound —
	 * the order keeps the payment id it already had — so the browser can drive
	 * the same mounted form a second time.
	 *
	 * Every check bind_to_order() would have made is made here too, and against
	 * the API rather than the session: the payment must still be INITIATED (a
	 * captured one must never be charged again), its money must still match the
	 * order to the cent, its token must not be about to expire, and it must not
	 * be one a previous pass recorded as failed. Fails CLOSED — a false answer
	 * costs the pay-page flow, which still works.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function can_recharge_bound_payment( $order ) {
		$payment_id = (string) $order->get_meta( '_vezmopay_payment_id' );
		$token      = (string) $order->get_meta( '_vezmopay_client_token' );
		$expires    = (int) $order->get_meta( '_vezmopay_token_expires' );

		// No client token means no form is mounted in the browser for it, so
		// there is nothing to re-charge.
		if ( '' === $payment_id || '' === $token ) {
			return false;
		}
		if ( $payment_id === (string) $order->get_meta( '_vezmopay_failed_payment_id' ) ) {
			return false;
		}
		if ( $expires <= time() + MINUTE_IN_SECONDS ) {
			return false;
		}

		$environment = (string) $order->get_meta( '_vezmopay_environment' );
		if ( $environment !== $this->environment() ) {
			return false;
		}

		$payment = $this->api_client( $environment )->get_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			$this->logger->error(
				'Cannot re-charge payment ' . $payment_id . ' for order #' . $order->get_id()
				. ': could not read its state (' . $payment->get_error_message() . ').'
			);
			return false;
		}

		$status = isset( $payment['status'] ) ? strtoupper( (string) $payment['status'] ) : '';
		if ( Checkout_Session::BINDABLE_STATUS !== $status ) {
			$this->logger->debug(
				'Not re-charging payment ' . $payment_id . ' for order #' . $order->get_id()
				. ': status is ' . ( '' === $status ? 'unknown' : $status ) . '.'
			);
			return false;
		}
		if ( ! $this->amounts_agree( $order, $payment ) ) {
			$this->logger->error(
				'Not re-charging payment ' . $payment_id . ' for order #' . $order->get_id()
				. ': its amount or currency no longer matches the order.'
			);
			return false;
		}

		$this->logger->debug( 'Re-charging the payment already on order #' . $order->get_id() . ' (' . $payment_id . ').' );
		return true;
	}

	/**
	 * The payment settled, so the cart the order was built from is spent.
	 *
	 * The order-received page empties the cart on its own; this covers the inline
	 * flow, which keeps the cart through the charge so a decline can be retried —
	 * without it a shopper who paid and then reopened the checkout instead of the
	 * success page would find the same items still there.
	 */
	public function release_cart() {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Hosted mode: create a paylink and redirect to the VezmoPay checkout page.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private function process_payment_hosted( $order ) {
		$existing = $order->get_meta( '_vezmopay_paylink_code' );

		// A stored link is only reusable while it is still for THIS money. The
		// code used to be reused unconditionally, so a link minted for a $10
		// order kept collecting $10 after the total changed.
		if ( '' !== $existing && ! $this->paylink_matches_order( $order ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: paylink code */
					__( 'Order total or currency changed since VezmoPay paylink %s was created; creating a new link for the current total.', 'vezmopay-woocommerce' ),
					$existing
				)
			);
			$order->delete_meta_data( '_vezmopay_paylink_code' );
			$order->delete_meta_data( '_vezmopay_paylink_id' );
			$order->save();
			$existing = '';
		}

		if ( '' === $existing ) {
			$payload = array(
				'title'       => $this->payment_title( $order ),
				'amount'      => (float) wc_format_decimal( $order->get_total(), 2 ),
				'currency'    => $order->get_currency(),
				'description' => sprintf(
					/* translators: 1: order number, 2: site name */
					__( 'Order %1$s at %2$s', 'vezmopay-woocommerce' ),
					$order->get_order_number(),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
			);

			$paylink = $this->api_client()->create_paylink( $payload );
			if ( is_wp_error( $paylink ) ) {
				$this->handle_start_failure( $order, $paylink );
				return array( 'result' => 'failure' );
			}

			$code = isset( $paylink['shortCode'] ) ? (string) $paylink['shortCode'] : '';
			if ( '' === $code ) {
				wc_add_notice( __( 'VezmoPay did not return a payment link. Please try again.', 'vezmopay-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( '_vezmopay_paylink_code', $code );
			// Record what the link is FOR, so a later total change is detectable.
			$order->update_meta_data( '_vezmopay_paylink_amount', (float) wc_format_decimal( $order->get_total(), 2 ) );
			$order->update_meta_data( '_vezmopay_paylink_currency', strtoupper( $order->get_currency() ) );
			if ( ! empty( $paylink['id'] ) ) {
				$order->update_meta_data( '_vezmopay_paylink_id', (string) $paylink['id'] );
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: paylink code */
					__( 'VezmoPay paylink created (%s). Customer redirected to the hosted checkout. The order will be completed by webhook when VezmoPay confirms payment.', 'vezmopay-woocommerce' ),
					$code
				)
			);
			$existing = $code;
		}

		// Awaiting payment on the external page. Routed through await_payment()
		// so it gets the status GUARD the inline path has: an unguarded
		// update_status( 'pending' ) pushed an on-hold ACH order back to pending,
		// which fires wc_maybe_increase_stock_levels() and restores stock for an
		// order that is still being paid.
		$this->await_payment( $order, __( 'Awaiting VezmoPay hosted checkout payment.', 'vezmopay-woocommerce' ) );
		$order->update_meta_data( '_vezmopay_effective_mode', 'hosted' );
		$order->save_meta_data();

		return array(
			'result'   => 'success',
			'redirect' => $this->checkout_base() . '/checkout/payments-links/' . rawurlencode( $existing ),
		);
	}

	/**
	 * Whether the account can actually take a paylink payment.
	 *
	 * Read from the same account endpoint the settings panel already uses, cached
	 * so checkout never pays for it. Fails CLOSED for hosted mode only, where a
	 * wrong answer costs a stranded order rather than a fallback.
	 *
	 * TODO(platform): confirm which field signals paylink readiness. Until then
	 * this treats "at least one enabled payment method" as the signal, and an
	 * unreadable response as not-capable.
	 *
	 * @return bool
	 */
	public function paylink_capable( $allow_fetch = false ) {
		if ( ! $this->api_client()->is_configured() ) {
			return false;
		}

		$cache_key = $this->capability_key();
		$cached     = get_transient( $cache_key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}
		if ( ! $allow_fetch ) {
			// Fails SAFE. Serving the embedded form when hosted would have worked is a
			// mode the merchant did not pick; sending a shopper to a page that cannot
			// take their money loses the sale and strands the order behind a webhook
			// that never comes. The admin screen and the settings save both warm this
			// with $allow_fetch = true, so a configured store answers from cache.
			return false;
		}

		$methods = $this->api_client()->get_payment_methods();
		$capable = false;
		if ( ! is_wp_error( $methods ) && is_array( $methods ) ) {
			$capable = $this->methods_confirm_paylink( $methods );
		} elseif ( is_wp_error( $methods ) ) {
			$this->logger->error( 'Could not read account payment methods: ' . $methods->get_error_message() );
		}

		set_transient( $cache_key, $capable ? '1' : '0', self::CAPABILITY_TTL );
		return $capable;
	}

	/**
	 * Whether the account is CONFIRMED able to take a payment-link payment.
	 *
	 * Only an explicit flag counts. The previous version treated "some payment
	 * method is toggled on" as capability, which is a different question:
	 * GET /merchant/account/payment-methods reports the methods a merchant has
	 * switched on, not whether the account is verified and activated to receive
	 * money. On an unactivated account card reads enabled, so the old fallback
	 * returned true, hosted mode passed the gate, and the shopper was redirected
	 * to a page reading "No payment method available … contact the merchant
	 * directly" while the order sat pending behind a webhook that never came.
	 *
	 * So this now answers "confirmed" rather than "nothing contradicted it", and
	 * returns false until the platform sends one of these flags — which is the
	 * correct state today, because today hosted mode does not work on an
	 * unactivated account. A merchant whose account IS activated can force the
	 * redirect with the vezmopay_force_hosted_mode filter.
	 *
	 * TODO(platform): this needs an account activation/verification flag on the
	 * merchant API — e.g. GET /merchant/account returning
	 * { activated, canAcceptPayments, verificationStatus }, or paylinkEnabled
	 * added to the payment-methods response. Nothing currently reports it:
	 * POST /merchant/paylinks returns a usable shortCode regardless, so creation
	 * success is not a signal either. Only this method needs to change once the
	 * flag exists.
	 *
	 * @param array $methods Decoded `data` payload.
	 * @return bool
	 */
	private function methods_confirm_paylink( array $methods ) {
		foreach ( array( 'paylinkEnabled', 'paylinksEnabled', 'canCreatePaylinks' ) as $flag ) {
			if ( isset( $methods[ $flag ] ) ) {
				return (bool) $methods[ $flag ];
			}
		}
		return false;
	}

	/**
	 * Whether the stored paylink was created for the order's current money.
	 *
	 * A link created before this meta existed reports false, so it is replaced
	 * rather than trusted.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function paylink_matches_order( $order ) {
		$amount   = $order->get_meta( '_vezmopay_paylink_amount' );
		$currency = (string) $order->get_meta( '_vezmopay_paylink_currency' );

		if ( '' === (string) $amount || '' === $currency ) {
			return false;
		}
		if ( abs( (float) $amount - (float) wc_format_decimal( $order->get_total(), 2 ) ) >= 0.005 ) {
			return false;
		}
		return $currency === strtoupper( $order->get_currency() );
	}

	/**
	 * Create (or reuse) the VezmoPay secure payment for element/iframe modes.
	 *
	 * Idempotent per attempt: the Idempotency-Key is derived from the order key plus an
	 * attempt counter that is bumped after a failed/expired attempt (the API rejects
	 * reuse of a key with a different body and refuses checkout on terminal payments).
	 *
	 * @param \WC_Order $order     Order.
	 * @param bool      $force_new Mint a new payment even if the stored token is
	 *                             still live (the last attempt failed).
	 * @return true|\WP_Error
	 */
	public function ensure_secure_payment( $order, $force_new = false ) {
		$expires = (int) $order->get_meta( '_vezmopay_token_expires' );
		$token   = (string) $order->get_meta( '_vezmopay_client_token' );

		// A payment the API has reported as FAILED cannot be paid. Reusing its
		// still-unexpired token re-mounted the dead payment, so "try again" on the
		// pay page — and the inline retry that falls through to it — could only
		// fail again. Recorded by apply_payment_state(), so this costs no API read.
		$failed = (string) $order->get_meta( '_vezmopay_failed_payment_id' );
		if ( '' !== $failed && $failed === (string) $order->get_meta( '_vezmopay_payment_id' ) ) {
			$force_new = true;
		}

		// Reuse a live token so page refreshes don't mint new payments.
		if ( ! $force_new && '' !== $token && $expires > time() + MINUTE_IN_SECONDS ) {
			return true;
		}

		$attempt = max( 1, (int) $order->get_meta( '_vezmopay_attempt' ) );

		// Replacing a payment means a new idempotency key as well, or the API
		// replays the one we are trying to get away from.
		if ( $force_new && '' !== $token ) {
			++$attempt;
			$order->update_meta_data( '_vezmopay_attempt', $attempt );
			$order->save_meta_data();
		}

		$payload = array(
			'title'      => $this->payment_title( $order ),
			'amount'     => (float) wc_format_decimal( $order->get_total(), 2 ),
			'currency'   => $order->get_currency(),
			'ttlMinutes' => self::TOKEN_TTL_MINUTES,
			// Auto-return the shopper to the store after VezmoPay settles the
			// payment. VezmoPay appends ?paymentId=…&status=success|failed.
			'successUrl' => $this->get_return_url( $order ),
			'cancelUrl'  => add_query_arg( 'vezmopay_retry', '1', $order->get_checkout_payment_url( true ) ),
		);

		// The client object is optional, but WHEN sent the API requires name,
		// email, country AND postalCode (processor verification). Only attach it
		// when all four are present — a missing billing postcode (optional in
		// some WooCommerce country configs) must never block the payment.
		$name        = trim( $order->get_formatted_billing_full_name() );
		$email       = $order->get_billing_email();
		$country     = $order->get_billing_country();
		$postal_code = $order->get_billing_postcode();
		if ( '' !== $name && '' !== $email && '' !== $country && '' !== $postal_code ) {
			$payload['client'] = array_filter(
				array(
					'name'       => $name,
					'email'      => $email,
					'phone'      => $order->get_billing_phone(),
					'company'    => $order->get_billing_company(),
					'country'    => $country,
					'postalCode' => $postal_code,
					'line1'      => $order->get_billing_address_1(),
					'line2'      => $order->get_billing_address_2(),
					'city'       => $order->get_billing_city(),
					'state'      => $order->get_billing_state(),
				)
			);
		}

		$idempotency_key = 'wc-' . $order->get_order_key() . '-a' . $attempt;
		$data            = $this->api_client()->create_secure_payment( $payload, $idempotency_key );

		// 409/422 mean the previous attempt reached a terminal state or the body changed
		// (e.g. cart total edited): advance the attempt counter and retry once.
		if ( is_wp_error( $data ) && in_array( $data->get_error_code(), array( 'vezmopay_http_409', 'vezmopay_http_422' ), true ) ) {
			$attempt++;
			$order->update_meta_data( '_vezmopay_attempt', $attempt );
			// Persist the bump immediately: if the retry below also fails, the next
			// request must not collide with the same terminal idempotency key again.
			$order->save_meta_data();
			$idempotency_key = 'wc-' . $order->get_order_key() . '-a' . $attempt;
			$data            = $this->api_client()->create_secure_payment( $payload, $idempotency_key );
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['securePayment']['clientToken'] ) || empty( $data['payment']['id'] ) ) {
			return new \WP_Error( 'vezmopay_response', __( 'VezmoPay returned an incomplete payment session.', 'vezmopay-woocommerce' ) );
		}

		$secure = $data['securePayment'];

		$order->update_meta_data( '_vezmopay_attempt', $attempt );
		$order->update_meta_data( '_vezmopay_payment_id', (string) $data['payment']['id'] );
		$order->update_meta_data( '_vezmopay_client_token', (string) $secure['clientToken'] );
		$order->update_meta_data( '_vezmopay_iframe_url', isset( $secure['url'] ) ? esc_url_raw( $secure['url'] ) : '' );
		$order->update_meta_data( '_vezmopay_sdk_url', isset( $secure['sdkUrl'] ) ? esc_url_raw( $secure['sdkUrl'] ) : '' );
		$order->update_meta_data( '_vezmopay_token_expires', ! empty( $secure['expiresAt'] ) ? strtotime( $secure['expiresAt'] ) : time() + self::TOKEN_TTL_MINUTES * MINUTE_IN_SECONDS );
		$order->save();

		$this->logger->debug( 'Secure payment ready for order #' . $order->get_id(), array( 'payment_id' => $data['payment']['id'], 'attempt' => $attempt ) );

		return true;
	}

	/**
	 * Render the element/iframe on the order-pay ("receipt") page.
	 *
	 * @param int $order_id Order id.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Returned here after a failed/cancelled payment (VezmoPay cancelUrl).
		// Show a retry state instead of auto-forwarding, or we'd loop straight
		// back to VezmoPay. phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$came_back_failed = isset( $_GET['vezmopay_retry'] ) || ( isset( $_GET['status'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['status'] ) ) );

		// Refresh the session if the token expired while the customer idled — and
		// replace it outright when the shopper is here because the last attempt
		// failed, rather than showing them the payment that just declined.
		$ready = $this->ensure_secure_payment( $order, $came_back_failed );
		if ( is_wp_error( $ready ) ) {
			echo '<div class="woocommerce-error">' . esc_html( $this->customer_facing_error( $ready ) ) . '</div>';
			return;
		}

		$iframe_url = (string) $order->get_meta( '_vezmopay_iframe_url' );
		if ( '' === $iframe_url ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'VezmoPay checkout is unavailable right now. Please try again.', 'vezmopay-woocommerce' ) . '</div>';
			return;
		}

		wp_enqueue_style( 'vezmopay', VEZMOPAY_WC_PLUGIN_URL . 'assets/css/vezmopay.css', array(), Plugin::asset_version( 'assets/css/vezmopay.css' ) );

		$mode  = $this->integration_mode();
		$embed = 'hosted' !== $mode && ! $came_back_failed && $this->embed_allowed( $order );

		if ( $embed ) {
			$this->render_embedded_checkout( $order, $mode, $iframe_url );
			return;
		}

		// Record the hand-off against the order so the mode the shopper actually
		// got is answerable later, not just the one that was configured. Covers
		// both hosted mode and an embed that had to fall back. Skipped on a
		// return from a failed attempt, which must not rewrite that history.
		if ( ! $came_back_failed ) {
			$order->update_meta_data( '_vezmopay_effective_mode', 'hosted' );
			$order->save_meta_data();
		}

		$this->render_redirect_checkout( $iframe_url, $came_back_failed );
	}

	/**
	 * Pay page for element/iframe mode: mount the VezmoPay form on the store's own
	 * page. Only reached when this origin is a VezmoPay trusted origin, so the
	 * frame is never blocked by the platform's frame-ancestors CSP.
	 *
	 * The order is finalized by the SDK/postMessage events where they are
	 * available, and by the status poll (which re-verifies against the API) where
	 * they are not — plus the webhook and cron as the outer safety nets.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $mode       'element'|'iframe'.
	 * @param string    $iframe_url Secure payment page URL.
	 */
	private function render_embedded_checkout( $order, $mode, $iframe_url ) {
		$sdk_url = (string) $order->get_meta( '_vezmopay_sdk_url' );

		$params = array(
			'mode'         => $mode,
			'apiBase'      => $this->api_client()->host(),
			'checkoutOrigin' => $this->checkout_origin(),
			'orderId'      => $order->get_id(),
			'orderKey'     => $order->get_order_key(),
			'clientToken'  => (string) $order->get_meta( '_vezmopay_client_token' ),
			'iframeUrl'    => $iframe_url,
			// Target origin for the parent -> iframe submit message that drives the
			// charge in iframe mode (element mode goes through the SDK's .pay()).
			'secureOrigin' => $this->url_origin( $iframe_url ),
			'confirmUrl'   => \WC_AJAX::get_endpoint( 'vezmopay_confirm' ),
			'statusUrl'    => \WC_AJAX::get_endpoint( 'vezmopay_status' ),
			// Where the browser reports an attempt it has given up on, so the
			// order carries a note and the store gets one last API read.
			'failedUrl'    => \WC_AJAX::get_endpoint( 'vezmopay_failed' ),
			'nonce'        => wp_create_nonce( 'vezmopay-checkout' ),
			'pollInterval' => 4000,
			'i18n'         => array(
				// wc_price() returns the currency symbol as an HTML entity
				// (&#36;), and the script writes this label with textContent —
				// so decode it here or a shopper who retries after a decline
				// sees the literal "Pay &#36;12.34".
				'pay'        => sprintf(
					/* translators: %s: order total, e.g. $300.00 */
					__( 'Pay %s', 'vezmopay-woocommerce' ),
					html_entity_decode(
						wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
						ENT_QUOTES,
						'UTF-8'
					)
				),
				'frameTitle' => __( 'VezmoPay secure payment', 'vezmopay-woocommerce' ),
				'processing' => __( 'Processing your payment…', 'vezmopay-woocommerce' ),
				'pending'    => __( 'Your bank payment is processing. We will email you when it completes.', 'vezmopay-woocommerce' ),
				'failed'     => __( 'Payment failed. Please try again or use a different payment method.', 'vezmopay-woocommerce' ),
				'expired'    => __( 'This payment session expired. Reloading…', 'vezmopay-woocommerce' ),
				'error'      => __( 'Something went wrong. Please try again.', 'vezmopay-woocommerce' ),
				'review'     => __( 'We received your payment, but this order needs a quick manual review before it is confirmed. Please contact us — do not pay again.', 'vezmopay-woocommerce' ),
				'cancelled'  => __( 'The payment was cancelled. You can try again.', 'vezmopay-woocommerce' ),
				// Appended to a message from the payment form, which names the
				// problem but not the remedy. The form is still mounted with the
				// shopper's details in it, so this is an invitation to press Pay
				// again — not to start over.
				'tryAgain'   => __( 'You can correct your card details and try again.', 'vezmopay-woocommerce' ),
				'verifying'  => __( 'Completing an extra verification step with your bank…', 'vezmopay-woocommerce' ),
				// The bounded attempt (see ATTEMPT_LIMIT_MS in pay-attempt.js).
				// VezmoPay reports nothing at all for a declined card, so this
				// covers a decline as well as a payment that never resolved — the
				// wording has to be true of both.
				'noResult'   => __( 'VezmoPay did not report a result for that payment. Please check your card details and press Pay again — if the payment did go through, your order will be updated automatically.', 'vezmopay-woocommerce' ),
			),
		);

		// Inline mode hands the frame to VezmoPay's own SDK, which owns its origin
		// checks, auto-resize and the captcha/3-D Secure popup fallback. Iframe
		// mode deliberately does NOT load it: the plugin embeds the page and
		// confirms by polling, which is the point of choosing that mode. Inline
		// without a sdkUrl on the session has nothing to mount, so it degrades to
		// exactly what iframe mode does rather than failing.
		$use_sdk = 'element' === $mode && '' !== $sdk_url;
		if ( 'element' === $mode && ! $use_sdk ) {
			$this->logger->debug(
				'Inline mode requested but the session carried no sdkUrl; falling back to the embedded iframe.'
			);
		}
		// One copy of the shared attempt behaviour, whichever driver runs. Both
		// used to carry their own, and the copies drifted — see pay-attempt.js.
		wp_register_script(
			'vezmopay-pay-attempt',
			VEZMOPAY_WC_PLUGIN_URL . 'assets/js/pay-attempt.js',
			array(),
			Plugin::asset_version( 'assets/js/pay-attempt.js' ),
			true
		);

		if ( $use_sdk ) {
			wp_enqueue_script( 'vezmopay-sdk', $sdk_url, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote SDK, provider-versioned.
			wp_enqueue_script( 'vezmopay-element', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-element.js', array( 'vezmopay-sdk', 'vezmopay-pay-attempt' ), Plugin::asset_version( 'assets/js/checkout-element.js' ), true );
			wp_localize_script( 'vezmopay-element', 'vezmopay_params', $params );
		} else {
			wp_enqueue_script( 'vezmopay-iframe', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-iframe.js', array( 'vezmopay-pay-attempt' ), Plugin::asset_version( 'assets/js/checkout-iframe.js' ), true );
			wp_localize_script( 'vezmopay-iframe', 'vezmopay_params', $params );
		}

		// The embed scripts load in the FOOTER, by which time the frame can already
		// have loaded and posted its ready/resize messages — which left the Pay
		// button hidden behind a safety timeout and the frame at its default
		// height. Register before the frame exists: a capture-phase load listener
		// catches an element added later, and every message is queued for the
		// script to replay.
		wp_print_inline_script_tag(
			// Bounded (a MessageEvent keeps the frame's Window alive) and removable
			// — the collector used to run for the life of the page, and only one
			// of the two embed scripts ever drained it.
			// NO AMPERSANDS. WordPress texturizes an inline script body, so a `&&`
			// is emitted as `&#038;&#038;` and the WHOLE snippet dies with
			// "SyntaxError: Invalid or unexpected token" — which is exactly what
			// had happened to this collector since it was introduced: it never
			// ran, so nothing was ever queued and vezmopayFrameLoaded was never
			// set. Nested ifs instead of `&&`, and keep it that way.
			'window.vezmopayEmbedEvents=[];' .
			'window.vezmopayEarlyEventHandler=function(e){' .
			'if(window.vezmopayEmbedEvents.length<50){window.vezmopayEmbedEvents.push(e);}' .
			'};' .
			'window.addEventListener("message",window.vezmopayEarlyEventHandler);' .
			'window.vezmopayStopEarlyEvents=function(){' .
			'if(window.vezmopayEarlyEventHandler){' .
			'window.removeEventListener("message",window.vezmopayEarlyEventHandler);' .
			'window.vezmopayEarlyEventHandler=null;' .
			'}' .
			'window.vezmopayEmbedEvents=[];' .
			'};' .
			'document.addEventListener("load",function(e){' .
			'var t=e.target;' .
			'if(t){if(t.id==="vezmopay-frame"){window.vezmopayFrameLoaded=true;}}' .
			'},true);',
			array( 'id' => 'vezmopay-embed-early' )
		);

		$order->update_meta_data( '_vezmopay_effective_mode', $use_sdk ? 'element' : 'iframe' );
		$order->save_meta_data();

		$logo_url = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay.svg';

		echo '<div id="vezmopay-checkout" class="vezmopay-checkout" data-mode="' . esc_attr( $use_sdk ? 'element' : 'iframe' ) . '" data-theme="' . esc_attr( $this->checkout_theme() ) . '">';

		echo '<div class="vezmopay-header">';
		echo '<img class="vezmopay-logo" src="' . esc_url( $logo_url ) . '" alt="VezmoPay" />';
		if ( $this->is_test_mode() ) {
			echo '<span class="vezmopay-test-badge">' . esc_html__( 'Test mode', 'vezmopay-woocommerce' ) . '</span>';
		}
		echo '</div>';

		echo '<div class="vezmopay-body">';
		echo '<div class="vezmopay-loading" aria-hidden="true"><span class="vezmopay-spinner"></span>' . esc_html__( 'Preparing your secure payment…', 'vezmopay-woocommerce' ) . '</div>';
		echo '<div id="vezmopay-container" class="vezmopay-container">';
		if ( ! $use_sdk ) {
			// Server-rendered so payment still works with our JS disabled; the
			// order is then completed by webhook.
			// `payment *` and `storage-access *` mirror what vezmo.js sets on its
			// own iframe, and for the reasons its source gives: bare `payment`
			// scopes the permission to the frame's src origin, which breaks
			// Apple/Google Pay across the checkout redirect, and without Storage
			// Access the fraud captcha / 3-D Secure challenge cannot complete
			// inside a third-party frame.
			// width/height ATTRIBUTES as well as CSS: an iframe with neither
			// defaults to 300x150, which renders the checkout in its mobile
			// layout inside a tiny box — so the frame must be full width even
			// if the stylesheet is missing, blocked or stale.
			echo '<iframe id="vezmopay-frame" src="' . esc_url( $iframe_url ) . '" width="100%" height="720" allow="payment *; storage-access *" title="' . esc_attr__( 'VezmoPay secure payment', 'vezmopay-woocommerce' ) . '"></iframe>';
		}
		echo '</div>';
		echo '</div>';

		// When the secure page is embedded it HIDES its own card/bank submit button
		// and waits for a `vezmo:secure-payment:submit` message from us — by design,
		// so the merchant owns the primary call to action. Render that button here or
		// the shopper has a card form they cannot submit. Wallet (Apple/Google Pay)
		// buttons inside the frame keep working on their own. Revealed once the frame
		// reports ready, so it never appears over an empty box.
		// Wrapped so the button and link share the card's side inset — the body
		// above carries its own padding, and these sit outside it.
		echo '<div class="vezmopay-actions">';
		echo '<button type="button" id="vezmopay-pay" class="vezmopay-pay">';
		echo '<span class="vezmopay-pay-label">' . esc_html( $params['i18n']['pay'] ) . '</span>';
		echo '</button>';

		// Manual escape hatch: if the embedded form does not work for this shopper
		// (blocked third-party frames, an extension, a browser we did not predict),
		// the same payment is one top-level navigation away.
		echo '<p class="vezmopay-embed-escape"><a href="' . esc_url( $iframe_url ) . '">' . esc_html__( 'Trouble with the form? Continue on the VezmoPay page →', 'vezmopay-woocommerce' ) . '</a></p>';
		echo '</div>';

		echo '<p id="vezmopay-message" class="vezmopay-message" role="status" aria-live="polite"></p>';

		echo '<noscript><p class="vezmopay-message is-info" style="display:block;">' . esc_html__( 'JavaScript is disabled. After paying in the secure form above, your order will be confirmed by email once VezmoPay notifies us.', 'vezmopay-woocommerce' ) . '</p></noscript>';

		echo '<div class="vezmopay-footer">';
		// "Payments secured by VezmoPay" belongs BELOW the Pay button — it
		// reassures at the moment of paying, not before the form is filled in.
		// The embedded page hides its own copy of this line, so there is exactly
		// one, here. phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		echo '<span class="vezmopay-powered">' . '<svg class="vezmopay-lock-mini" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z"/></svg>' . ' ' . esc_html__( 'Payments secured by', 'vezmopay-woocommerce' ) . ' <img src="' . esc_url( $logo_url ) . '" alt="VezmoPay" /></span>';
		echo '<span class="vezmopay-trust"><span>' . esc_html__( 'PCI DSS', 'vezmopay-woocommerce' ) . '</span><span>' . esc_html__( '3-D Secure', 'vezmopay-woocommerce' ) . '</span></span>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Pay page for hosted mode, for a store whose origin VezmoPay will not let it
	 * embed from, and for a shopper coming back from a failed attempt: a branded
	 * hand-off to VezmoPay's own secure page.
	 *
	 * @param string $iframe_url       Secure payment page URL.
	 * @param bool   $came_back_failed Whether the shopper returned from a failure.
	 */
	private function render_redirect_checkout( $iframe_url, $came_back_failed ) {
		$logo_url = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay.svg';

		echo '<div id="vezmopay-checkout" class="vezmopay-checkout" data-theme="' . esc_attr( $this->checkout_theme() ) . '">';

		echo '<div class="vezmopay-header">';
		echo '<img class="vezmopay-logo" src="' . esc_url( $logo_url ) . '" alt="VezmoPay" />';
		if ( $this->is_test_mode() ) {
			echo '<span class="vezmopay-test-badge">' . esc_html__( 'Test mode', 'vezmopay-woocommerce' ) . '</span>';
		}
		echo '</div>';

		echo '<div class="vezmopay-body vezmopay-redirect">';
		if ( $came_back_failed ) {
			// Failed/cancelled at VezmoPay — offer a retry, do NOT auto-forward.
			echo '<p class="vezmopay-redirect-text">' . esc_html__( 'Your payment was not completed.', 'vezmopay-woocommerce' ) . '</p>';
			echo '<a class="vezmopay-continue" href="' . esc_url( $iframe_url ) . '">' . esc_html__( 'Try payment again', 'vezmopay-woocommerce' ) . '</a>';
		} else {
			echo '<span class="vezmopay-spinner"></span>';
			echo '<p class="vezmopay-redirect-text">' . esc_html__( 'Taking you to the secure VezmoPay checkout…', 'vezmopay-woocommerce' ) . '</p>';
			echo '<a class="vezmopay-continue" href="' . esc_url( $iframe_url ) . '">' . esc_html__( 'Continue to payment', 'vezmopay-woocommerce' ) . '</a>';
		}
		echo '</div>';

		echo '<div class="vezmopay-footer">';
		// "Payments secured by VezmoPay" belongs BELOW the Pay button — it
		// reassures at the moment of paying, not before the form is filled in.
		// The embedded page hides its own copy of this line, so there is exactly
		// one, here. phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		echo '<span class="vezmopay-powered">' . '<svg class="vezmopay-lock-mini" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z"/></svg>' . ' ' . esc_html__( 'Payments secured by', 'vezmopay-woocommerce' ) . ' <img src="' . esc_url( $logo_url ) . '" alt="VezmoPay" /></span>';
		echo '<span class="vezmopay-trust"><span>' . esc_html__( 'PCI DSS', 'vezmopay-woocommerce' ) . '</span><span>' . esc_html__( '3-D Secure', 'vezmopay-woocommerce' ) . '</span></span>';
		echo '</div>';

		echo '</div>';

		if ( ! $came_back_failed ) {
			wp_print_inline_script_tag(
				'window.location.replace(' . wp_json_encode( $iframe_url ) . ');',
				array( 'id' => 'vezmopay-redirect' )
			);
		}
	}

	/**
	 * Thank-you page notice for orders still awaiting confirmation.
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		// Normally already done by reconcile_order_received() before the template
		// rendered; this covers an order-received page reached some other way.
		if ( $this->reconcile_if_unsettled( $order ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order->is_paid() ) {
			return;
		}
		if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			echo '<p>' . esc_html__( 'Your VezmoPay payment is being confirmed. You will receive an email as soon as it completes.', 'vezmopay-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Verify an unsettled order against the API, at most once per request.
	 *
	 * FAILED orders are included deliberately. WooCommerce's thank-you template
	 * renders "your order cannot be processed as the originating bank/merchant has
	 * declined your transaction" for any order in `failed` — so a shopper whose
	 * first card declined and whose second attempt was captured saw that error on
	 * the success page of a paid order, because only `pending` was ever
	 * re-checked here. Reading the API is the same source of truth used
	 * everywhere else, and mark_order_paid() still only completes an order whose
	 * money the API confirms to the cent (see amounts_agree()).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether a reconcile ran (so the caller re-reads the order).
	 */
	public function reconcile_if_unsettled( $order ) {
		if ( ! $order || $order->is_paid() || $order->get_payment_method() !== $this->id ) {
			return false;
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return false;
		}
		if ( '' === (string) $order->get_meta( '_vezmopay_payment_id' ) && '' === (string) $order->get_meta( '_vezmopay_paylink_code' ) ) {
			return false;
		}
		$id = $order->get_id();
		if ( isset( $this->reconciled[ $id ] ) ) {
			return false;
		}
		$this->reconciled[ $id ] = true;
		$this->reconcile_order_with_api( $order );
		return true;
	}

	/**
	 * Settle the order BEFORE the order-received page is rendered.
	 *
	 * woocommerce_thankyou_{id} fires from inside the thank-you template, after it
	 * has already branched on the order's status — and on an object it read before
	 * our hook ran, so reconciling there cannot change what the shopper sees. This
	 * runs at template_redirect, so the page is built from the settled state.
	 */
	public function reconcile_order_received() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		if ( ! $order_id ) {
			return;
		}

		// The same ownership check WooCommerce makes before it renders any of the
		// order's details on this page. phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, key-authenticated.
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		$order = wc_get_order( $order_id );
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		$this->reconcile_if_unsettled( $order );
	}

	/* ---------------------------------------------------------------------
	 * Reconciliation (shared by AJAX confirm/poll and the webhook).
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch the authoritative state from the VezmoPay API and update the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|\WP_Error Payment status (CAPTURED|PENDING|FAILED|INITIATED|REFUNDED).
	 */
	public function reconcile_order_with_api( $order ) {
		// Four actors can reach this at once — the webhook, the five-minute cron,
		// the browser poll every 3.5s, and thankyou_page() on every
		// order-received load. mark_order_paid()'s is_paid() guard reads an
		// in-memory object, so two passes both saw "unpaid" and both called
		// payment_complete(): duplicate emails, duplicate notes, and two stock
		// reductions that each read _order_stock_reduced = no.
		//
		// add_option() is atomic on the options table's unique index, so exactly
		// one caller gets the lock and the rest back off.
		$lock = 'vezmopay_recon_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			$this->logger->debug( 'Reconciliation for order #' . $order->get_id() . ' is already running; skipping this pass.' );
			return 'LOCKED';
		}

		try {
			return $this->reconcile_locked( $order );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * The reconcile itself, run under the lock taken above.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|\WP_Error
	 */
	private function reconcile_locked( $order ) {
		// Re-read: another pass may have completed this order between the last
		// read and the lock being taken.
		$order       = wc_get_order( $order->get_id() );
		if ( ! $order ) {
			return new \WP_Error( 'vezmopay_no_order', __( 'Order not found.', 'vezmopay-woocommerce' ) );
		}
		$environment = $order->get_meta( '_vezmopay_environment' );
		$client      = $this->api_client( in_array( $environment, array( 'test', 'live' ), true ) ? $environment : null );

		$payment_id = (string) $order->get_meta( '_vezmopay_payment_id' );

		if ( '' !== $payment_id ) {
			$payment = $client->get_payment( $payment_id );
			if ( is_wp_error( $payment ) ) {
				return $payment;
			}
			return $this->apply_payment_state( $order, $payment );
		}

		// Hosted mode before the webhook told us the payment id: check the paylink.
		$code = (string) $order->get_meta( '_vezmopay_paylink_code' );
		if ( '' !== $code ) {
			$paylink = $client->get_paylink( $code );
			if ( is_wp_error( $paylink ) ) {
				return $paylink;
			}
			$status = isset( $paylink['status'] ) ? strtoupper( (string) $paylink['status'] ) : '';
			if ( 'PAID' === $status ) {
				// The same tamper guard the payment path runs. Without it, a
				// still-valid link for an abandoned $10 order paid $10 while the
				// order total had since been raised — and the order completed in
				// full. Fails closed on a response that cannot be checked.
				if ( ! $this->amounts_agree( $order, $paylink ) ) {
					$this->hold_for_mismatch(
						$order,
						isset( $paylink['amount'] ) ? (float) $paylink['amount'] : null,
						isset( $paylink['currency'] ) ? strtoupper( (string) $paylink['currency'] ) : null,
						__( 'payment link', 'vezmopay-woocommerce' )
					);
					return 'MISMATCH';
				}
				$this->mark_order_paid( $order, '', __( 'VezmoPay paylink reported as paid.', 'vezmopay-woocommerce' ) );
				return 'CAPTURED';
			}
			return 'INITIATED';
		}

		return new \WP_Error( 'vezmopay_no_ref', __( 'No VezmoPay payment reference on this order.', 'vezmopay-woocommerce' ) );
	}

	/**
	 * Map a VezmoPay payment record onto the WooCommerce order state machine.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $payment Payment record from the API.
	 * @return string Normalized status.
	 */
	public function apply_payment_state( $order, array $payment ) {
		$status     = isset( $payment['status'] ) ? strtoupper( (string) $payment['status'] ) : '';
		$payment_id = isset( $payment['id'] ) ? (string) $payment['id'] : (string) $order->get_meta( '_vezmopay_payment_id' );

		// The plugin's ONLY tamper check, so it fails closed: a response with no
		// amount used to skip the guard and complete the order, and a matching
		// number in the wrong currency used to pass it (100.00 USD satisfied a
		// 100.00 EUR order). An unverifiable response is a mismatch, not a pass.
		if ( ! $this->amounts_agree( $order, $payment ) ) {
			$this->hold_for_mismatch(
				$order,
				isset( $payment['amount'] ) ? (float) $payment['amount'] : null,
				isset( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : null,
				__( 'payment', 'vezmopay-woocommerce' )
			);
			return 'MISMATCH';
		}

		switch ( $status ) {
			case 'CAPTURED':
				$this->mark_order_paid( $order, $payment_id, __( 'VezmoPay payment captured.', 'vezmopay-woocommerce' ) );
				return 'CAPTURED';

			case 'AUTHORIZED':
				if ( ! $order->has_status( 'on-hold' ) && ! $order->is_paid() ) {
					$order->update_status( 'on-hold', __( 'VezmoPay payment authorized / bank settlement pending (e.g. ACH). Awaiting final confirmation.', 'vezmopay-woocommerce' ) );
					if ( $payment_id ) {
						$order->set_transaction_id( $payment_id );
						$order->save();
					}
				}
				return 'PENDING';

			case 'FAILED':
				// Remember WHICH payment failed, so nothing re-offers it: the pay
				// page reuses an unexpired token, and the checkout falls back to
				// the pay page. See ensure_secure_payment().
				if ( '' !== $payment_id ) {
					$order->update_meta_data( '_vezmopay_failed_payment_id', $payment_id );
					$order->save_meta_data();
				}
				if ( ! $order->has_status( 'failed' ) && ! $order->is_paid() ) {
					$order->update_status( 'failed', __( 'VezmoPay reported the payment as failed.', 'vezmopay-woocommerce' ) );
				}
				return 'FAILED';

			case 'REFUNDED':
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->add_order_note( __( 'VezmoPay reports this payment as refunded (refund performed on the VezmoPay side).', 'vezmopay-woocommerce' ) );
					$order->update_status( 'refunded' );
				}
				return 'REFUNDED';

			case 'INITIATED':
			default:
				return 'INITIATED';
		}
	}

	/**
	 * Whether a payment id really is a captured payment for this order's money.
	 *
	 * Used to decide whether an id that arrived in an unauthenticated webhook
	 * payload may be stored as the order's transaction reference. Reads the
	 * payment server-to-server and fails closed.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payment_id Candidate payment id from a payload.
	 * @return bool
	 */
	public function payment_belongs_to_order( $order, $payment_id ) {
		$payment_id = (string) $payment_id;
		if ( '' === $payment_id ) {
			return false;
		}

		$environment = $order->get_meta( '_vezmopay_environment' );
		$payment     = $this->api_client( in_array( $environment, array( 'test', 'live' ), true ) ? $environment : null )
			->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			return false;
		}
		$status = isset( $payment['status'] ) ? strtoupper( (string) $payment['status'] ) : '';
		if ( 'CAPTURED' !== $status ) {
			return false;
		}
		return $this->amounts_agree( $order, $payment );
	}

	/**
	 * Whether a provider record's money matches the order's, to the cent and in
	 * the same currency.
	 *
	 * Returns FALSE when either field is missing: an amount we cannot read is not
	 * an amount that agrees.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $record Payment or paylink record from the API.
	 * @return bool
	 */
	private function amounts_agree( $order, array $record ) {
		$amount   = isset( $record['amount'] ) ? (float) $record['amount'] : null;
		$currency = isset( $record['currency'] ) ? strtoupper( (string) $record['currency'] ) : null;

		if ( null === $amount || null === $currency ) {
			return false;
		}
		if ( abs( $amount - (float) $order->get_total() ) >= 0.005 ) {
			return false;
		}
		return $currency === strtoupper( $order->get_currency() );
	}

	/**
	 * Park an order for manual review when the provider's money does not match.
	 *
	 * @param \WC_Order   $order    Order.
	 * @param float|null  $amount   Amount the provider reported, null when absent.
	 * @param string|null $currency Currency the provider reported, null when absent.
	 * @param string      $source   Human label for what was read ('payment', 'payment link').
	 */
	private function hold_for_mismatch( $order, $amount, $currency, $source ) {
		$reported = ( null === $amount || null === $currency )
			? __( 'an amount it did not report', 'vezmopay-woocommerce' )
			: sprintf(
				'%s %s',
				wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ),
				$currency
			);

		$order->add_order_note(
			sprintf(
				/* translators: 1: what was read (payment / payment link), 2: amount and currency VezmoPay reported, 3: order total, 4: order currency */
				__( 'VezmoPay %1$s mismatch: provider reports %2$s but the order total is %3$s %4$s. Order NOT completed automatically — review manually.', 'vezmopay-woocommerce' ),
				$source,
				$reported,
				wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
				strtoupper( $order->get_currency() )
			)
		);
		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold' );
		}
	}

	/**
	 * Complete payment exactly once, storing the provider transaction id.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payment_id VezmoPay payment id (may be empty for paylink-only knowledge).
	 * @param string    $note       Order note.
	 */
	public function mark_order_paid( $order, $payment_id, $note ) {
		// Re-read from storage rather than trusting the in-memory object: this is
		// the check that decides whether payment_complete() runs, and a stale
		// object is exactly how it ran twice.
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) {
			$order = $fresh;
		}
		if ( $order->is_paid() ) {
			return;
		}
		$order->add_order_note( $note . ( $payment_id ? ' (' . sprintf( /* translators: %s: transaction id */ __( 'Transaction ID: %s', 'vezmopay-woocommerce' ), $payment_id ) . ')' : '' ) );
		$order->payment_complete( $payment_id );
	}

	/**
	 * Record a payment attempt that ended without success.
	 *
	 * A declined card left NO trace at all: the order sat in pending with no
	 * notes, indistinguishable from an abandoned cart — which is precisely the
	 * distinction a merchant needs when following up a lost sale.
	 *
	 * Deliberately factual and provider-text-free. The browser's reason is a
	 * guess (VezmoPay reports no terminal state for a decline), so the note says
	 * what was observed and what the store will do next, and never asserts that
	 * money did or did not move — except where this plugin knows it did not.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason One of the allow-listed reasons from the browser.
	 * @param string    $status Last status the API reported, e.g. INITIATED.
	 */
	public function note_failed_attempt( $order, $reason, $status ) {
		$payment_id = (string) $order->get_meta( '_vezmopay_payment_id' );
		$status     = '' === $status ? 'UNKNOWN' : $status;

		// One note per (payment, reason): the browser may report the same attempt
		// more than once (a retry, a reload), and an order note per poll is noise.
		$fingerprint = $payment_id . '|' . $reason;
		if ( (string) $order->get_meta( '_vezmopay_attempt_noted' ) === $fingerprint ) {
			return;
		}

		switch ( $reason ) {
			case 'declined':
				$text = __( 'The VezmoPay payment form reported a failed attempt (payment %1$s, VezmoPay still reports %2$s). The customer was not charged and was asked to try again.', 'vezmopay-woocommerce' );
				break;
			case 'cancelled':
				$text = __( 'The customer cancelled the VezmoPay payment (payment %1$s, VezmoPay still reports %2$s).', 'vezmopay-woocommerce' );
				break;
			case 'expired':
				$text = __( 'The VezmoPay payment session expired before the payment completed (payment %1$s, VezmoPay still reports %2$s).', 'vezmopay-woocommerce' );
				break;
			case 'not-ready':
				$text = __( 'The VezmoPay payment form never finished loading, so the card was never submitted (payment %1$s, VezmoPay still reports %2$s).', 'vezmopay-woocommerce' );
				break;
			case 'status':
				$text = __( 'VezmoPay reported this payment attempt as failed (payment %1$s, status %2$s). The customer was asked to try again.', 'vezmopay-woocommerce' );
				break;
			case 'timeout':
			default:
				$text = __( 'VezmoPay reported no result for this payment attempt within the time the checkout waits (payment %1$s, still %2$s). The customer was asked to try again. If VezmoPay later reports this payment as captured, the order will be updated automatically.', 'vezmopay-woocommerce' );
				break;
		}

		$order->add_order_note(
			sprintf(
				$text,
				'' === $payment_id ? __( 'none recorded', 'vezmopay-woocommerce' ) : $payment_id,
				$status
			)
		);
		$order->update_meta_data( '_vezmopay_attempt_noted', $fingerprint );
		$order->save();

		$this->logger->debug(
			'Recorded a failed VezmoPay attempt on order #' . $order->get_id() . ' (' . $reason . ', API says ' . $status . ').'
		);
	}

	/* ---------------------------------------------------------------------
	 * Refunds — flagged platform gap.
	 * ------------------------------------------------------------------- */

	/**
	 * VezmoPay exposes no merchant refund API (verified against the platform source).
	 * Refunds must be issued from the VezmoPay dashboard; this method exists so the
	 * limitation is explained rather than silently absent.
	 *
	 * @param int    $order_id Order id.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Reason.
	 * @return \WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new \WP_Error(
			'vezmopay_refund_unsupported',
			__( 'VezmoPay does not currently provide a refund API. Please issue the refund from your VezmoPay dashboard; the order will be marked refunded when the plugin next verifies the payment.', 'vezmopay-woocommerce' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Misc helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Provider-side payment title for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function payment_title( $order ) {
		return substr(
			sprintf(
				/* translators: 1: site name, 2: order number */
				__( '%1$s — Order %2$s', 'vezmopay-woocommerce' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$order->get_order_number()
			),
			0,
			120
		);
	}

	/**
	 * Record a payment-start failure so the merchant can actually diagnose it:
	 * always logged, always an order note, and — for store managers testing
	 * checkout themselves — an extra notice with the real API error.
	 *
	 * @param \WC_Order $order Order.
	 * @param \WP_Error $error API error.
	 */
	private function handle_start_failure( $order, $error ) {
		$this->logger->error( 'Payment start failed for order #' . $order->get_id() . ': ' . $error->get_error_message() );

		$order->add_order_note(
			sprintf(
				/* translators: %s: error detail from the VezmoPay API */
				__( 'VezmoPay could not start the payment: %s', 'vezmopay-woocommerce' ),
				$error->get_error_message()
			)
		);
		$order->save();

		$message = $this->customer_facing_error( $error );

		// Store managers get the real API error inline in the checkout error
		// itself — the Block checkout only surfaces 'error' notices, so a
		// separate 'notice'-type message would never be seen there.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$detail = $error->get_error_message();
			if ( 'vezmopay_http_403' === $error->get_error_code() ) {
				$detail .= ' — ' . __( 'Your VezmoPay API key is missing a required permission: inline and iframe modes need secure-payment.create, hosted mode needs paylink.create. Assign it to the key in the VezmoPay admin.', 'vezmopay-woocommerce' );
			}
			$message .= ' ' . sprintf(
				/* translators: %s: technical error detail (shown to store managers only) */
				__( '[Store managers only] Reason: %s', 'vezmopay-woocommerce' ),
				$detail
			);
		}

		wc_add_notice( $message, 'error' );
	}

	/**
	 * Reduce an API error to something safe and helpful for customers.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private function customer_facing_error( $error ) {
		if ( 'vezmopay_transport' === $error->get_error_code() ) {
			return $error->get_error_message();
		}
		return __( 'We could not start your VezmoPay payment. Please try again or choose a different payment method.', 'vezmopay-woocommerce' );
	}
}
