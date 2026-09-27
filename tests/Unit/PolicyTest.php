<?php
/**
 * WooCommerce's lists applied through the library's Policy.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Cache\RequestContext;
use Qoliber\TridentWoo\Cache\WooPolicy;

final class PolicyTest extends TestCase {

	/**
	 * @return array<string, array{RequestContext, string}>
	 */
	public static function never_stored(): array {
		return array(
			'GET add-to-cart link'          => array( new RequestContext( query: array( 'add-to-cart' => '35' ) ), 'query add-to-cart' ),
			'wc-ajax'                       => array( new RequestContext( query: array( 'wc-ajax' => 'get_refreshed_fragments' ) ), 'query wc-ajax' ),
			'rest_route'                    => array( new RequestContext( query: array( 'rest_route' => '/wc/store/v1/cart' ) ), 'query rest_route' ),
			'nonce'                         => array( new RequestContext( query: array( '_wpnonce' => 'abc' ) ), 'query _wpnonce' ),
			'customizer preview'            => array( new RequestContext( query: array( 'customize_changeset_uuid' => 'x' ) ), 'query customize_changeset_uuid' ),
			'logged-in cookie (per-site suffix)' => array( new RequestContext( cookieNames: array( 'wordpress_logged_in_5c0f' ) ), 'logged-in' ),
			'secure auth cookie'            => array( new RequestContext( cookieNames: array( 'wordpress_sec_5c0f' ) ), 'logged-in' ),
			'post password cookie'          => array( new RequestContext( cookieNames: array( 'wp-postpass_abc' ) ), 'logged-in' ),
			'comment author cookie'         => array( new RequestContext( cookieNames: array( 'comment_author_abc' ) ), 'logged-in' ),
			'woocommerce session'           => array( new RequestContext( cookieNames: array( 'wp_woocommerce_session_abc' ) ), 'session' ),
			'items in cart'                 => array( new RequestContext( cookieNames: array( 'woocommerce_items_in_cart' ) ), 'session' ),
			'cart hash'                     => array( new RequestContext( cookieNames: array( 'woocommerce_cart_hash' ) ), 'session' ),
			'recently viewed history'       => array( new RequestContext( cookieNames: array( 'woocommerce_recently_viewed' ) ), 'session' ),
		);
	}

	#[DataProvider( 'never_stored' )]
	public function test_woocommerce_lists( RequestContext $ctx, string $reason ): void {
		$d = WooPolicy::create( 3600, 86400 )->decide( $ctx );
		self::assertFalse( $d->cacheable );
		self::assertSame( $reason, $d->reason );
	}

	public function test_catalogue_page_with_harmless_cookies_is_public(): void {
		$d = WooPolicy::create( 1800, 600 )->decide( new RequestContext( cookieNames: array( '_ga', 'wp-settings-1', 'trident_pv' ), query: array( 'orderby' => 'price' ) ) );
		self::assertTrue( $d->cacheable );
		self::assertSame( 'public, max-age=0, s-maxage=1800, stale-while-revalidate=600', $d->cacheControl );
	}

	public function test_session_cookie_list_is_filterable(): void {
		$policy = WooPolicy::create( 60, 0, array( 'my_wishlist_session' ) );
		self::assertFalse( $policy->decide( new RequestContext( cookieNames: array( 'my_wishlist_session' ) ) )->cacheable );
		self::assertTrue( $policy->decide( new RequestContext( cookieNames: array( 'woocommerce_items_in_cart' ) ) )->cacheable );
	}
}
