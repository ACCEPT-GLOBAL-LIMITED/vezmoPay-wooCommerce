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
		$this->icon               = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg';
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
	 * @return string 'element'|'iframe'|'hosted'
	 */
	public function integration_mode() {
		$mode = $this->get_option( 'integration_mode', 'element' );
		return in_array( $mode, array( 'element', 'iframe', 'hosted' ), true ) ? $mode : 'element';
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
		return untrailingslashit( $this->get_option( $environment . '_checkout_base', 'live' === $environment ? Settings::DEFAULT_LIVE_CHECKOUT : Settings::DEFAULT_TEST_CHECKOUT ) );
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
		Connect::maybe_render_connect_notices( $this );
		if ( $this->is_test_mode() ) {
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
		parent::admin_options();
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
				<p class="description">
					<?php esc_html_e( 'Log in to your VezmoPay account and your API credentials will be created and filled in automatically. Connecting deactivates any previous API key on your account. Prefer manual setup? Paste a key and secret below instead.', 'vezmopay-woocommerce' ); ?>
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
				$('#vezmopay-test-connection').on('click', function(e){
					e.preventDefault();
					var btn=$(this), out=$('#vezmopay-test-connection-result');
					btn.prop('disabled',true); out.text('…');
					$.post(ajaxurl,{action:'vezmopay_test_connection',nonce:'" . esc_js( $nonce ) . "',environment:$('#woocommerce_vezmopay_environment').val()},function(r){
						out.css('color', r.success?'green':'#d63638').text(r.data&&r.data.message?r.data.message:'Error');
					}).fail(function(x){
						var m=(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message)?x.responseJSON.data.message:'Request failed';
						out.css('color','#d63638').text(m);
					}).always(function(){ btn.prop('disabled',false); });
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
				<span id="vezmopay-test-connection-result" style="margin-left:8px;"></span>
				<p class="description">
					<?php esc_html_e( 'Validates the saved API key and secret for the selected environment against the VezmoPay API. Save your changes first.', 'vezmopay-woocommerce' ); ?>
				</p>
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

		// Send the customer to the pay page where the element/iframe is rendered.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
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
		);

		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' !== $name && '' !== $order->get_billing_email() ) {
			$payload['client'] = array_filter(
				array(
					'name'    => $name,
					'email'   => $order->get_billing_email(),
					'phone'   => $order->get_billing_phone(),
					'company' => $order->get_billing_company(),
					'country' => $order->get_billing_country(),
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

		// Refresh the session if the token expired while the customer idled.
		$ready = $this->ensure_secure_payment( $order );
		if ( is_wp_error( $ready ) ) {
			echo '<div class="woocommerce-error">' . esc_html( $this->customer_facing_error( $ready ) ) . '</div>';
			return;
		}

		$mode       = $this->integration_mode();
		$iframe_url = (string) $order->get_meta( '_vezmopay_iframe_url' );
		$sdk_url    = (string) $order->get_meta( '_vezmopay_sdk_url' );

		wp_enqueue_style( 'vezmopay', VEZMOPAY_WC_PLUGIN_URL . 'assets/css/vezmopay.css', array(), VEZMOPAY_WC_VERSION );

		$params = array(
			'mode'         => $mode,
			'apiBase'      => $this->api_client()->host(),
			'orderId'      => $order->get_id(),
			'orderKey'     => $order->get_order_key(),
			'clientToken'  => (string) $order->get_meta( '_vezmopay_client_token' ),
			'iframeUrl'    => $iframe_url,
			'confirmUrl'   => \WC_AJAX::get_endpoint( 'vezmopay_confirm' ),
			'statusUrl'    => \WC_AJAX::get_endpoint( 'vezmopay_status' ),
			'nonce'        => wp_create_nonce( 'vezmopay-checkout' ),
			'pollInterval' => 4000,
			'i18n'         => array(
				'processing' => __( 'Processing your payment…', 'vezmopay-woocommerce' ),
				'pending'    => __( 'Your bank payment is processing. We will email you when it completes.', 'vezmopay-woocommerce' ),
				'failed'     => __( 'Payment failed. Please try again or use a different payment method.', 'vezmopay-woocommerce' ),
				'expired'    => __( 'This payment session expired. Reloading…', 'vezmopay-woocommerce' ),
				'error'      => __( 'Something went wrong. Please try again.', 'vezmopay-woocommerce' ),
				'review'     => __( 'We received your payment, but this order needs a quick manual review before it is confirmed. Please contact us — do not pay again.', 'vezmopay-woocommerce' ),
			),
		);

		if ( 'element' === $mode && '' !== $sdk_url ) {
			// VezmoPay's first-party embed SDK (defines window.Vezmo).
			wp_enqueue_script( 'vezmopay-sdk', $sdk_url, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote SDK, provider-versioned.
			wp_enqueue_script( 'vezmopay-element', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-element.js', array( 'vezmopay-sdk' ), VEZMOPAY_WC_VERSION, true );
			wp_localize_script( 'vezmopay-element', 'vezmopay_params', $params );
		} else {
			wp_enqueue_script( 'vezmopay-iframe', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-iframe.js', array(), VEZMOPAY_WC_VERSION, true );
			wp_localize_script( 'vezmopay-iframe', 'vezmopay_params', $params );
		}

		$logo_url = VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmopay.svg';
		$lock_svg = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

		echo '<div id="vezmopay-checkout" class="vezmopay-checkout" data-mode="' . esc_attr( $mode ) . '">';

		echo '<div class="vezmopay-header">';
		echo '<img class="vezmopay-logo" src="' . esc_url( $logo_url ) . '" alt="VezmoPay" />';
		echo '<span class="vezmopay-secure-pill"><span class="vezmopay-lock">' . $lock_svg . '</span>' . esc_html__( 'Secure payment · 256-bit TLS', 'vezmopay-woocommerce' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG defined above.
		echo '</div>';

		if ( $this->is_test_mode() ) {
			echo '<span class="vezmopay-test-badge">' . esc_html__( 'Test mode — no real money will move', 'vezmopay-woocommerce' ) . '</span>';
		}

		echo '<div class="vezmopay-body">';
		echo '<div class="vezmopay-loading" aria-hidden="true"><span class="vezmopay-spinner"></span>' . esc_html__( 'Preparing your secure payment…', 'vezmopay-woocommerce' ) . '</div>';
		echo '<div id="vezmopay-container" class="vezmopay-container">';
		if ( 'iframe' === $mode || '' === $sdk_url ) {
			// Graceful-degradation path: a plain iframe works even if our JS fails;
			// the order is then completed by webhook.
			echo '<iframe id="vezmopay-frame" src="' . esc_url( $iframe_url ) . '" allow="payment" title="' . esc_attr__( 'VezmoPay secure payment', 'vezmopay-woocommerce' ) . '"></iframe>';
		}
		echo '</div>';
		echo '</div>';

		echo '<p id="vezmopay-message" class="vezmopay-message" role="status" aria-live="polite"></p>';
		echo '<noscript><p class="vezmopay-message is-info" style="display:block;">' . esc_html__( 'JavaScript is disabled. After paying in the secure form above, your order will be confirmed by email once VezmoPay notifies us.', 'vezmopay-woocommerce' ) . '</p></noscript>';

		echo '<div class="vezmopay-footer">';
		echo '<span class="vezmopay-powered">' . esc_html__( 'Powered by', 'vezmopay-woocommerce' ) . ' <img src="' . esc_url( $logo_url ) . '" alt="VezmoPay" /></span>';
		echo '<span class="vezmopay-trust"><span>' . esc_html__( 'PCI DSS', 'vezmopay-woocommerce' ) . '</span><span>' . esc_html__( '3-D Secure', 'vezmopay-woocommerce' ) . '</span></span>';
		echo '</div>';

		echo '</div>';
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

		wc_add_notice( $this->customer_facing_error( $error ), 'error' );

		if ( current_user_can( 'manage_woocommerce' ) ) {
			$detail = $error->get_error_message();
			if ( 'vezmopay_http_403' === $error->get_error_code() ) {
				$detail .= ' — ' . __( 'Your VezmoPay API key is missing a required permission: element/iframe modes need secure-payment.create, hosted mode needs paylink.create. Assign it to the key in the VezmoPay admin.', 'vezmopay-woocommerce' );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: technical error detail (shown to store managers only) */
					__( 'VezmoPay (visible to store managers only): %s', 'vezmopay-woocommerce' ),
					$detail
				),
				'notice'
			);
		}
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
