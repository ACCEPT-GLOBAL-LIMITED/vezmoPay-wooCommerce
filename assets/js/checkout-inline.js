/**
 * VezmoPay on the WooCommerce checkout page.
 *
 * The Stripe-plugin shape: the payment form lives in the payment box, the
 * shopper presses WooCommerce's own "Place order" button, and there is no extra
 * pay page. Because VezmoPay's embed owns the charge (the plugin never sees a
 * card token), the sequence is:
 *
 *   1. Selecting VezmoPay asks the store for a cart-level payment session and
 *      mounts the VezmoPay form — via vezmo.js in inline mode, as a plain frame
 *      in iframe mode.
 *   2. Place order submits the checkout normally, so WOOCOMMERCE CREATES THE
 *      ORDER FIRST. process_payment() binds the session to it and answers with
 *      '#vezmopay-charge:<id>:<key>'.
 *   3. That hash arrives here, we tell the mounted form to charge, and on
 *      success ask the store to confirm the order against the VezmoPay API —
 *      the browser never asserts that a payment succeeded.
 *
 * With this script absent or blocked, process_payment() finds no session and
 * falls back to the pay-page flow, which needs no JavaScript at all.
 *
 * @package VezmoPay
 */

/* global vezmopay_inline_params, jQuery, Vezmo */
( function ( $ ) {
	'use strict';

	if ( typeof vezmopay_inline_params === 'undefined' ) {
		return;
	}

	var params = vezmopay_inline_params;
	var CHARGE_PREFIX = '#vezmopay-charge:';

	var session = null;      // { clientToken, url, sdkUrl, amount, … }
	var vezmo = null;        // vezmo.js instance, inline mode only
	var frame = null;        // the frame we drive in iframe mode
	var mountedFor = null;   // clientToken currently mounted
	var loading = false;
	var ready = false;
	var charging = null;     // { orderId, orderKey } while a charge is running

	// Where to mount. The classic checkout renders the markup server-side in the
	// payment box; the Blocks checkout hands us its own element instead.
	var hostEl = null;

	function root() {
		return hostEl ? hostEl.closest( '.vezmopay-inline' ) || hostEl : document.getElementById( 'vezmopay-inline' );
	}

	function container() {
		return hostEl || document.getElementById( 'vezmopay-inline-container' );
	}

	function setMessage( text, kind ) {
		var scope = root();
		var el = ( scope && scope.querySelector( '.vezmopay-inline-message' ) ) || document.getElementById( 'vezmopay-inline-message' );
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.className = 'vezmopay-inline-message' + ( text ? ' is-' + ( kind || 'info' ) : '' );
	}

	function markReady() {
		ready = true;
		var el = root();
		if ( el ) {
			el.classList.add( 'is-ready' );
		}
		bindPayButton();
		refreshPayButton();
	}

	/**
	 * The Pay button under the embedded form.
	 *
	 * WooCommerce's own "Place order" button already does this — the Stripe
	 * plugin ships nothing else — but the embedded VezmoPay form hides its own
	 * submit button, which leaves the payment area looking unfinished. So this
	 * button exists for reassurance and simply places the order: same action,
	 * next to the fields the shopper just filled in.
	 */
	function payButton() {
		var scope = root();
		return scope ? scope.querySelector( '.vezmopay-inline-pay' ) : null;
	}

	function formatAmount() {
		if ( ! session ) {
			return '';
		}
		try {
			return new Intl.NumberFormat( undefined, {
				style: 'currency',
				currency: session.currency || 'USD',
			} ).format( Number( session.amount ) );
		} catch ( e ) {
			return String( session.amount );
		}
	}

	function refreshPayButton() {
		var btn = payButton();
		if ( ! btn ) {
			return;
		}
		var label = btn.querySelector( '.vezmopay-inline-pay-label' );
		if ( label ) {
			label.textContent = charging
				? params.i18n.processing
				: params.i18n.pay.replace( '%s', formatAmount() );
		}
		btn.disabled = !! charging || ! ready;
		btn.hidden = ! session;
	}

	/** Place the order — whichever checkout we are on. */
	function submitCheckout() {
		var blocks = document.querySelector( '.wc-block-components-checkout-place-order-button' );
		if ( blocks ) {
			blocks.click();
			return;
		}
		var classic = document.querySelector( 'form.checkout #place_order' );
		if ( classic ) {
			classic.click();
		}
	}

	function bindPayButton() {
		var btn = payButton();
		if ( ! btn || btn.dataset.vezmopayBound ) {
			return;
		}
		btn.dataset.vezmopayBound = '1';
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( charging || ! ready ) {
				return;
			}
			setMessage( '' );
			submitCheckout();
		} );
		refreshPayButton();
	}

	function selected() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		return !! input && 'vezmopay' === input.value;
	}

	/** Ask the store for a session for the CURRENT cart total. */
	function fetchSession() {
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		return window
			.fetch( params.sessionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} );
	}

	function loadSdk( src ) {
		return new Promise( function ( resolve, reject ) {
			if ( typeof Vezmo !== 'undefined' ) {
				resolve();
				return;
			}
			var tag = document.createElement( 'script' );
			tag.src = src;
			tag.onload = resolve;
			tag.onerror = reject;
			document.head.appendChild( tag );
		} );
	}

	/** Mount the form for the session we hold, if it is not already mounted. */
	function mount() {
		var host = container();
		if ( ! host || ! session || mountedFor === session.clientToken ) {
			return Promise.resolve();
		}

		host.innerHTML = '';
		vezmo = null;
		frame = null;
		ready = false;
		mountedFor = session.clientToken;

		// Inline mode: let VezmoPay's SDK own the frame (auto-resize, events,
		// captcha/3-D Secure popup fallback). Falls through to a plain frame if
		// the SDK cannot be loaded, which is what iframe mode uses outright.
		if ( 'element' === params.mode && session.sdkUrl ) {
			return loadSdk( session.sdkUrl )
				.then( function () {
					vezmo = new Vezmo( params.apiBase ? { apiBase: params.apiBase } : undefined );
					vezmo.mount( host, { clientToken: session.clientToken, theme: params.theme } );
					vezmo.on( 'ready', markReady );
					vezmo.on( 'error', function ( evt ) {
						setMessage( ( evt && evt.message ) || params.i18n.failed, 'error' );
						failCharge( ( evt && evt.message ) || params.i18n.failed );
					} );
					vezmo.on( 'success', function ( evt ) {
						completeCharge( evt );
					} );
					vezmo.on( 'pending', function ( evt ) {
						completeCharge( evt );
					} );
					window.setTimeout( markReady, 2500 );
				} )
				.catch( function () {
					mountFrame( host );
				} );
		}

		mountFrame( host );
		bindPayButton();
		return Promise.resolve();
	}

	function mountFrame( host ) {
		frame = document.createElement( 'iframe' );
		frame.id = 'vezmopay-inline-frame';
		frame.src = session.url;
		frame.width = '100%';
		frame.height = '720';
		// Mirrors vezmo.js: `payment *` survives the checkout redirect for
		// Apple/Google Pay, and storage-access lets captcha / 3-D Secure run in
		// a third-party frame.
		frame.setAttribute( 'allow', 'payment *; storage-access *' );
		frame.setAttribute( 'title', 'VezmoPay secure payment' );
		frame.addEventListener( 'load', markReady );
		host.appendChild( frame );
		window.setTimeout( markReady, 2500 );
	}

	function frameOrigin() {
		try {
			return new URL( session.url ).origin;
		} catch ( e ) {
			return null;
		}
	}

	/** Messages from a plain frame (iframe mode, or the inline fallback). */
	window.addEventListener( 'message', function ( e ) {
		if ( ! session || ! frame ) {
			return;
		}
		var origin = frameOrigin();
		if ( origin && e.origin !== origin ) {
			return;
		}
		var data = e.data || {};
		if ( 'vezmo:secure-payment:resize' === data.type ) {
			var h = Number( data.height );
			if ( h > 200 && h < 4000 ) {
				frame.style.height = h + 'px';
			}
		} else if ( 'vezmo:secure-payment:ready' === data.type ) {
			markReady();
		} else if ( 'vezmo:secure-payment:success' === data.type || 'vezmo:secure-payment:pending' === data.type ) {
			completeCharge( data );
		} else if ( 'vezmo:secure-payment:error' === data.type ) {
			setMessage( data.message || params.i18n.failed, 'error' );
			failCharge( data.message || params.i18n.failed );
		}
	} );

	function ensureSession() {
		if ( session || loading ) {
			return;
		}
		loading = true;
		fetchSession()
			.then( function ( res ) {
				loading = false;
				if ( ! res || ! res.success || ! res.data || ! res.data.clientToken ) {
					setMessage( ( res && res.data && res.data.message ) || params.i18n.unavailable, 'error' );
					return;
				}
				session = res.data;
				mount();
			} )
			.catch( function () {
				loading = false;
				setMessage( params.i18n.unavailable, 'error' );
			} );
	}

	/**
	 * The cart total changed (shipping, coupon, address). The mounted form was
	 * created for the old amount and the embed charges what it was created with,
	 * so drop it and build a new session rather than take the wrong money.
	 */
	function resetSession() {
		session = null;
		mountedFor = null;
		vezmo = null;
		frame = null;
		ready = false;
		var host = container();
		if ( host ) {
			host.innerHTML = '';
		}
		var el = root();
		if ( el ) {
			el.classList.remove( 'is-ready' );
		}
		setMessage( '' );
		if ( selected() ) {
			ensureSession();
		}
	}

	/* --------------------------------------------------------------------
	 * Charge, once WooCommerce has created the order.
	 * ------------------------------------------------------------------ */

	function startCharge( orderId, orderKey, deferred ) {
		if ( charging ) {
			return;
		}
		charging = { orderId: orderId, orderKey: orderKey, deferred: deferred || null };
		setMessage( params.i18n.processing, 'info' );
		refreshPayButton();

		if ( vezmo ) {
			vezmo.pay();
			return;
		}
		if ( frame && frame.contentWindow ) {
			frame.contentWindow.postMessage( { type: 'vezmo:secure-payment:submit' }, frameOrigin() || '*' );
			return;
		}
		// Nothing mounted to charge — the order exists, so send the shopper to
		// the pay page, which renders the form again and can complete it.
		window.location.href = window.location.origin + '/checkout/order-pay/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey );
	}

	/** Ask the STORE whether the order is paid; never trust this page's word. */
	function completeCharge() {
		if ( ! charging ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		body.append( 'order_id', charging.orderId );
		body.append( 'order_key', charging.orderKey );

		window
			.fetch( params.confirmUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( res && res.success && res.data && res.data.redirect ) {
					var to = res.data.redirect;
					var d = charging && charging.deferred;
					charging = null;
					if ( d ) {
						d.resolve( to );
						return;
					}
					window.location.href = to;
					return;
				}
				// Paid but not yet visible to the API: the pay page keeps polling
				// and the webhook/cron settle it, so hand over there.
				window.location.href = window.location.origin + '/checkout/order-pay/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey );
			} )
			.catch( function () {
				window.location.href = window.location.origin + '/checkout/order-pay/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey );
			} );
	}

	/** The charge failed: unblock the form so the shopper can retry in place. */
	function failCharge( message ) {
		if ( ! charging ) {
			return;
		}
		var d = charging.deferred;
		charging = null;
		refreshPayButton();
		if ( d ) {
			// The caller (Blocks) surfaces the failure in its own notice area —
			// showing it here too would say the same thing twice.
			d.reject( message || params.i18n.failed );
			return;
		}
		setMessage( message || params.i18n.failed, 'error' );
		if ( $ && $( 'form.checkout' ).length ) {
			$( 'form.checkout' ).removeClass( 'processing' ).unblock();
			$( document.body ).trigger( 'checkout_error' );
		}
	}

	function handleHash() {
		var hash = window.location.hash;
		if ( 0 !== hash.indexOf( CHARGE_PREFIX ) ) {
			return;
		}
		var parts = hash.slice( CHARGE_PREFIX.length ).split( ':' );
		if ( parts.length < 2 ) {
			return;
		}
		// Clear it so a reload cannot re-run a charge.
		window.history.replaceState( null, '', window.location.pathname + window.location.search );
		startCharge( parts[ 0 ], parts[ 1 ] );
	}

	/* --------------------------------------------------------------------
	 * Wiring.
	 * ------------------------------------------------------------------ */

	/**
	 * Shared API. The Blocks integration drives the same session, frame and
	 * charge as the classic checkout — one payment flow, two checkouts.
	 */
	window.VezmoPayInline = {
		/** Mount into an element supplied by the caller (Blocks). */
		mountInto: function ( el ) {
			hostEl = el;
			mountedFor = null;
			if ( session ) {
				return mount();
			}
			ensureSession();
			return Promise.resolve();
		},
		unmount: function () {
			hostEl = null;
			mountedFor = null;
			vezmo = null;
			frame = null;
			ready = false;
		},
		isReady: function () {
			return ready && !! session;
		},
		hasSession: function () {
			return !! session;
		},
		/** Charge the mounted form; resolves with the store's redirect URL. */
		charge: function ( orderId, orderKey ) {
			return new Promise( function ( resolve, reject ) {
				startCharge( orderId, orderKey, { resolve: resolve, reject: reject } );
			} );
		},
		/** Pull a '#vezmopay-charge:id:key' marker out of a redirect string. */
		parseMarker: function ( value ) {
			if ( ! value || value.indexOf( CHARGE_PREFIX ) === -1 ) {
				return null;
			}
			var parts = value.slice( value.indexOf( CHARGE_PREFIX ) + CHARGE_PREFIX.length ).split( ':' );
			return parts.length >= 2 ? { orderId: parts[ 0 ], orderKey: parts[ 1 ] } : null;
		},
		messages: params.i18n,
	};

	$( function () {
		// Everything below is the CLASSIC checkout's wiring; the Blocks checkout
		// has no such form and drives the API above from its own React tree.
		if ( ! $( 'form.checkout' ).length ) {
			return;
		}

		if ( selected() ) {
			ensureSession();
		}

		// WooCommerce re-renders the payment box on every checkout update, which
		// throws away our frame — remount, and rebuild the session if the total
		// moved underneath us.
		$( document.body ).on( 'updated_checkout', function () {
			mountedFor = null;
			if ( ! selected() ) {
				return;
			}
			if ( session ) {
				mount();
			} else {
				ensureSession();
			}
		} );

		$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
			if ( selected() ) {
				ensureSession();
			}
		} );

		// A changed total invalidates the session (see resetSession).
		$( document.body ).on( 'updated_cart_totals applied_coupon removed_coupon', resetSession );

		// Do not let the order be placed before the form can be charged.
		$( 'form.checkout' ).on( 'checkout_place_order_vezmopay', function () {
			if ( 'hosted' === params.mode ) {
				return true;
			}
			if ( ! session ) {
				setMessage( params.i18n.unavailable, 'error' );
				return false;
			}
			if ( ! ready ) {
				setMessage( params.i18n.incomplete, 'error' );
				return false;
			}
			return true;
		} );

		window.addEventListener( 'hashchange', handleHash );
		handleHash();
	} );
} )( jQuery );
