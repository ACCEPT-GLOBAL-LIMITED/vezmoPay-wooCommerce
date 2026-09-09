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
	var POLL_INTERVAL_MS = 3500;
	// A charge that has not resolved by here is not going to resolve on its own
	// message: show the shopper a way out rather than a spinner.
	var STALL_MS = 20000;
	// The embedded form posts `processing` the moment it accepts a charge, so the
	// ABSENCE of it means our submit was dropped — and it is dropped for a real
	// reason: the form's handler begins `if (!stripe || !elements) return;` and
	// posts NOTHING, while the page announces `ready` on mount without waiting
	// for Stripe.js to load inside the frame. That window is brief on a fast
	// connection and indefinite when js.stripe.com is slow or blocked. Re-send
	// until the form answers.
	var SUBMIT_RETRY_MS = 2500;
	var SUBMIT_RETRY_MAX = 8;
	// Long enough for a slow 3-D Secure challenge, short enough that nobody
	// stares at a spinner: after this the pay page takes over.
	var POLL_LIMIT_MS = 75 * 1000;

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

	/** Trace the payment when the gateway's Debug setting is on. */
	function log() {
		if ( ! params.debug || ! window.console ) {
			return;
		}
		var args = Array.prototype.slice.call( arguments );
		args.unshift( '[VezmoPay]' );
		window.console.log.apply( window.console, args );
	}

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

	/**
	 * Decode a charge marker. The payload is base64url JSON carrying the order id
	 * and key plus the store's own success and pay-page URLs; the older
	 * 'id:key' form is still accepted so a checkout page cached from a previous
	 * version keeps working.
	 */
	function parseMarker( value ) {
		if ( ! value || value.indexOf( CHARGE_PREFIX ) === -1 ) {
			return null;
		}
		var raw = value.slice( value.indexOf( CHARGE_PREFIX ) + CHARGE_PREFIX.length );
		try {
			var b64 = raw.replace( /-/g, '+' ).replace( /_/g, '/' );
			while ( b64.length % 4 ) {
				b64 += '=';
			}
			var data = JSON.parse( window.atob( b64 ) );
			if ( data && data.id && data.key ) {
				return {
					orderId: String( data.id ),
					orderKey: data.key,
					returnUrl: data.ret || '',
					payUrl: data.pay || '',
				};
			}
		} catch ( e ) {
			// Not the encoded form — fall through to the legacy shape.
		}
		var parts = raw.split( ':' );
		return parts.length >= 2
			? { orderId: parts[ 0 ], orderKey: parts[ 1 ], returnUrl: '', payUrl: '' }
			: null;
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
					// The SDK creates this frame and nothing labels it, so in the
					// DEFAULT mode a screen reader announced an unlabelled frame
					// containing the whole card form (WCAG 4.1.2). Only the
					// fallback frame was ever titled.
					var mounted = host.querySelector( 'iframe' );
					if ( mounted && ! mounted.getAttribute( 'title' ) ) {
						mounted.setAttribute( 'title', params.i18n.frameTitle );
					}
					[ 'ready', 'processing', 'success', 'error', 'pending', 'already-paid', 'expired', 'cancel' ].forEach(
						function ( name ) {
							vezmo.on( name, function ( evt ) {
								log( 'sdk event:', name, ( evt && evt.message ) || '' );
							} );
						}
					);
					vezmo.on( 'ready', markReady );
					vezmo.on( 'error', function ( evt ) {
						setMessage( ( evt && evt.message ) || params.i18n.failed, 'error' );
						failCharge( ( evt && evt.message ) || params.i18n.failed );
					} );
					vezmo.on( 'success', completeCharge );
					vezmo.on( 'pending', completeCharge );
					// Terminal states that are NOT success or error. Missing these
					// was one way the spinner used to run forever.
					vezmo.on( 'already-paid', completeCharge );
					vezmo.on( 'cancel', function () {
						failCharge( params.i18n.cancelled );
					} );
					vezmo.on( 'expired', function () {
						failCharge( params.i18n.expired );
					} );
					// Progress, not an outcome: keep waiting, say so.
					vezmo.on( 'processing', noteProcessing );
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
		// A starting height only — the checkout reports its real content height and
		// the resize handler applies it. Lowered from 720 because on this path the
		// hosted page appears not to emit `resize` when it is mounted WITHOUT the
		// SDK, which left roughly 300px of dead space under the form.
		// TODO(platform): confirm whether the hosted checkout emits
		// vezmo:secure-payment:resize when embedded directly (no vezmo.js). If it
		// does not, either have it emit, or answer the request-resize ping below.
		frame.height = '620';
		// Mirrors vezmo.js: `payment *` survives the checkout redirect for
		// Apple/Google Pay, and storage-access lets captcha / 3-D Secure run in
		// a third-party frame.
		frame.setAttribute( 'allow', 'payment *; storage-access *' );
		frame.setAttribute( 'title', params.i18n.frameTitle );
		frame.addEventListener( 'load', markReady );
		frame.addEventListener( 'load', function () {
			// Harmless if unimplemented (unknown message types are ignored), and it
			// gives the platform a place to answer with a height.
			var target = frameOrigin();
			if ( target && frame.contentWindow ) {
				frame.contentWindow.postMessage( { type: 'vezmo:secure-payment:request-resize' }, target );
			}
		} );
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
		// Fail CLOSED. `origin &&` meant an unparsable session URL (the server used
		// to allow an empty one) disabled the check entirely, and any frame or
		// script on the checkout page could then forge a payment result: a fake
		// `error` mid-charge invites a retry and a double charge, a fake `success`
		// lands an unpaid order on the success page. The source check is the other
		// half — an origin can be shared by frames we did not create, and no page
		// can forge e.source for a window it does not own.
		var origin = frameOrigin();
		if ( ! origin || e.origin !== origin || ! frame || e.source !== frame.contentWindow ) {
			return;
		}
		var data = e.data || {};
		if ( data.type && 0 === String( data.type ).indexOf( 'vezmo:secure-payment:' ) && 'vezmo:secure-payment:resize' !== data.type ) {
			log( 'frame event:', data.type, data.message || '' );
		}
		if ( 'vezmo:secure-payment:resize' === data.type ) {
			var h = Number( data.height );
			if ( h > 200 && h < 4000 ) {
				frame.style.height = h + 'px';
			}
		} else if ( 'vezmo:secure-payment:ready' === data.type ) {
			markReady();
		} else if (
			'vezmo:secure-payment:success' === data.type ||
			'vezmo:secure-payment:pending' === data.type ||
			'vezmo:secure-payment:already-paid' === data.type
		) {
			completeCharge( data );
		} else if ( 'vezmo:secure-payment:error' === data.type ) {
			setMessage( data.message || params.i18n.failed, 'error' );
			failCharge( data.message || params.i18n.failed );
		} else if ( 'vezmo:secure-payment:cancel' === data.type ) {
			failCharge( params.i18n.cancelled );
		} else if ( 'vezmo:secure-payment:expired' === data.type ) {
			failCharge( params.i18n.expired );
		} else if ( 'vezmo:secure-payment:processing' === data.type ) {
			noteProcessing();
		} else if ( 'vezmo:secure-payment:requires_action' === data.type ) {
			// Extra verification (3-D Secure) is happening inside the frame.
			setMessage( params.i18n.verifying, 'info' );
			if ( charging ) {
				charging.awaitingAction = true;
				if ( charging.stallTimer ) {
					window.clearTimeout( charging.stallTimer );
					charging.stallTimer = null;
				}
			}
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
				// No URL means no frame to mount and nothing to pin messages to.
				if ( ! res || ! res.success || ! res.data || ! res.data.clientToken || ! res.data.url ) {
					setMessage( ( res && res.data && res.data.message ) || params.i18n.unavailable, 'error' );
					return;
				}
				session = res.data;
				log( 'session ready', { amount: session.amount, currency: session.currency, hasSdk: !! session.sdkUrl, url: session.url } );
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

	/**
	 * Ask the STORE what happened, on a timer, for as long as a charge is in
	 * flight.
	 *
	 * A charge must never depend only on the frame telling us it worked. The
	 * embedded page can only postMessage to a parent it can identify as a
	 * trusted origin — which it derives from document.referrer, so a store
	 * sending no referrer gets no events at all — and it has terminal states
	 * (already paid, expired, cancelled) that arrive as their own event names or
	 * not at all. Without this poll, a single missed message left the shopper on
	 * "Processing your payment…" forever. The store asks the VezmoPay API, so it
	 * knows the truth either way.
	 */
	function startPolling() {
		if ( ! charging || charging.pollTimer ) {
			return;
		}
		// Count FOREGROUND time only. Date.now() runs while the tab is hidden but
		// the payment inside the frame does not stop, so a shopper who switched to
		// Messages to copy a 3-D Secure code came back to a blown limit and an
		// iframe — holding their live challenge — navigated away.
		charging.elapsed = 0;
		charging.lastTick = Date.now();
		charging.visibility = function () {
			charging.lastTick = Date.now();
		};
		document.addEventListener( 'visibilitychange', charging.visibility );

		charging.stallTimer = window.setTimeout( showStall, STALL_MS );
		charging.pollTimer = window.setInterval( function () {
			if ( ! charging ) {
				return;
			}
			if ( ! document.hidden ) {
				charging.elapsed += Date.now() - charging.lastTick;
			}
			charging.lastTick = Date.now();

			// An extra verification step owns the shopper's attention until it
			// reports back; timing it out destroys the challenge.
			if ( charging.awaitingAction ) {
				return;
			}
			if ( charging.elapsed > POLL_LIMIT_MS ) {
				// Long enough. The pay page keeps polling, shows the form again
				// and can finish the payment — better than an endless spinner.
				handOffToPayPage();
				return;
			}
			var body = new URLSearchParams();
			body.append( 'nonce', params.nonce );
			body.append( 'order_id', charging.orderId );
			body.append( 'order_key', charging.orderKey );
			window
				.fetch( params.statusUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} )
				.then( function ( res ) {
					// A rejected nonce answers 403 with `-1`, which is not JSON.
					// Treating that as a transient error meant polling forever.
					if ( 401 === res.status || 403 === res.status ) {
						log( 'status poll rejected our nonce — stopping and handing off' );
						setMessage( params.i18n.expired, 'error' );
						handOffToPayPage();
						throw new Error( 'auth' );
					}
					return res.json();
				} )
				.then( function ( res ) {
					if ( ! charging || ! res || ! res.success || ! res.data ) {
						log( 'status poll returned no usable answer', res );
						return;
					}
					log( 'status poll:', res.data.status, res.data.redirect ? '(settled)' : '(still waiting)' );
					if ( res.data.redirect ) {
						finish( res.data.redirect );
					} else if ( 'FAILED' === res.data.status ) {
						failCharge( params.i18n.failed );
					}
					// Anything else: still settling — keep polling.
				} )
				.catch( function () {
					// Transient network error — keep polling.
				} );
		}, POLL_INTERVAL_MS );
	}

	function stopPolling() {
		if ( ! charging ) {
			return;
		}
		if ( charging.pollTimer ) {
			window.clearInterval( charging.pollTimer );
			charging.pollTimer = null;
		}
		if ( charging.stallTimer ) {
			window.clearTimeout( charging.stallTimer );
			charging.stallTimer = null;
		}
		if ( charging.confirmTimer ) {
			window.clearTimeout( charging.confirmTimer );
			charging.confirmTimer = null;
		}
		if ( charging.submitTimer ) {
			window.clearTimeout( charging.submitTimer );
			charging.submitTimer = null;
		}
		if ( charging.visibility ) {
			document.removeEventListener( 'visibilitychange', charging.visibility );
			charging.visibility = null;
		}
	}

	/**
	 * The charge is taking too long. Two things matter here: say so, and give the
	 * shopper a route that cannot fail the same way — the VezmoPay page itself,
	 * top-level, with no frame and no cross-window messaging in the path.
	 */
	function showStall() {
		var scope = root();
		if ( ! scope || scope.querySelector( '.vezmopay-inline-escape' ) || ! session ) {
			return;
		}
		log( 'charge has not settled in', STALL_MS, 'ms — offering the hand-off link' );
		// The classic checkout has form.checkout .block()ed for the duration of the
		// charge, and that overlay swallows clicks — including this link, in exactly
		// the stalled case it exists for. Lift the overlay; the Pay button stays
		// disabled, so the only newly clickable thing is the way out.
		if ( $ && $.fn && $.fn.unblock ) {
			var stalledForm = $( 'form.checkout' );
			if ( stalledForm.length ) {
				stalledForm.removeClass( 'processing' ).unblock();
				refreshPayButton();
			}
		}
		setMessage( params.i18n.slow, 'info' );
		var p = document.createElement( 'p' );
		p.className = 'vezmopay-inline-escape';
		var a = document.createElement( 'a' );
		a.href = session.url;
		a.textContent = params.i18n.continueOnVezmo;
		p.appendChild( a );
		scope.appendChild( p );
	}

	function payPageUrl() {
		if ( charging && charging.payUrl ) {
			return charging.payUrl;
		}
		return window.location.origin + '/checkout/order-pay/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey );
	}

	function returnUrl() {
		if ( charging && charging.returnUrl ) {
			return charging.returnUrl;
		}
		return window.location.origin + '/checkout/order-received/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey );
	}

	/**
	 * Nothing settled in time. Where to send the shopper depends on what we saw:
	 * after a success event the payment HAPPENED, so the success page is right —
	 * it verifies against the API as it loads and will show the order as paid.
	 * With no success event, the pay page is right: it re-renders the form so the
	 * payment can still be made.
	 */
	function handOffToPayPage() {
		if ( ! charging ) {
			return;
		}
		finish( charging.sawSuccess ? returnUrl() : payPageUrl() );
	}

	/** Settle the charge: resolve the caller's promise, or navigate ourselves. */
	function finish( url ) {
		if ( ! charging ) {
			return;
		}
		charging.awaitingAction = false;
		stopPolling();
		var d = charging.deferred;
		charging = null;
		refreshPayButton();
		if ( d ) {
			d.resolve( url );
			return;
		}
		window.location.href = url;
	}

	function startCharge( orderId, orderKey, deferred, marker ) {
		if ( charging ) {
			// A second attempt while one is running. Returning silently left the
			// Blocks checkout awaiting a promise nobody would ever settle, with no
			// notice and no way out but a page reload.
			log( 'ignoring a second charge attempt for order', orderId, '- one is already running' );
			if ( deferred ) {
				deferred.reject( params.i18n.processing );
			}
			return;
		}
		charging = {
			orderId: orderId,
			orderKey: orderKey,
			deferred: deferred || null,
			pollTimer: null,
			stallTimer: null,
			confirmTimer: null,
			sawSuccess: false,
			sawProcessing: false,
			submitTries: 0,
			submitTimer: null,
			returnUrl: ( marker && marker.returnUrl ) || '',
			payUrl: ( marker && marker.payUrl ) || '',
		};
		log( 'charge starting for order', orderId, vezmo ? 'via SDK pay()' : 'via frame submit message' );
		setMessage( params.i18n.processing, 'info' );
		refreshPayButton();
		// Start watching the store immediately: the charge is already running
		// inside the frame, and this is what settles it if no event reaches us.
		startPolling();

		sendSubmit();
		scheduleSubmitRetry();
	}

	/** Ask the mounted form to charge. Repeatable — see scheduleSubmitRetry(). */
	function sendSubmit() {
		if ( vezmo ) {
			vezmo.pay();
			return;
		}
		if ( frame && frame.contentWindow ) {
			var target = frameOrigin();
			if ( ! target ) {
				// Without a known origin we will not broadcast into the frame.
				failCharge( params.i18n.unavailable );
				return;
			}
			frame.contentWindow.postMessage( { type: 'vezmo:secure-payment:submit' }, target );
			return;
		}
		// Nothing mounted to charge — the order exists, so send the shopper to
		// the pay page, which renders the form again and can complete it.
		finish( payPageUrl() );
	}

	/**
	 * Re-send the submit until the form acknowledges it with `processing`.
	 *
	 * Safe to repeat: the form ignores a submit while a charge is running
	 * (`if (paymentLoading) return;`) and the PaymentIntent behind it is minted
	 * idempotently, so at most one charge results. Stops at the first
	 * acknowledgement, at any terminal event, or after SUBMIT_RETRY_MAX tries —
	 * at which point the shopper is told the form did not load, rather than
	 * being left with a spinner.
	 */
	function scheduleSubmitRetry() {
		if ( ! charging || charging.sawProcessing ) {
			return;
		}
		if ( charging.submitTries >= SUBMIT_RETRY_MAX ) {
			log( 'form never acknowledged the submit after', charging.submitTries, 'tries' );
			setMessage( params.i18n.notReady, 'error' );
			showStall();
			return;
		}
		charging.submitTimer = window.setTimeout( function () {
			if ( ! charging || charging.sawProcessing ) {
				return;
			}
			charging.submitTries++;
			log( 'no `processing` from the form — re-sending the submit, try', charging.submitTries );
			sendSubmit();
			scheduleSubmitRetry();
		}, SUBMIT_RETRY_MS );
	}

	/** The form accepted the charge; stop re-sending. */
	function noteProcessing() {
		if ( ! charging || charging.sawProcessing ) {
			return;
		}
		charging.sawProcessing = true;
		if ( charging.submitTimer ) {
			window.clearTimeout( charging.submitTimer );
			charging.submitTimer = null;
		}
		log( 'form acknowledged the charge' );
		setMessage( params.i18n.processing, 'info' );
	}

	/** Ask the STORE whether the order is paid; never trust this page's word. */
	function completeCharge() {
		if ( ! charging ) {
			return;
		}
		charging.awaitingAction = false;
		charging.sawSuccess = true;
		noteProcessing();
		log( 'form reported success — confirming with the store' );

		// The payment succeeded. Confirming server-side is the right thing to do,
		// but it must not be the ONLY way to the success page: if this call is
		// slow, 502s, or has its nonce invalidated (checkout can create an
		// account mid-flow, which retires the nonce), the shopper must still land
		// on the success page — which reconciles against the API as it loads.
		if ( ! charging.confirmTimer ) {
			charging.confirmTimer = window.setTimeout( function () {
				if ( charging ) {
					log( 'confirm did not answer in time — going to the success page anyway' );
					finish( returnUrl() );
				}
			}, 6000 );
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
					finish( res.data.redirect );
					return;
				}
				// Charged, but the API does not show it settled yet. Do NOT give
				// up: the poll above keeps asking, and the webhook and cron are
				// behind it. Only the poll's own time limit hands over.
			} )
			.catch( function () {
				// Same — the poll is the safety net.
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
		var marker = parseMarker( window.location.hash );
		if ( ! marker ) {
			return;
		}
		// Clear it so a reload cannot re-run a charge.
		window.history.replaceState( null, '', window.location.pathname + window.location.search );
		startCharge( marker.orderId, marker.orderKey, null, marker );
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
			// Timers must not outlive the form they were watching, and a charge
			// waiting on this frame can no longer be observed here — so settle it
			// toward a page that CAN finish the payment rather than leaving the
			// caller awaiting a promise and three timers running against a form
			// that no longer exists.
			if ( charging ) {
				log( 'form unmounted while a charge was running — handing the shopper off' );
				handOffToPayPage();
			}
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
		charge: function ( orderId, orderKey, marker ) {
			return new Promise( function ( resolve, reject ) {
				startCharge( orderId, orderKey, { resolve: resolve, reject: reject }, marker );
			} );
		},
		/** Decode the '#vezmopay-charge:<payload>' marker. */
		parseMarker: parseMarker,
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
			if ( ! selected() ) {
				return;
			}
			// WooCommerce replaces the payment fragment on every country, state,
			// postcode, shipping-method and coupon change. Remounting there threw
			// away everything the shopper had typed into the card form for no
			// reason — the clientToken had not changed. Remount only when the
			// container really lost its frame.
			var liveHost = container();
			if ( liveHost && liveHost.querySelector( 'iframe' ) ) {
				return;
			}
			mountedFor = null;
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
