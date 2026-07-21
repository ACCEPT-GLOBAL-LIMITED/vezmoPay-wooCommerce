/**
 * VezmoPay account settings panel (WooCommerce → Settings → Payments → VezmoPay).
 *
 * Mirrors the VezmoPay console's Settings page for the two account controls the
 * merchant API exposes: payment-method toggles (card always on; ACH / Apple Pay /
 * Google Pay) and the 3-D Secure switch. State is loaded from and saved to the
 * VezmoPay API through the store's server (admin-ajax), never from the browser.
 *
 * @package VezmoPay
 */

/* global vezmopay_admin_params */
( function () {
	'use strict';

	var panel = document.getElementById( 'vezmopay-account-panel' );
	if ( ! panel || '1' !== panel.getAttribute( 'data-configured' ) || typeof vezmopay_admin_params === 'undefined' ) {
		return;
	}

	var params = vezmopay_admin_params;
	var i18n = params.i18n;
	var METHODS = [
		{ key: 'card', label: i18n.card, desc: i18n.cardDesc },
		{ key: 'ach', label: i18n.ach, desc: i18n.achDesc },
		{ key: 'applePay', label: i18n.applePay, desc: i18n.applePayDesc },
		{ key: 'googlePay', label: i18n.googlePay, desc: i18n.googlePayDesc },
	];

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function post( data ) {
		var body = new URLSearchParams();
		body.append( 'nonce', params.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return window
			.fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} );
	}

	function pill( text, kind ) {
		return el( 'span', 'vezmopay-pill ' + ( kind || 'brand' ), text );
	}

	/**
	 * A console-style switch. options: { on, interactive, locked, onToggle }.
	 */
	function makeToggle( options ) {
		var btn = el( 'button', 'vezmopay-toggle' + ( options.on ? ' is-on' : '' ) + ( options.interactive ? '' : ' is-disabled' ) );
		btn.type = 'button';
		btn.setAttribute( 'role', 'switch' );
		btn.setAttribute( 'aria-checked', options.on ? 'true' : 'false' );
		var knob = el( 'span', 'vezmopay-toggle-knob' );
		if ( options.locked ) {
			knob.appendChild( el( 'span', 'vezmopay-knob-lock' ) );
		}
		btn.appendChild( knob );
		if ( options.interactive ) {
			btn.addEventListener( 'click', function () {
				options.onToggle( btn );
			} );
		} else {
			btn.disabled = true;
		}
		return btn;
	}

	function setToggle( btn, on, saving ) {
		btn.classList.toggle( 'is-on', on );
		btn.classList.toggle( 'is-saving', !! saving );
		btn.setAttribute( 'aria-checked', on ? 'true' : 'false' );
	}

	function card( title ) {
		var box = el( 'div', 'vezmopay-settings-card' );
		box.appendChild( el( 'div', 'vezmopay-settings-card-title', title ) );
		return box;
	}

	function errorNote( parent, message ) {
		var note = el( 'p', 'vezmopay-inline-error', message );
		parent.appendChild( note );
		window.setTimeout( function () {
			if ( note.parentNode ) {
				note.parentNode.removeChild( note );
			}
		}, 6000 );
	}

	/* ------------------------------------------------------------------ */

	function renderPaymentMethods( container, block ) {
		var box = card( i18n.paymentMethods );
		if ( ! block.ok ) {
			box.appendChild( el( 'p', 'description', block.message || i18n.loadFailed ) );
			container.appendChild( box );
			return;
		}
		box.appendChild( el( 'p', 'description', i18n.pmIntro ) );

		var state = block.data && block.data.methods ? block.data.methods : {};

		METHODS.forEach( function ( method ) {
			var entry = state[ method.key ] || {};
			var isCard = 'card' === method.key;
			var enabled = isCard ? true : true === entry.enabled;
			var eligible = isCard ? true : true === entry.eligible;
			var blockedByAdmin = ! isCard && true === entry.blockedByAdmin;
			var toggleable = ! isCard && true === entry.toggleable;

			var row = el( 'div', 'vezmopay-method-row' );
			var info = el( 'div', 'vezmopay-method-info' );
			var head = el( 'div', 'vezmopay-method-label', method.label );
			if ( isCard ) {
				head.appendChild( pill( i18n.alwaysOn, 'brand' ) );
			} else if ( blockedByAdmin ) {
				head.appendChild( pill( i18n.managed, 'brand' ) );
			} else if ( ! eligible ) {
				head.appendChild( pill( i18n.notAvailable, 'amber' ) );
			}
			info.appendChild( head );
			var desc = method.desc + ( ! isCard && ! eligible && ! blockedByAdmin ? ' ' + i18n.verifyHint : '' );
			info.appendChild( el( 'p', 'vezmopay-method-desc', desc ) );
			row.appendChild( info );

			if ( ! isCard ) {
				var toggle = makeToggle( {
					on: enabled,
					interactive: toggleable,
					locked: ! toggleable,
					onToggle: function ( btn ) {
						if ( btn.classList.contains( 'is-saving' ) ) {
							return;
						}
						var next = ! btn.classList.contains( 'is-on' );
						setToggle( btn, next, true );
						post( { action: 'vezmopay_account_update', kind: 'payment-methods', method: method.key, enabled: next ? '1' : '0' } )
							.then( function ( res ) {
								if ( ! res || ! res.success ) {
									setToggle( btn, ! next, false );
									errorNote( box, i18n.updateFailed + ( res && res.data && res.data.message ? res.data.message : '' ) );
								} else {
									setToggle( btn, next, false );
								}
							} )
							.catch( function () {
								setToggle( btn, ! next, false );
								errorNote( box, i18n.updateFailed );
							} );
					},
				} );
				row.appendChild( toggle );
			}
			box.appendChild( row );
		} );

		box.appendChild( el( 'p', 'vezmopay-card-footnote', i18n.pmFooter ) );
		container.appendChild( box );
	}

	function renderThreeDs( container, block ) {
		var box = card( i18n.threeDs );
		if ( ! block.ok ) {
			box.appendChild( el( 'p', 'description', block.message || i18n.loadFailed ) );
			container.appendChild( box );
			return;
		}

		var data = block.data || {};
		var on = 'on' === data.mode;
		var locked = false === data.controlAllowed;

		var row = el( 'div', 'vezmopay-method-row' );
		var info = el( 'div', 'vezmopay-method-info' );
		var head = el( 'div', 'vezmopay-method-label', on ? i18n.threeDsOn : i18n.threeDsOff );
		if ( locked ) {
			head.appendChild( pill( i18n.managed, 'brand' ) );
		}
		info.appendChild( head );
		var desc = el( 'p', 'vezmopay-method-desc', on ? i18n.threeDsOnDesc : i18n.threeDsOffDesc );
		info.appendChild( desc );
		row.appendChild( info );

		var toggle = makeToggle( {
			on: on,
			interactive: ! locked,
			locked: locked,
			onToggle: function ( btn ) {
				if ( btn.classList.contains( 'is-saving' ) ) {
					return;
				}
				var next = ! btn.classList.contains( 'is-on' );
				setToggle( btn, next, true );
				post( { action: 'vezmopay_account_update', kind: '3ds', mode: next ? 'on' : 'auto' } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							setToggle( btn, ! next, false );
							errorNote( box, i18n.updateFailed + ( res && res.data && res.data.message ? res.data.message : '' ) );
						} else {
							setToggle( btn, next, false );
							head.textContent = next ? i18n.threeDsOn : i18n.threeDsOff;
							desc.textContent = next ? i18n.threeDsOnDesc : i18n.threeDsOffDesc;
						}
					} )
					.catch( function () {
						setToggle( btn, ! next, false );
						errorNote( box, i18n.updateFailed );
					} );
			},
		} );
		row.appendChild( toggle );
		box.appendChild( row );

		if ( locked ) {
			box.appendChild( el( 'p', 'vezmopay-card-footnote', i18n.threeDsLocked ) );
		}
		box.appendChild( el( 'p', 'vezmopay-card-footnote', i18n.threeDsNote ) );
		container.appendChild( box );
	}

	/* ------------------------------------------------------------------ */

	panel.appendChild( el( 'p', 'description vezmopay-account-loading', i18n.loading ) );

	post( { action: 'vezmopay_account_get' } )
		.then( function ( res ) {
			panel.textContent = '';
			if ( ! res || ! res.success || ! res.data ) {
				panel.appendChild( el( 'p', 'description', ( res && res.data && res.data.message ) || i18n.loadFailed ) );
				return;
			}
			renderPaymentMethods( panel, res.data.methods || { ok: false } );
			renderThreeDs( panel, res.data.threeDs || { ok: false } );
		} )
		.catch( function () {
			panel.textContent = '';
			panel.appendChild( el( 'p', 'description', i18n.loadFailed ) );
		} );
} )();
