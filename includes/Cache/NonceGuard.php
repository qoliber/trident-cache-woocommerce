<?php
/**
 * No guest nonce or session token may reach a shared cache entry.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cache;

/**
 * Records every nonce WordPress creates for a logged-out visitor while the page
 * renders, and after the render looks for their VALUES in the HTML.
 *
 * `wp_create_nonce()` applies `nonce_user_logged_out` for every guest nonce, so
 * nothing — WordPress, WooCommerce, a theme or another plugin — can create one
 * unseen. A value found in the page means a token the shared entry would hand
 * to everyone until it expires (or, for WooCommerce's `woocommerce*` actions,
 * to visitors whose session it does not match). Known tokens are punched out
 * first (Esi\PrivateTokens); anything left makes the response `no-store`, with
 * the action named in the debug header — safe by default for tokens nobody
 * has catalogued.
 */
final class NonceGuard {

	/** @var array<string, true> */
	private array $actions = array();

	private bool $computing = false;

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'nonce_user_logged_out', array( $this, 'record' ), 1, 2 );
	}

	/**
	 * @param int|string $uid    User id (0 for guests).
	 * @param int|string $action Nonce action.
	 * @return int|string Unchanged.
	 */
	public function record( $uid, $action ) {
		if ( ! $this->computing && ( is_string( $action ) || is_int( $action ) ) ) {
			$this->actions[ (string) $action ] = true;
		}
		return $uid;
	}

	/**
	 * Actions whose current guest nonce value appears in `$html`, and whether a
	 * Store API Cart-Token does.
	 *
	 * @param string $html Page (with punched-out fallbacks removed).
	 * @return list<string>
	 */
	public function leaks( string $html ): array {
		$found           = array();
		$this->computing = true;
		foreach ( array_keys( $this->actions ) as $action ) {
			$value = wp_create_nonce( is_numeric( $action ) ? (int) $action : $action );
			if ( '' !== $value && 1 === preg_match( '/(?<![0-9a-f])' . preg_quote( $value, '/' ) . '(?![0-9a-f])/', $html ) ) {
				$found[] = (string) $action;
			}
		}
		$this->computing = false;
		if ( str_contains( $html, 'Cart-Token' ) ) {
			$found[] = 'Cart-Token';
		}
		return $found;
	}
}
