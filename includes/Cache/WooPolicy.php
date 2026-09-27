<?php
/**
 * WooCommerce's configuration of the shared cacheability rules.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cache;

use Qoliber\Trident\Cache\Policy;

/**
 * The WordPress/WooCommerce lists the library's generic Policy is given.
 * The rules themselves (and why a session is served but never stored) are in
 * {@see Policy}.
 */
final class WooPolicy {

	/**
	 * Query parameters that change state or are one-off. `add-to-cart` is the
	 * important one: WooCommerce's non-AJAX "Add to cart" links are GET requests.
	 */
	public const BYPASS_QUERY = array(
		'add-to-cart',
		'wc-ajax',
		'remove_item',
		'undo_item',
		'removed_item',
		'order_again',
		'_wpnonce',
		'preview',
		'preview_id',
		'customize_changeset_uuid',
		'wc-api',
		'rest_route',
		'trident-nocache',
	);

	/** Cookie name prefixes that mean "this visitor is logged in" (the suffix is per site). */
	public const LOGGED_IN_COOKIES = array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_' );

	/**
	 * Cookie name prefixes a render READS: a page rendered for a request that
	 * carries one may contain that visitor's data, so it is sent `private,
	 * no-store` (it is still served cached pages). The WooCommerce session and
	 * cart, and the Recently Viewed history. Extend with the
	 * `trident_session_cookies` filter for every other cookie a theme or plugin
	 * personalises the HTML with (wishlists, currency switchers, …).
	 */
	public const SESSION_COOKIES = array( 'wp_woocommerce_session_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'woocommerce_recently_viewed' );

	/**
	 * @param int                $ttl             s-maxage.
	 * @param int                $swr             stale-while-revalidate.
	 * @param array<int, string> $session_cookies Session cookie prefixes (filterable).
	 * @return Policy
	 */
	public static function create( int $ttl, int $swr, array $session_cookies = self::SESSION_COOKIES ): Policy {
		return new Policy( $ttl, $swr, self::BYPASS_QUERY, self::LOGGED_IN_COOKIES, array_values( $session_cookies ) );
	}
}
