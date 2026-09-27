<?php
/**
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Esi\FragmentUrl;
use Qoliber\TridentWoo\Esi\PrivateTokens;
use Qoliber\TridentWoo\Esi\SharedBlocks;
use Qoliber\TridentWoo\Secret;

final class SecretAndEsiTest extends TestCase {

	public function test_token_round_trips_and_is_not_stored_in_clear(): void {
		$secret = new Secret( 'site-keys-1' );
		$stored = $secret->encrypt( 'e2e-token-do-not-use' );
		self::assertStringStartsWith( 'tv1:', $stored );
		self::assertStringNotContainsString( 'e2e-token', $stored );
		self::assertNotSame( $stored, $secret->encrypt( 'e2e-token-do-not-use' ), 'random nonce' );
		self::assertSame( 'e2e-token-do-not-use', $secret->decrypt( $stored ) );
	}

	public function test_rotated_keys_or_tampering_are_detected(): void {
		$stored = ( new Secret( 'old-keys' ) )->encrypt( 'token' );
		self::assertNull( ( new Secret( 'new-keys' ) )->decrypt( $stored ) );
		self::assertNull( ( new Secret( 'old-keys' ) )->decrypt( substr( $stored, 0, -2 ) . 'AA' ) );
		self::assertNull( ( new Secret( 'k' ) )->decrypt( 'plain-text-token' ) );
		self::assertSame( '', ( new Secret( 'k' ) )->decrypt( '' ) );
		self::assertSame( '', ( new Secret( 'k' ) )->encrypt( '' ) );
	}

	public function test_key_material_is_outside_the_database_only_with_real_constants(): void {
		self::assertTrue( Secret::keys_in_config( array( 'AUTH_KEY' => 'k7#Lq…', 'AUTH_SALT' => 'z@9…' ) ) );
		self::assertFalse( Secret::keys_in_config( array( 'AUTH_SALT' => 'z@9…' ) ), 'AUTH_KEY missing: WordPress stores one in the database' );
		self::assertFalse( Secret::keys_in_config( array( 'AUTH_KEY' => 'k7#Lq…' ) ) );
		self::assertFalse( Secret::keys_in_config( array( 'AUTH_KEY' => 'put your unique phrase here', 'AUTH_SALT' => 'x' ) ), 'the sample value' );
		self::assertFalse( Secret::keys_in_config( array( 'AUTH_KEY' => '', 'AUTH_SALT' => 'x' ) ) );
	}

	public function test_menu_args_travel_in_the_url_only_when_scalar(): void {
		$args = SharedBlocks::portable_args(
			array(
				'theme_location'  => 'primary',
				'container_class' => 'primary-navigation',
				'depth'           => 0,
				'echo'            => true,
				'walker'          => '',
				'fallback_cb'     => 'wp_page_menu',
				'menu'            => '',
			)
		);
		self::assertSame(
			array(
				'container_class' => 'primary-navigation',
				'depth'           => 0,
				'fallback_cb'     => 'wp_page_menu',
			),
			$args
		);
		self::assertSame( $args, FragmentUrl::decode( FragmentUrl::encode( (array) $args ) ) );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', FragmentUrl::encode( (array) $args ), 'URL-safe' );
		self::assertNull( SharedBlocks::portable_args( array( 'walker' => new \stdClass() ) ) );
		self::assertNull( SharedBlocks::portable_args( array( 'fallback_cb' => static fn () => '' ) ) );
	}

	public function test_cart_preload_is_removed_and_other_preloads_kept(): void {
		$requests = array(
			'/wc/store/v1/cart'     => array(
				'body'    => array( 'items' => array() ),
				'headers' => array(
					'Nonce'      => 'abcdef1234',
					'Cart-Token' => 'eyJ0eXAi.x.y',
				),
			),
			'/wc/store/v1/products' => array( 'body' => array() ),
		);
		$script   = "var wcSettings = {};wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( JSON.parse( decodeURIComponent( '" . rawurlencode( (string) json_encode( $requests ) ) . "' ) ) ) );";
		$out      = PrivateTokens::strip_cart_preload( '<script id="wc-settings-js-before">' . $script . '</script>' );
		self::assertStringNotContainsString( 'Cart-Token', rawurldecode( $out ) );
		self::assertStringNotContainsString( 'abcdef1234', rawurldecode( $out ) );
		self::assertStringContainsString( 'store\\/v1\\/products', rawurldecode( $out ) );
		self::assertStringContainsString( 'var wcSettings = {};', $out );

		$only_cart = "wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( JSON.parse( decodeURIComponent( '" . rawurlencode( (string) json_encode( array( '/wc/store/v1/cart' => array() ) ) ) . "' ) ) ) );";
		self::assertSame( 'x', PrivateTokens::strip_cart_preload( 'x' . $only_cart ) );
	}
}
