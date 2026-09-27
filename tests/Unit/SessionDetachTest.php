<?php
/**
 * Which cookies a shopper's cacheable render may be detached from.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentWoo\Cache\SessionDetach;
use Qoliber\TridentWoo\Cache\WooPolicy;

final class SessionDetachTest extends TestCase {

	private const CART = array( 'wp_woocommerce_session_5c0f', 'woocommerce_items_in_cart', 'woocommerce_cart_hash' );

	public function test_a_shoppers_cart_cookies_are_detached(): void {
		$names = array_merge( self::CART, array( '_ga', 'wp-settings-time-1' ) );
		self::assertSame( self::CART, self::detachable( $names ) );
	}

	/**
	 * @return array<string, array{array<int, string>}>
	 */
	public static function kept(): array {
		return array(
			'no session at all'          => array( array( '_ga' ) ),
			'logged in'                  => array( array_merge( self::CART, array( 'wordpress_logged_in_5c0f' ) ) ),
			'post password'              => array( array_merge( self::CART, array( 'wp-postpass_abc' ) ) ),
			// The history is printed into the page by the widget; hiding only
			// the cart would still store a page carrying it.
			'recently viewed history'    => array( array_merge( self::CART, array( 'woocommerce_recently_viewed' ) ) ),
			'filter-added (currency)'    => array( array_merge( self::CART, array( 'wmc_current_currency' ) ) ),
		);
	}

	/**
	 * @param array<int, string> $names Request cookie names.
	 */
	#[DataProvider( 'kept' )]
	public function test_nothing_is_detached( array $names ): void {
		self::assertSame( array(), self::detachable( $names ) );
	}

	public function test_the_detachable_set_is_part_of_the_session_set(): void {
		foreach ( SessionDetach::DETACHABLE as $prefix ) {
			self::assertContains( $prefix, WooPolicy::SESSION_COOKIES, 'a detached cookie must be one the policy treats as session' );
		}
	}

	/**
	 * @param array<int, string> $names Request cookie names.
	 * @return array<int, string>
	 */
	private static function detachable( array $names ): array {
		$session = array_merge( WooPolicy::SESSION_COOKIES, array( 'wmc_current_currency' ) );
		return SessionDetach::detachable( $names, $session, WooPolicy::LOGGED_IN_COOKIES );
	}
}
