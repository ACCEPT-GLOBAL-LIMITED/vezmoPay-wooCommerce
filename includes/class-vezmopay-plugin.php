<?php
/**
 * Singleton loader: wires the gateway, webhook route, Blocks support and admin hooks.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin orchestrator.
 */
final class Plugin {

	/**
	 * Gateway id used everywhere (settings option key, order meta prefix, hooks).
	 */
	const GATEWAY_ID = 'vezmopay';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Webhook controller.
	 *
	 * @var Webhook
	 */
	public $webhook;

	/**
	 * Get (and lazily create) the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Cron hook that reconciles unresolved VezmoPay orders. Essential for hosted
	 * (paylink) mode, where the customer is never redirected back to the store.
	 */
	const CRON_HOOK = 'vezmopay_reconcile_pending';

	/**
	 * Register hooks.
	 */
	private function __construct() {
		$this->webhook = new Webhook();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'rest_api_init', array( $this->webhook, 'register_routes' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );

		// AJAX endpoints used by the checkout JS (logged-in and guest customers).
		add_action( 'wc_ajax_vezmopay_confirm', array( $this, 'ajax_confirm' ) );
		add_action( 'wc_ajax_vezmopay_status', array( $this, 'ajax_status' ) );

		// Admin: "Test connection" button.
		add_action( 'wp_ajax_vezmopay_test_connection', array( Connect::class, 'ajax_test_connection' ) );

		// Background reconciliation (webhook safety net; sole automatic path for hosted mode).
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 min is required to settle hosted-checkout orders promptly.
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'reconcile_pending_orders' ) );

		// Manual "check status now" action on the order edit screen.
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_vezmopay_check_status', array( $this, 'order_action_check_status' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( VEZMOPAY_WC_PLUGIN_FILE ),
			array( $this, 'plugin_action_links' )
		);
	}

	/**
	 * Add the gateway class to WooCommerce.
	 *
	 * @param string[] $gateways Registered gateway class names.
	 * @return string[]
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Register Cart & Checkout Blocks payment method integration.
	 */
	public function register_blocks_support() {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				$registry->register( new Blocks_Support() );
			}
		);
	}

	/**
	 * Get the configured gateway instance from WooCommerce's registry.
	 *
	 * @return Gateway|null
	 */
	public function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways[ self::GATEWAY_ID ] ) ? $gateways[ self::GATEWAY_ID ] : null;
		return $gateway instanceof Gateway ? $gateway : null;
	}

	/**
	 * Resolve an order that the current request is allowed to act on.
	 *
	 * Requires a matching order key so guests can only touch their own order.
	 *
	 * @param int    $order_id  Order id from the request.
	 * @param string $order_key Order key from the request.
	 * @return \WC_Order|null
	 */
	private function get_authorized_order( $order_id, $order_key ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * AJAX: the SDK reported success/pending — verify against the API and finalize the order.
	 */
	public function ajax_confirm() {
		check_ajax_referer( 'vezmopay-checkout', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = $this->get_authorized_order( $order_id, $order_key );
		$gateway   = $this->gateway();

		if ( ! $order || ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'vezmopay-woocommerce' ) ), 400 );
		}

		$result = $gateway->reconcile_order_with_api( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		// Only forward the customer once the API confirms a settled/settling state;
		// otherwise the page keeps polling.
		$settled = in_array( $result, array( 'CAPTURED', 'PENDING', 'REFUNDED' ), true );
		wp_send_json_success(
			array(
				'status'   => $result,
				'redirect' => $settled ? $gateway->get_return_url( $order ) : '',
			)
		);
	}

	/**
	 * AJAX: polling fallback (iframe mode, or element mode when postMessage is blocked).
	 */
	public function ajax_status() {
		check_ajax_referer( 'vezmopay-checkout', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = $this->get_authorized_order( $order_id, $order_key );
		$gateway   = $this->gateway();

		if ( ! $order || ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'vezmopay-woocommerce' ) ), 400 );
		}

		// Already finalized (e.g. by webhook)? Send the customer on.
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			wp_send_json_success(
				array(
					'status'   => $order->is_paid() ? 'CAPTURED' : 'PENDING',
					'redirect' => $gateway->get_return_url( $order ),
				)
			);
		}

		$result = $gateway->reconcile_order_with_api( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		$done = in_array( $result, array( 'CAPTURED', 'PENDING', 'FAILED' ), true );
		wp_send_json_success(
			array(
				'status'   => $result,
				'redirect' => $done && 'FAILED' !== $result ? $gateway->get_return_url( $order ) : '',
			)
		);
	}

	/**
	 * Add a 5-minute interval for order reconciliation.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['vezmopay_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (VezmoPay)', 'vezmopay-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Ensure the reconciliation event is scheduled while the gateway is enabled.
	 */
	public function maybe_schedule_cron() {
		$gateway = null;
		if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
			$gateway = $this->gateway();
		}
		$enabled = $gateway && 'yes' === $gateway->get_option( 'enabled' );

		if ( $enabled && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'vezmopay_five_minutes', self::CRON_HOOK );
		} elseif ( ! $enabled && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Cron: re-verify recent unresolved VezmoPay orders against the API.
	 *
	 * Covers hosted (paylink) orders — where no customer-side polling exists — and acts
	 * as a webhook-loss safety net for the other modes (including on-hold ACH orders).
	 */
	public function reconcile_pending_orders() {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 25,
				'status'         => array( 'pending', 'on-hold' ),
				'payment_method' => Plugin::GATEWAY_ID,
				'date_created'   => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $orders as $order ) {
			$has_ref = '' !== (string) $order->get_meta( '_vezmopay_payment_id' ) || '' !== (string) $order->get_meta( '_vezmopay_paylink_code' );
			if ( ! $has_ref ) {
				continue;
			}
			$result = $gateway->reconcile_order_with_api( $order );
			if ( is_wp_error( $result ) ) {
				$gateway->logger()->debug( 'Cron reconcile failed for order #' . $order->get_id() . ': ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Add the manual status-check action to the order edit screen.
	 *
	 * @param array          $actions Order actions.
	 * @param \WC_Order|null $order   Order being edited (null on older WC).
	 * @return array
	 */
	public function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() === Plugin::GATEWAY_ID ) {
			$actions['vezmopay_check_status'] = __( 'Check VezmoPay payment status', 'vezmopay-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Handle the manual status-check order action.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function order_action_check_status( $order ) {
		$gateway = $this->gateway();
		if ( ! $gateway || $order->get_payment_method() !== Plugin::GATEWAY_ID ) {
			return;
		}
		$result = $gateway->reconcile_order_with_api( $order );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'VezmoPay status check failed: %s', 'vezmopay-woocommerce' ),
					$result->get_error_message()
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: payment status reported by VezmoPay */
					__( 'VezmoPay status check: %s', 'vezmopay-woocommerce' ),
					$result
				)
			);
		}
	}

	/**
	 * Settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vezmopay-woocommerce' ) . '</a>' );
		return $links;
	}
}
