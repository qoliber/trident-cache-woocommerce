/**
 * Trident personal sections — per-visitor bits on a shared, cached page.
 *
 * Platform-neutral part; a platform binding (WooCommerce, Magento, Shopware…)
 * serves the data endpoint and configures this script. The page is the same
 * for everyone; personal values are filled in here, from localStorage when the
 * stored copy is current, otherwise from the platform's endpoint (never
 * cached). "Current" means: stored for the same VERSION — the values of the
 * configured version cookies joined with "|" — and younger than `maxAge`
 * seconds. The server rotates a version cookie whenever personal data changes;
 * a platform adds its own (WooCommerce: the cart hash cookie).
 *
 * No version cookie set at all means nothing personal exists, so no request is
 * made: an anonymous visitor costs the origin nothing.
 *
 * Markup:  <span data-trident-section="customer_name" data-trident-before="Hello, " hidden></span>
 *          <div data-trident-if="logged_in" hidden>…</div>
 * Values are written with textContent — never parsed as HTML.
 *
 * Configuration (window.tridentSections, set before this script runs):
 *   endpoint        URL returning {"version": "...", "sections": {...}}
 *   versionCookies  cookie names, in the order the server joins them
 *   storageKey      localStorage key (default "trident_sections")
 *   maxAge          seconds (default 3600)
 *
 * API: window.TridentSections.refresh() — re-check after an in-page change
 * (the platform binding calls it, e.g. after an AJAX add-to-cart).
 * Event: "trident:sections" on document, detail = the sections object.
 */
( function () {
	'use strict';

	var cfg = window.tridentSections || {};
	var cookies = cfg.versionCookies || [ 'trident_pv' ];
	var storageKey = cfg.storageKey || 'trident_sections';
	var maxAge = ( cfg.maxAge || 3600 ) * 1000;

	function cookie( name ) {
		var parts = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var eq = parts[ i ].indexOf( '=' );
			if ( parts[ i ].slice( 0, eq ) === name ) {
				return decodeURIComponent( parts[ i ].slice( eq + 1 ) );
			}
		}
		return '';
	}

	function currentVersion() {
		return cookies.map( cookie ).join( '|' );
	}

	function storage() {
		try {
			var probe = '__trident';
			window.localStorage.setItem( probe, probe );
			window.localStorage.removeItem( probe );
			return window.localStorage;
		} catch ( e ) {
			return null;
		}
	}

	function render( sections ) {
		var nodes = document.querySelectorAll( '[data-trident-section]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var el = nodes[ i ];
			var key = el.getAttribute( 'data-trident-section' );
			var value = sections && Object.prototype.hasOwnProperty.call( sections, key ) ? sections[ key ] : '';
			if ( value === '' || value === null || value === false || value === 0 ) {
				el.textContent = '';
				el.hidden = true;
				continue;
			}
			el.textContent = ( el.getAttribute( 'data-trident-before' ) || '' ) + String( value ) + ( el.getAttribute( 'data-trident-after' ) || '' );
			el.hidden = false;
		}
		var toggles = document.querySelectorAll( '[data-trident-if]' );
		for ( var j = 0; j < toggles.length; j++ ) {
			toggles[ j ].hidden = ! ( sections && sections[ toggles[ j ].getAttribute( 'data-trident-if' ) ] );
		}
		document.dispatchEvent( new CustomEvent( 'trident:sections', { detail: sections || {} } ) );
	}

	function load() {
		var version = currentVersion();
		var store = storage();
		if ( version.replace( /\|/g, '' ) === '' ) {
			if ( store ) {
				store.removeItem( storageKey );
			}
			render( {} );
			return;
		}
		if ( store ) {
			try {
				var cached = JSON.parse( store.getItem( storageKey ) || 'null' );
				if ( cached && cached.version === version && ( Date.now() - cached.at ) < maxAge ) {
					render( cached.sections );
					return;
				}
			} catch ( e ) { /* corrupt entry: fetch below */ }
		}
		if ( ! cfg.endpoint || ! window.fetch ) {
			return;
		}
		window.fetch( cfg.endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( res ) {
				return res.ok ? res.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data || typeof data.sections !== 'object' ) {
					return;
				}
				render( data.sections );
				if ( store ) {
					// Stored under the version the SERVER computed from the request's
					// cookies, so a cookie that changed mid-flight is re-fetched.
					store.setItem( storageKey, JSON.stringify( { version: data.version, at: Date.now(), sections: data.sections } ) );
				}
			} )
			.catch( function () { /* personal bits are optional; the page is complete without them */ } );
	}

	window.TridentSections = { refresh: load, version: currentVersion };
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', load );
	} else {
		load();
	}
}() );
