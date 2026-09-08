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
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Plugin::GATEWAY_ID;
		$this->icon               = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay-icon.png';
		$this->method_title       = __( 'VezmoPay', 'vezmopay-woocommerce' );
		$this->method_description = __( 'Accept payments through VezmoPay — hosted checkout, inline payment element, or secure iframe. Card data never touches your server.', 'vezmopay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->logger      = new Logger( 'yes' === $this->get_option( 'debug' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
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
	 * Legacy 'element' and 'iframe' both normalise to 'embedded': they rendered
	 * the same VezmoPay page and differed only in what drove the frame, which the
	 * plugin now decides per session. Stores keep working without re-saving.
	 *
	 * @return string 'embedded'|'hosted'
	 */
	public function integration_mode() {
		return 'hosted' === $this->get_option( 'integration_mode', 'embedded' ) ? 'hosted' : 'embedded';
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
		$base        = $this->get_option( $environment . '_api_base', 'live' === $environment ? Settings::DEFAULT_LIVE_API : Settings::DEFAULT_TEST_API );
		return new Api_Client( $base, $this->credential( $environment, 'key' ), $this->credential( $environment, 'secret' ), $environment, $this->logger );
	}

	/**
	 * Hosted checkout / dashboard app base for the active environment
	 * (also hosts the Connect-with-VezmoPay consent page).
	 *
	 * @return string
	 */
	public function checkout_base() {
		$environment = $this->environment();
		$base        = untrailingslashit( $this->get_option( $environment . '_checkout_base', 'live' === $environment ? Settings::DEFAULT_LIVE_CHECKOUT : Settings::DEFAULT_TEST_CHECKOUT ) );

		// Self-heal installs that persisted the earlier wrong default: dev.vezmo.com
		// is the marketing site, not the merchant app — the app dev host is
		// user.dev.vezmo.com. Never a legitimate checkout host, so safe to correct.
		if ( 'https://dev.vezmo.com' === $base || 'http://dev.vezmo.com' === $base ) {
			$base = Settings::DEFAULT_TEST_CHECKOUT;
		}

		return $base;
	}

	/**
	 * Transient TTL for the trusted-origin lookup.
	 */
	const EMBED_CHECK_TTL = 10 * MINUTE_IN_SECONDS;

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
	 * @param \WC_Order $order Order.
	 * @param string    $note  Status note.
	 */
	private function await_payment( $order, $note ) {
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status( 'pending', $note );
		}
		$order->save();

		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
		if ( isset( WC()->cart ) && WC()->cart ) {
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

		// Awaiting payment on the external page.
		$order->update_status( 'pending', __( 'Awaiting VezmoPay hosted checkout payment.', 'vezmopay-woocommerce' ) );
		$order->save();

		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
		if ( isset( WC()->cart ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->checkout_base() . '/checkout/payments-links/' . rawurlencode( $existing ),
		);
	}

	/**
	 * Create (or reuse) the VezmoPay secure payment for element/iframe modes.
	 *
	 * Idempotent per attempt: the Idempotency-Key is derived from the order key plus an
	 * attempt counter that is bumped after a failed/expired attempt (the API rejects
	 * reuse of a key with a different body and refuses checkout on terminal payments).
	 *
	 * @param \WC_Order $order Order.
	 * @return true|\WP_Error
	 */
	public function ensure_secure_payment( $order ) {
		$expires = (int) $order->get_meta( '_vezmopay_token_expires' );
		$token   = (string) $order->get_meta( '_vezmopay_client_token' );

		// Reuse a live token so page refreshes don't mint new payments.
		if ( '' !== $token && $expires > time() + MINUTE_IN_SECONDS ) {
			return true;
		}

		$attempt = max( 1, (int) $order->get_meta( '_vezmopay_attempt' ) );

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

		// Refresh the session if the token expired while the customer idled.
		$ready = $this->ensure_secure_payment( $order );
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
		// Prefer VezmoPay's own SDK when the session carries one: it owns the
		// frame's origin checks, auto-resize and the captcha/3-D Secure popup
		// fallback. Without a sdkUrl, drive a plain frame ourselves.
		$sdk_url = (string) $order->get_meta( '_vezmopay_sdk_url' );

		$params = array(
			'mode'         => $mode,
			'apiBase'      => $this->api_client()->host(),
			'orderId'      => $order->get_id(),
			'orderKey'     => $order->get_order_key(),
			'clientToken'  => (string) $order->get_meta( '_vezmopay_client_token' ),
			'iframeUrl'    => $iframe_url,
			// Target origin for the parent -> iframe submit message that drives the
			// charge in iframe mode (element mode goes through the SDK's .pay()).
			'secureOrigin' => $this->url_origin( $iframe_url ),
			'confirmUrl'   => \WC_AJAX::get_endpoint( 'vezmopay_confirm' ),
			'statusUrl'    => \WC_AJAX::get_endpoint( 'vezmopay_status' ),
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
				'processing' => __( 'Processing your payment…', 'vezmopay-woocommerce' ),
				'pending'    => __( 'Your bank payment is processing. We will email you when it completes.', 'vezmopay-woocommerce' ),
				'failed'     => __( 'Payment failed. Please try again or use a different payment method.', 'vezmopay-woocommerce' ),
				'expired'    => __( 'This payment session expired. Reloading…', 'vezmopay-woocommerce' ),
				'error'      => __( 'Something went wrong. Please try again.', 'vezmopay-woocommerce' ),
				'review'     => __( 'We received your payment, but this order needs a quick manual review before it is confirmed. Please contact us — do not pay again.', 'vezmopay-woocommerce' ),
			),
		);

		$use_sdk = '' !== $sdk_url;
		if ( $use_sdk ) {
			wp_enqueue_script( 'vezmopay-sdk', $sdk_url, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote SDK, provider-versioned.
			wp_enqueue_script( 'vezmopay-element', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-element.js', array( 'vezmopay-sdk' ), Plugin::asset_version( 'assets/js/checkout-element.js' ), true );
			wp_localize_script( 'vezmopay-element', 'vezmopay_params', $params );
		} else {
			wp_enqueue_script( 'vezmopay-iframe', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-iframe.js', array(), Plugin::asset_version( 'assets/js/checkout-iframe.js' ), true );
			wp_localize_script( 'vezmopay-iframe', 'vezmopay_params', $params );
		}

		// The embed scripts load in the FOOTER, by which time the frame can already
		// have loaded and posted its ready/resize messages — which left the Pay
		// button hidden behind a safety timeout and the frame at its default
		// height. Register before the frame exists: a capture-phase load listener
		// catches an element added later, and every message is queued for the
		// script to replay.
		wp_print_inline_script_tag(
			'window.vezmopayEmbedEvents=[];' .
			'window.addEventListener("message",function(e){window.vezmopayEmbedEvents.push(e);});' .
			'document.addEventListener("load",function(e){' .
			'if(e.target&&e.target.id==="vezmopay-frame"){window.vezmopayFrameLoaded=true;}' .
			'},true);',
			array( 'id' => 'vezmopay-embed-early' )
		);

		$logo_url = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay.svg';

		echo '<div id="vezmopay-checkout" class="vezmopay-checkout" data-mode="' . esc_attr( $mode ) . '" data-theme="' . esc_attr( $this->checkout_theme() ) . '">';

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

		// Shopper just returned from VezmoPay's successUrl — verify against the
		// API now so the order shows as paid immediately, instead of waiting for
		// the webhook or the reconciliation cron.
		if ( $order->has_status( 'pending' ) && ( '' !== (string) $order->get_meta( '_vezmopay_payment_id' ) || '' !== (string) $order->get_meta( '_vezmopay_paylink_code' ) ) ) {
			$this->reconcile_order_with_api( $order );
			$order = wc_get_order( $order_id );
		}

		if ( $order->is_paid() ) {
			return;
		}
		if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			echo '<p>' . esc_html__( 'Your VezmoPay payment is being confirmed. You will receive an email as soon as it completes.', 'vezmopay-woocommerce' ) . '</p>';
		}
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

		// Guard against amount tampering / mismatched sessions.
		if ( isset( $payment['amount'] ) && abs( (float) $payment['amount'] - (float) $order->get_total() ) > 0.01 ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount from VezmoPay, 2: order total */
					__( 'VezmoPay amount mismatch: provider reports %1$s but the order total is %2$s. Order NOT completed automatically — review manually.', 'vezmopay-woocommerce' ),
					wc_price( (float) $payment['amount'], array( 'currency' => $order->get_currency() ) ),
					wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) )
				)
			);
			$order->update_status( 'on-hold' );
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
	 * Complete payment exactly once, storing the provider transaction id.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payment_id VezmoPay payment id (may be empty for paylink-only knowledge).
	 * @param string    $note       Order note.
	 */
	public function mark_order_paid( $order, $payment_id, $note ) {
		if ( $order->is_paid() ) {
			return;
		}
		$order->add_order_note( $note . ( $payment_id ? ' (' . sprintf( /* translators: %s: transaction id */ __( 'Transaction ID: %s', 'vezmopay-woocommerce' ), $payment_id ) . ')' : '' ) );
		$order->payment_complete( $payment_id );
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
				$detail .= ' — ' . __( 'Your VezmoPay API key is missing a required permission: embedded mode needs secure-payment.create, hosted mode needs paylink.create. Assign it to the key in the VezmoPay admin.', 'vezmopay-woocommerce' );
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
