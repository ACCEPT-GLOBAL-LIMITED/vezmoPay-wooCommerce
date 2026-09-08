/**
 * VezmoPay payment method for the WooCommerce Checkout Block.
 *
 * Every VezmoPay mode finalizes payment after a server-side redirect (pay page or
 * hosted checkout), so the Blocks tile is informational: title, description and a
 * test-mode badge. process_payment() on the server performs the redirect.
 *
 * @package VezmoPay
 */

( function () {
	'use strict';

	// Bail safely if any Blocks dependency is missing — throwing here would
	// break the whole checkout's payment step ("no payment method available").
	if (
		! window.wc ||
		! window.wc.wcBlocksRegistry ||
		! window.wc.wcSettings ||
		! window.wp ||
		! window.wp.element
	) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var createElement = window.wp.element.createElement;
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	var settings = getSetting( 'vezmopay_data', {} );
	var labelText = decodeEntities( settings.title || __( 'VezmoPay', 'vezmopay-woocommerce' ) );

	// Mark FIRST, then the title — the same order as the classic checkout row,
	// where get_icon() puts the image before the label with `order:-1`. The icon
	// is decorative (the title carries the name), so it stays aria-hidden.
	function Label() {
		var children = [];
		if ( settings.icon ) {
			children.push(
				createElement( 'img', {
					key: 'icon',
					src: settings.icon,
					alt: '',
					'aria-hidden': 'true',
					style: { height: '20px', width: 'auto', marginRight: '8px' },
				} )
			);
		}
		children.push( createElement( 'span', { key: 'text' }, labelText ) );
		return createElement(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center' } },
			children
		);
	}

	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;

	/**
	 * Hosted mode, and the fallback when the inline script is unavailable: an
	 * informational tile. The redirect happens server-side after the order is
	 * created.
	 */
	function Notice() {
		var children = [];
		if ( settings.description ) {
			children.push(
				createElement( 'span', { key: 'desc' }, decodeEntities( settings.description ) )
			);
		}
		if ( settings.testMode ) {
			children.push(
				createElement(
					'strong',
					{ key: 'test', className: 'vezmopay-blocks-test-badge' },
					' ' + __( '(Test mode)', 'vezmopay-woocommerce' )
				)
			);
		}
		return createElement( 'span', null, children );
	}

	/**
	 * Inline / iframe modes: mount the SAME VezmoPay form the classic checkout
	 * uses, right here in the Blocks payment method, and charge it after Blocks
	 * has created the order — so the shopper presses the block checkout's own
	 * "Place order" button and never leaves the page.
	 *
	 * The order is created BEFORE the charge (the Store API runs process_payment
	 * server-side, which answers with a '#vezmopay-charge:…' marker), and the
	 * store — not this component — decides whether the payment really settled.
	 */
	function InlineContent( props ) {
		var hostRef = useRef( null );
		var inline = window.VezmoPayInline;

		useEffect( function () {
			if ( ! inline || ! hostRef.current ) {
				return;
			}
			inline.mountInto( hostRef.current );
			return function () {
				inline.unmount();
			};
		}, [ inline ] );

		// Block the order while the form has not finished loading, instead of
		// creating an order we cannot charge.
		useEffect( function () {
			if ( ! inline || ! props.eventRegistration ) {
				return;
			}
			return props.eventRegistration.onPaymentSetup( function () {
				if ( ! inline.hasSession() ) {
					return { type: 'error', message: inline.messages.unavailable };
				}
				if ( ! inline.isReady() ) {
					return { type: 'error', message: inline.messages.incomplete };
				}
				return { type: 'success' };
			} );
		}, [ inline, props.eventRegistration ] );

		// The order now exists. Drive the charge, then follow the store's own
		// confirmed redirect rather than the marker.
		useEffect( function () {
			if ( ! inline || ! props.eventRegistration ) {
				return;
			}
			return props.eventRegistration.onCheckoutSuccess( function ( data ) {
				// The Store API drops a hash-only redirect_url, but it passes the
				// gateway's whole result through as paymentDetails — so that is
				// where the marker survives on the Blocks checkout. Accept either
				// shape: an object keyed by name, or the raw key/value list.
				var details = ( data && data.processingResponse && data.processingResponse.paymentDetails ) || {};
				var fromDetails = '';
				if ( Array.isArray( details ) ) {
					details.forEach( function ( row ) {
						if ( row && 'redirect' === row.key ) {
							fromDetails = row.value;
						}
					} );
				} else if ( details.redirect ) {
					fromDetails = details.redirect;
				}
				var marker = inline.parseMarker( fromDetails || ( data && data.redirectUrl ) || '' );
				if ( ! marker ) {
					return true; // hosted / fallback redirect — let Blocks follow it.
				}
				return inline
					.charge( marker.orderId, marker.orderKey )
					.then( function ( url ) {
						window.location.href = url;
						return { type: 'success', redirectUrl: url };
					} )
					.catch( function ( message ) {
						return {
							type: 'error',
							message: message || inline.messages.failed,
							messageContext: 'wc/checkout/payments',
						};
					} );
			} );
		}, [ inline, props.eventRegistration ] );

		var children = [];
		if ( settings.testMode ) {
			children.push(
				createElement(
					'strong',
					{ key: 'test', className: 'vezmopay-blocks-test-badge' },
					__( 'Test mode — no real money will move.', 'vezmopay-woocommerce' )
				)
			);
		}
		children.push(
			createElement( 'div', {
				key: 'host',
				ref: hostRef,
				className: 'vezmopay-inline-container',
			} )
		);
		// Same button the classic checkout renders server-side: the embedded form
		// hides its own submit, so the payment area gets one here. It places the
		// order, exactly as the block checkout's own button does.
		children.push(
			createElement(
				'button',
				{ key: 'pay', type: 'button', className: 'vezmopay-inline-pay', hidden: true },
				createElement( 'span', { className: 'vezmopay-inline-pay-label' }, __( 'Pay', 'vezmopay-woocommerce' ) )
			)
		);
		children.push(
			createElement( 'p', {
				key: 'msg',
				className: 'vezmopay-inline-message',
				role: 'status',
				'aria-live': 'polite',
			} )
		);

		return createElement( 'div', { className: 'vezmopay-inline is-blocks' }, children );
	}

	function Content( props ) {
		// Hosted mode never embeds. Without the inline script (blocked, or an
		// older cached copy) fall back to the tile + server-side redirect, which
		// still completes a payment.
		if ( 'hosted' === settings.mode || ! window.VezmoPayInline ) {
			return createElement( Notice );
		}
		return createElement( InlineContent, props );
	}

	registerPaymentMethod( {
		name: 'vezmopay',
		label: createElement( Label ),
		ariaLabel: labelText,
		content: createElement( Content ),
		edit: createElement( Notice ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
