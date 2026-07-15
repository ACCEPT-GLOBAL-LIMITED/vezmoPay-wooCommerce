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
	var finalized = false;
	var pollTimer = null;

	function setMessage( text ) {
		if ( messageEl ) {
			messageEl.textContent = text || '';
		}
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
				return res.json();
			} );
	}

	function finalize( statusMessage ) {
		if ( finalized ) {
			return;
		}
		finalized = true;
		setMessage( statusMessage || params.i18n.processing );
		post( params.confirmUrl )
			.then( function ( res ) {
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

	function startPolling() {
		if ( pollTimer ) {
			return;
		}
		pollTimer = window.setInterval( function () {
			if ( finalized ) {
				return;
			}
			post( params.statusUrl )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						return;
					}
					if ( res.data.redirect ) {
						finalized = true;
						window.clearInterval( pollTimer );
						window.location = res.data.redirect;
					} else if ( 'FAILED' === res.data.status ) {
						setMessage( params.i18n.failed );
					} else if ( 'MISMATCH' === res.data.status ) {
						// Manual review required — polling will never resolve this.
						finalized = true;
						window.clearInterval( pollTimer );
						setMessage( params.i18n.review );
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
		container.appendChild( frame );
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
				setMessage( ( evt && evt.message ) || params.i18n.failed );
			} );
			vezmo.on( 'expired', function () {
				setMessage( params.i18n.expired );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} );
		} catch ( e ) {
			mountFallbackIframe();
		}

		// Fallback for stores whose origin is not (yet) in VezmoPay's trusted origins:
		// events never arrive, but the poll still completes the order.
		startPolling();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
