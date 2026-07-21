/**
 * VezmoPay iframe mode.
 *
 * The pay page embeds VezmoPay's hosted payment page directly in an iframe (rendered
 * server-side, so payment works even without this script). This script only polls the
 * store's status endpoint — which re-verifies against the VezmoPay API — and forwards
 * the customer to the thank-you page once the payment is captured or pending.
 *
 * @package VezmoPay
 */

/* global vezmopay_params */
( function () {
	'use strict';

	if ( typeof vezmopay_params === 'undefined' ) {
		return;
	}

	var params = vezmopay_params;
	var messageEl = document.getElementById( 'vezmopay-message' );
	var checkoutEl = document.getElementById( 'vezmopay-checkout' );
	var frameEl = document.getElementById( 'vezmopay-frame' );
	var done = false;

	function setMessage( text, kind ) {
		if ( ! messageEl ) {
			return;
		}
		messageEl.textContent = text || '';
		messageEl.className = 'vezmopay-message' + ( text ? ' is-' + ( kind || 'info' ) : '' );
	}

	function markReady() {
		if ( checkoutEl ) {
			checkoutEl.classList.add( 'is-ready' );
		}
	}

	if ( frameEl ) {
		frameEl.addEventListener( 'load', markReady );
	}
	window.setTimeout( markReady, 8000 );

	function poll() {
		if ( done ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		body.append( 'order_id', params.orderId );
		body.append( 'order_key', params.orderKey );

		window
			.fetch( params.statusUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				if ( res.data.redirect ) {
					done = true;
					if ( 'PENDING' === res.data.status ) {
						setMessage( params.i18n.pending, 'info' );
					}
					window.location = res.data.redirect;
				} else if ( 'FAILED' === res.data.status ) {
					setMessage( params.i18n.failed, 'error' );
				} else if ( 'MISMATCH' === res.data.status ) {
					// Manual review required — polling will never resolve this.
					done = true;
					setMessage( params.i18n.review, 'info' );
				}
			} )
			.catch( function () {
				// Transient network error — keep polling.
			} );
	}

	window.setInterval( poll, Math.max( 3000, parseInt( params.pollInterval, 10 ) || 4000 ) );
} )();
