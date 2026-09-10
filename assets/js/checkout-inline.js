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

	// The pay page has its own driver (checkout-element.js / checkout-iframe.js,
	// localized as vezmopay_params). This script arrives there too via the Blocks
	// payment-method script handles, and two pollers with two message areas on
	// one page is a bug waiting to happen — even while it sits idle.
	if ( typeof window.vezmopay_params !== 'undefined' ) {
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
	// TODO(platform): remove once a declined payment reports FAILED (or the frame
	// emits `error` reliably). Until then a decline is indistinguishable from a
	// slow capture — VezmoPay leaves the payment INITIATED and says nothing — so
	// an attempt that produces no outcome is called a failed attempt rather than
	// waited on. A real capture resolves in about nine seconds, so a minute is
	// generous; a 3-D Secure challenge is exempt (see awaitingAction).
	var ATTEMPT_LIMIT_MS = 60 * 1000;

	var session = null;      // { clientToken, url, sdkUrl, amount, … }
	var vezmo = null;        // vezmo.js instance, inline mode only
	var frame = null;        // the frame we drive in iframe mode
	var sdkFrame = null;     // the frame vezmo.js created, in element mode
	var mountedFor = null;   // clientToken currently mounted
	var loading = false;
	var ready = false;
	var charging = null;     // { orderId, orderKey } while a charge is running
	// Set when a charge fails: the message to put back on screen once the
	// replacement form has mounted, so the reason survives the re-mount.
	var pendingNotice = '';

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
		sdkFrame = null;
		ready = false;
		pinnedOrigin = null;
		mountedFor = session.clientToken;

		// Inline mode: let VezmoPay's SDK own the frame (auto-resize, events,
		// captcha/3-D Secure popup fallback). Falls through to a plain frame if
		// the SDK cannot be loaded, which is what iframe mode uses outright.
		if ( 'element' === params.mode && session.sdkUrl ) {
			return loadSdk( session.sdkUrl )
				.then( function () {
					// Naming the checkout origin is the SDK's strictest posture: it
					// trusts that origin from the start and pins nothing at runtime.
					var sdkOpts = {};
					if ( params.apiBase ) {
						sdkOpts.apiBase = params.apiBase;
					}
					if ( params.checkoutOrigin ) {
						sdkOpts.checkoutOrigin = params.checkoutOrigin;
					}
					vezmo = new Vezmo( sdkOpts );
					vezmo.mount( host, { clientToken: session.clientToken, theme: params.theme } );
					// The SDK creates this frame and nothing labels it, so in the
					// DEFAULT mode a screen reader announced an unlabelled frame
					// containing the whole card form (WCAG 4.1.2). Only the
					// fallback frame was ever titled.
					var mounted = host.querySelector( 'iframe' );
					if ( mounted && ! mounted.getAttribute( 'title' ) ) {
						mounted.setAttribute( 'title', params.i18n.frameTitle );
					}
					// Kept for the requires_action listener below, which is the
					// only way that event reaches us in this mode.
					sdkFrame = mounted;
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
						failCharge( ( evt && evt.message ) || params.i18n.failed, 'declined' );
					} );
					vezmo.on( 'success', completeCharge );
					vezmo.on( 'pending', completeCharge );
					// Terminal states that are NOT success or error. Missing these
					// was one way the spinner used to run forever.
					vezmo.on( 'already-paid', completeCharge );
					vezmo.on( 'cancel', function () {
						failCharge( params.i18n.cancelled, 'cancelled' );
					} );
					vezmo.on( 'expired', function () {
						failCharge( params.i18n.expired, 'expired' );
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
		// A starting height only: the checkout reports its real content height on
		// its own — it does emit vezmo:secure-payment:resize when embedded
		// directly, with or without the SDK — and the resize handler applies it
		// inline, which beats this. The dead space this used to leave was our
		// own origin check rejecting those messages, not a missing emit.
		frame.height = '620';
		// Mirrors vezmo.js: `payment *` survives the checkout redirect for
		// Apple/Google Pay, and storage-access lets captcha / 3-D Secure run in
		// a third-party frame.
		frame.setAttribute( 'allow', 'payment *; storage-access *' );
		frame.setAttribute( 'title', params.i18n.frameTitle );
		frame.addEventListener( 'load', markReady );
		// No request-resize ping. It fired on `load` at frameOrigin(), which
		// before any message resolves to the CHECKOUT origin while the frame is
		// typically still on the API origin — so the browser dropped it as a
		// targetOrigin mismatch, and it was never answered by anything. The page
		// reports its height unprompted, so nothing is lost by removing it, and
		// broadcasting with '*' to make it land is not worth it.
		host.appendChild( frame );
		window.setTimeout( markReady, 2500 );
	}

	// The frame's src is the API origin, and the API 302-redirects it to the
	// Vezmo-hosted checkout origin — which is why vezmo.js carries the same
	// pinning logic. So the document we exchange messages with is NOT on
	// session.url's origin: checking against that alone dropped every message
	// the frame sent, including the content height, and aimed our submit at an
	// origin the frame had already left.
	//
	// Trusted set, in order of strictness: the checkout origin the merchant has
	// configured, the API origin the frame started from, and one origin PINNED
	// from the first message that already proved it came from our own frame
	// (e.source is unforgeable for a window we created).
	var pinnedOrigin = null;

	function sessionOrigin() {
		try {
			return new URL( session.url ).origin;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Origins allowed to send us a payment result.
	 *
	 * Before anything is pinned this is the bootstrap set: the checkout origin
	 * the merchant configured, and the API origin the frame's src starts on.
	 * Once the first source-verified message pins an origin the set NARROWS to
	 * that one alone — the frame has told us where it actually settled, so
	 * nothing else needs to be trusted for the rest of the page's life, and a
	 * later navigation of our own frame to another origin is refused.
	 */
	function trustedOrigins() {
		if ( pinnedOrigin ) {
			return [ pinnedOrigin ];
		}
		var list = [];
		if ( params.checkoutOrigin ) {
			list.push( params.checkoutOrigin );
		}
		var api = sessionOrigin();
		if ( api ) {
			list.push( api );
		}
		return list;
	}

	/** Where to post INTO the frame: wherever it actually ended up. */
	function frameOrigin() {
		return pinnedOrigin || params.checkoutOrigin || sessionOrigin();
	}

	/** Messages from a plain frame (iframe mode, or the inline fallback). */
	window.addEventListener( 'message', function ( e ) {
		if ( ! session || ! frame ) {
			return;
		}
		// Fail CLOSED, and in this order:
		//
		//   1. SOURCE first. No page can forge e.source for a window it does not
		//      own, so this alone rejects every other frame, opener and tab —
		//      which is what stops anything on the checkout page forging a
		//      payment result (a fake `error` invites a retry and a double
		//      charge; a fake `success` lands an unpaid order on the success
		//      page).
		//   2. ORIGIN against the trusted set, never '*' and never skipped. The
		//      first message that passes the source check pins its origin, so a
		//      later navigation of our own frame to a third origin is refused.
		if ( ! frame || e.source !== frame.contentWindow ) {
			return;
		}
		if ( ! e.origin || 'null' === e.origin ) {
			return;
		}
		if ( trustedOrigins().indexOf( e.origin ) === -1 ) {
			log( 'ignoring a frame message from an untrusted origin:', e.origin );
			return;
		}
		if ( ! pinnedOrigin ) {
			pinnedOrigin = e.origin;
			log( 'pinned the frame origin:', pinnedOrigin );
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
			failCharge( data.message || params.i18n.failed, 'declined' );
		} else if ( 'vezmo:secure-payment:cancel' === data.type ) {
			failCharge( params.i18n.cancelled, 'cancelled' );
		} else if ( 'vezmo:secure-payment:expired' === data.type ) {
			failCharge( params.i18n.expired, 'expired' );
		} else if ( 'vezmo:secure-payment:processing' === data.type ) {
			noteProcessing();
		} else if ( 'vezmo:secure-payment:requires_action' === data.type ) {
			// Extra verification is happening inside the frame. It is also proof
			// the form took the charge, so stop re-sending the submit: another one
			// would land on a form already mid-verification.
			noteProcessing();
			noteAwaitingAction();
		}
	} );

	/**
	 * Extra verification (3-D Secure, or the captcha that cannot run inside a
	 * third-party frame) has taken over. It owns the shopper until it reports
	 * back, so both bounds are suspended rather than restarted.
	 */
	function noteAwaitingAction() {
		setMessage( params.i18n.verifying, 'info' );
		if ( ! charging ) {
			return;
		}
		charging.awaitingAction = true;
		if ( charging.stallTimer ) {
			window.clearTimeout( charging.stallTimer );
			charging.stallTimer = null;
		}
		if ( charging.attemptTimer ) {
			// Never time out a live challenge.
			window.clearTimeout( charging.attemptTimer );
			charging.attemptTimer = null;
		}
	}

	/**
	 * `requires_action`, in element mode.
	 *
	 * vezmo.js relays eight event names — ready, processing, success, error,
	 * pending, already-paid, expired, cancel (SUFFIX_BY_NAME in the SDK) — and
	 * drops everything else, so `vezmo.on( 'requires_action' )` can never fire.
	 * Without this listener the bounded attempt would call a live bank
	 * verification a failure at sixty seconds and tell the shopper to try again
	 * while their challenge was still open.
	 *
	 * Same guards as the frame-mode listener: source first (unforgeable for a
	 * window we did not create), then origin against the trusted set.
	 */
	window.addEventListener( 'message', function ( e ) {
		if ( ! sdkFrame || ! sdkFrame.contentWindow || e.source !== sdkFrame.contentWindow ) {
			return;
		}
		if ( ! e.origin || 'null' === e.origin || trustedOrigins().indexOf( e.origin ) === -1 ) {
			return;
		}
		if ( ! e.data || 'vezmo:secure-payment:requires_action' !== e.data.type ) {
			return;
		}
		log( 'sdk frame: requires_action (relayed past the SDK)' );
		noteAwaitingAction();
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
				refreshPayButton();
				// A session fetched to replace a failed one: the shopper is looking
				// at a blank form, so restate why.
				if ( pendingNotice ) {
					setMessage( pendingNotice, 'error' );
					pendingNotice = '';
				}
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
		dropSession();
		setMessage( '' );
		if ( selected() ) {
			ensureSession();
		}
	}

	/**
	 * Throw away the mounted form and the session behind it, without touching the
	 * message area — the caller decides what the shopper should be reading.
	 */
	function dropSession() {
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
			var escape = el.querySelector( '.vezmopay-inline-escape' );
			if ( escape && escape.parentNode ) {
				// A stall link from the attempt that just ended offers a payment
				// the shopper can no longer make.
				escape.parentNode.removeChild( escape );
			}
		}
		refreshPayButton();
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
		charging.attemptTimer = window.setTimeout( attemptTimedOut, ATTEMPT_LIMIT_MS );
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
						failCharge( params.i18n.failed, 'status' );
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
		if ( charging.attemptTimer ) {
			window.clearTimeout( charging.attemptTimer );
			charging.attemptTimer = null;
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
		// Only while a charge is actually running. This used to be reachable after
		// a decline (failCharge left the stall timer armed), and it then replaced
		// "your card was declined" with "this is taking longer than usual" and
		// offered a link to the VezmoPay page — so the shopper lost the reason and
		// was handed a fresh payment form instead.
		if ( ! charging ) {
			return;
		}
		// Only when the form never took the card. If it DID acknowledge the
		// charge, a top-level VezmoPay page is the wrong offer: the frame is
		// holding a live payment intent, so starting another attempt there is how
		// a shopper ends up paying twice — and the outcome is bounded now anyway
		// (ATTEMPT_LIMIT_MS), which is what this link used to stand in for.
		if ( charging.sawProcessing ) {
			return;
		}
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

	/**
	 * Every route to the pay page from here is a route taken because the payment
	 * did NOT complete in the payment box. receipt_page() reads this flag and
	 * says so — without it the shopper simply arrived at another payment form
	 * with no explanation of what happened to the first one.
	 */
	function withRetryFlag( url ) {
		if ( ! url || url.indexOf( 'vezmopay_retry=' ) !== -1 ) {
			return url;
		}
		return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'vezmopay_retry=1';
	}

	/**
	 * The attempt produced no outcome in time. Nothing is coming: VezmoPay does
	 * not report a declined payment, so this is the only way a decline that the
	 * frame could not tell us about ever reaches the shopper.
	 */
	function attemptTimedOut() {
		if ( ! charging || charging.awaitingAction ) {
			return;
		}
		log( 'no outcome', ATTEMPT_LIMIT_MS, 'ms after the charge started — treating the attempt as failed' );
		failCharge( params.i18n.noResult, 'timeout' );
	}

	function payPageUrl() {
		if ( charging && charging.payUrl ) {
			return withRetryFlag( charging.payUrl );
		}
		return withRetryFlag(
			window.location.origin + '/checkout/order-pay/' + charging.orderId + '/?key=' + encodeURIComponent( charging.orderKey )
		);
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
			attemptTimer: null,
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
				failCharge( params.i18n.unavailable, 'not-ready' );
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

	/**
	 * The charge failed — a declined card, a cancelled or expired payment, or the
	 * store's own poll reading FAILED from the API.
	 *
	 * Three things have to happen, and two of them used to be missing:
	 *
	 *   1. Stop the timers. They were left running, so twenty seconds later the
	 *      stall handler replaced the decline with "this is taking longer than
	 *      usual" and a link to the VezmoPay page — the shopper lost the reason
	 *      and was shown a fresh payment form instead.
	 *   2. Say what happened, in the payment box, on BOTH checkouts. The Blocks
	 *      notice sits above the payment method and is dismissible; the message
	 *      the shopper needs belongs next to the form they must correct.
	 *   3. Replace the form. The payment behind it is FAILED at VezmoPay and
	 *      process_payment() has already unbound it from the order, so placing the
	 *      order again could not charge it — it fell through to the pay page, i.e.
	 *      a redirect to yet another payment form. A new session mounted here is
	 *      what makes "try again" work where the shopper is standing.
	 */
	function failCharge( message, reason ) {
		if ( ! charging ) {
			return;
		}
		var text = message || params.i18n.failed;
		var d = charging.deferred;
		var orderId = charging.orderId;
		var orderKey = charging.orderKey;
		stopPolling();
		charging = null;
		refreshPayButton();
		setMessage( text, 'error' );
		if ( $ && $( 'form.checkout' ).length ) {
			$( 'form.checkout' ).removeClass( 'processing' ).unblock();
			$( document.body ).trigger( 'checkout_error' );
		}
		if ( d ) {
			// Blocks needs the rejection to leave its processing state; it shows
			// the same text in its own notice area.
			d.reject( text );
		}
		reportFailedAttempt( reason || 'unknown', orderId, orderKey );
		rearm( text );
	}

	/**
	 * Tell the store this attempt failed.
	 *
	 * Two reasons to bother. The order gets a note — without one a declined card
	 * and an abandoned cart are indistinguishable to the merchant. And the store
	 * asks the API before it believes us, so if the payment actually settled
	 * while we were giving up (which a timed-out attempt cannot rule out), the
	 * shopper is forwarded instead of being shown a failure.
	 */
	function reportFailedAttempt( reason, orderId, orderKey ) {
		if ( ! params.failedUrl || ! orderId || ! orderKey ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		body.append( 'order_id', orderId );
		body.append( 'order_key', orderKey );
		body.append( 'reason', reason );
		window
			.fetch( params.failedUrl, {
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
					log( 'the store found this payment settled after all — forwarding' );
					window.location.href = res.data.redirect;
				}
			} )
			.catch( function () {
				// The order note is a nicety; never let it break the checkout.
			} );
	}

	/**
	 * Build a payment the shopper can actually retry with, and keep the reason on
	 * screen while it loads.
	 */
	function rearm( reason ) {
		if ( 'hosted' === params.mode ) {
			return;
		}
		pendingNotice = reason
			? reason + ' ' + params.i18n.retryHint
			: '';
		setMessage( pendingNotice || '', 'error' );
		dropSession();
		log( 'the failed payment cannot be reused — fetching a fresh session' );
		ensureSession();
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
