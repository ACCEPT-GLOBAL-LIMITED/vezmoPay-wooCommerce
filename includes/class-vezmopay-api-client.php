<?php
/**
 * Thin HTTP client for the VezmoPay merchant API.
 *
 * Auth model (see docs/VEZMOPAY-API-CONTRACT.md): exchange the vzm_ key + secret for a
 * 30-minute Bearer JWT via POST /merchant/api-auth/login, cache it in a transient, and
 * send it on every /merchant/* call. On a 401 the token is refreshed once and the call retried.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * VezmoPay REST client built on wp_remote_request.
 */
class Api_Client {

	/**
	 * API base URL including the /api/v1 prefix, no trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Merchant API key (vzm_…).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Merchant API secret.
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Environment slug ('test'|'live') — used to namespace the token cache.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 20;

	/**
	 * Access-token transient TTL: server issues 30 min; renew early.
	 */
	const TOKEN_TTL = 25 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param string $base_url    API host, with or without /api/v1.
	 * @param string $api_key     Merchant API key.
	 * @param string $api_secret  Merchant API secret.
	 * @param string $environment 'test' or 'live'.
	 * @param Logger $logger      Logger instance.
	 */
	public function __construct( $base_url, $api_key, $api_secret, $environment, Logger $logger ) {
		$base_url = untrailingslashit( trim( $base_url ) );
		if ( '' !== $base_url && false === strpos( $base_url, '/api/v1' ) ) {
			$base_url .= '/api/v1';
		}
		$this->base_url    = $base_url;
		$this->api_key     = trim( $api_key );
		$this->api_secret  = trim( $api_secret );
		$this->environment = $environment;
		$this->logger      = $logger;
	}

	/**
	 * Whether key, secret and base URL are all present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->api_key && '' !== $this->api_secret;
	}

	/**
	 * The API host without the /api/v1 prefix (what the vezmo.js SDK calls apiBase).
	 *
	 * @return string
	 */
	public function host() {
		return preg_replace( '#/api/v1$#', '', $this->base_url );
	}

	/**
	 * Transient key for the cached access token (scoped to env + credentials).
	 *
	 * @return string
	 */
	private function token_cache_key() {
		return 'vezmopay_token_' . $this->environment . '_' . substr( hash( 'sha256', $this->api_key . '|' . $this->api_secret . '|' . $this->base_url ), 0, 20 );
	}

	/**
	 * Exchange key+secret for a fresh access token. Never cached failures.
	 *
	 * @return string|\WP_Error Access token.
	 */
	public function login() {
		$response = $this->raw_request(
			'POST',
			'/merchant/api-auth/login',
			null,
			array(
				'x-api-key'    => $this->api_key,
				'x-api-secret' => $this->api_secret,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = isset( $response['accessToken']['token'] ) ? $response['accessToken']['token'] : '';
		if ( '' === $token ) {
			return new \WP_Error( 'vezmopay_auth', __( 'VezmoPay login succeeded but no access token was returned.', 'vezmopay-woocommerce' ) );
		}

		set_transient( $this->token_cache_key(), $token, self::TOKEN_TTL );
		$this->logger->debug( 'Obtained new VezmoPay access token.' );
		return $token;
	}

	/**
	 * Get a cached or fresh access token.
	 *
	 * @param bool $force_fresh Skip the cache.
	 * @return string|\WP_Error
	 */
	private function access_token( $force_fresh = false ) {
		if ( ! $force_fresh ) {
			$cached = get_transient( $this->token_cache_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		return $this->login();
	}

	/**
	 * Authenticated request against /merchant/* (retries once on 401 with a fresh token,
	 * and once on transient transport failure for idempotent GETs).
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under /api/v1, leading slash.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers (e.g. Idempotency-Key).
	 * @return array|\WP_Error Decoded `data` payload.
	 */
	public function request( $method, $path, $body = null, array $headers = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'vezmopay_config', __( 'VezmoPay API credentials are not configured.', 'vezmopay-woocommerce' ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$attempt_headers = array_merge( $headers, array( 'Authorization' => 'Bearer ' . $token ) );
		$response        = $this->raw_request( $method, $path, $body, $attempt_headers );

		// Expired/revoked token → one forced re-login and retry.
		if ( is_wp_error( $response ) && 'vezmopay_http_401' === $response->get_error_code() ) {
			delete_transient( $this->token_cache_key() );
			$token = $this->access_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$attempt_headers['Authorization'] = 'Bearer ' . $token;
			$response                         = $this->raw_request( $method, $path, $body, $attempt_headers );
		}

		// One retry on pure transport failure for idempotent reads.
		if ( is_wp_error( $response ) && 'vezmopay_transport' === $response->get_error_code() && 'GET' === strtoupper( $method ) ) {
			$response = $this->raw_request( $method, $path, $body, $attempt_headers );
		}

		return $response;
	}

	/**
	 * Perform a single HTTP request and unwrap the VezmoPay response envelope.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under the base URL.
	 * @param array|null $body    JSON body or null.
	 * @param array      $headers Headers.
	 * @return array|\WP_Error Decoded `data` member of the envelope.
	 */
	private function raw_request( $method, $path, $body, array $headers ) {
		$url  = $this->base_url . $path;
		$args = array(
			'method'    => strtoupper( $method ),
			'timeout'   => self::TIMEOUT,
			'sslverify' => true,
			'headers'   => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'VezmoPay-WooCommerce/' . VEZMOPAY_WC_VERSION . '; ' . home_url(),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$this->logger->debug( 'API request: ' . $args['method'] . ' ' . $path, array( 'body' => is_array( $body ) ? Logger::redact( $body ) : null ) );

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'API transport error: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new \WP_Error( 'vezmopay_transport', __( 'Could not reach the VezmoPay API. Please try again.', 'vezmopay-woocommerce' ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['success'] ) ) {
			return isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		}

		$message = is_array( $decoded ) && ! empty( $decoded['message'] ) ? (string) $decoded['message'] : __( 'Unexpected response from the VezmoPay API.', 'vezmopay-woocommerce' );
		$this->logger->error( 'API error ' . $code . ' on ' . $path . ': ' . $message );

		return new \WP_Error( 'vezmopay_http_' . $code, $message, array( 'status' => $code ) );
	}

	/* ---------------------------------------------------------------------
	 * High-level endpoints.
	 * ------------------------------------------------------------------- */

	/**
	 * Create a secure payment (element/iframe modes).
	 *
	 * @param array  $payload         CreateSecurePaymentDto fields.
	 * @param string $idempotency_key Idempotency key (1–255 printable ASCII).
	 * @return array|\WP_Error { payment: {...}, securePayment: { clientToken, url, sdkUrl, html, expiresAt } }
	 */
	public function create_secure_payment( array $payload, $idempotency_key ) {
		$headers = array();
		if ( is_string( $idempotency_key ) && '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = substr( preg_replace( '/[^\x20-\x7E]/', '', $idempotency_key ), 0, 255 );
		}
		return $this->request( 'POST', '/merchant/secure-payments', $payload, $headers );
	}

	/**
	 * Create a paylink (hosted checkout mode).
	 *
	 * @param array $payload CreatePaylinkDto fields.
	 * @return array|\WP_Error Paylink row incl. shortCode.
	 */
	public function create_paylink( array $payload ) {
		return $this->request( 'POST', '/merchant/paylinks', $payload );
	}

	/**
	 * Resolve a paylink by short code.
	 *
	 * @param string $code Paylink short code.
	 * @return array|\WP_Error
	 */
	public function get_paylink( $code ) {
		return $this->request( 'GET', '/merchant/paylinks/' . rawurlencode( $code ) );
	}

	/**
	 * Fetch a payment record — the source of truth for order state.
	 *
	 * @param string $payment_id VezmoPay payment id.
	 * @return array|\WP_Error Payment row incl. status (INITIATED|AUTHORIZED|CAPTURED|FAILED|REFUNDED).
	 */
	public function get_payment( $payment_id ) {
		return $this->request( 'GET', '/merchant/payment/' . rawurlencode( $payment_id ) );
	}

	/**
	 * List payments (used to reconcile paylink orders where the payment id
	 * is only known after the customer pays on the hosted page).
	 *
	 * @param array $query Query args.
	 * @return array|\WP_Error
	 */
	public function list_payments( array $query = array() ) {
		$path = '/merchant/payment';
		if ( $query ) {
			$path .= '?' . http_build_query( $query );
		}
		return $this->request( 'GET', $path );
	}
}
