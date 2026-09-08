<?php
/**
 * Cart-time secure payment session.
 *
 * Stripe's plugin can render card fields on the checkout page because it
 * tokenizes client-side and charges server-side. VezmoPay never hands the
 * plugin a token — its embed owns the fields AND the charge — so to keep the
 * shopper on the checkout page the payment session has to exist BEFORE the
 * order does: created for the cart total, mounted in the payment box, and then
 * bound to the order in process_payment().
 *
 * The session lives in the WooCommerce customer session, so a page reload keeps
 * the same VezmoPay payment instead of minting a new one per keystroke, and the
 * server — never the browser — is the only thing that decides which payment id
 * belongs to which order.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and caches the pre-order secure payment for the current cart.
 */
class Checkout_Session {

	/**
	 * WooCommerce session key.
	 */
	const SESSION_KEY = 'vezmopay_checkout_session';

	/**
	 * Rebuild the session when fewer than this many seconds of the token remain.
	 */
	const MIN_REMAINING = 120;

	/**
	 * Gateway.
	 *
	 * @var Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Gateway.
	 */
	public function __construct( Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * The amount the embed must be created for: the cart total, to 2dp.
	 *
	 * @return float
	 */
	private function cart_total() {
		if ( ! isset( WC()->cart ) || ! WC()->cart ) {
			return 0.0;
		}
		return (float) wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 );
	}

	/**
	 * Read the stored session, or null.
	 *
	 * @return array|null
	 */
	public function stored() {
		if ( ! isset( WC()->session ) || ! WC()->session ) {
			return null;
		}
		$stored = WC()->session->get( self::SESSION_KEY );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Forget the session (after it is bound to an order, or when it goes stale).
	 */
	public function forget() {
		if ( isset( WC()->session ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * Whether a stored session can still be used for this cart.
	 *
	 * A total that no longer matches (shipping chosen, coupon applied) must NOT
	 * be reused: the embed charges the amount it was created with, so a stale
	 * session would take the wrong money.
	 *
	 * @param array|null $session     Stored session.
	 * @param string     $environment Current environment.
	 * @param float      $amount      Current cart total.
	 * @return bool
	 */
	private function is_usable( $session, $environment, $amount ) {
		if ( ! $session || empty( $session['clientToken'] ) || empty( $session['paymentId'] ) ) {
			return false;
		}
		if ( ( $session['environment'] ?? '' ) !== $environment ) {
			return false;
		}
		if ( abs( (float) ( $session['amount'] ?? 0 ) - $amount ) > 0.001 ) {
			return false;
		}
		if ( ( $session['currency'] ?? '' ) !== get_woocommerce_currency() ) {
			return false;
		}
		return (int) ( $session['expires'] ?? 0 ) > time() + self::MIN_REMAINING;
	}

	/**
	 * Get a secure payment session for the current cart, creating one if needed.
	 *
	 * @return array|\WP_Error { paymentId, clientToken, url, sdkUrl, amount, currency, expires, environment }
	 */
	public function get() {
		$amount = $this->cart_total();
		if ( $amount < 0.01 ) {
			return new \WP_Error( 'vezmopay_amount', __( 'This cart total cannot be processed by VezmoPay.', 'vezmopay-woocommerce' ) );
		}

		$environment = $this->gateway->environment();
		$stored      = $this->stored();
		if ( $this->is_usable( $stored, $environment, $amount ) ) {
			return $stored;
		}

		$currency = get_woocommerce_currency();
		$payload  = array(
			'title'      => sprintf(
				/* translators: %s: site name */
				__( '%s — checkout', 'vezmopay-woocommerce' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'amount'     => $amount,
			'currency'   => $currency,
			'ttlMinutes' => Gateway::TOKEN_TTL_MINUTES,
		);

		// A fresh idempotency key per (cart, amount): reusing one with a changed
		// body is rejected by the API, and the amount is exactly what changes.
		$key  = 'wc-cart-' . substr( hash( 'sha256', ( WC()->session ? WC()->session->get_customer_id() : '' ) . '|' . $amount . '|' . $currency ), 0, 32 );
		$data = $this->gateway->api_client( $environment )->create_secure_payment( $payload, $key );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['securePayment']['clientToken'] ) || empty( $data['payment']['id'] ) ) {
			return new \WP_Error( 'vezmopay_response', __( 'VezmoPay returned an incomplete payment session.', 'vezmopay-woocommerce' ) );
		}

		$secure  = $data['securePayment'];
		$session = array(
			'paymentId'   => (string) $data['payment']['id'],
			'clientToken' => (string) $secure['clientToken'],
			'url'         => isset( $secure['url'] ) ? esc_url_raw( $secure['url'] ) : '',
			'sdkUrl'      => isset( $secure['sdkUrl'] ) ? esc_url_raw( $secure['sdkUrl'] ) : '',
			'amount'      => $amount,
			'currency'    => $currency,
			'environment' => $environment,
			'expires'     => ! empty( $secure['expiresAt'] ) ? strtotime( $secure['expiresAt'] ) : time() + Gateway::TOKEN_TTL_MINUTES * MINUTE_IN_SECONDS,
		);

		if ( isset( WC()->session ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $session );
		}
		return $session;
	}

	/**
	 * Move a cart session onto the order at process_payment() time.
	 *
	 * Returns false when the session cannot be trusted for this order — a total
	 * that drifted, a different currency, an expired token — and the caller then
	 * falls back to the pay-page flow rather than charging the wrong amount.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function bind_to_order( $order ) {
		$session = $this->stored();
		if ( ! $session ) {
			return false;
		}

		$total = (float) wc_format_decimal( $order->get_total(), 2 );
		if ( abs( (float) $session['amount'] - $total ) > 0.001 ) {
			return false;
		}
		if ( $session['currency'] !== $order->get_currency() ) {
			return false;
		}
		if ( (int) $session['expires'] <= time() + self::MIN_REMAINING ) {
			return false;
		}

		$order->update_meta_data( '_vezmopay_payment_id', $session['paymentId'] );
		$order->update_meta_data( '_vezmopay_client_token', $session['clientToken'] );
		$order->update_meta_data( '_vezmopay_iframe_url', $session['url'] );
		$order->update_meta_data( '_vezmopay_sdk_url', $session['sdkUrl'] );
		$order->update_meta_data( '_vezmopay_token_expires', (int) $session['expires'] );
		$order->update_meta_data( '_vezmopay_environment', $session['environment'] );
		$order->save();

		// One session, one order: never let a second order inherit this payment.
		$this->forget();
		return true;
	}
}
