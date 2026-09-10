/**
 * VezmoPay inline element mode, on the store's own pay page.
 *
 * Mounts the vezmo.js SDK (VezmoPay-hosted iframe) into the pay page and finalizes the
 * order on the SDK's postMessage events. Because those events only reach origins the
 * merchant has registered as "trusted origins" in the VezmoPay dashboard, a slow status
 * poll runs in parallel as a fallback, so payment still completes if events are blocked.
 *
 * Everything that is not mounting or event wiring — the message area, the Pay
 * button, the poll, the bounded attempt, the failure handling — is in
 * pay-attempt.js, shared with checkout-iframe.js. The two used to carry their
 * own copies and drifted apart; see that file.
 *
 * @package VezmoPay
 */

/* global Vezmo, vezmopay_params, VezmoPayAttempt */
( function () {
	'use strict';

	if ( typeof vezmopay_params === 'undefined' || typeof VezmoPayAttempt !== 'function' ) {
		return;
	}

	var params = vezmopay_params;
	var container = document.getElementById( 'vezmopay-container' );
	var attempt = VezmoPayAttempt( params );

	function mountFallbackIframe() {
		if ( ! container || container.querySelector( 'iframe' ) ) {
			return;
		}
		var frame = document.createElement( 'iframe' );
		frame.id = 'vezmopay-frame';
		frame.src = params.iframeUrl;
		// `payment *` and `storage-access *`, matching every other embed in the
		// plugin: bare `payment` scopes the permission to the frame's src origin,
		// which breaks wallets across the checkout redirect, and WITHOUT storage
		// access the 3-D Secure challenge cannot complete in a third-party frame.
		// This is the ad-blocker fallback path, so it runs exactly when things are
		// already degraded.
		frame.setAttribute( 'allow', 'payment *; storage-access *' );
		frame.setAttribute( 'title', ( params.i18n && params.i18n.frameTitle ) || 'VezmoPay secure payment' );
		frame.addEventListener( 'load', attempt.markReady );
		container.appendChild( frame );

		// No SDK here, so drive the charge with the same message it would post,
		// and listen for the frame's own events the way iframe mode does.
		if ( params.secureOrigin ) {
			attempt.onPay( function () {
				frame.contentWindow.postMessage(
					{ type: 'vezmo:secure-payment:submit' },
					params.secureOrigin
				);
			} );
			window.addEventListener( 'message', function ( e ) {
				if ( e.source !== frame.contentWindow || e.origin !== params.secureOrigin ) {
					return;
				}
				var type = e.data && e.data.type;
				if ( 'vezmo:secure-payment:resize' === type ) {
					var h = Number( e.data.height );
					if ( h > 200 && h < 4000 ) {
						frame.style.height = h + 'px';
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
					attempt.markReady();
					attempt.failAttempt( 'declined', ( e.data && e.data.message ) || params.i18n.failed );
				}
			} );
		}
	}

	function init() {
		if ( ! container ) {
			return;
		}

		// The page's early collector holds a MessageEvent — and through it the
		// frame's Window — for every message posted before this script parsed. This
		// path never drained it, so it grew from the first resize onward. Take it
		// down; the SDK has its own listener from here.
		if ( window.vezmopayStopEarlyEvents ) {
			window.vezmopayStopEarlyEvents();
		}

		if ( typeof Vezmo === 'undefined' ) {
			// SDK failed to load — degrade to a raw iframe + polling.
			mountFallbackIframe();
			attempt.startPolling();
			return;
		}

		try {
			var vezmo = new Vezmo( params.apiBase ? { apiBase: params.apiBase } : undefined );
			vezmo.mount( container, { clientToken: params.clientToken } );

			var sdkFrame = container.querySelector( 'iframe' );
			if ( sdkFrame ) {
				sdkFrame.addEventListener( 'load', attempt.markReady );
			}

			// Posts vezmo:secure-payment:submit into the frame, which runs the
			// same charge path as the hosted page's own button.
			attempt.onPay( function () {
				vezmo.pay();
			} );

			// `requires_action` cannot arrive through the SDK: vezmo.js relays
			// eight event names (SUFFIX_BY_NAME) and that is not one of them, so
			// vezmo.on( 'requires_action' ) never fires. Listen for it directly —
			// source-checked against the SDK's own frame, then origin-checked —
			// or the bounded attempt would call a live bank verification a
			// failure at sixty seconds.
			if ( sdkFrame ) {
				window.addEventListener( 'message', function ( e ) {
					if ( ! sdkFrame.contentWindow || e.source !== sdkFrame.contentWindow ) {
						return;
					}
					if ( ! e.origin || 'null' === e.origin ) {
						return;
					}
					if ( e.origin !== params.secureOrigin && e.origin !== params.checkoutOrigin ) {
						return;
					}
					if ( e.data && 'vezmo:secure-payment:requires_action' === e.data.type ) {
						attempt.awaitAction( true );
					}
				} );
			}

			vezmo.on( 'ready', attempt.markReady );
			vezmo.on( 'success', function () {
				attempt.finalize();
			} );
			vezmo.on( 'pending', function () {
				attempt.finalize( params.i18n.pending );
			} );
			vezmo.on( 'already-paid', function () {
				attempt.finalize();
			} );
			// Progress, not an outcome — but it is the form saying it took the
			// card, which is what the attempt bound is measured against.
			vezmo.on( 'processing', attempt.acknowledge );
			vezmo.on( 'error', function ( evt ) {
				attempt.markReady();
				attempt.failAttempt( 'declined', ( evt && evt.message ) || params.i18n.failed );
			} );
			vezmo.on( 'cancel', function () {
				attempt.failAttempt( 'cancelled', params.i18n.cancelled || params.i18n.failed );
			} );
			vezmo.on( 'expired', function () {
				// A token that has expired cannot be charged, so this one DOES
				// need a replacement payment: reload, which mints a fresh one.
				attempt.failAttempt( 'expired', params.i18n.expired );
				window.setTimeout( function () {
					// Unless the report above found the payment settled after all
					// and is already forwarding the shopper.
					if ( ! attempt.isSettled() ) {
						window.location.reload();
					}
				}, 2500 );
			} );
		} catch ( e ) {
			mountFallbackIframe();
		}

		// The SDK mounts its own frame, so a ready event cannot have been missed
		// before this script ran — but never leave the spinner (and the Pay button
		// with it) waiting on an event that may never arrive.
		window.setTimeout( attempt.markReady, 2500 );

		// Fallback for stores whose origin is not (yet) in VezmoPay's trusted origins:
		// events never arrive, but the poll still completes the order.
		attempt.startPolling();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
