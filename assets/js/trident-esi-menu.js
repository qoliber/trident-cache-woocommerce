/**
 * Trident ESI menus — restore `current-menu-item` on a shared menu fragment.
 *
 * A menu served as an ESI fragment is one cache entry for every page, so it
 * cannot know which page it is on; WordPress's `current-menu-item` /
 * `current_page_item` classes are added here, by comparing each link with the
 * current URL.
 */
( function () {
	'use strict';

	function normalise( href ) {
		try {
			var url = new URL( href, window.location.href );
			return url.origin + url.pathname.replace( /\/+$/, '' ) + url.search;
		} catch ( e ) {
			return '';
		}
	}

	function mark() {
		var here = normalise( window.location.href );
		var links = document.querySelectorAll( '.menu-item > a[href]' );
		for ( var i = 0; i < links.length; i++ ) {
			var item = links[ i ].parentNode;
			if ( normalise( links[ i ].getAttribute( 'href' ) ) === here ) {
				item.classList.add( 'current-menu-item', 'current_page_item' );
				links[ i ].setAttribute( 'aria-current', 'page' );
				for ( var p = item.parentNode; p && p.classList; p = p.parentNode ) {
					if ( p.classList.contains( 'menu-item' ) ) {
						p.classList.add( 'current-menu-ancestor' );
					}
				}
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mark );
	} else {
		mark();
	}
}() );
