<?php
/**
 * Webhook receiver: POST /wp-json/vezmopay/v1/webhook
 *
 * VezmoPay delivers `{ id, event, data }` envelopes for payment.success / payment.failed
 * with up to 4 retries over 24h and NO ordering guarantee. This receiver treats every
 * webhook as an untrusted hint: once a webhook secret is configured EVERY delivery must
 * carry a valid signature, and no order is ever updated from payload data alone — the
 * payment/paylink state is re-fetched from the VezmoPay API (using references stored on
 * the order) before anything changes. The one value read from a payload, the payment id
 * for a paylink order's transaction reference, is only kept after the API confirms it
 * belongs to that order.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * REST webhook controller.
 */
class Webhook {

	/**
	 * REST namespace/route.
	 */
	const REST_NAMESPACE = 'vezmopay/v1';
	const REST_ROUTE     = '/webhook';

	/**
	 * Deliveries allowed per sender per THROTTLE_WINDOW before 429s begin.
	 */
	const THROTTLE_MAX    = 60;
	const THROTTLE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * The public webhook URL for this store.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Register the REST route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				// Authenticity is established by signature check + API re-verification,
				// not by a WP capability — VezmoPay has no WP credentials.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a webhook delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$gateway = Plugin::instance()->gateway();
		if ( ! $gateway ) {
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'gateway-unavailable' ), 503 );
		}
		$logger = $gateway->logger();

		$raw  = $request->get_body();
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || empty( $body['event'] ) ) {
			$logger->error( 'Webhook rejected: malformed payload.' );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'malformed' ), 400 );
		}

		// Rate limit ahead of everything that costs money or time. The endpoint is
		// necessarily unauthenticated at the WP layer, and each delivery can drive
		// a 20-second outbound API call — an easy way to burn PHP workers and the
		// merchant's API rate limit.
		if ( $this->is_throttled( $request ) ) {
			$logger->error( 'Webhook throttled: too many deliveries from ' . $this->client_fingerprint( $request ) . '.' );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'throttled' ), 429 );
		}

		$event    = sanitize_text_field( (string) $body['event'] );
		$event_id = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		$data     = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		// Signature verification. With a secret configured, a signature is
		// MANDATORY: accepting unsigned deliveries meant the header could simply
		// be omitted, which left an unauthenticated way to drive outbound API
		// calls. Without a secret there is nothing to verify against, and the
		// permissive path stays only for that case.
		$signature = $request->get_header( 'x-webhook-signature' );
		$signature = is_string( $signature ) ? trim( $signature ) : '';
		$secret    = (string) $gateway->get_option( 'webhook_secret' );

		if ( '' !== $secret ) {
			if ( '' === $signature ) {
				$logger->error( 'Webhook rejected: a webhook secret is configured but the delivery carried no signature.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'signature-required' ), 401 );
			}
			if ( ! $this->signature_valid( $raw, $signature, $secret ) ) {
				$logger->error( 'Webhook signature mismatch; rejecting.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'bad-signature' ), 401 );
			}
		} elseif ( '' !== $signature ) {
			$logger->error( 'Webhook signature received but no webhook secret is configured; rejecting.' );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'no-secret' ), 401 );
		} else {
			// No secret saved, so there is nothing to verify against. Reconnect (or
			// paste the secret) to make signatures mandatory.
			$logger->debug( 'Unsigned webhook accepted: no webhook secret is configured for this store.' );
		}

		$logger->debug( 'Webhook received: ' . $event, array( 'event_id' => $event_id ) );

		if ( ! in_array( $event, array( 'payment.success', 'payment.failed' ), true ) ) {
			// Not a payment event (invoice.*, proposal.* etc.) — acknowledge and ignore.
			return new \WP_REST_Response( array( 'received' => true, 'handled' => false ), 200 );
		}

		$order = $this->find_order( $data );
		if ( ! $order ) {
			$logger->debug( 'Webhook ' . $event . ' did not match any order; ignoring.', array( 'data_id' => isset( $data['id'] ) ? $data['id'] : null ) );
			// 200 so the platform does not retry an event we can never match.
			return new \WP_REST_Response( array( 'received' => true, 'handled' => false ), 200 );
		}

		// Idempotency: skip envelopes we have already processed for this order.
		if ( '' !== $event_id ) {
			$processed = (array) $order->get_meta( '_vezmopay_processed_events' );
			if ( in_array( $event_id, $processed, true ) ) {
				return new \WP_REST_Response( array( 'received' => true, 'handled' => true, 'duplicate' => true ), 200 );
			}
		}

		// Authoritative reconciliation via the API (never from the payload).
		$result = $gateway->reconcile_order_with_api( $order );
		if ( is_wp_error( $result ) ) {
			$logger->error( 'Webhook reconciliation failed for order #' . $order->get_id() . ': ' . $result->get_error_message() );
			// 500 → the platform retries later (up to 4 attempts over 24h).
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'verify-failed' ), 500 );
		}

		// After a paylink order is confirmed paid, record the payment id for the
		// admin transaction reference — but only once the API says that payment is
		// captured for THIS order's money. The id arrives in an unauthenticated
		// payload, so reading it back is the difference between a reference and a
		// value an attacker chose.
		if ( 'CAPTURED' === $result && '' === (string) $order->get_meta( '_vezmopay_payment_id' ) && ! empty( $data['id'] ) ) {
			$candidate = sanitize_text_field( (string) $data['id'] );
			if ( $gateway->payment_belongs_to_order( $order, $candidate ) ) {
				$order->update_meta_data( '_vezmopay_payment_id', $candidate );
				if ( ! $order->get_transaction_id() ) {
					$order->set_transaction_id( $candidate );
				}
			} else {
				$logger->error(
					'Webhook payload offered payment id ' . $candidate . ' for order #' . $order->get_id()
					. ', but the API does not corroborate it; not stored.'
				);
			}
		}

		if ( '' !== $event_id ) {
			$processed   = (array) $order->get_meta( '_vezmopay_processed_events' );
			$processed[] = $event_id;
			$order->update_meta_data( '_vezmopay_processed_events', array_slice( $processed, -25 ) );
		}
		$order->save();

		return new \WP_REST_Response( array( 'received' => true, 'handled' => true, 'status' => $result ), 200 );
	}

	/**
	 * Verify a delivery signature.
	 *
	 * Accepts bare lowercase hex (what the platform sends today), an optional
	 * `sha256=` prefix, and base64 — so a format change on the platform side does
	 * not reject every delivery for the 24 hours of its retry window. Comparison
	 * is always hash_equals against the raw body's HMAC.
	 *
	 * TODO(platform): confirm the wire format (and whether the prefix is used) so
	 * the tolerated set can be narrowed back down to exactly what is sent.
	 *
	 * @param string $raw       Raw request body.
	 * @param string $signature Header value, already trimmed.
	 * @param string $secret    Configured signing secret.
	 * @return bool
	 */
	private function signature_valid( $raw, $signature, $secret ) {
		if ( 0 === stripos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		$signature = trim( $signature );
		if ( '' === $signature ) {
			return false;
		}

		$hex = hash_hmac( 'sha256', $raw, $secret );
		if ( hash_equals( $hex, strtolower( $signature ) ) ) {
			return true;
		}

		$binary = hash_hmac( 'sha256', $raw, $secret, true );
		return hash_equals( base64_encode( $binary ), $signature );
	}

	/**
	 * Coarse identity for throttling: the requesting IP, or the reference in the
	 * payload when the IP is unavailable (proxied setups).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function client_fingerprint( \WP_REST_Request $request ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $ip ) {
			return 'ip:' . $ip;
		}
		$body = json_decode( $request->get_body(), true );
		$ref  = is_array( $body ) && ! empty( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : 'unknown';
		return 'ref:' . $ref;
	}

	/**
	 * Whether this sender has exceeded the delivery allowance.
	 *
	 * Deliberately generous — the platform retries legitimately, and a burst of
	 * real events must not be dropped — but bounded, so the endpoint cannot be
	 * used to drive unlimited outbound API calls.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_throttled( \WP_REST_Request $request ) {
		$key   = 'vezmopay_wh_' . md5( $this->client_fingerprint( $request ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::THROTTLE_MAX ) {
			return true;
		}
		set_transient( $key, $count + 1, self::THROTTLE_WINDOW );
		return false;
	}

	/**
	 * Locate the order a webhook refers to, using only references this plugin stored.
	 *
	 * @param array $data Event data payload.
	 * @return \WC_Order|null
	 */
	private function find_order( array $data ) {
		// Secure-payment / element / iframe orders: match the payment id we created.
		foreach ( array( 'paymentId', 'id' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}
			$order = $this->find_order_by_meta( '_vezmopay_payment_id', (string) $data[ $key ] );
			if ( $order ) {
				return $order;
			}
		}

		// Hosted (paylink) orders: match the paylink id stored at creation.
		if ( ! empty( $data['paylinkId'] ) ) {
			$order = $this->find_order_by_meta( '_vezmopay_paylink_id', (string) $data['paylinkId'] );
			if ( $order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * HPOS-compatible meta lookup.
	 *
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return \WC_Order|null
	 */
	private function find_order_by_meta( $meta_key, $meta_value ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required lookup, indexed by HPOS meta table.
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);
		return $orders ? $orders[0] : null;
	}
}
