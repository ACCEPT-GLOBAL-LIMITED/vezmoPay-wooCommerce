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
	var payButton = document.getElementById( 'vezmopay-pay' );
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
	// The inline snippet on the page records a load that happened before this
	// script ran, so a fast frame does not leave the Pay button hidden.
	if ( window.vezmopayFrameLoaded ) {
		markReady();
	}
	// Safety net for a frame that never reports anything. Short, because the
	// button below is the only way to pay — nobody should wait on a spinner.
	window.setTimeout( markReady, 2500 );

	// The embedded page hides its own submit button and charges only on a
	// parent -> iframe submit message (the same one vezmo.js's .pay() posts),
	// so without this button the shopper cannot pay at all.
	function setPaying( paying ) {
		if ( ! payButton ) {
			return;
		}
		payButton.disabled = paying;
		payButton.classList.toggle( 'is-paying', !! paying );
		var label = payButton.querySelector( '.vezmopay-pay-label' );
		if ( label ) {
			label.textContent = paying ? params.i18n.processing : params.i18n.pay;
		}
	}

	if ( payButton && frameEl && params.secureOrigin ) {
		payButton.addEventListener( 'click', function () {
			setPaying( true );
			setMessage( '' );
			frameEl.contentWindow.postMessage(
				{ type: 'vezmo:secure-payment:submit' },
				params.secureOrigin
			);
		} );
	}

	// The frame reports outcomes to us as well as to the store's status endpoint.
	// Only the failure/ready signals matter here — completion is handled by the
	// poll below, which re-verifies against the API rather than trusting a
	// message — but a decline must hand the button back immediately.
	function onFrameMessage( e ) {
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
			markReady();
		} else if ( 'vezmo:secure-payment:error' === type ) {
			setPaying( false );
			setMessage( ( e.data && e.data.message ) || params.i18n.failed, 'error' );
		}
	}

	window.addEventListener( 'message', onFrameMessage );

	// Replay anything the frame posted before this script was parsed (a ready or
	// resize we would otherwise have missed entirely).
	if ( window.vezmopayEmbedEvents && window.vezmopayEmbedEvents.length ) {
		window.vezmopayEmbedEvents.forEach( onFrameMessage );
		window.vezmopayEmbedEvents.length = 0;
	}

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
					setPaying( false );
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
