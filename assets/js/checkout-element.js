/**
 * VezmoPay inline element mode.
 *
 * Mounts the vezmo.js SDK (VezmoPay-hosted iframe) into the pay page and finalizes the
 * order on the SDK's postMessage events. Because those events only reach origins the
 * merchant has registered as "trusted origins" in the VezmoPay dashboard, a slow status
 * poll runs in parallel as a fallback, so payment still completes if events are blocked.
 *
 * @package VezmoPay
 */

/* global Vezmo, vezmopay_params */
( function () {
	'use strict';

	if ( typeof vezmopay_params === 'undefined' ) {
		return;
	}

	var params = vezmopay_params;
	var container = document.getElementById( 'vezmopay-container' );
	var messageEl = document.getElementById( 'vezmopay-message' );
	var checkoutEl = document.getElementById( 'vezmopay-checkout' );
	var payButton = document.getElementById( 'vezmopay-pay' );
	var finalized = false;
	var pollTimer = null;
	var POLL_LIMIT_MS = 15 * 60 * 1000;

	// The embedded secure page hides its own submit button and charges only when
	// the merchant page asks it to, so this button is the shopper's way to pay.
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

	// A theme can hand this page a column far narrower than the screen, which
	// makes the VezmoPay form inside render its phone layout on a desktop
	// monitor. Only then — narrow card, wide viewport — centre the card on the
	// viewport instead of the column.
	function checkBreakout() {
		if ( ! checkoutEl ) {
			return;
		}
		// Always measure from the un-broken state, so a resize can undo this.
		checkoutEl.classList.remove( 'is-breakout' );
		checkoutEl.style.width = '';
		checkoutEl.style.marginLeft = '';

		var cardWidth = checkoutEl.getBoundingClientRect().width;
		if ( cardWidth >= 420 || window.innerWidth < 700 ) {
			return;
		}

		// Centre on the VIEWPORT: offset the card by the gap between the
		// viewport's centred position and wherever the narrow parent starts.
		var target = Math.min( 760, window.innerWidth - 40 );
		var parent = checkoutEl.parentElement || document.body;
		var offset = Math.round(
			( window.innerWidth - target ) / 2 - parent.getBoundingClientRect().left
		);
		checkoutEl.classList.add( 'is-breakout' );
		checkoutEl.style.width = target + 'px';
		checkoutEl.style.marginLeft = offset + 'px';
	}

	function post( url, extra ) {
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		body.append( 'order_id', params.orderId );
		body.append( 'order_key', params.orderKey );
		Object.keys( extra || {} ).forEach( function ( k ) {
			body.append( k, extra[ k ] );
		} );
		return window
			.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				// `-1` from a rejected nonce is not JSON; flag it instead of
				// letting the parse error masquerade as a network hiccup.
				if ( 401 === res.status || 403 === res.status ) {
					return { __authFailed: true };
				}
				return res.json();
			} );
	}

	function finalize( statusMessage ) {
		if ( finalized ) {
			return;
		}
		finalized = true;
		setMessage( statusMessage || params.i18n.processing, 'info' );
		post( params.confirmUrl )
			.then( function ( res ) {
				if ( res && res.__authFailed ) {
					finalized = false;
					setMessage( params.i18n.expired, 'error' );
					return;
				}
				if ( res && res.success && res.data && res.data.redirect ) {
					// Only stop the fallback poll once the server has confirmed;
					// it is the safety net if this confirm call fails.
					if ( pollTimer ) {
						window.clearInterval( pollTimer );
					}
					window.location = res.data.redirect;
				} else {
					finalized = false;
				}
			} )
			.catch( function () {
				finalized = false;
			} );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function startPolling() {
		if ( pollTimer ) {
			return;
		}
		var started = Date.now();
		pollTimer = window.setInterval( function () {
			if ( finalized ) {
				return;
			}
			// Bounded, like the iframe poller: an unsettleable payment must not
			// leave this running for the life of the page.
			if ( Date.now() - started > POLL_LIMIT_MS ) {
				stopPolling();
				return;
			}
			post( params.statusUrl )
				.then( function ( res ) {
					if ( res && res.__authFailed ) {
						// A retired nonce, not a network blip. Stop and say so.
						finalized = true;
						stopPolling();
						setMessage( params.i18n.expired, 'error' );
						return;
					}
					if ( ! res || ! res.success || ! res.data ) {
						return;
					}
					if ( res.data.redirect ) {
						finalized = true;
						window.clearInterval( pollTimer );
						window.location = res.data.redirect;
					} else if ( 'FAILED' === res.data.status ) {
						setPaying( false );
						setMessage( params.i18n.failed, 'error' );
					} else if ( 'MISMATCH' === res.data.status ) {
						// Manual review required — polling will never resolve this.
						finalized = true;
						window.clearInterval( pollTimer );
						setMessage( params.i18n.review, 'info' );
					}
				} )
				.catch( function () {
					// Transient network error — keep polling.
				} );
		}, Math.max( 3000, parseInt( params.pollInterval, 10 ) || 4000 ) );
	}

	function mountFallbackIframe() {
		if ( ! container || container.querySelector( 'iframe' ) ) {
			return;
		}
		var frame = document.createElement( 'iframe' );
		frame.id = 'vezmopay-frame';
		frame.src = params.iframeUrl;
		frame.setAttribute( 'allow', 'payment' );
		frame.setAttribute( 'title', 'VezmoPay secure payment' );
		frame.addEventListener( 'load', markReady );
		container.appendChild( frame );

		// No SDK here, so drive the charge with the same message it would post.
		if ( payButton && params.secureOrigin ) {
			payButton.addEventListener( 'click', function () {
				setPaying( true );
				setMessage( '' );
				frame.contentWindow.postMessage(
					{ type: 'vezmo:secure-payment:submit' },
					params.secureOrigin
				);
			} );
		}
	}

	function init() {
		if ( ! container ) {
			return;
		}

		if ( typeof Vezmo === 'undefined' ) {
			// SDK failed to load — degrade to a raw iframe + polling.
			mountFallbackIframe();
			startPolling();
			return;
		}

		try {
			var vezmo = new Vezmo( params.apiBase ? { apiBase: params.apiBase } : undefined );
			vezmo.mount( container, { clientToken: params.clientToken } );

			var sdkFrame = container.querySelector( 'iframe' );
			if ( sdkFrame ) {
				sdkFrame.addEventListener( 'load', markReady );
			}

			if ( payButton ) {
				payButton.addEventListener( 'click', function () {
					setPaying( true );
					setMessage( '' );
					// Posts vezmo:secure-payment:submit into the frame, which runs
					// the same charge path as the hosted page's own button.
					vezmo.pay();
				} );
			}

			vezmo.on( 'ready', markReady );
			vezmo.on( 'success', function () {
				finalize();
			} );
			vezmo.on( 'pending', function () {
				finalize( params.i18n.pending );
			} );
			vezmo.on( 'already-paid', function () {
				finalize();
			} );
			vezmo.on( 'error', function ( evt ) {
				markReady();
				setPaying( false );
				setMessage( ( evt && evt.message ) || params.i18n.failed, 'error' );
			} );
			vezmo.on( 'expired', function () {
				setMessage( params.i18n.expired, 'info' );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} );
		} catch ( e ) {
			mountFallbackIframe();
		}

		// The SDK mounts its own frame, so a ready event cannot have been missed
		// before this script ran — but never leave the spinner (and the Pay button
		// with it) waiting on an event that may never arrive.
		window.setTimeout( markReady, 2500 );

		// Fallback for stores whose origin is not (yet) in VezmoPay's trusted origins:
		// events never arrive, but the poll still completes the order.
		startPolling();
	}

	checkBreakout();
	window.addEventListener( 'resize', checkBreakout );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
