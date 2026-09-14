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
	// A failure the mounted form cannot recover from: its payment is dead (the
	// API reported FAILED, or the token expired), so the form has to be replaced.
	// Every other failure — a declined card above all — leaves the payment
	// INITIATED and chargeable, so the form STAYS, with the shopper's card
	// details in it, and the error is shown next to it.
	var REBUILD_REASONS = [ 'status', 'expired', 'not-ready' ];

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

	// A wallet approval the embed is holding for us while WooCommerce places the
	// order. { id, settle, timer } — settle() answers the embed exactly once.
	var walletAuth = null;
	// How long we may keep the shopper's wallet sheet open. The embed gives up at
	// twenty seconds and the browser's own sheet not long after, so answer well
	// inside that: a Woo checkout POST that has not come back by now is not going
	// to in time, and a cancelled sheet costs nothing but a second tap.
	var WALLET_AUTH_LIMIT_MS = 14 * 1000;
	// Last readiness we told the embed, so the watcher only speaks on a change.
	var lastCheckoutReady = null;

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
		// First chance to tell a newly mounted form whether the checkout is
		// payable. The watcher only speaks when something CHANGES, so without
		// this a checkout that was already complete when the form mounted — a
		// returning shopper with everything prefilled, most obviously — would
		// leave its wallet buttons greyed out until they touched a field.
		pushCheckoutReady();
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
		// A fresh form has heard nothing from us. Forgetting what we last said
		// means the watcher re-states it: otherwise readiness settled before this
		// mount existed would never be sent, and the wallet buttons would sit
		// enabled on an incomplete checkout (or greyed out on a complete one).
		lastCheckoutReady = null;
		// Nothing can be holding an approval for a form that no longer exists.
		settleWalletAuth( false );

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
					// wallets: 'authorize' — the wallet sheet asks US before it
					// charges, so Place order can create the order first. See
					// walletAuthorizeUrl() below.
					vezmo.mount( host, {
						clientToken: session.clientToken,
						theme: params.theme,
						wallets: 'authorize',
					} );
					vezmo.onWalletAuthorized( function ( details ) {
						return onWalletAuthorized( details );
					} );
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

	/**
	 * Ask for the wallet AUTHORIZE handshake on a payment-box mount.
	 *
	 * Every other method in the embed waits for OUR submit: the shopper presses
	 * WooCommerce's Place order, the order is created, process_payment() binds
	 * the session to it, and only then are we told to charge. A wallet button
	 * does not work that way — its sheet confirms on a gesture inside the embed,
	 * so left alone it charges before any Place order, with no order to bind it
	 * to and no charge of ours in flight.
	 *
	 * In authorize mode the approval reaches us as `wallet-authorized` and
	 * NOTHING is charged until we answer. We press Place order on the shopper's
	 * behalf, let WooCommerce validate and create the order exactly as it always
	 * does, and only then say charge.
	 *
	 * BOTH parameters go on, and that is deliberate. `wallets=0` is the answer
	 * for a checkout build that predates the handshake: it understands that one
	 * and hides the buttons, rather than seeing a mode it cannot honour and
	 * charging on approval anyway. `walletMode=authorize` is read only by a build
	 * that can actually answer. Whichever the store's VezmoPay account is running,
	 * the shopper's money is safe.
	 *
	 * @param {string} url Secure-payment URL from the session.
	 * @return {string} The same URL, asking for the handshake.
	 */
	function walletAuthorizeUrl( url ) {
		if ( ! url || url.indexOf( 'wallets=' ) !== -1 ) {
			return url;
		}
		var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
		return url + sep + 'wallets=0&walletMode=authorize';
	}

	/* ---------------------------------------------------------------------
	 * The wallet authorize handshake.
	 * ------------------------------------------------------------------- */

	/**
	 * Send a message INTO the mounted form, whichever way it is mounted.
	 *
	 * Element mode has the SDK's own methods for this; iframe mode (and element
	 * mode that fell back to a plain frame) posts on the wire. Same split
	 * sendSubmit() already makes.
	 *
	 * @param {string} type    Message type, without the vezmo prefix.
	 * @param {Object} payload Extra fields.
	 */
	function postToForm( type, payload ) {
		var message = { type: 'vezmo:secure-payment:' + type };
		for ( var k in payload ) {
			if ( Object.prototype.hasOwnProperty.call( payload, k ) ) {
				message[ k ] = payload[ k ];
			}
		}
		if ( frame && frame.contentWindow ) {
			var target = frameOrigin();
			if ( ! target ) {
				return false;
			}
			frame.contentWindow.postMessage( message, target );
			return true;
		}
		return false;
	}

	/** Answer a held wallet approval. Exactly once — later calls are no-ops. */
	function settleWalletAuth( ok, message ) {
		if ( ! walletAuth ) {
			return;
		}
		var held = walletAuth;
		walletAuth = null;
		if ( held.timer ) {
			window.clearTimeout( held.timer );
		}
		log( 'answering the wallet authorization:', ok ? 'proceed' : 'cancel', message || '' );
		held.settle( ok, message );
	}

	/**
	 * The shopper approved Apple/Google Pay and the embed is holding the charge.
	 *
	 * Press Place order for them. WooCommerce validates the checkout and creates
	 * the order exactly as it would have, process_payment() binds the payment
	 * session to it, and the marker comes back to handleHash() → beginCharge() →
	 * sendSubmit(), which answers `proceed` instead of sending a submit.
	 *
	 * If validation fails, WooCommerce says so on `checkout_error` and we answer
	 * `cancel`: the sheet closes, no money moves, and the shopper is looking at
	 * their own form with the missing fields marked.
	 *
	 * @param {Object}   details Authorization details from the embed.
	 * @param {Function} settle  Called with (ok, message) to answer the embed.
	 */
	function beginWalletAuthorization( details, settle ) {
		var id = details && details.authorizationId;
		log( 'wallet approved, holding the charge:', details && details.wallet, id );

		// A second approval while one is in flight: answer the first with a
		// cancel so the embed is never left holding two.
		settleWalletAuth( false );

		if ( charging ) {
			// A charge of ours is already running — the shopper pressed Pay and
			// then tapped the wallet. Refuse rather than place a second order.
			settle( false, params.i18n.walletBusy );
			return;
		}

		walletAuth = {
			id: id,
			settle: settle,
			timer: window.setTimeout( function () {
				log( 'the store did not place the order in time — cancelling the wallet' );
				settleWalletAuth( false, params.i18n.walletSlow );
			}, WALLET_AUTH_LIMIT_MS ),
		};

		setMessage( params.i18n.walletPlacing, 'info' );
		submitCheckout();
	}

	/**
	 * Bridge for element mode: the SDK wants a promise back.
	 *
	 * Answers in the SDK's object form rather than a bare boolean, so a refusal
	 * carries OUR reason — "check the highlighted fields" beats the generic
	 * decline the embed would otherwise show for a checkout WooCommerce simply
	 * would not accept.
	 *
	 * @param {Object} details Authorization details.
	 * @return {Promise<Object>} { proceed, message } for the SDK.
	 */
	function onWalletAuthorized( details ) {
		return new Promise( function ( resolve ) {
			beginWalletAuthorization( details, function ( ok, message ) {
				resolve( { proceed: !! ok, message: message || undefined } );
			} );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Readiness: is our checkout complete enough to pay from?
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the CLASSIC checkout's required fields are filled.
	 *
	 * Returns null when there is no classic form to read, so the caller can tell
	 * "not ready" apart from "cannot tell" — they are very different answers and
	 * only one of them should grey out a payment button.
	 *
	 * @return {boolean|null} Validity, or null when unknown.
	 */
	function classicCheckoutReady() {
		var form = document.querySelector( 'form.checkout' );
		if ( ! form ) {
			return null;
		}
		var fields = form.querySelectorAll(
			'.validate-required input, .validate-required select, .validate-required textarea'
		);
		for ( var i = 0; i < fields.length; i++ ) {
			var el = fields[ i ];
			if ( el.offsetParent === null && el.type !== 'hidden' ) {
				// Hidden by a shipping/billing toggle — not being asked for.
				continue;
			}
			if ( 'checkbox' === el.type ) {
				if ( ! el.checked ) {
					return false;
				}
			} else if ( ! String( el.value || '' ).trim() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the BLOCKS checkout reports itself valid.
	 *
	 * Blocks owns its fields in React, so there is no markup to read — its
	 * validation store is the only honest source. Null when that store is not
	 * present.
	 *
	 * @return {boolean|null} Validity, or null when unknown.
	 */
	function blocksCheckoutReady() {
		var data = window.wp && window.wp.data;
		if ( ! data || typeof data.select !== 'function' ) {
			return null;
		}
		try {
			var store = data.select( 'wc/store/validation' );
			if ( ! store || typeof store.hasValidationErrors !== 'function' ) {
				return null;
			}
			return ! store.hasValidationErrors();
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Tell the embed whether the shopper may pay by wallet yet.
	 *
	 * Only on a change, and never when we cannot tell: an embed that hears
	 * nothing leaves its buttons enabled, and a tap against an invalid checkout
	 * is still refused at the handshake. This gate exists to spare the shopper
	 * approving a payment we are about to refuse — it is not what protects the
	 * money.
	 */
	function pushCheckoutReady() {
		var state = classicCheckoutReady();
		if ( null === state ) {
			state = blocksCheckoutReady();
		}
		if ( null === state || state === lastCheckoutReady ) {
			return;
		}
		lastCheckoutReady = state;
		log( 'checkout readiness:', state );
		if ( vezmo && typeof vezmo.setCheckoutReady === 'function' ) {
			vezmo.setCheckoutReady( state );
			return;
		}
		postToForm( 'checkout-ready', { ready: state } );
	}

	/** Watch the checkout for changes that make it payable, or stop being so. */
	function watchCheckoutReadiness() {
		var timer = null;
		var schedule = function () {
			if ( timer ) {
				window.clearTimeout( timer );
			}
			timer = window.setTimeout( pushCheckoutReady, 250 );
			// Same events, a slower beat of its own: readiness is a local read,
			// this one is a request to the store.
			scheduleBillingSync();
		};

		document.addEventListener( 'input', schedule, true );
		document.addEventListener( 'change', schedule, true );
		if ( $ && $.fn ) {
			// Woo rebuilds the review order — and sometimes the fields — here.
			$( document.body ).on( 'updated_checkout country_to_state_changed', schedule );
		}
		if ( window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function' ) {
			window.wp.data.subscribe( schedule );
		}
		schedule();
	}

	/* ---------------------------------------------------------------------
	 * Billing details: attaching the customer to the session
	 * ------------------------------------------------------------------- */

	/**
	 * The session is created for the CART — before the shopper has typed a
	 * billing email — so it starts with no customer on it. Card payments do not
	 * care. A bank payment does: the ACH debit mandate REQUIRES the payer's
	 * email, and without one the embed refuses the payment outright.
	 *
	 * So the billing fields go up to the store as they are filled, and it
	 * attaches them to the session the form is already mounted on. Nothing is
	 * remounted and nothing the shopper typed is thrown away — the embed re-reads
	 * the customer when it mints its payment intent.
	 */

	// Fingerprint of the details we last sent, so a checkout re-render or an
	// edit to an unrelated field does not re-post what the store already has.
	var sentBilling = '';
	var billingTimer = null;
	// Long enough that typing an email address is one request rather than thirty.
	var BILLING_DEBOUNCE_MS = 900;
	// What the API demands on a client object. A partial one is refused, so an
	// incomplete checkout is skipped rather than sent.
	var BILLING_REQUIRED = [ 'name', 'email', 'country', 'postalCode' ];

	function fieldValue( id ) {
		var el = document.getElementById( id );
		return el ? String( el.value || '' ).trim() : '';
	}

	/** The CLASSIC checkout's billing fields, or null when there is no form. */
	function classicBilling() {
		if ( ! document.querySelector( 'form.checkout' ) ) {
			return null;
		}
		return {
			name: ( fieldValue( 'billing_first_name' ) + ' ' + fieldValue( 'billing_last_name' ) ).trim(),
			email: fieldValue( 'billing_email' ),
			phone: fieldValue( 'billing_phone' ),
			company: fieldValue( 'billing_company' ),
			country: fieldValue( 'billing_country' ),
			line1: fieldValue( 'billing_address_1' ),
			line2: fieldValue( 'billing_address_2' ),
			city: fieldValue( 'billing_city' ),
			state: fieldValue( 'billing_state' ),
			postalCode: fieldValue( 'billing_postcode' ),
		};
	}

	/**
	 * The BLOCKS checkout's billing address, or null when its store is absent.
	 *
	 * Blocks owns its fields in React, so there is no markup to read — the cart
	 * store is the only honest source, exactly as with readiness above.
	 */
	function blocksBilling() {
		var data = window.wp && window.wp.data;
		if ( ! data || typeof data.select !== 'function' ) {
			return null;
		}
		try {
			var store = data.select( 'wc/store/cart' );
			if ( ! store || typeof store.getCustomerData !== 'function' ) {
				return null;
			}
			var address = ( store.getCustomerData() || {} ).billingAddress || {};
			var str = function ( value ) {
				return String( value || '' ).trim();
			};
			return {
				name: ( str( address.first_name ) + ' ' + str( address.last_name ) ).trim(),
				email: str( address.email ),
				phone: str( address.phone ),
				company: str( address.company ),
				country: str( address.country ),
				line1: str( address.address_1 ),
				line2: str( address.address_2 ),
				city: str( address.city ),
				state: str( address.state ),
				postalCode: str( address.postcode ),
			};
		} catch ( e ) {
			return null;
		}
	}

	/** Send the billing details up, once they are complete and have changed. */
	function syncBilling() {
		// No session is nothing to attach to; a charge in flight is already past
		// the point where this could help.
		// `selected()` reads the CLASSIC radio, which the Blocks checkout does not
		// render — there, our form being mounted into a host element IS the
		// selection, and Blocks clears that host when another gateway is picked.
		var chosen = hostEl ? true : selected();
		if ( ! params.clientUrl || ! session || charging || ! chosen ) {
			return;
		}

		var billing = classicBilling();
		if ( null === billing ) {
			billing = blocksBilling();
		}
		if ( ! billing ) {
			return;
		}
		for ( var i = 0; i < BILLING_REQUIRED.length; i++ ) {
			if ( ! billing[ BILLING_REQUIRED[ i ] ] ) {
				// Still being filled in. The next edit tries again.
				return;
			}
		}

		var fingerprint = session.clientToken + '|' + JSON.stringify( billing );
		if ( fingerprint === sentBilling ) {
			return;
		}
		// Claim it BEFORE the request, or the next keystroke sends a duplicate
		// while this one is still in flight. Released again on failure.
		sentBilling = fingerprint;

		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		Object.keys( billing ).forEach( function ( key ) {
			body.append( key, billing[ key ] );
		} );

		window
			.fetch( params.clientUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( res && res.success ) {
					log( 'billing details attached to the payment session' );
					return;
				}
				sentBilling = '';
				log( 'billing details were not attached', res && res.data && res.data.message );
			} )
			.catch( function () {
				sentBilling = '';
			} );
		// Deliberately silent to the shopper either way: this runs while they are
		// still typing, and the bank form says plainly what is missing if it ever
		// matters. A failure here never blocks a card payment.
	}

	/** Coalesce a burst of field edits into one attach. */
	function scheduleBillingSync() {
		if ( billingTimer ) {
			window.clearTimeout( billingTimer );
		}
		billingTimer = window.setTimeout( syncBilling, BILLING_DEBOUNCE_MS );
	}

	function mountFrame( host ) {
		frame = document.createElement( 'iframe' );
		frame.id = 'vezmopay-inline-frame';
		frame.src = walletAuthorizeUrl( session.url );
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
			// The embed tells us here whether it can hold a wallet approval. It
			// cannot be made to — an older deploy simply will not — but because we
			// ask with `wallets=0` alongside the mode, such a build hides the
			// buttons rather than charging on approval. Worth a line in the log
			// so a store wondering where Apple/Google Pay went has an answer.
			if ( ! data.walletAuthorize ) {
				log( 'this VezmoPay checkout cannot hold a wallet approval — wallet buttons stay hidden' );
			}
		} else if ( 'vezmo:secure-payment:wallet-authorized' === data.type ) {
			beginWalletAuthorization( data, function ( ok, message ) {
				postToForm( ok ? 'wallet-proceed' : 'wallet-cancel', {
					authorizationId: data.authorizationId,
					message: message || undefined,
				} );
			} );
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
	 * The store answered a poll with LOCKED: another actor — the webhook, the
	 * five-minute cron, a second tab — is inside the store's reconcile RIGHT NOW,
	 * writing the answer this poll is asking for. That holder runs
	 * payment_complete(), which sends the order emails synchronously and can take
	 * tens of seconds.
	 *
	 * This page used to keep counting through it and call the attempt failed
	 * underneath a completing order: the shopper was told their payment reported
	 * no result and asked to try again, while the order was collecting its
	 * "payment captured" note and its confirmation emails.
	 *
	 * Treated like a live verification: the bounds are lifted while it lasts,
	 * because the wait is on our own store rather than on the shopper. The first
	 * answer the store gives for itself starts them again from there. Nothing
	 * here can wait forever — the store breaks an abandoned reconcile lock after
	 * RECONCILE_LOCK_TTL, and the poll keeps asking throughout.
	 */
	function noteStoreBusy() {
		if ( ! charging || charging.storeBusy ) {
			return;
		}
		charging.storeBusy = true;
		log( 'the store is already settling this order — holding the attempt open' );
		if ( charging.stallTimer ) {
			window.clearTimeout( charging.stallTimer );
			charging.stallTimer = null;
		}
		if ( charging.attemptTimer ) {
			window.clearTimeout( charging.attemptTimer );
			charging.attemptTimer = null;
		}
	}

	/** The store is answering for itself again: restore the bound it suspended. */
	function noteStoreFree() {
		if ( ! charging || ! charging.storeBusy ) {
			return;
		}
		charging.storeBusy = false;
		log( 'the store finished its reconcile — the attempt is bounded again' );
		if ( ! charging.awaitingAction && ! charging.attemptTimer ) {
			charging.attemptTimer = window.setTimeout( attemptTimedOut, ATTEMPT_LIMIT_MS );
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
				// The readiness watcher runs from page load and gives up while
				// there is no session to attach to, so a checkout that was already
				// filled in would never send its billing details. Ask again now
				// that there is somewhere to put them.
				scheduleBillingSync();
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
		}
		dropStallLink();
		refreshPayButton();
	}

	/** A stall link from the attempt that just ended is no longer an offer. */
	function dropStallLink() {
		var el = root();
		var escape = el ? el.querySelector( '.vezmopay-inline-escape' ) : null;
		if ( escape && escape.parentNode ) {
			escape.parentNode.removeChild( escape );
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
		charging.attemptTimer = window.setTimeout( attemptTimedOut, ATTEMPT_LIMIT_MS );
		charging.pollTimer = window.setInterval( function () {
			if ( ! charging ) {
				return;
			}
			// Neither while the tab is hidden, nor while the STORE is mid-reconcile
			// (see noteStoreBusy): time spent waiting on our own server is not the
			// shopper staring at a spinner with nothing happening, and handing off
			// to the pay page in the middle of the order completing is the same
			// mistake the attempt limit used to make.
			if ( ! document.hidden && ! charging.storeBusy ) {
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
					} else if ( 'LOCKED' === res.data.status ) {
						noteStoreBusy();
					} else if ( 'FAILED' === res.data.status ) {
						noteStoreFree();
						failCharge( params.i18n.failed, 'status' );
					} else {
						noteStoreFree();
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
			storeBusy: false,
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
		// A wallet approval is being held and the order now exists: say charge.
		// The form is already past its own submit — it is sitting on an approved
		// wallet sheet — so a submit here would be answering the wrong question.
		if ( walletAuth ) {
			if ( charging ) {
				charging.viaWallet = true;
			}
			settleWalletAuth( true );
			return;
		}
		// Same charge, a later retry. scheduleSubmitRetry() re-sends until the
		// form acknowledges, which is right for a card but wrong here: the wallet
		// is already charging, and a submit would tell the CARD form to charge
		// as well. Two charges, one order.
		if ( charging && charging.viaWallet ) {
			return;
		}
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
			// A payment settled that we never asked for, so there is no order to
			// confirm it against — the shopper paid in the payment box without
			// pressing Place order. The wallet buttons did exactly this (their
			// sheet charges on its own gesture) and are no longer offered here;
			// if anything else ever manages it, the money is real and saying
			// nothing is the one response that is certainly wrong. Do NOT try to
			// recover by charging again.
			log( 'a payment settled with no charge of ours in flight — nothing to confirm it against' );
			// The literal fallback is deliberate. setMessage() renders a missing
			// string as an EMPTY message, which is the silence this whole branch
			// exists to end, and a page cached from an older version of the plugin
			// has localized params without this key. Untranslated beats invisible
			// when the shopper has already been charged.
			setMessage(
				params.i18n.unsolicited ||
					'That payment went through, but your order has not been placed yet. Please do not pay again — contact the store to complete your order.',
				'error'
			);
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
		// The store answers with what the API says. If it says this payment is
		// FAILED, the mounted form cannot be charged again after all — rebuild
		// then, on the store's word rather than a guess here.
		var heldFor = mountedFor;
		reportFailedAttempt( reason || 'unknown', orderId, orderKey, function ( status ) {
			if ( 'FAILED' === status && mountedFor === heldFor ) {
				log( 'the store says this payment is FAILED — replacing the form' );
				rebuildForm( text );
			}
		} );

		if ( REBUILD_REASONS.indexOf( reason ) !== -1 ) {
			rebuildForm( text );
			return;
		}
		holdForRetry( text );
	}

	/**
	 * Keep the form exactly as it is and let the shopper try again.
	 *
	 * Nothing is unmounted and no new session is fetched: the card details the
	 * shopper typed are inside the VezmoPay frame, and throwing the frame away to
	 * mount an identical empty one made them type the card again for no reason.
	 * The payment behind it is still INITIATED, and process_payment() will hand
	 * back a marker for that same payment (see can_recharge_bound_payment), so
	 * pressing Pay charges the form that is already there.
	 */
	function holdForRetry( reason ) {
		dropStallLink();
		// The frame's own message names the problem but not the remedy ("Your
		// card was declined."), so it gets the invitation to try again appended.
		// This plugin's own strings already carry it — appending would say the
		// same thing twice.
		var text = reason || params.i18n.failed;
		if ( ! ownMessage( text ) ) {
			text += ' ' + params.i18n.tryAgain;
		}
		setMessage( text, 'error' );
		// charging is already null, so this re-enables Pay.
		refreshPayButton();
	}

	function ownMessage( text ) {
		return [
			params.i18n.failed,
			params.i18n.noResult,
			params.i18n.cancelled,
			params.i18n.expired,
			params.i18n.unavailable,
		].indexOf( text ) !== -1;
	}

	/**
	 * The mounted form's payment cannot be charged again — replace it, and say so,
	 * because this is the case where the card really does have to be re-entered.
	 */
	function rebuildForm( reason ) {
		if ( 'hosted' === params.mode ) {
			return;
		}
		pendingNotice = reason ? reason + ' ' + params.i18n.retryHint : '';
		setMessage( pendingNotice || '', 'error' );
		dropSession();
		log( 'this payment cannot be charged again — fetching a fresh session' );
		ensureSession();
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
	function reportFailedAttempt( reason, orderId, orderKey, onStatus ) {
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
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				if ( res.data.redirect ) {
					log( 'the store found this payment settled after all — forwarding' );
					window.location.href = res.data.redirect;
					return;
				}
				if ( onStatus ) {
					onStatus( res.data.status );
				}
			} )
			.catch( function () {
				// The order note is a nicety; never let it break the checkout.
			} );
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
			// Picking VezmoPay in Blocks is what makes the billing details worth
			// sending, and no field changed to wake the watcher.
			scheduleBillingSync();
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
		// BOTH checkouts. The wallet readiness gate reads the classic form's
		// required fields when there is one and the Blocks validation store when
		// there is not, so it has to be started above the classic-only guard
		// below — otherwise a Blocks store would never gate its wallet buttons.
		watchCheckoutReadiness();

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
				// Both matter: ensureSession() returns early when a session is
				// already held, and syncBilling() refuses while another gateway is
				// selected — so choosing VezmoPay second needs its own nudge.
				ensureSession();
				scheduleBillingSync();
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

		// WooCommerce refused the checkout — a missing field, a failed gateway
		// validation, anything. If a wallet sheet is open waiting on us, this is
		// the answer: cancel it, so the shopper's money stays where it is and
		// they are left looking at their own form with the problem marked.
		//
		// Classic only: `checkout_error` is a jQuery event on the classic form and
		// the Blocks checkout never fires it. A refusal there falls through to
		// WALLET_AUTH_LIMIT_MS instead — slower to tell the shopper, but it
		// cancels just the same, and no money moves either way.
		$( document.body ).on( 'checkout_error', function () {
			if ( walletAuth ) {
				log( 'WooCommerce refused the checkout — cancelling the held wallet approval' );
				settleWalletAuth( false, params.i18n.walletRefused );
				setMessage( params.i18n.walletRefused, 'error' );
			}
		} );
	} );
} )( jQuery );
