/**
 * WooCommerce binding for the Trident personal-sections loader
 * (qoliber/trident-php assets/js/trident-sections.js).
 *
 * After an AJAX add-to-cart WooCommerce has already changed the cart hash
 * cookie — which is part of the version — so asking the loader to re-check is
 * enough: it fetches only when the version really changed.
 */
( function () {
	'use strict';
	if ( window.jQuery && window.TridentSections ) {
		window.jQuery( document.body ).on( 'added_to_cart removed_from_cart wc_fragments_refreshed', function () {
			window.TridentSections.refresh();
		} );
	}
}() );
