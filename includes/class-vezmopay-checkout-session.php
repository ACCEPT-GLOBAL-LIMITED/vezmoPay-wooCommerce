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
	 * Session key holding the idempotency key of a creation request that is in
	 * flight, so ONLY an identical retry can reuse it.
	 */
	const PENDING_KEY = 'vezmopay_checkout_pending_key';

	/**
	 * The only status a freshly created, unpaid secure payment may report before
	 * it is bound to an order.
	 *
	 * TODO(platform): confirm that a created-but-unpaid secure payment always
	 * reports exactly INITIATED. Anything else is refused here, which costs a
	 * fallback to the pay page (still payable) rather than risking a bind to a
	 * payment that has already been captured.
	 */
	const BINDABLE_STATUS = 'INITIATED';

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
		if ( ! is_array( $stored ) ) {
			return null;
		}

		// Every consumer of this array dereferences these keys, and a session can
		// hold a record written by an older version of the plugin (or a partially
		// written one). Treat anything incomplete as absent rather than trusting
		// a half-built payment session.
		foreach ( array( 'paymentId', 'clientToken', 'url', 'amount', 'currency', 'environment', 'expires' ) as $required ) {
			if ( ! isset( $stored[ $required ] ) ) {
				return null;
			}
		}
		if ( '' === (string) $stored['paymentId'] || '' === (string) $stored['clientToken'] || '' === (string) $stored['url'] ) {
			return null;
		}
		if ( ! is_numeric( $stored['amount'] ) || ! is_numeric( $stored['expires'] ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Forget the session (after it is bound to an order, or when it goes stale).
	 */
	public function forget() {
		if ( isset( WC()->session ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
			// The key dies with the session it created. Leaving it behind is what
			// let a captured payment be replayed onto the next order.
			WC()->session->set( self::PENDING_KEY, null );
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

		// The merchant's label for this store, so their VezmoPay transactions name
		// the site the money came from. Omitted when unset: the API then falls back
		// to the session title above, which already carries the site name.
		//
		// Deliberately NOT part of creation_key(): the key is a random per-creation
		// UUID reused only to re-send a request that failed in transit, and the API
		// compares amount/currency/environment/title/description/dueDate on a reuse.
		// A label edited between a transport failure and its retry is a cosmetic
		// difference and must not turn the retry into an error.
		$descriptor = $this->gateway->descriptor();
		if ( '' !== $descriptor ) {
			$payload['descriptor'] = $descriptor;
		}

		$key  = $this->creation_key( $amount, $currency, $environment );
		$data = $this->gateway->api_client( $environment )->create_secure_payment( $payload, $key );
		if ( is_wp_error( $data ) ) {
			// Keep the pending key ONLY for a transport failure, where the request
			// may have reached the API and a retry must be idempotent. Any other
			// error means the next attempt starts clean with a new key.
			if ( 'vezmopay_transport' !== $data->get_error_code() ) {
				$this->clear_pending_key();
			}
			return $data;
		}
		if ( empty( $data['securePayment']['clientToken'] ) || empty( $data['payment']['id'] ) ) {
			return new \WP_Error( 'vezmopay_response', __( 'VezmoPay returned an incomplete payment session.', 'vezmopay-woocommerce' ) );
		}

		$secure  = $data['securePayment'];
		$session = array(
			'idemKey'     => $key,
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
		// The key is now spent on a real payment; it must never open a second one.
		$this->clear_pending_key();
		return $session;
	}

	/**
	 * The idempotency key for a creation request.
	 *
	 * A key that is a pure function of customer, amount and currency — with a
	 * request body that is equally stable — means the API replays its stored
	 * response, so a second cart of the same value received the FIRST cart's
	 * already-captured payment and completed for free. So: a random key per
	 * creation, remembered only while that exact request is in flight, and reused
	 * only to re-send an identical request that failed in transit (which is what
	 * idempotency keys are for).
	 *
	 * @param float  $amount      Cart total.
	 * @param string $currency    Store currency.
	 * @param string $environment 'test'|'live'.
	 * @return string
	 */
	private function creation_key( $amount, $currency, $environment ) {
		$session = isset( WC()->session ) ? WC()->session : null;
		$pending = $session ? $session->get( self::PENDING_KEY ) : null;

		if (
			is_array( $pending )
			&& ! empty( $pending['key'] )
			&& isset( $pending['amount'], $pending['currency'], $pending['environment'] )
			&& abs( (float) $pending['amount'] - $amount ) < 0.001
			&& $pending['currency'] === $currency
			&& $pending['environment'] === $environment
		) {
			return (string) $pending['key'];
		}

		$key = 'wc-cart-' . wp_generate_uuid4();
		if ( $session ) {
			$session->set(
				self::PENDING_KEY,
				array(
					'key'         => $key,
					'amount'      => $amount,
					'currency'    => $currency,
					'environment' => $environment,
				)
			);
		}
		return $key;
	}

	/**
	 * Drop the in-flight key so the next creation starts from a new one.
	 */
	private function clear_pending_key() {
		if ( isset( WC()->session ) && WC()->session ) {
			WC()->session->set( self::PENDING_KEY, null );
		}
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

		// ONE validation path, shared with get(): a subset re-implemented here
		// silently dropped the environment check, so a session created against
		// test could be bound to an order stamped live — and the environment
		// written below would then send reconciliation to the wrong API.
		$total = (float) wc_format_decimal( $order->get_total(), 2 );
		if ( ! $this->is_usable( $session, $this->gateway->environment(), $total ) ) {
			return false;
		}
		if ( $session['currency'] !== $order->get_currency() ) {
			return false;
		}
		if ( ! $this->is_bindable( $session, $order ) ) {
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

	/**
	 * Whether the session's payment is still an unpaid payment we may bind.
	 *
	 * Read live from the API rather than trusted from the session: a payment that
	 * has already been captured must never be attached to a second order, and the
	 * session cannot know that it was. Fails CLOSED — an unreadable or
	 * non-INITIATED payment refuses the bind, and process_payment() then falls
	 * back to the pay-page flow, which creates its own session.
	 *
	 * @param array     $session Stored session.
	 * @param \WC_Order $order   Order being bound.
	 * @return bool
	 */
	private function is_bindable( $session, $order ) {
		$payment = $this->gateway
			->api_client( $session['environment'] )
			->get_payment( $session['paymentId'] );

		if ( is_wp_error( $payment ) ) {
			$this->gateway->logger()->error(
				'Refusing to bind payment ' . $session['paymentId'] . ' to order #' . $order->get_id()
				. ': could not read its state (' . $payment->get_error_message() . ').'
			);
			return false;
		}

		$status = isset( $payment['status'] ) ? strtoupper( (string) $payment['status'] ) : '';
		if ( self::BINDABLE_STATUS !== $status ) {
			$this->gateway->logger()->error(
				'Refusing to bind payment ' . $session['paymentId'] . ' to order #' . $order->get_id()
				. ': status is ' . ( '' === $status ? 'unknown' : $status ) . ', not ' . self::BINDABLE_STATUS . '.'
			);
			return false;
		}

		return true;
	}
}
