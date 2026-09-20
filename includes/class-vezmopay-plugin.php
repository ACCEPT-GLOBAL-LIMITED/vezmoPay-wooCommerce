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
	 * Cache-busting version for a bundled asset.
	 *
	 * The plugin version alone is not enough: a store that tracks the repo (or
	 * pulls a fix between releases) keeps the SAME version string while the file
	 * changes underneath it, so browsers and page caches keep serving the old
	 * stylesheet — which is exactly how a released Pay button ended up on a
	 * merchant's checkout with none of its CSS. Fold the file's mtime in so any
	 * change to the file changes its URL.
	 *
	 * @param string $relative Path under the plugin directory, e.g. assets/css/x.css.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$path  = VEZMOPAY_WC_PLUGIN_DIR . ltrim( $relative, '/' );
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to the plugin version.
		return $mtime ? VEZMOPAY_WC_VERSION . '.' . $mtime : VEZMOPAY_WC_VERSION;
	}

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
	 * Self-hosted updater (GitHub releases).
	 *
	 * @var Updater
	 */
	public $updater;

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
	 * How long the settings screen's account panel is cached (per environment).
	 */
	const ACCOUNT_PANEL_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Payment sessions a single visitor may create per window. Generous enough
	 * for a shopper editing their cart, bounded enough that nobody can mint
	 * provider resources in a loop.
	 */
	/**
	 * How much larger the per-address budget is than the per-visitor one.
	 *
	 * A shared address (office NAT, mobile carrier) carries several honest
	 * shoppers, so this is generous — it exists to bound abuse, not to ration
	 * ordinary traffic.
	 */
	const RATE_IP_FACTOR = 6;

	/**
	 * How long the cron keeps asking about an order that never settles.
	 *
	 * Long enough for a slow bank debit (1–2 business days is covered by the
	 * on-hold path, which this does not touch) and short enough that a merchant
	 * hears about a stranded hosted order the next morning rather than never.
	 */
	const ABANDON_AFTER = 24 * HOUR_IN_SECONDS;

	const SESSION_RATE_MAX    = 10;
	const SESSION_RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Customer attachments a single visitor may send per window. Higher than the
	 * session limit because the checkout re-sends on billing edits, but still
	 * bounded: the API throttles this route too, and spending that budget here
	 * would leave a real shopper unable to pay by bank.
	 */
	const CLIENT_RATE_MAX    = 20;
	const CLIENT_RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	private function __construct() {
		$this->webhook = new Webhook();
		$this->updater = new Updater();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'rest_api_init', array( $this->webhook, 'register_routes' ) );

		// Blocks checkout: woocommerce_blocks_loaded fires during plugins_loaded
		// priority 10, BEFORE this constructor runs (priority 11) — subscribing to
		// it here is subscribing to an event that already fired, so the payment
		// method type never registered and the Block checkout showed "no payment
		// methods available". Handle both orders explicitly.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_blocks_support();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
		}

		// AJAX endpoints used by the checkout JS (logged-in and guest customers).
		add_action( 'wc_ajax_vezmopay_session', array( $this, 'ajax_session' ) );
		add_action( 'wc_ajax_vezmopay_client', array( $this, 'ajax_attach_client' ) );
		add_action( 'wc_ajax_vezmopay_confirm', array( $this, 'ajax_confirm' ) );
		add_action( 'wc_ajax_vezmopay_status', array( $this, 'ajax_status' ) );
		add_action( 'wc_ajax_vezmopay_failed', array( $this, 'ajax_attempt_failed' ) );

		// Admin: "Test connection" button + Connect-with-VezmoPay callback.
		add_action( 'wp_ajax_vezmopay_test_connection', array( Connect::class, 'ajax_test_connection' ) );
		add_action( 'admin_post_vezmopay_connect_callback', array( Connect::class, 'handle_connect_callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );

		// The order-received page decides what to show from the order's status, so
		// the API has to be read before it renders — not from inside the template.
		add_action( 'template_redirect', array( $this, 'reconcile_order_received' ) );

		// Without a webhook secret nothing that arrives at the webhook endpoint can
		// be authenticated, so nothing is processed — say so where it will be seen.
		add_action( 'admin_notices', array( $this, 'webhook_secret_notice' ) );

		// Admin: live VezmoPay account settings (payment methods, 3-D Secure).
		add_action( 'wp_ajax_vezmopay_account_get', array( $this, 'ajax_account_get' ) );
		add_action( 'wp_ajax_vezmopay_account_update', array( $this, 'ajax_account_update' ) );

		// Background reconciliation (webhook safety net; sole automatic path for hosted mode).
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 min is required to settle hosted-checkout orders promptly.
		// NOT on `init`: maybe_schedule_cron() used to touch WC()->payment_gateways
		// there, whose constructor runs apply_filters( 'woocommerce_payment_gateways' )
		// and caches the result for the request — on every front-end and admin
		// request, purely to read one `enabled` flag, and early enough to freeze
		// the gateway list before a plugin registering later on `init` could join
		// it (which can make another gateway vanish from checkout).
		add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . self::GATEWAY_ID, array( $this, 'maybe_schedule_cron' ), 30 );
		add_action( 'woocommerce_update_options_payment_gateways_' . self::GATEWAY_ID, array( $this, 'flush_account_panel_cache' ), 30 );
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
	 * Classic checkout assets. Only on the checkout page, and only when the
	 * gateway is actually available and set to an embedded mode.
	 */
	public function enqueue_checkout_scripts() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$gateway = $this->gateway();
		if ( ! $gateway || 'hosted' === $gateway->integration_mode() || ! $gateway->is_available() ) {
			return;
		}

		wp_enqueue_style(
			'vezmopay',
			VEZMOPAY_WC_PLUGIN_URL . 'assets/css/vezmopay.css',
			array(),
			self::asset_version( 'assets/css/vezmopay.css' )
		);
		self::register_checkout_inline_script();
		wp_enqueue_script( 'vezmopay-checkout-inline' );
	}

	/**
	 * Register (idempotently) the shared checkout script.
	 *
	 * Both checkouts use it, and the Blocks integration resolves its script
	 * handles before wp_enqueue_scripts runs — so registration cannot live in
	 * the enqueue callback alone.
	 */
	public static function register_checkout_inline_script() {
		if ( wp_script_is( 'vezmopay-checkout-inline', 'registered' ) ) {
			return;
		}
		$gateway = self::instance()->gateway();
		if ( ! $gateway ) {
			return;
		}

		wp_register_script(
			'vezmopay-checkout-inline',
			VEZMOPAY_WC_PLUGIN_URL . 'assets/js/checkout-inline.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/checkout-inline.js' ),
			true
		);
		wp_localize_script(
			'vezmopay-checkout-inline',
			'vezmopay_inline_params',
			array(
				'mode'           => $gateway->integration_mode(),
				'theme'          => $gateway->checkout_theme(),
				'apiBase'        => $gateway->api_client()->host(),
				// The frame's src is on the API origin and is redirected to this
				// one, so this is the origin we actually exchange messages with.
				'checkoutOrigin' => $gateway->checkout_origin(),
				'sessionUrl'     => \WC_AJAX::get_endpoint( 'vezmopay_session' ),
				// The cart session starts with no customer on it; the checkout
				// sends the billing fields here as they are filled, so a bank
				// payment has the email its debit mandate requires.
				'clientUrl'      => \WC_AJAX::get_endpoint( 'vezmopay_client' ),
				'confirmUrl'     => \WC_AJAX::get_endpoint( 'vezmopay_confirm' ),
				// The charge is watched server-side too, so a message the frame
				// cannot deliver never leaves the shopper waiting.
				'statusUrl'      => \WC_AJAX::get_endpoint( 'vezmopay_status' ),
				// Where the browser reports an attempt it has given up on, so the
				// order carries a note and the store gets one last API read.
				'failedUrl'      => \WC_AJAX::get_endpoint( 'vezmopay_failed' ),
				// Mirrors the gateway's Debug setting: with it on, the checkout
				// traces the payment to the browser console, so a stuck payment
				// can be diagnosed from what the shopper's browser saw.
				'debug'          => 'yes' === $gateway->get_option( 'debug' ),
				'nonce'          => wp_create_nonce( 'vezmopay-checkout' ),
				'i18n'           => array(
					/* translators: %s: order total, e.g. $500.00 */
					'pay'             => __( 'Pay %s', 'vezmopay-woocommerce' ),
					'processing'      => __( 'Processing your payment…', 'vezmopay-woocommerce' ),
					'failed'          => __( 'Payment failed. Please check your card details and try again.', 'vezmopay-woocommerce' ),
					'unavailable'     => __( 'Secure payment fields could not be loaded. Please reload the page or choose another payment method.', 'vezmopay-woocommerce' ),
					'incomplete'      => __( 'Please complete your card details before placing the order.', 'vezmopay-woocommerce' ),
					'cancelled'       => __( 'The payment was cancelled. You can try again.', 'vezmopay-woocommerce' ),
					'expired'         => __( 'The payment session expired. Please reload the page and try again.', 'vezmopay-woocommerce' ),
					'verifying'       => __( 'Completing an extra verification step with your bank…', 'vezmopay-woocommerce' ),
					'frameTitle'      => __( 'VezmoPay secure payment', 'vezmopay-woocommerce' ),
					'notReady'        => __( 'The payment form did not finish loading, so your card was not charged. Reload the page and try again, or use the VezmoPay page link below.', 'vezmopay-woocommerce' ),
					'slow'            => __( 'This is taking longer than usual. Your card has not been charged twice — you can finish the payment on the VezmoPay page below.', 'vezmopay-woocommerce' ),
					'continueOnVezmo' => __( 'Continue on the VezmoPay page →', 'vezmopay-woocommerce' ),
					// Appended to a failure the mounted form CAN be retried from: the
					// card details are still in it, so this is an invitation to
					// press Pay again, not to start over.
					'tryAgain'        => __( 'You can correct your card details and try again.', 'vezmopay-woocommerce' ),
					// Appended only when the form had to be replaced (the payment
					// behind it is dead), which is the one case where the card
					// really does have to be entered again.
					'retryHint'       => __( 'Please re-enter your card details below and try again.', 'vezmopay-woocommerce' ),
					// The bounded attempt (see ATTEMPT_LIMIT_MS). VezmoPay reports
					// nothing at all for a declined card, so this covers a decline
					// as well as a payment that simply never resolved — the wording
					// must be true of both.
					'noResult'        => __( 'VezmoPay did not report a result for that payment. Please check your card details and try again — if the payment did go through, your order will be updated automatically.', 'vezmopay-woocommerce' ),
					// A payment the shopper made in the payment box WITHOUT pressing
					// Place order, so there is no order to attach it to. The wallet
					// buttons used to do this — they charge on their own gesture —
					// and now hold the charge until we have placed the order, so
					// this should never be seen. It exists because the alternative,
					// which is what happened before, is saying nothing at all while
					// the money is gone.
					'unsolicited'     => __( 'That payment went through, but your order has not been placed yet. Please do not pay again — contact the store to complete your order.', 'vezmopay-woocommerce' ),
					// The wallet handshake. The shopper has approved Apple/Google
					// Pay and the charge is being HELD while WooCommerce places
					// the order; nothing has been charged in any of these cases.
					'walletPlacing'   => __( 'Payment approved — placing your order…', 'vezmopay-woocommerce' ),
					'walletRefused'   => __( 'Your order could not be placed, so nothing was charged. Please check the highlighted fields and try again.', 'vezmopay-woocommerce' ),
					'walletSlow'      => __( 'Your order took too long to place, so nothing was charged. Please try again.', 'vezmopay-woocommerce' ),
					'walletBusy'      => __( 'A payment is already in progress. Please wait for it to finish.', 'vezmopay-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Admin notice: the gateway is live but webhook deliveries cannot be checked.
	 *
	 * Orders still settle — the five-minute cron and the checkout's own polling
	 * see to that — but slower, and the merchant has no other way to learn that
	 * every delivery is being refused.
	 */
	public function webhook_secret_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Only where a merchant is already looking at their store, not on every
		// admin page of the site.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$where  = array( 'woocommerce_page_wc-settings', 'dashboard', 'plugins' );
		if ( ! $screen || ! in_array( $screen->id, $where, true ) ) {
			return;
		}
		$gateway = $this->gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enabled' ) ) {
			return;
		}

		$secret = (string) $gateway->get_option( 'webhook_secret' );
		$health = get_transient( Webhook::HEALTH_KEY );
		$health = is_array( $health ) ? $health : array();

		// Two different problems, and the second is the one 0.3.9 could not see:
		// a store WITH a secret whose every delivery is being refused. That is
		// what reads as "the webhook is broken" while the endpoint is behaving
		// exactly as designed — and the only place it showed was a log.
		if ( '' === $secret ) {
			$headline = __( 'VezmoPay: webhook deliveries are being rejected.', 'vezmopay-woocommerce' );
			$detail   = __( 'No webhook secret is saved, so nothing arriving at the webhook endpoint can be authenticated and none of it is processed. Orders still complete — the plugin checks VezmoPay every five minutes — but they complete more slowly.', 'vezmopay-woocommerce' )
				. ' ' . Webhook::refusal_remedy( 'no-secret' );
		} elseif ( ! empty( $health['reason'] ) && '' !== Webhook::refusal_remedy( $health['reason'] ) ) {
			$headline = sprintf(
				/* translators: 1: number of refused deliveries, 2: how long ago the last one was, e.g. "5 mins" */
				_n(
					'VezmoPay: %1$d webhook delivery has been refused (the last one %2$s ago).',
					'VezmoPay: %1$d webhook deliveries have been refused (the last one %2$s ago).',
					(int) $health['count'],
					'vezmopay-woocommerce'
				),
				(int) $health['count'],
				human_time_diff( (int) $health['last'] )
			);
			$detail = Webhook::refusal_remedy( $health['reason'] );

			// Both causes at once is worth naming on its own: some deliveries
			// arriving unsigned while others are signed with a secret this store
			// does not have is what TWO registered endpoints looks like — one
			// created without a secret, one whose secret went somewhere else.
			$seen = isset( $health['reasons'] ) && is_array( $health['reasons'] ) ? $health['reasons'] : array();
			if ( ! empty( $seen['signature-required'] ) && ! empty( $seen['bad-signature'] ) ) {
				$detail .= ' ' . __( 'Some of these arrived unsigned and others were signed with a secret this store does not have, which usually means more than one webhook endpoint is registered for this store. Remove the ones you are not using, then make sure the secret saved here belongs to the one you keep.', 'vezmopay-woocommerce' );
			}

			$detail .= ' ' . __( 'Until then orders still complete, just more slowly — the plugin checks VezmoPay every five minutes. This notice clears itself as soon as one delivery is accepted.', 'vezmopay-woocommerce' );
		} else {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html( $headline );
		echo '</strong> ';
		echo esc_html( $detail );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=vezmopay' ) ) . '">';
		echo esc_html__( 'Open VezmoPay settings', 'vezmopay-woocommerce' );
		echo '</a></p></div>';
	}

	/**
	 * Verify an unsettled VezmoPay order as the order-received page loads.
	 *
	 * Resolving the gateway is safe here: template_redirect is long past the
	 * point where instantiating gateways could freeze the list for the request.
	 */
	public function reconcile_order_received() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		$gateway = $this->gateway();
		if ( $gateway ) {
			$gateway->reconcile_order_received();
		}
	}

	/**
	 * Whether this visitor has asked for too many payment sessions.
	 *
	 * Counted per WooCommerce customer session, falling back to the requesting
	 * IP for a visitor without one.
	 *
	 * @return bool
	 */
	private function session_rate_limited() {
		return $this->rate_limited( 'sess', self::SESSION_RATE_MAX, self::SESSION_RATE_WINDOW );
	}

	/**
	 * Count a request against a per-visitor budget.
	 *
	 * @param string $bucket Short bucket name, namespacing the counter.
	 * @param int    $max    Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @return bool Whether the budget is already spent.
	 */
	private function rate_limited( $bucket, $max, $window ) {
		// TWO counters, and either one can refuse.
		//
		// The customer-id bucket is the fair one — it limits a shopper without
		// punishing the office they share an address with — but it cannot be the
		// only one: get_customer_id() mints a fresh random id for any request
		// arriving without a WooCommerce session cookie, so an empty cookie jar
		// bought a fresh budget every time. That counted cookie jars, not
		// visitors, which is no limit at all.
		//
		// So the address is always counted as well, at a multiple of the cap so a
		// shared NAT is not throttled by ordinary shopping, while an unbounded
		// minting run from one place still stops.
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'anonymous';
		$limited = $this->bump_counter( 'vezmopay_' . $bucket . '_rl_ip_' . md5( $ip ), $max * self::RATE_IP_FACTOR, $window );

		if ( function_exists( 'WC' ) && isset( WC()->session ) && WC()->session ) {
			$id = (string) WC()->session->get_customer_id();
			if ( '' !== $id ) {
				// Deliberately not short-circuited: both counters must advance.
				$limited = $this->bump_counter( 'vezmopay_' . $bucket . '_rl_' . md5( $id ), $max, $window ) || $limited;
			}
		}

		return $limited;
	}

	/**
	 * Advance one counter and say whether it was already spent.
	 *
	 * @param string $key    Transient key.
	 * @param int    $max    Requests allowed in the window.
	 * @param int    $window Seconds.
	 * @return bool
	 */
	private function bump_counter( $key, $max, $window ) {
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Check the CSRF nonce on a post-charge endpoint without dying on failure.
	 *
	 * These two endpoints are authorised by order id + order key, compared with
	 * hash_equals in get_authorized_order() — that is the capability, and the
	 * nonce adds nothing on top of it. What it does add is a failure mode AFTER
	 * the card has been charged: guest checkout that creates an account retires
	 * the nonce mid-flow, and a hard wp_die( -1 ) then blocked the store from
	 * confirming a payment the shopper had already made. So: log it, and let the
	 * order key decide. Everything downstream still re-verifies against the API.
	 *
	 * @param string $context Which endpoint, for the log line.
	 */
	private function verify_checkout_request( $context ) {
		if ( false !== check_ajax_referer( 'vezmopay-checkout', 'nonce', false ) ) {
			return;
		}
		$gateway = $this->gateway();
		if ( $gateway ) {
			$gateway->logger()->debug(
				'Checkout ' . $context . ' request arrived with a stale or missing nonce; '
				. 'proceeding on the order key (hash_equals) instead of refusing a charge that may already have happened.'
			);
		}
	}

	/**
	 * Create (or return) the cart's VezmoPay payment session, so the form can be
	 * mounted in the payment box before any order exists.
	 *
	 * Keeps the HARD nonce check: this mints a real provider resource and no
	 * money has moved yet, so a stale nonce here costs a page reload, not a
	 * payment.
	 */
	public function ajax_session() {
		check_ajax_referer( 'vezmopay-checkout', 'nonce' );

		$gateway = $this->gateway();
		if ( ! $gateway || 'hosted' === $gateway->integration_mode() ) {
			wp_send_json_error( array( 'message' => __( 'VezmoPay is not accepting inline payments.', 'vezmopay-woocommerce' ) ), 400 );
		}

		// Rate limit: this mints a REAL provider resource, and an anonymous
		// visitor could vary the cart total to create one per request.
		if ( $this->session_rate_limited() ) {
			$gateway->logger()->error( 'Refusing to create another VezmoPay payment session: rate limit reached for this visitor.' );
			wp_send_json_error(
				array( 'message' => __( 'Too many payment attempts. Please wait a moment and reload the page.', 'vezmopay-woocommerce' ) ),
				429
			);
		}

		$session = $gateway->checkout_session()->get();
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}

		// A session with no checkout URL cannot be mounted, and the browser's
		// origin check for the frame's messages is derived from that URL — so
		// handing one out would disable the check rather than fail visibly.
		if ( empty( $session['url'] ) ) {
			$gateway->logger()->error( 'VezmoPay returned a payment session with no checkout URL; refusing to start an inline payment.' );
			wp_send_json_error(
				array( 'message' => __( 'VezmoPay returned an incomplete payment session.', 'vezmopay-woocommerce' ) ),
				502
			);
		}

		// Only what the browser needs to mount the frame. The payment id stays
		// server-side: the order is bound to it in process_payment(), so nothing
		// the browser sends can point an order at a different payment.
		wp_send_json_success(
			array(
				'clientToken' => $session['clientToken'],
				'url'         => $session['url'],
				'sdkUrl'      => $session['sdkUrl'],
				'amount'      => $session['amount'],
				'currency'    => $session['currency'],
				'expires'     => $session['expires'],
			)
		);
	}

	/**
	 * Attach the shopper's billing details to the cart's payment session.
	 *
	 * The session is minted for the cart, before any billing field is filled, so
	 * it starts with no customer on it — and a bank payment cannot be made
	 * without one, because the ACH debit mandate requires the payer's email. The
	 * checkout sends the billing fields here as they are completed.
	 *
	 * Keeps the HARD nonce check, like ajax_session: this writes a customer
	 * record on the merchant's account, and a stale nonce costs a page reload
	 * rather than a payment (card is unaffected, and the bank path re-reads the
	 * session before it refuses).
	 */
	public function ajax_attach_client() {
		check_ajax_referer( 'vezmopay-checkout', 'nonce' );

		$gateway = $this->gateway();
		if ( ! $gateway || 'hosted' === $gateway->integration_mode() ) {
			wp_send_json_error( array( 'message' => __( 'VezmoPay is not accepting inline payments.', 'vezmopay-woocommerce' ) ), 400 );
		}

		if ( $this->rate_limited( 'client', self::CLIENT_RATE_MAX, self::CLIENT_RATE_WINDOW ) ) {
			$gateway->logger()->error( 'Refusing to attach another customer to the VezmoPay session: rate limit reached for this visitor.' );
			wp_send_json_error( array( 'message' => __( 'Too many checkout updates. Please wait a moment and reload the page.', 'vezmopay-woocommerce' ) ), 429 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$client = array();
		$fields = array(
			'name'       => 'sanitize_text_field',
			'email'      => 'sanitize_email',
			'phone'      => 'sanitize_text_field',
			'company'    => 'sanitize_text_field',
			'country'    => 'sanitize_text_field',
			'line1'      => 'sanitize_text_field',
			'line2'      => 'sanitize_text_field',
			'city'       => 'sanitize_text_field',
			'state'      => 'sanitize_text_field',
			'postalCode' => 'sanitize_text_field',
		);
		foreach ( $fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by $sanitizer, the allow-listed callback for this field.
				$client[ $field ] = call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// sanitize_email() empties anything malformed, and the API would 422 on
		// it. Say so plainly rather than letting the shopper reach the bank form
		// and be told there, vaguely, that an email is needed.
		if ( '' === trim( (string) ( $client['email'] ?? '' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid billing email address.', 'vezmopay-woocommerce' ) ), 400 );
		}

		$result = $gateway->checkout_session()->attach_client( $client );
		if ( is_wp_error( $result ) ) {
			// Deliberately generic: the API refuses some customers on the
			// merchant's risk rules and never says why, and relaying its wording
			// would turn this endpoint into an oracle for that list. The reason
			// is in the store's log.
			wp_send_json_error(
				array( 'message' => __( 'Your billing details could not be saved to the payment. Please check them and try again.', 'vezmopay-woocommerce' ) ),
				502
			);
		}

		wp_send_json_success( array( 'attached' => true ) );
	}

	/**
	 * AJAX: the SDK reported success/pending - verify against the API and finalize the order.
	 */
	public function ajax_confirm() {
		$this->verify_checkout_request( 'confirm' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_checkout_request() ran check_ajax_referer() above; get_authorized_order() below is the hard check (hash_equals on the order key).
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$order   = $this->get_authorized_order( $order_id, $order_key );
		$gateway = $this->gateway();

		if ( ! $order || ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'vezmopay-woocommerce' ) ), 400 );
		}

		$result = $gateway->reconcile_for_browser( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		// Only forward the customer once the API confirms a settled/settling state;
		// otherwise the page keeps polling.
		$settled = in_array( $result, array( 'CAPTURED', 'PENDING', 'REFUNDED' ), true );
		if ( $settled ) {
			// The inline flow keeps the cart through the charge so a decline stays
			// retryable; now that the money has moved, it is spent.
			$gateway->release_cart();
		}
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
		$this->verify_checkout_request( 'status' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_checkout_request() ran check_ajax_referer() above; get_authorized_order() below is the hard check (hash_equals on the order key).
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$order   = $this->get_authorized_order( $order_id, $order_key );
		$gateway = $this->gateway();

		if ( ! $order || ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'vezmopay-woocommerce' ) ), 400 );
		}

		// Already finalized (e.g. by webhook)? Send the customer on.
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			$gateway->release_cart();
			wp_send_json_success(
				array(
					'status'   => $order->is_paid() ? 'CAPTURED' : 'PENDING',
					'redirect' => $gateway->get_return_url( $order ),
				)
			);
		}

		$result = $gateway->reconcile_for_browser( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		$gateway->logger()->debug(
			'Status poll for order #' . $order->get_id() . ': ' . $result . ' (payment ' . $order->get_meta( '_vezmopay_payment_id' ) . ')'
		);

		$done = in_array( $result, array( 'CAPTURED', 'PENDING', 'FAILED' ), true );
		if ( $done && 'FAILED' !== $result ) {
			$gateway->release_cart();
		}
		wp_send_json_success(
			array(
				'status'   => $result,
				'redirect' => $done && 'FAILED' !== $result ? $gateway->get_return_url( $order ) : '',
			)
		);
	}

	/**
	 * AJAX: the browser is giving up on a payment attempt.
	 *
	 * Two jobs, in this order:
	 *
	 *   1. Ask the API, not the browser. The reason the browser reports is a
	 *      guess — VezmoPay reports no terminal state for a declined card (see
	 *      docs/VEZMOPAY-API-CONTRACT.md), so a timeout cannot tell a decline
	 *      from a slow capture. If the payment did settle, answer with the
	 *      redirect and let the shopper through instead of showing a failure.
	 *   2. Record the attempt on the order. Without this a declined card and an
	 *      abandoned cart look identical to the merchant: a pending order with
	 *      no notes at all.
	 */
	public function ajax_attempt_failed() {
		$this->verify_checkout_request( 'attempt-failed' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_checkout_request() ran check_ajax_referer() above; get_authorized_order() below is the hard check (hash_equals on the order key).
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$order   = $this->get_authorized_order( $order_id, $order_key );
		$gateway = $this->gateway();

		if ( ! $order || ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'vezmopay-woocommerce' ) ), 400 );
		}

		// Never a free-text message from the browser: an allow-list of reasons,
		// each mapped to wording this plugin owns.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same guard as the order lookup above.
		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'unknown';
		if ( ! in_array( $reason, array( 'timeout', 'declined', 'cancelled', 'expired', 'status', 'not-ready' ), true ) ) {
			$reason = 'unknown';
		}

		$status = $order->is_paid() ? 'CAPTURED' : $gateway->reconcile_for_browser( $order );
		if ( is_wp_error( $status ) || 'LOCKED' === $status ) {
			// Could not check. Say nothing on the order rather than record a
			// failure that may not have happened.
			//
			// LOCKED belongs here and not below: another actor is settling this
			// order at this very moment, and it is the one case where a failure
			// note is not merely unproven but actively wrong. This endpoint used
			// to write one anyway, so an order that completed a few seconds later
			// carried "VezmoPay reported no result for this payment attempt
			// (payment …, still LOCKED)" immediately above its own "payment
			// captured" note.
			wp_send_json_success(
				array(
					'status'   => 'UNKNOWN',
					'redirect' => '',
				)
			);
		}

		// It actually settled while the browser was giving up.
		if ( in_array( $status, array( 'CAPTURED', 'PENDING', 'REFUNDED' ), true ) ) {
			$gateway->release_cart();
			wp_send_json_success(
				array(
					'status'   => $status,
					'redirect' => $gateway->get_return_url( $order ),
				)
			);
		}

		$gateway->note_failed_attempt( $order, $reason, (string) $status );

		wp_send_json_success(
			array(
				'status'   => $status,
				'redirect' => '',
			)
		);
	}

	/**
	 * Guard shared by the account-settings AJAX handlers.
	 *
	 * @return Gateway Exits with a JSON error when not allowed / not configured.
	 */
	private function account_ajax_gateway() {
		check_ajax_referer( 'vezmopay-admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'vezmopay-woocommerce' ) ), 403 );
		}
		$gateway = $this->gateway();
		if ( ! $gateway || ! $gateway->api_client()->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your VezmoPay account first.', 'vezmopay-woocommerce' ) ), 400 );
		}
		return $gateway;
	}

	/**
	 * AJAX: load the account's payment-method + 3-D Secure state.
	 *
	 * Each block reports independently so a key missing one scope still
	 * renders the other card.
	 */
	public function ajax_account_get() {
		$gateway = $this->account_ajax_gateway();
		$client  = $gateway->api_client();

		// Cached briefly: this panel cost TWO blocking round trips (each preceded
		// by a login on a cold token cache) on every settings-screen load, and
		// double that when saving. The values change rarely and the merchant can
		// still force a read by saving, which busts the cache.
		$cache_key = 'vezmopay_account_panel_' . $gateway->environment();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$methods  = $client->get_payment_methods();
		$three_ds = $client->get_three_ds();

		$payload = array(
			'methods' => is_wp_error( $methods )
				? array(
					'ok'      => false,
					'message' => $methods->get_error_message(),
				)
				: array(
					'ok'   => true,
					'data' => $methods,
				),
			'threeDs' => is_wp_error( $three_ds )
				? array(
					'ok'      => false,
					'message' => $three_ds->get_error_message(),
				)
				: array(
					'ok'   => true,
					'data' => $three_ds,
				),
		);

		// Only cache a good read — an error must not be served for minutes.
		if ( ! is_wp_error( $methods ) && ! is_wp_error( $three_ds ) ) {
			set_transient( $cache_key, $payload, self::ACCOUNT_PANEL_TTL );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Drop the cached account panel (after a change, or a settings save).
	 */
	public function flush_account_panel_cache() {
		delete_transient( 'vezmopay_account_panel_test' );
		delete_transient( 'vezmopay_account_panel_live' );
	}

	/**
	 * AJAX: apply a settings change (payment-method toggle or 3-D Secure mode).
	 */
	public function ajax_account_update() {
		// The guard FIRST — account_ajax_gateway() is where the nonce and the
		// manage_woocommerce capability are checked. Flushing before it let any
		// logged-out request clear the cache and force the next admin page load to
		// re-fetch from the API.
		$client = $this->account_ajax_gateway()->api_client();

		// Whatever this changes, the cached panel is now stale.
		$this->flush_account_panel_cache();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- account_ajax_gateway() above is a hard check_ajax_referer( 'vezmopay-admin' ) plus a manage_woocommerce capability check.
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';

		if ( 'payment-methods' === $kind ) {
			$method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : '';
			if ( ! in_array( $method, array( 'ach', 'applePay', 'googlePay' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid payment method.', 'vezmopay-woocommerce' ) ), 400 );
			}
			$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
			$result  = $client->set_payment_methods( array( $method => $enabled ) );
		} elseif ( '3ds' === $kind ) {
			$mode   = isset( $_POST['mode'] ) && 'on' === $_POST['mode'] ? 'on' : 'auto';
			$result = $client->set_three_ds( $mode );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid setting.', 'vezmopay-woocommerce' ) ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Brand the gateway's settings screen (Connect button, status pill).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( self::GATEWAY_ID !== $section ) {
			return;
		}
		wp_enqueue_style( 'vezmopay-admin', VEZMOPAY_WC_PLUGIN_URL . 'assets/css/vezmopay-admin.css', array(), self::asset_version( 'assets/css/vezmopay-admin.css' ) );
		wp_enqueue_script( 'vezmopay-admin-account', VEZMOPAY_WC_PLUGIN_URL . 'assets/js/admin-account.js', array(), self::asset_version( 'assets/js/admin-account.js' ), true );
		wp_localize_script(
			'vezmopay-admin-account',
			'vezmopay_admin_params',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vezmopay-admin' ),
				// What this store actually serves shoppers. The panel describes the
				// VezmoPay ACCOUNT, which is not the same thing — see the scope
				// lines below — so it has to know which checkout this store runs.
				'mode'    => $gateway->integration_mode(),
				'i18n'    => array(
					'loading'        => __( 'Loading your VezmoPay account settings…', 'vezmopay-woocommerce' ),
					'loadFailed'     => __( 'We couldn\'t load your VezmoPay account settings. Make sure your API key has the account.read permission, or manage them in your VezmoPay console.', 'vezmopay-woocommerce' ),
					'paymentMethods' => __( 'Payment methods', 'vezmopay-woocommerce' ),
					'pmIntro'        => __( 'Choose which methods your VezmoPay account offers. Cards are always on.', 'vezmopay-woocommerce' ),
					// The embedded form's method list is resolved by VezmoPay when the
					// payment is created; POST /merchant/secure-payments takes no
					// method restriction, so nothing the plugin sends can change it.
					// A merchant reading this panel must not conclude otherwise.
					'pmScope'        => __( 'These settings apply to your VezmoPay account — payment links, invoices and products. VezmoPay decides which methods the embedded payment form on this store offers, so a method shown here as unavailable can still appear at your checkout.', 'vezmopay-woocommerce' ),
					'embedOnly'      => __( 'Hosted checkout only', 'vezmopay-woocommerce' ),
					'embedOnlyDesc'  => __( 'Your checkout is set to the embedded payment form, which does not show wallet buttons on the current VezmoPay build — turning this on changes nothing for this store today. It still applies to payment links, invoices and products.', 'vezmopay-woocommerce' ),
					'pmFooter'       => __( 'Apple Pay and Google Pay appear automatically for eligible customers on supported devices, wherever VezmoPay offers them. Disabling a method hides it everywhere immediately.', 'vezmopay-woocommerce' ),
					'card'           => __( 'Cards', 'vezmopay-woocommerce' ),
					'cardDesc'       => __( 'Visa, Mastercard, Amex and more. Always on — the baseline payment method for every checkout.', 'vezmopay-woocommerce' ),
					'ach'            => __( 'Bank transfer (ACH)', 'vezmopay-woocommerce' ),
					'achDesc'        => __( 'Let US customers pay directly from a bank account. Lower fees; USD only.', 'vezmopay-woocommerce' ),
					'applePay'       => __( 'Apple Pay', 'vezmopay-woocommerce' ),
					'applePayDesc'   => __( 'One-tap checkout for customers on Apple devices (rides the card rail).', 'vezmopay-woocommerce' ),
					'googlePay'      => __( 'Google Pay', 'vezmopay-woocommerce' ),
					'googlePayDesc'  => __( 'One-tap checkout for customers on Google / Android (rides the card rail).', 'vezmopay-woocommerce' ),
					'alwaysOn'       => __( 'Always on', 'vezmopay-woocommerce' ),
					'managed'        => __( 'Managed by Vezmo', 'vezmopay-woocommerce' ),
					'notAvailable'   => __( 'Not available yet', 'vezmopay-woocommerce' ),
					'verifyHint'     => __( 'Complete account verification to enable this method.', 'vezmopay-woocommerce' ),
					'threeDs'        => __( '3D Secure (card authentication)', 'vezmopay-woocommerce' ),
					'threeDsOn'      => __( '3D Secure is on', 'vezmopay-woocommerce' ),
					'threeDsOff'     => __( '3D Secure is off', 'vezmopay-woocommerce' ),
					'threeDsOnDesc'  => __( 'Every card payment is authenticated with 3D Secure, reducing fraud and shifting chargeback liability to the card issuer.', 'vezmopay-woocommerce' ),
					'threeDsOffDesc' => __( '3D Secure is only requested when the card issuer or regulations require it.', 'vezmopay-woocommerce' ),
					'threeDsLocked'  => __( '3D Secure is required on your account. Contact support if you need to change this.', 'vezmopay-woocommerce' ),
					'threeDsNote'    => __( 'Cards from regions with regulatory requirements (e.g. Europe/SCA) are always authenticated regardless of this setting — turning it off only stops requesting 3D Secure on other cards.', 'vezmopay-woocommerce' ),
					'updateFailed'   => __( 'The change could not be saved: ', 'vezmopay-woocommerce' ),
				),
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
		// Read the flag straight from the option. Building the gateway registry
		// for this is what made the old `init` hook expensive and order-sensitive.
		$settings = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		$enabled  = is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];

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

		// Least-recently-checked first. Taking the NEWEST 25 every run, with no
		// record of what had been checked, meant abandoned orders sat in the
		// window and occupied the same slots forever: a store taking more than 25
		// VezmoPay orders per five minutes never reached an older paid-but-
		// unreconciled one — and in hosted mode this cron is the only automatic
		// path when a webhook is lost.
		$orders = wc_get_orders(
			array(
				'limit'          => 25,
				'status'         => array( 'pending', 'on-hold' ),
				'payment_method' => self::GATEWAY_ID,
				'date_created'   => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'meta_key'       => '_vezmopay_last_reconciled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering by this meta IS the fix; the alternative is starving older orders.
				'orderby'        => array(
					'meta_value_num' => 'ASC',
					'ID'             => 'ASC',
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see above.
					'relation' => 'OR',
					array(
						'key'     => '_vezmopay_last_reconciled',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_vezmopay_last_reconciled',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		// Housekeeping first, and bounded: an event claim is KEPT for its TTL — that
		// is what makes a replayed delivery a duplicate — so nothing removed it at
		// the end of a request and a busy store grew one wp_options row per webhook
		// event for the life of the install. They are not autoloaded, so this is
		// table size rather than page weight, but it is unbounded either way.
		$swept = Lock::sweep( 'vezmopay_evt_', Webhook::EVENT_CLAIM_TTL )
			+ Lock::sweep( 'vezmopay_recon_', Gateway::RECONCILE_LOCK_TTL );
		if ( $swept > 0 ) {
			$gateway->logger()->debug( 'Swept ' . $swept . ' expired VezmoPay claim rows.' );
		}

		foreach ( $orders as $order ) {
			$has_ref = '' !== (string) $order->get_meta( '_vezmopay_payment_id' ) || '' !== (string) $order->get_meta( '_vezmopay_paylink_code' );
			if ( ! $has_ref ) {
				continue;
			}
			if ( $this->give_up_on_order( $gateway, $order ) ) {
				continue;
			}

			// Stamp BEFORE the call: an order whose reconcile throws must still go
			// to the back of the queue, or it blocks everything behind it.
			$order->update_meta_data( '_vezmopay_last_reconciled', time() );
			$order->save_meta_data();

			$result = $gateway->reconcile_order_with_api( $order );
			if ( is_wp_error( $result ) ) {
				$gateway->logger()->debug( 'Cron reconcile failed for order #' . $order->get_id() . ': ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Stop re-reading an order that is never going to answer.
	 *
	 * A hosted order on an account VezmoPay has not activated is the case that
	 * matters: the customer meets an "unavailable" page, no payment is made, no
	 * webhook is sent, and GET /merchant/paylinks/{code} answers INITIATED for
	 * ever — so the cron re-read a dead link every five minutes for a week and
	 * the merchant was never told. One note, once, then leave it alone.
	 *
	 * @param Gateway   $gateway Gateway.
	 * @param \WC_Order $order   Order.
	 * @return bool Whether this order should be skipped from here on.
	 */
	private function give_up_on_order( $gateway, $order ) {
		if ( '' !== (string) $order->get_meta( '_vezmopay_gave_up' ) ) {
			return true;
		}
		// NEVER an on-hold order: that is a bank debit on its way, which takes one
		// to two business days and settles on its own. Only a pending order, where
		// nothing is moving, is a candidate for giving up on.
		if ( ! $order->has_status( 'pending' ) ) {
			return false;
		}
		$created = $order->get_date_created();
		if ( ! $created || ( time() - $created->getTimestamp() ) < self::ABANDON_AFTER ) {
			return false;
		}

		$gateway->logger()->error(
			'Order #' . $order->get_id() . ' has not settled in ' . round( self::ABANDON_AFTER / HOUR_IN_SECONDS )
			. ' hours; stopping automatic checks.'
		);
		$order->add_order_note(
			'' !== (string) $order->get_meta( '_vezmopay_paylink_code' )
				? __( 'VezmoPay has reported no payment against this order’s payment link in 24 hours, and the plugin has stopped checking automatically. If the customer says they paid, check the payment in your VezmoPay dashboard. If the link showed them “unavailable”, this account is not activated for payment links — contact VezmoPay, and consider switching Integration mode away from Hosted checkout so customers get the embedded payment form instead.', 'vezmopay-woocommerce' )
				: __( 'VezmoPay has reported no result for this order’s payment in 24 hours, and the plugin has stopped checking automatically. Check the payment in your VezmoPay dashboard before cancelling the order.', 'vezmopay-woocommerce' )
		);
		$order->update_meta_data( '_vezmopay_gave_up', time() );
		$order->save();
		return true;
	}

	/**
	 * Add the manual status-check action to the order edit screen.
	 *
	 * @param array          $actions Order actions.
	 * @param \WC_Order|null $order   Order being edited (null on older WC).
	 * @return array
	 */
	public function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() === self::GATEWAY_ID ) {
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
		if ( ! $gateway || $order->get_payment_method() !== self::GATEWAY_ID ) {
			return;
		}
		$result = $gateway->reconcile_for_browser( $order );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'VezmoPay status check failed: %s', 'vezmopay-woocommerce' ),
					$result->get_error_message()
				)
			);
		} elseif ( 'LOCKED' === $result ) {
			// An internal marker, not a payment status — never show it as one.
			$order->add_order_note(
				__( 'VezmoPay status check: another check was already running for this order. Try again in a moment.', 'vezmopay-woocommerce' )
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
