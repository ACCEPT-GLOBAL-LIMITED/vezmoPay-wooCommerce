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

	/**
	 * Unconditional ceiling per sender per window, applied before any signature
	 * is computed. High enough that no genuine delivery rate reaches it; it
	 * exists so the endpoint cannot be used to make the store compute HMACs.
	 */
	const THROTTLE_CEILING = 600;
	const THROTTLE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * How long an event claim is honoured before a retry may take it over.
	 * Comfortably longer than a reconcile, shorter than the platform's 24h
	 * retry window.
	 */
	const EVENT_CLAIM_TTL = 10 * MINUTE_IN_SECONDS;

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
			$this->log_refusal( $logger, 'malformed', 'Webhook rejected: malformed payload.' );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'malformed' ), 400 );
		}

		// Rate limit ahead of everything that costs money or time. The endpoint is
		// necessarily unauthenticated at the WP layer, and each delivery can drive
		// a 20-second outbound API call — an easy way to burn PHP workers and the
		// merchant's API rate limit.
		// An unconditional ceiling, ahead of any HMAC work, purely so the endpoint
		// cannot be used to burn CPU. Deliberately far above any real delivery
		// rate — the RATE limit below applies only to traffic that turns out to be
		// unauthenticated, so a store taking hundreds of orders an hour can never
		// have genuine signed deliveries refused by it. That was the risk in the
		// old arrangement: one throttle, before the signature check, at 60 per five
		// minutes per address, which a single-egress platform would trip for a
		// store above roughly twelve orders a minute.
		if ( $this->is_throttled( $request, 'all', self::THROTTLE_CEILING ) ) {
			$this->log_refusal( $logger, 'throttled', 'Webhook ceiling reached for ' . $this->client_fingerprint( $request ) . '; refusing until the window rolls.' );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => 'throttled' ), 429 );
		}

		$event    = sanitize_text_field( (string) $body['event'] );
		$event_id = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		$data     = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		// Signature verification, and there is no permissive path past it. With a
		// secret configured a signature is MANDATORY — accepting unsigned
		// deliveries left the header optional, which is no check at all. Without a
		// secret nothing here can be authenticated, so nothing is processed; the
		// cost of that is reconciliation latency, never correctness, because the
		// five-minute cron and the checkout's own polling settle every order
		// regardless.
		$signature = $request->get_header( 'x-webhook-signature' );
		$signature = is_string( $signature ) ? trim( $signature ) : '';
		$secret    = (string) $gateway->get_option( 'webhook_secret' );

		// A refusal is unauthenticated traffic, and THAT is what the rate limit is
		// for. Counted once per refused delivery, whatever the reason.
		$refuse = function ( $reason, $message ) use ( $logger, $request ) {
			if ( $this->is_throttled( $request, 'bad', self::THROTTLE_MAX ) ) {
				$this->log_refusal( $logger, 'throttled', 'Too many unauthenticated webhook deliveries from ' . $this->client_fingerprint( $request ) . '.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'throttled' ), 429 );
			}
			$this->log_refusal( $logger, $reason, $message );
			return new \WP_REST_Response( array( 'received' => false, 'reason' => $reason ), 401 );
		};

		if ( '' !== $secret ) {
			if ( '' === $signature ) {
				return $refuse( 'signature-required', 'Webhook rejected: a webhook secret is configured but the delivery carried no signature.' );
			}
			if ( ! $this->signature_valid( $raw, $signature, $secret ) ) {
				return $refuse( 'bad-signature', 'Webhook signature mismatch; rejecting.' );
			}
		} else {
			// No secret saved: NOTHING that arrives here can be authenticated, so
			// nothing is processed. 0.3.5 accepted these — the reasoning was that
			// a forged payload is harmless because every delivery is re-read from
			// the API before an order is touched, which is true of the ORDER but
			// not of the store: each accepted delivery drives a blocking outbound
			// API call, so an unauthenticated endpoint is an amplifier pointed at
			// the merchant's own rate limit. Signed-but-unverifiable is the same
			// position — a signature this store cannot check is not a credential.
			//
			// The cost is reconciliation speed, not correctness: the five-minute
			// cron and the checkout's own polling still settle every order. The
			// merchant is told in the admin (see Plugin::webhook_secret_notice()),
			// not only in a log nobody reads.
			return $refuse(
				'no-secret',
				'Webhook rejected: this store has no webhook secret saved, so the delivery could not be authenticated. '
				. 'Paste the whsec_… secret from the VezmoPay dashboard into the gateway settings.'
			);
		}

		// Authenticated. Anything the admin was being warned about is resolved.
		$this->note_accepted();

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

		// Idempotency, claimed before any work and with a primitive that really is
		// atomic. The original sequence was check-meta, reconcile, write-meta —
		// three steps, so two concurrent deliveries of one event both passed the
		// check — and the record was a last-25 slice on the order, so a late retry
		// of an older event was reprocessed once 25 newer ones had arrived. Its
		// replacement claimed with add_option(), which reads through the object
		// cache before writing and is therefore not a mutex either (see Lock).
		// A delivery with no envelope id was never claimed, so the same POST could
		// be replayed indefinitely and each replay drove a fresh reconcile. Claim
		// on the body's own digest instead: identical bodies are the same event,
		// which is exactly what the id would have told us.
		if ( '' === $event_id ) {
			$event_id = 'raw-' . md5( (string) $raw );
		}
		$claim = $this->claim_event( $event_id );
		if ( ! $claim ) {
			$logger->debug( 'Webhook ' . $event_id . ' is already claimed; treating as a duplicate.' );
			return new \WP_REST_Response( array( 'received' => true, 'handled' => true, 'duplicate' => true ), 200 );
		}

		// Authoritative reconciliation via the API (never from the payload).
		$result = $gateway->reconcile_order_with_api( $order );
		if ( is_wp_error( $result ) || 'LOCKED' === $result ) {
			// Release the claim: this delivery did no work, and the platform's
			// retry (4 attempts over 24h) must not be swallowed as a duplicate.
			$this->release_event( $claim );
			if ( 'LOCKED' === $result ) {
				$logger->debug( 'Webhook for order #' . $order->get_id() . ' arrived while a reconcile was running; asking for a retry.' );
				return new \WP_REST_Response( array( 'received' => false, 'reason' => 'busy' ), 503 );
			}
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

		$order->save();

		return new \WP_REST_Response( array( 'received' => true, 'handled' => true, 'status' => $result ), 200 );
	}

	/**
	 * Claim this event, or report that another delivery already has it.
	 *
	 * Built on Lock for the reasons that class documents: add_option() reads
	 * through the object cache before it writes, so two deliveries of one event
	 * could both believe they had claimed it — and the release deleted by NAME,
	 * so a delivery told "busy" could free the claim of the delivery actually
	 * doing the work. The platform's retry then drove the whole reconcile again
	 * for an event already handled: no double completion (the reconcile lock
	 * stops that) but a wasted blocking API call per replay, which is exactly
	 * the idempotency this code exists to provide.
	 *
	 * Kept deliberately on the success path: the claim IS the duplicate record,
	 * and it must outlive the request until EVENT_CLAIM_TTL expires.
	 *
	 * @param string $event_id Event id from the envelope, or a raw-<md5> stand-in.
	 * @return Lock|null
	 */
	private function claim_event( $event_id ) {
		return Lock::claim( self::event_claim_key( $event_id ), self::EVENT_CLAIM_TTL );
	}

	/**
	 * Give the claim back, so the platform's retry is not swallowed as a
	 * duplicate — ours only, never whatever claim happens to be there.
	 *
	 * @param Lock|null $claim Claim returned by claim_event().
	 */
	private function release_event( $claim ) {
		if ( $claim instanceof Lock ) {
			$claim->release();
		}
	}

	/**
	 * Option name for an event claim. Hashed, because an event id is
	 * attacker-supplied text and option names have a length limit.
	 *
	 * @param string $event_id Event id.
	 * @return string
	 */
	private static function event_claim_key( $event_id ) {
		return 'vezmopay_evt_' . md5( $event_id );
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
	/**
	 * Log a refusal loudly the first time and quietly after that.
	 *
	 * Every refusal branch used to call error(), which logs whatever the Debug
	 * setting says — so a store that has not pasted its webhook secret (the
	 * default for every manual-key install) wrote one error line per delivery,
	 * times the platform's four retries, for ever. A hundred orders a day buried
	 * the errors a merchant actually needs under hundreds of identical lines,
	 * and made a correctly-behaving endpoint read as broken.
	 *
	 * The response is untouched: same status, same reason. Only the volume of
	 * the log changes. The merchant is told properly by
	 * Plugin::webhook_secret_notice(), which is a channel they will actually see.
	 *
	 * @param Logger $logger  Logger.
	 * @param string $reason  Refusal reason, used as the throttle key.
	 * @param string $message What to write.
	 */
	/**
	 * Key under which the last refusal is remembered for the admin notice.
	 */
	const HEALTH_KEY = 'vezmopay_wh_health';

	/**
	 * What a merchant should actually DO about each refusal.
	 *
	 * The log said what happened and left them to infer the rest. These are the
	 * two states a correctly-installed store can still be in, and neither is
	 * guessable from "signature mismatch":
	 *
	 *  - signature-required: the endpoint record at VezmoPay has no signing
	 *    secret, so the platform delivers unsigned by design and logs that it
	 *    did. Nothing pasted into this store can fix that; the endpoint has to be
	 *    recreated, because the secret is generated once, at creation.
	 *  - bad-signature: the delivery WAS signed, with a different secret from the
	 *    one saved here — a second endpoint, or one whose secret was regenerated.
	 *
	 * @param string $reason Refusal reason.
	 * @return string
	 */
	public static function refusal_remedy( $reason ) {
		switch ( $reason ) {
			case 'signature-required':
				return __( 'VezmoPay is delivering these events unsigned, which means the webhook endpoint in your VezmoPay dashboard has no signing secret. A secret is only generated when an endpoint is created, so delete that endpoint, create it again, and paste the new whsec_… value here.', 'vezmopay-woocommerce' );
			case 'bad-signature':
				return __( 'These deliveries are signed with a different secret from the one saved here. Copy the whsec_… value for this exact endpoint from your VezmoPay dashboard again — or, if it was regenerated, paste the new one.', 'vezmopay-woocommerce' );
			case 'no-secret':
				return __( 'Paste the whsec_… secret from your VezmoPay dashboard into the gateway settings.', 'vezmopay-woocommerce' );
			case 'malformed':
				return __( 'Something posted to the webhook URL that was not a VezmoPay event. If it keeps happening, check what else knows that URL.', 'vezmopay-woocommerce' );
			default:
				return '';
		}
	}

	/**
	 * Remember that deliveries are being refused, and why.
	 *
	 * 0.3.9 told the merchant about a MISSING secret in the admin, but not about
	 * a store that has one and is refusing every delivery anyway — which is the
	 * state that reads as "the webhook is broken" while the endpoint is doing
	 * exactly what it should.
	 *
	 * @param string $reason Refusal reason.
	 */
	private function note_refusal( $reason ) {
		$health = get_transient( self::HEALTH_KEY );
		$health = is_array( $health ) && isset( $health['reason'] ) && $health['reason'] === $reason
			? $health
			: array( 'reason' => $reason, 'count' => 0, 'first' => time() );

		$health['count'] = (int) $health['count'] + 1;
		$health['last']  = time();
		set_transient( self::HEALTH_KEY, $health, WEEK_IN_SECONDS );
	}

	/**
	 * A delivery got through: whatever was wrong is over.
	 */
	private function note_accepted() {
		if ( get_transient( self::HEALTH_KEY ) ) {
			delete_transient( self::HEALTH_KEY );
		}
	}

	private function log_refusal( $logger, $reason, $message ) {
		$this->note_refusal( $reason );
		$remedy = self::refusal_remedy( $reason );
		if ( '' !== $remedy ) {
			$message .= ' ' . $remedy;
		}
		// A mismatched signature is the one refusal that can mean a real
		// misconfiguration or an attack rather than an unfinished onboarding, so
		// it is never quietened.
		if ( 'bad-signature' === $reason ) {
			$logger->error( $message );
			return;
		}

		$key = 'vezmopay_wh_logged_' . $reason;
		if ( get_transient( $key ) ) {
			$logger->debug( $message . ' (repeated; logged once per ' . ( self::THROTTLE_WINDOW / MINUTE_IN_SECONDS ) . ' minutes)' );
			return;
		}
		set_transient( $key, 1, self::THROTTLE_WINDOW );
		$logger->error( $message );
	}

	private function is_throttled( \WP_REST_Request $request, $bucket = 'all', $max = self::THROTTLE_MAX ) {
		// Approximate on purpose: get_transient/set_transient is a read-then-write,
		// so two requests can read the same count and both pass. That makes the cap
		// soft by a handful of requests under concurrency, which is fine for what
		// it defends — this is a brake on volume, not a mutex, and the two things
		// it protects (CPU before the HMAC, log churn after a refusal) both
		// tolerate being a little late.
		$key   = 'vezmopay_wh_' . $bucket . '_' . md5( $this->client_fingerprint( $request ) );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
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
				// Two, so an ambiguous reference can be DETECTED rather than
				// silently resolved to whichever row came back first.
				'limit'          => 2,
				'payment_method' => Plugin::GATEWAY_ID,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required lookup, indexed by HPOS meta table.
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);
		if ( ! $orders ) {
			return null;
		}
		if ( count( $orders ) > 1 ) {
			$gateway = Plugin::instance()->gateway();
			if ( $gateway ) {
				$gateway->logger()->error(
					'More than one order carries ' . $meta_key . ' = ' . $meta_value
					. ' (#' . $orders[0]->get_id() . ', #' . $orders[1]->get_id() . '); using the oldest. This should not happen — investigate.'
				);
			}
		}
		return $orders[0];
	}
}
