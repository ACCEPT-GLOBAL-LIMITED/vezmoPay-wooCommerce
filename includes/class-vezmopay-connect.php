<?php
/**
 * Connection onboarding.
 *
 * VezmoPay has no OAuth/connect-style app authorization flow (verified against the
 * platform source — see docs/VEZMOPAY-API-CONTRACT.md). Onboarding is therefore
 * manual-keys only; this class provides the "Test connection" validation, which
 * performs the real credential exchange (POST /merchant/api-auth/login).
 *
 * When the platform ships an OAuth flow, the handshake belongs here.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Manual-key connection validation.
 */
class Connect {

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

		$environment = isset( $_POST['environment'] ) && 'live' === $_POST['environment'] ? 'live' : 'test';
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
