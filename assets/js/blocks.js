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

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var createElement = window.wp.element.createElement;
	var __ = window.wp.i18n.__;

	var settings = getSetting( 'vezmopay_data', {} );
	var labelText = decodeEntities( settings.title || __( 'VezmoPay', 'vezmopay-woocommerce' ) );

	function Label() {
		var children = [
			createElement( 'span', { key: 'text' }, labelText ),
		];
		if ( settings.icon ) {
			children.push(
				createElement( 'img', {
					key: 'icon',
					src: settings.icon,
					alt: '',
					'aria-hidden': 'true',
					style: { height: '20px', width: 'auto', marginLeft: '8px' },
				} )
			);
		}
		return createElement(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', width: '100%', justifyContent: 'space-between' } },
			children
		);
	}

	function Content() {
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

	registerPaymentMethod( {
		name: 'vezmopay',
		label: createElement( Label ),
		ariaLabel: labelText,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
