/**
 * Registro de "Paga con QuickPay" en la caja por bloques.
 * JavaScript plano (sin compilación): usa los globales que WooCommerce ya carga.
 */
( function () {
	'use strict';
	var wc = window.wc || {};
	if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}
	var datos = wc.wcSettings.getSetting( 'quickpay_data', {} );
	var el = window.wp.element.createElement;
	var decodificar = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var titulo = decodificar( datos.title || 'Paga con QuickPay' );

	var Contenido = function () {
		return el( 'div', { className: 'quickpay-descripcion' }, decodificar( datos.description || '' ) );
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'quickpay',
		label: el( 'span', null, titulo ),
		ariaLabel: titulo,
		content: el( Contenido ),
		edit: el( Contenido ),
		canMakePayment: function () { return true; },
		supports: { features: datos.supports || [ 'products' ] },
	} );
} )();
