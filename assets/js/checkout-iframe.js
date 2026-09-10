/**
 * VezmoPay iframe mode, on the store's own pay page.
 *
 * The pay page embeds VezmoPay's hosted payment page directly in an iframe (rendered
 * server-side, so payment works even without this script). This script drives the
 * charge, listens to the frame's own messages, and leans on the store's status
 * endpoint — which re-verifies against the VezmoPay API — to forward the customer
 * once the payment is captured or pending.
 *
 * Everything that is not this mode's own frame handling — the message area, the
 * Pay button, the poll, the bounded attempt, the failure handling — is in
 * pay-attempt.js, shared with checkout-element.js.
 *
 * @package VezmoPay
 */

/* global vezmopay_params, VezmoPayAttempt */
( function () {
	'use strict';

	if ( typeof vezmopay_params === 'undefined' || typeof VezmoPayAttempt !== 'function' ) {
		return;
	}

	var params = vezmopay_params;
	var frameEl = document.getElementById( 'vezmopay-frame' );
	var attempt = VezmoPayAttempt( params );

	if ( frameEl ) {
		frameEl.addEventListener( 'load', attempt.markReady );
	}
	// The inline snippet on the page records a load that happened before this
	// script ran, so a fast frame does not leave the Pay button hidden.
	if ( window.vezmopayFrameLoaded ) {
		attempt.markReady();
	}
	// Safety net for a frame that never reports anything. Short, because the
	// button below is the only way to pay — nobody should wait on a spinner.
	window.setTimeout( attempt.markReady, 2500 );

	// The embedded page hides its own submit button and charges only on a
	// parent -> iframe submit message (the same one vezmo.js's .pay() posts),
	// so without this button the shopper cannot pay at all.
	if ( frameEl && params.secureOrigin ) {
		attempt.onPay( function () {
			frameEl.contentWindow.postMessage(
				{ type: 'vezmo:secure-payment:submit' },
				params.secureOrigin
			);
		} );
	}

	// The frame reports outcomes to us as well as to the store's status endpoint.
	// Completion still goes through the store (the poll and the confirm call
	// re-verify against the API rather than trusting a message), but a decline
	// must hand the button back immediately — and it is the only signal a
	// decline produces at all.
	function onFrameMessage( e ) {
		// Source first where we have it: no page can forge e.source for a window
		// it does not own. The replayed events from the page's early collector
		// carry their original source, so this holds for those too.
		if ( frameEl && e.source && e.source !== frameEl.contentWindow ) {
			return;
		}
		if ( params.secureOrigin && e.origin !== params.secureOrigin ) {
			return;
		}
		var type = e.data && e.data.type;
		if ( 'vezmo:secure-payment:resize' === type ) {
			// The checkout reports its content height; size the frame to it so the
			// form never sits in its own scrollbar. Same behaviour vezmo.js gives
			// element mode, and clamped so a bad number cannot break the page.
			var h = Number( e.data.height );
			if ( frameEl && h > 200 && h < 4000 ) {
				frameEl.style.height = h + 'px';
				frameEl.setAttribute( 'height', String( h ) );
			}
		} else if ( 'vezmo:secure-payment:ready' === type ) {
			attempt.markReady();
		} else if ( 'vezmo:secure-payment:processing' === type ) {
			attempt.acknowledge();
		} else if ( 'vezmo:secure-payment:requires_action' === type ) {
			attempt.awaitAction( true );
		} else if (
			'vezmo:secure-payment:success' === type ||
			'vezmo:secure-payment:already-paid' === type
		) {
			attempt.finalize();
		} else if ( 'vezmo:secure-payment:pending' === type ) {
			attempt.finalize( params.i18n.pending );
		} else if ( 'vezmo:secure-payment:error' === type ) {
			attempt.failAttempt( 'declined', ( e.data && e.data.message ) || params.i18n.failed );
		} else if ( 'vezmo:secure-payment:cancel' === type ) {
			attempt.failAttempt( 'cancelled', params.i18n.cancelled || params.i18n.failed );
		} else if ( 'vezmo:secure-payment:expired' === type ) {
			attempt.failAttempt( 'expired', params.i18n.expired );
		}
	}

	window.addEventListener( 'message', onFrameMessage );

	// Replay anything the frame posted before this script was parsed (a ready or
	// resize we would otherwise have missed entirely).
	if ( window.vezmopayEmbedEvents && window.vezmopayEmbedEvents.length ) {
		window.vezmopayEmbedEvents.forEach( onFrameMessage );
		window.vezmopayEmbedEvents.length = 0;
	}

	// Stop the page's early collector now that we are listening ourselves: each
	// retained MessageEvent holds a reference to the frame's Window, and nothing
	// used to remove that listener.
	if ( window.vezmopayStopEarlyEvents ) {
		window.vezmopayStopEarlyEvents();
	}

	attempt.startPolling();
} )();
