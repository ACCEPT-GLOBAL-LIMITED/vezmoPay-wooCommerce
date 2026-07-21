<?php
/**
 * Connection onboarding.
 *
 * Two ways to connect:
 *  1. "Connect with VezmoPay" — the admin is sent to the VezmoPay dashboard
 *     (/integrations/woocommerce/connect), logs in, approves, and is redirected back
 *     with a one-time token. This class exchanges that token server-to-server
 *     (POST /integrations/woocommerce/exchange) for an API key + secret and saves
 *     them, so credentials are never typed and never travel through the browser.
 *  2. Manual keys — paste key + secret, validate with the "Test connection" button
 *     (which performs the real login exchange, POST /merchant/api-auth/login).
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Connect-with-VezmoPay handshake + manual-key validation.
 */
class Connect {

	/**
	 * State nonce lifetime for the connect round-trip.
	 */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Build the URL of the VezmoPay consent page for the current admin,
	 * minting a fresh one-time state nonce.
	 *
	 * @param Gateway $gateway Gateway instance.
	 * @return string
	 */
	public static function connect_url( Gateway $gateway ) {
		$state = wp_generate_password( 32, false, false );
		set_transient( 'vezmopay_connect_state_' . $state, get_current_user_id(), self::STATE_TTL );

		$args = array(
			'redirect_uri' => admin_url( 'admin-post.php?action=vezmopay_connect_callback' ),
			'state'        => $state,
			'environment'  => $gateway->environment(),
		);

		// So VezmoPay can auto-register our webhook and return its secret. The
		// consent page only accepts an https URL (or http on localhost), so omit
		// it otherwise — a webhook detail must never block onboarding.
		$webhook_url = Webhook::url();
		$host        = wp_parse_url( $webhook_url, PHP_URL_HOST );
		$is_https    = 0 === strpos( (string) $webhook_url, 'https://' );
		$is_local    = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( $is_https || $is_local ) {
			$args['webhook_url'] = $webhook_url;
		}

		return $gateway->checkout_base() . '/integrations/woocommerce/connect?' . http_build_query( $args );
	}

	/**
	 * admin-post callback: the VezmoPay consent page redirected back here.
	 *
	 * Validates the state nonce, exchanges the one-time token for credentials
	 * (server-to-server), saves them for the returned environment, and bounces
	 * back to the gateway settings screen with a notice.
	 */
	public static function handle_connect_callback() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'vezmopay-woocommerce' ) );
		}

		$gateway = Plugin::instance()->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VezmoPay gateway is not available.', 'vezmopay-woocommerce' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- cross-site round-trip; integrity comes from the one-time state transient below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$token = isset( $_GET['vezmopay_token'] ) ? sanitize_text_field( wp_unslash( $_GET['vezmopay_token'] ) ) : '';
		$error = isset( $_GET['vezmopay_error'] ) ? sanitize_key( wp_unslash( $_GET['vezmopay_error'] ) ) : '';
		$env   = isset( $_GET['environment'] ) && 'live' === $_GET['environment'] ? 'live' : 'test';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// One-time state check, bound to the admin who started the flow.
		$state_user = '' !== $state ? get_transient( 'vezmopay_connect_state_' . $state ) : false;
		if ( false !== $state_user ) {
			delete_transient( 'vezmopay_connect_state_' . $state );
		}
		if ( false === $state_user || (int) $state_user !== get_current_user_id() ) {
			self::back_to_settings( array( 'vezmopay_connect_error' => 'state' ) );
		}

		if ( 'cancelled' === $error ) {
			self::back_to_settings( array( 'vezmopay_connect_error' => 'cancelled' ) );
		}
		if ( '' === $token ) {
			self::back_to_settings( array( 'vezmopay_connect_error' => 'token' ) );
		}

		$credentials = self::exchange_token( $gateway, $env, $token );
		if ( is_wp_error( $credentials ) ) {
			self::back_to_settings( array( 'vezmopay_connect_error' => 'exchange' ) );
		}

		// The API reports which environment the key was minted for; trust that over the query arg.
		$key_env = isset( $credentials['environment'] ) && 'LIVE' === strtoupper( (string) $credentials['environment'] ) ? 'live' : 'test';

		$gateway->update_option( $key_env . '_api_key', (string) $credentials['key'] );
		$gateway->update_option( $key_env . '_api_secret', (string) $credentials['secret'] );
		$gateway->update_option( 'environment', $key_env );

		// Auto-registered webhook: save the signing secret so verification is on
		// with zero manual copy-paste.
		$webhook_saved = false;
		if ( ! empty( $credentials['webhookSecret'] ) ) {
			$gateway->update_option( 'webhook_secret', (string) $credentials['webhookSecret'] );
			$webhook_saved = true;
		}

		self::back_to_settings(
			array(
				'vezmopay_connected' => $key_env,
				'vezmopay_webhook'   => $webhook_saved ? '1' : '0',
			)
		);
	}

	/**
	 * Exchange the one-time connect token for credentials (server-to-server).
	 *
	 * @param Gateway $gateway     Gateway (for the environment base URL).
	 * @param string  $environment 'test'|'live'.
	 * @param string  $token       One-time token from the consent redirect.
	 * @return array|\WP_Error { key, secret, environment }
	 */
	private static function exchange_token( Gateway $gateway, $environment, $token ) {
		$url      = $gateway->api_client( $environment )->host() . '/api/v1/integrations/woocommerce/exchange';
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( array( 'token' => $token ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) || empty( $decoded['data']['key'] ) || empty( $decoded['data']['secret'] ) ) {
			return new \WP_Error( 'vezmopay_exchange', __( 'The connect token could not be exchanged. It may have expired — try connecting again.', 'vezmopay-woocommerce' ) );
		}

		return $decoded['data'];
	}

	/**
	 * Redirect back to the gateway settings screen with query args and exit.
	 *
	 * @param array $args Extra query args.
	 */
	private static function back_to_settings( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Plugin::GATEWAY_ID )
			)
		);
		exit;
	}

	/**
	 * Render admin notices on the gateway settings screen after a connect round-trip.
	 *
	 * @param Gateway $gateway Gateway instance.
	 */
	public static function maybe_render_connect_notices( Gateway $gateway ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notices from our own redirect.
		if ( isset( $_GET['vezmopay_connected'] ) ) {
			$env = 'live' === $_GET['vezmopay_connected'] ? __( 'live', 'vezmopay-woocommerce' ) : __( 'test', 'vezmopay-woocommerce' );
			echo '<div class="notice notice-success inline"><p><strong>';
			/* translators: %s: environment name */
			echo esc_html( sprintf( __( 'Connected with VezmoPay! Your %s API credentials were created and saved automatically.', 'vezmopay-woocommerce' ), $env ) );
			echo '</strong> ';
			if ( isset( $_GET['vezmopay_webhook'] ) && '1' === $_GET['vezmopay_webhook'] ) {
				echo esc_html__( 'Your webhook was registered automatically too — no copy-paste needed.', 'vezmopay-woocommerce' ) . ' ';
			}
			echo esc_html__( 'Note: connecting deactivates any previous API key for your VezmoPay account.', 'vezmopay-woocommerce' ) . '</p></div>';
		}

		if ( isset( $_GET['vezmopay_connect_error'] ) ) {
			$code     = sanitize_key( wp_unslash( $_GET['vezmopay_connect_error'] ) );
			$messages = array(
				'cancelled' => __( 'Connection cancelled — no changes were made.', 'vezmopay-woocommerce' ),
				'state'     => __( 'The connect request could not be verified (it may have expired). Please try again.', 'vezmopay-woocommerce' ),
				'token'     => __( 'VezmoPay did not return a connect token. Please try again.', 'vezmopay-woocommerce' ),
				'exchange'  => __( 'The connect token could not be exchanged for credentials. It may have expired — please try again.', 'vezmopay-woocommerce' ),
			);
			$message  = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['exchange'];
			$class    = 'cancelled' === $code ? 'notice-warning' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		unset( $gateway );
	}

	/**
	 * AJAX handler for the settings-page "Test connection" button.
	 *
	 * Validates the credentials currently saved for the selected environment by
	 * performing the login exchange against the VezmoPay API.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'vezmopay-admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'vezmopay-woocommerce' ) ), 403 );
		}

		$environment = isset( $_POST['environment'] ) && 'live' === sanitize_key( wp_unslash( $_POST['environment'] ) ) ? 'live' : 'test';
		$gateway     = Plugin::instance()->gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Gateway not available.', 'vezmopay-woocommerce' ) ), 500 );
		}

		$client = $gateway->api_client( $environment );
		if ( ! $client->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: environment name */
						__( 'Save your %s API key and secret first, then test the connection.', 'vezmopay-woocommerce' ),
						'live' === $environment ? __( 'live', 'vezmopay-woocommerce' ) : __( 'test', 'vezmopay-woocommerce' )
					),
				)
			);
		}

		$result = $client->login();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error detail from the API */
						__( 'Connection failed: %s', 'vezmopay-woocommerce' ),
						$result->get_error_message()
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: environment name */
					__( 'Connected to VezmoPay (%s environment). Credentials are valid.', 'vezmopay-woocommerce' ),
					'live' === $environment ? __( 'live', 'vezmopay-woocommerce' ) : __( 'test', 'vezmopay-woocommerce' )
				),
			)
		);
	}
}
