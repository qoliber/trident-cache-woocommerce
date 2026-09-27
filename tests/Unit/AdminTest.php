<?php
/**
 * The admin screens' WordPress-free logic.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentWoo\Admin\Operator;
use Qoliber\TridentWoo\Admin\Screens\Events;

final class AdminTest extends TestCase {

	public function test_live_events_never_carry_headers_cookies_or_the_client_ip(): void {
		$event = array(
			'request_id'       => 'aa9df130',
			'timestamp'        => '2026-09-26T09:50:44Z',
			'method'           => 'GET',
			'path'             => '/shop/',
			'host'             => 'localhost:8480',
			'client_ip'        => '172.21.0.7',
			'status'           => 200,
			'duration_ms'      => 66,
			'cache_status'     => 'MISS',
			'request_headers'  => array( 'cookie' => 'wordpress_logged_in_x=admin|secret' ),
			'response_headers' => array( 'set-cookie' => 'x' ),
			'cookie'           => 'wp_woocommerce_session_x=1',
			'authorization'    => 'Bearer t',
		);
		$safe = Events::safe_fields( $event );
		self::assertSame(
			array(
				'timestamp'    => '2026-09-26T09:50:44Z',
				'method'       => 'GET',
				'path'         => '/shop/',
				'host'         => 'localhost:8480',
				'status'       => 200,
				'cache_status' => 'MISS',
				'duration_ms'  => 66,
			),
			$safe
		);
		self::assertStringNotContainsString( 'secret', (string) json_encode( $safe ) );
	}

	public function test_a_list_of_scalars_is_joined_and_long_values_are_cut(): void {
		$safe = Events::safe_fields(
			array(
				'tags' => array( 'wc_shop', 'all' ),
				'path' => str_repeat( 'a', 900 ),
				'key'  => array( 'nested' => array( 'x' ) ),
			)
		);
		self::assertSame( 'wc_shop, all', $safe['tags'] );
		self::assertSame( 500, strlen( (string) $safe['path'] ) );
		self::assertArrayNotHasKey( 'key', $safe, 'a structure is not a field' );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function durations(): array {
		return array(
			'seconds' => array( 42, '42s' ),
			'minutes' => array( 125, '2m 5s' ),
			'hours'   => array( 3 * 3600 + 7 * 60, '3h 7m' ),
			'days'    => array( 2 * 86400 + 5 * 3600, '2d 5h' ),
		);
	}

	/**
	 * @param int    $seconds  Seconds.
	 * @param string $expected Text.
	 */
	#[DataProvider( 'durations' )]
	public function test_formats_durations( int $seconds, string $expected ): void {
		self::assertSame( $expected, Operator::seconds( $seconds ) );
	}
}
