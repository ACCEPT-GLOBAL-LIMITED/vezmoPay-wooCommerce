/**
 * One payment attempt on the store's own pay page.
 *
 * The pay page has two drivers — checkout-element.js (vezmo.js SDK) and
 * checkout-iframe.js (plain frame) — and they used to carry their own copies of
 * the message area, the Pay button state, the status poll and the failure
 * branches. They drifted, and the drift is what made a decline invisible here:
 * the checkout page's payment box was given careful failure handling while this
 * page kept the original silent behaviour, whose only failure branch was a
 * status the API never reports. So the shared behaviour lives here, once, and
 * both drivers wire their own mounting and events onto it.
 *
 * What "one attempt" means: from the shopper pressing Pay to a terminal
 * outcome. VezmoPay reports NO terminal state for a declined card — the payment
 * record stays INITIATED and the hosted page emits no error event (see
 * docs/VEZMOPAY-API-CONTRACT.md) — so an attempt that produces nothing is
 * bounded here rather than waited on forever.
 *
 * @package VezmoPay
 */

/* exported VezmoPayAttempt */
window.VezmoPayAttempt = function ( params ) {
	'use strict';

	// The outer bound on the poll: a payment that can never settle must not
	// leave this running for the life of the page.
	var POLL_LIMIT_MS = 15 * 60 * 1000;

	// TODO(platform): remove once a declined payment reports FAILED (or the
	// frame emits `error`). Until then a decline is indistinguishable from a
	// slow capture, and waiting forever is worse than a bounded guess. A real
	// capture in this plugin's own testing resolved in about nine seconds, so a
	// minute is generous; a 3-D Secure challenge is exempt (see awaitAction).
	var ATTEMPT_LIMIT_MS = 60 * 1000;

	var els = {
		checkout: document.getElementById( 'vezmopay-checkout' ),
		message: document.getElementById( 'vezmopay-message' ),
		payButton: document.getElementById( 'vezmopay-pay' ),
	};

	var settled = false;          // a terminal outcome has been acted on
	var paying = false;           // an attempt is in flight
	var awaitingAction = false;   // extra verification owns the shopper
	var pollTimer = null;
	var pollStarted = 0;
	var attemptTimer = null;
	// The store answered a poll with LOCKED: another actor — the webhook, the
	// five-minute cron, another tab — is inside the store's reconcile right now,
	// writing the answer this poll wants. That holder runs payment_complete(),
	// which sends the order emails synchronously and can take tens of seconds,
	// and calling the attempt failed underneath it tells a shopper whose order is
	// completing that their payment reported no result. Both bounds are lifted for
	// as long as it lasts and start again from the store's next answer for itself;
	// the store breaks an abandoned reconcile lock on its own, so this cannot wait
	// forever.
	var storeBusy = false;
	var busyMs = 0;
	var lastTick = 0;

	function setMessage( text, kind ) {
		if ( ! els.message ) {
			return;
		}
		els.message.textContent = text || '';
		els.message.className = 'vezmopay-message' + ( text ? ' is-' + ( kind || 'info' ) : '' );
	}

	function setPaying( on ) {
		paying = !! on;
		if ( ! els.payButton ) {
			return;
		}
		els.payButton.disabled = !! on;
		els.payButton.classList.toggle( 'is-paying', !! on );
		var label = els.payButton.querySelector( '.vezmopay-pay-label' );
		if ( label ) {
			label.textContent = on ? params.i18n.processing : params.i18n.pay;
		}
	}

	function markReady() {
		if ( els.checkout ) {
			els.checkout.classList.add( 'is-ready' );
		}
	}

	// A theme can hand this page a column far narrower than the screen, which
	// makes the VezmoPay form inside render its phone layout on a desktop
	// monitor. Only then — narrow card, wide viewport — centre the card on the
	// viewport instead of the column.
	function checkBreakout() {
		if ( ! els.checkout ) {
			return;
		}
		// Always measure from the un-broken state, so a resize can undo this.
		els.checkout.classList.remove( 'is-breakout' );
		els.checkout.style.width = '';
		els.checkout.style.marginLeft = '';

		var cardWidth = els.checkout.getBoundingClientRect().width;
		if ( cardWidth >= 420 || window.innerWidth < 700 ) {
			return;
		}

		// Centre on the VIEWPORT: offset the card by the gap between the
		// viewport's centred position and wherever the narrow parent starts.
		var target = Math.min( 760, window.innerWidth - 40 );
		var parent = els.checkout.parentElement || document.body;
		var offset = Math.round(
			( window.innerWidth - target ) / 2 - parent.getBoundingClientRect().left
		);
		els.checkout.classList.add( 'is-breakout' );
		els.checkout.style.width = target + 'px';
		els.checkout.style.marginLeft = offset + 'px';
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

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function clearAttemptTimer() {
		if ( attemptTimer ) {
			window.clearTimeout( attemptTimer );
			attemptTimer = null;
		}
	}

	/** The store is mid-reconcile: hold the attempt open rather than fail it. */
	function noteStoreBusy() {
		if ( storeBusy ) {
			return;
		}
		storeBusy = true;
		clearAttemptTimer();
	}

	/** The store is answering for itself again: restore the bound it suspended. */
	function noteStoreFree() {
		if ( ! storeBusy ) {
			return;
		}
		storeBusy = false;
		if ( ! settled && paying && ! awaitingAction && ! attemptTimer ) {
			attemptTimer = window.setTimeout( onAttemptTimeout, ATTEMPT_LIMIT_MS );
		}
	}

	/** Nothing reported an outcome inside the attempt's time limit. */
	function onAttemptTimeout() {
		if ( settled || ! paying || awaitingAction ) {
			return;
		}
		failAttempt( 'timeout', params.i18n.noResult );
	}

	function startPolling() {
		if ( pollTimer || settled ) {
			return;
		}
		pollStarted = Date.now();
		lastTick = pollStarted;
		busyMs = 0;
		pollTimer = window.setInterval( function () {
			if ( settled ) {
				return;
			}
			var now = Date.now();
			if ( storeBusy ) {
				busyMs += now - lastTick;
			}
			lastTick = now;
			// Time the store spent settling this order is not time the shopper
			// spent waiting on nothing, so it does not count against the limit.
			if ( now - pollStarted - busyMs > POLL_LIMIT_MS ) {
				stopPolling();
				return;
			}
			post( params.statusUrl )
				.then( function ( res ) {
					if ( res && res.__authFailed ) {
						// A retired nonce, not a network blip. Stop and say so.
						settled = true;
						stopPolling();
						clearAttemptTimer();
						setPaying( false );
						setMessage( params.i18n.expired, 'error' );
						return;
					}
					if ( ! res || ! res.success || ! res.data ) {
						return;
					}
					if ( res.data.redirect ) {
						settled = true;
						stopPolling();
						clearAttemptTimer();
						if ( 'PENDING' === res.data.status ) {
							setMessage( params.i18n.pending, 'info' );
						}
						window.location = res.data.redirect;
					} else if ( 'LOCKED' === res.data.status ) {
						noteStoreBusy();
					} else if ( 'FAILED' === res.data.status ) {
						noteStoreFree();
						failAttempt( 'status', params.i18n.failed );
					} else if ( 'MISMATCH' === res.data.status ) {
						noteStoreFree();
						// Manual review required — polling will never resolve it,
						// and the shopper must not be told to pay again.
						settled = true;
						stopPolling();
						clearAttemptTimer();
						setPaying( false );
						setMessage( params.i18n.review, 'info' );
					} else {
						noteStoreFree();
					}
				} )
				.catch( function () {
					// Transient network error — keep polling.
				} );
		}, Math.max( 3000, parseInt( params.pollInterval, 10 ) || 4000 ) );
	}

	/**
	 * The shopper pressed Pay. Starts the clock as well as the poll: the frame
	 * announces `processing` when it accepts the card, but only to a parent
	 * origin the merchant has registered with VezmoPay — so a store whose origin
	 * is not registered gets no events at all, and an attempt bounded only from
	 * an acknowledgement would never be bounded there.
	 */
	function beginAttempt() {
		if ( settled ) {
			return false;
		}
		awaitingAction = false;
		setPaying( true );
		setMessage( '' );
		clearAttemptTimer();
		storeBusy = false;
		attemptTimer = window.setTimeout( onAttemptTimeout, ATTEMPT_LIMIT_MS );
		startPolling();
		return true;
	}

	/** The form took the card. Progress, not an outcome. */
	function acknowledge() {
		if ( settled || ! paying ) {
			return;
		}
		setMessage( params.i18n.processing, 'info' );
	}

	/**
	 * Extra verification (3-D Secure) is running inside the frame. It owns the
	 * shopper's attention until it reports back, and timing it out would destroy
	 * a live challenge — so the attempt bound is suspended, not restarted.
	 */
	function awaitAction( on ) {
		awaitingAction = !! on;
		if ( awaitingAction ) {
			clearAttemptTimer();
			setMessage( params.i18n.verifying, 'info' );
		}
	}

	/**
	 * The attempt is over and the payment did not go through — as far as anything
	 * here can tell. Hand the button back so the shopper can correct their card
	 * and press Pay again on the SAME payment: a declined Stripe intent returns
	 * to requires_payment_method and is designed to be confirmed again, so
	 * minting a replacement payment here would risk two live payments for one
	 * order.
	 *
	 * @param {string} reason  Allow-listed reason code sent to the store.
	 * @param {string} message What the shopper reads.
	 */
	function ownMessage( text ) {
		return [
			params.i18n.failed,
			params.i18n.noResult,
			params.i18n.cancelled,
			params.i18n.expired,
			params.i18n.error,
		].indexOf( text ) !== -1;
	}

	function failAttempt( reason, message ) {
		if ( settled ) {
			return;
		}
		stopPolling();
		clearAttemptTimer();
		awaitingAction = false;
		setPaying( false );
		// A message from the frame names the problem but not the remedy ("Your
		// card was declined."), so it gets the invitation to try again appended;
		// this plugin's own strings already carry one.
		var text = message || params.i18n.failed;
		if ( params.i18n.tryAgain && ownMessage( text ) === false ) {
			text += ' ' + params.i18n.tryAgain;
		}
		setMessage( text, 'error' );
		report( reason );
	}

	/**
	 * Tell the store the attempt failed. The store asks the API before it
	 * believes us — so if the payment actually settled while the browser was
	 * giving up, the shopper is forwarded instead of being shown a failure — and
	 * records the attempt on the order either way.
	 */
	function report( reason ) {
		if ( ! params.failedUrl ) {
			return;
		}
		post( params.failedUrl, { reason: reason } )
			.then( function ( res ) {
				if ( res && res.success && res.data && res.data.redirect ) {
					settled = true;
					stopPolling();
					window.location = res.data.redirect;
				}
			} )
			.catch( function () {
				// The order note is a nicety; never let it break the page.
			} );
	}

	/** Ask the store to confirm the payment against the API, then forward. */
	function finalize( statusMessage ) {
		if ( settled ) {
			return;
		}
		settled = true;
		clearAttemptTimer();
		setMessage( statusMessage || params.i18n.processing, 'info' );
		post( params.confirmUrl )
			.then( function ( res ) {
				if ( res && res.__authFailed ) {
					settled = false;
					setPaying( false );
					setMessage( params.i18n.expired, 'error' );
					return;
				}
				if ( res && res.success && res.data && res.data.redirect ) {
					// Only stop the fallback poll once the server has confirmed;
					// it is the safety net if this confirm call fails.
					stopPolling();
					window.location = res.data.redirect;
					return;
				}
				// Charged, but the API does not show it settled yet: the poll is
				// still running and will forward the shopper when it does.
				settled = false;
			} )
			.catch( function () {
				settled = false;
			} );
	}

	/**
	 * Wire the Pay button. The driver supplies only the submit itself — telling
	 * its own frame to charge — and everything around it is shared.
	 */
	function onPay( submit ) {
		if ( ! els.payButton ) {
			return;
		}
		els.payButton.addEventListener( 'click', function () {
			if ( ! beginAttempt() ) {
				return;
			}
			submit();
		} );
	}

	checkBreakout();
	window.addEventListener( 'resize', checkBreakout );

	return {
		markReady: markReady,
		setMessage: setMessage,
		setPaying: setPaying,
		onPay: onPay,
		acknowledge: acknowledge,
		awaitAction: awaitAction,
		failAttempt: failAttempt,
		finalize: finalize,
		startPolling: startPolling,
		stopPolling: stopPolling,
		isSettled: function () {
			return settled;
		},
	};
};
