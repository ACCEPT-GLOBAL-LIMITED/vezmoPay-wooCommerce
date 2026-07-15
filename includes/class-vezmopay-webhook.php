<?php
/**
 * Webhook receiver: POST /wp-json/vezmopay/v1/webhook
 *
 * VezmoPay delivers `{ id, event, data }` envelopes for payment.success / payment.failed
 * with up to 4 retries over 24h and NO ordering guarantee. The platform's HMAC signing
 * (X-Webhook-Signature) is currently disabled server-side, so this receiver treats every
 * webhook as an untrusted hint: it verifies the signature when one is sent, and it NEVER
 * updates an order from payload data alone — it re-fetches the payment/paylink state from
 * the VezmoPay API (using references stored on the order) before changing anything.
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

		$event    = sanitize_text_field( (string) $body['event'] );
		$event_id = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		$data     = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		// Signature verification — enforced whenever VezmoPay sends one.
		$signature = $request->get_header( 'x-webhook-signature' );
		$secret    = (string) $gateway->get_option( 'webhook_secret' );
		if ( is_string( $signature ) && '' !== $signature ) {
			if ( '' === $secret ) {
				$logger->error( 'Webhook signature received but no webhook secret is configured; rejecting.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'no-secret' ), 401 );
			}
			$expected = hash_hmac( 'sha256', $raw, $secret );
			if ( ! hash_equals( $expected, strtolower( trim( $signature ) ) ) ) {
				$logger->error( 'Webhook signature mismatch; rejecting.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'bad-signature' ), 401 );
			}
		} else {
			// Platform currently sends unsigned webhooks — allowed, because nothing below
			// trusts the payload; state is re-verified against the API.
			$logger->debug( 'Unsigned webhook received (platform signing not enabled).' );
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

		// After a paylink order is confirmed paid, remember the payment id from the
		// (now API-corroborated) event for the admin transaction reference.
		if ( 'CAPTURED' === $result && '' === (string) $order->get_meta( '_vezmopay_payment_id' ) && ! empty( $data['id'] ) ) {
			$order->update_meta_data( '_vezmopay_payment_id', sanitize_text_field( (string) $data['id'] ) );
			if ( ! $order->get_transaction_id() ) {
				$order->set_transaction_id( sanitize_text_field( (string) $data['id'] ) );
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
