<?php
/**
 * TEST-ONLY VezmoPay API mock. Behaves like the real platform in the ways that
 * matter for the audit: creation is IDEMPOTENT on the Idempotency-Key header,
 * and a payment reads INITIATED until it is actually charged.
 */
add_action( "wp_ajax_nopriv_mockpay_fail", function () {
	$id = isset( $_POST["p"] ) ? sanitize_text_field( wp_unslash( $_POST["p"] ) ) : "";
	if ( $id ) { set_transient( "mockpay_failed_" . $id, 1, HOUR_IN_SECONDS ); }
	wp_send_json_success();
} );

add_action( "wp_ajax_nopriv_mockpay_capture", function () {
	$id = isset( $_POST["p"] ) ? sanitize_text_field( wp_unslash( $_POST["p"] ) ) : "";
	if ( $id ) { set_transient( "mockpay_captured_" . $id, 1, HOUR_IN_SECONDS ); }
	wp_send_json_success();
} );

/** decline_once: the first payment created declines, every later one succeeds. */
function vezmopay_mock_outcome() {
	$want = (string) get_option( "mockpay_outcome", "success" );
	if ( "decline_once" !== $want ) { return $want; }
	$n = (int) get_transient( "mockpay_created" );
	set_transient( "mockpay_created", $n + 1, HOUR_IN_SECONDS );
	return 0 === $n ? "decline" : "success";
}

add_filter( "pre_http_request", function ( $pre, $args, $url ) {
	$json = function ( $data, $code = 200 ) {
		return array(
			"response" => array( "code" => $code, "message" => "OK" ),
			"body"     => wp_json_encode( $data ),
			"headers"  => array(), "cookies" => array(), "filename" => null,
		);
	};

	if ( false !== strpos( $url, "/merchant/api-auth/login" ) ) {
		return $json( array( "success" => true, "data" => array( "accessToken" => array( "token" => "mock.jwt" ) ) ) );
	}
	// POST /secure-payments/{token}/client — 0.3.7 attaches the shopper's billing
	// details to the session so an ACH mandate can be signed.
	if ( preg_match( "#/secure-payments/[^/]+/client$#", $url ) && "POST" === strtoupper( $args["method"] ?? "" ) ) {
		return $json( array( "success" => true, "data" => array( "attached" => true ) ) );
	}
	if ( false !== strpos( $url, "/frame-ancestors" ) ) {
		return $json( array( "origins" => array( "http://localhost:8080" ) ) );
	}

	if ( false !== strpos( $url, "/merchant/secure-payments" ) && "POST" === strtoupper( $args["method"] ?? "" ) ) {
		$body     = json_decode( $args["body"] ?? "{}", true );
		$amount   = isset( $body["amount"] ) ? (float) $body["amount"] : 0;
		$currency = isset( $body["currency"] ) ? (string) $body["currency"] : "USD";
		$idem     = "";
		foreach ( (array) ( $args["headers"] ?? array() ) as $h => $v ) {
			if ( 0 === strcasecmp( $h, "Idempotency-Key" ) ) { $idem = (string) $v; }
		}

		// The whole point: the SAME key replays the SAME payment.
		$replay = $idem ? get_transient( "mockidem_" . md5( $idem ) ) : false;
		if ( is_array( $replay ) ) {
			return $json( array( "success" => true, "data" => $replay ) );
		}

		$id      = "pay_mock_" . substr( md5( $idem . microtime() ), 0, 10 );
		$url     = "http://127.0.0.1:8080/wp-content/uploads/mock-secure.html?outcome=" . vezmopay_mock_outcome() . "&p=" . $id;
		// The token carries the frame URL so the stand-in SDK can find it the way
		// the real vezmo.js derives it from the real token.
		$tok     = "tok_" . rtrim( strtr( base64_encode( $url ), "+/", "-_" ), "=" );
		$payload = array(
			"payment"       => array( "id" => $id, "status" => "INITIATED", "amount" => $amount, "currency" => $currency ),
			"securePayment" => array(
				"clientToken" => $tok,
				"url"         => $url,
				"sdkUrl"      => get_option( "mockpay_evil_sdk" ) ? "https://evil.example/pwn.js" : "http://127.0.0.1:8080/wp-content/uploads/mock-vezmo.js",
				"expiresAt"   => gmdate( "c", time() + 3600 ),
			),
		);
		set_transient( "mockpay_" . $id, array( "amount" => $amount, "currency" => $currency ), HOUR_IN_SECONDS );
		if ( $idem ) { set_transient( "mockidem_" . md5( $idem ), $payload, HOUR_IN_SECONDS ); }
		return $json( array( "success" => true, "data" => $payload ) );
	}

	// POST /merchant/paylinks — hosted mode.
	if ( false !== strpos( $url, "/merchant/paylinks" ) && "POST" === strtoupper( $args["method"] ?? "" ) ) {
		$body = json_decode( $args["body"] ?? "{}", true );
		$code = substr( md5( microtime() ), 0, 10 );
		set_transient( "mocklink_" . $code, array(
			"amount"   => isset( $body["amount"] ) ? (float) $body["amount"] : 0,
			"currency" => isset( $body["currency"] ) ? (string) $body["currency"] : "USD",
		), HOUR_IN_SECONDS );
		return $json( array( "success" => true, "data" => array( "shortCode" => $code, "status" => "INITIATED" ) ) );
	}

	// GET /merchant/paylinks/{code} — PAID once the test says so.
	if ( preg_match( "#/merchant/paylinks/([a-z0-9]+)$#i", $url, $lm ) ) {
		$meta = (array) get_transient( "mocklink_" . $lm[1] );
		$paid = (bool) get_transient( "mocklink_paid_" . $lm[1] );
		return $json( array( "success" => true, "data" => array(
			"shortCode" => $lm[1],
			"status"    => $paid ? "PAID" : "INITIATED",
			"amount"    => isset( $meta["amount"] ) ? $meta["amount"] : 0,
			"currency"  => isset( $meta["currency"] ) ? $meta["currency"] : "USD",
		) ) );
	}

	// GET /merchant/payment/{id} — INITIATED until charged, then CAPTURED.
	if ( preg_match( "#/merchant/payment/(pay_mock_[a-z0-9]+)#i", $url, $m ) ) {
		$meta   = (array) get_transient( "mockpay_" . $m[1] );
		$paid   = (bool) get_transient( "mockpay_captured_" . $m[1] );
		$failed = (bool) get_transient( "mockpay_failed_" . $m[1] );
		return $json( array( "success" => true, "data" => array(
			"id"       => $m[1],
			"status"   => $paid ? "CAPTURED" : ( $failed ? "FAILED" : "INITIATED" ),
			"amount"   => isset( $meta["amount"] ) ? $meta["amount"] : 0,
			"currency" => isset( $meta["currency"] ) ? $meta["currency"] : "USD",
		) ) );
	}

	return $pre;
}, 10, 3 );
