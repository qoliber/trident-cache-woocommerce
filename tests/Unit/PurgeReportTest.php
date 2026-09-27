<?php
/**
 * The Purge screen reports an answer Trident did not acknowledge as a failure.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Response\ClearResponse;
use Qoliber\Trident\Response\PurgeResponse;
use Qoliber\TridentWoo\Admin\Screens\Purge;

final class PurgeReportTest extends TestCase {

	public function test_an_acknowledged_purge_is_no_failure(): void {
		self::assertNull(
			Purge::failure(
				PurgeResponse::fromArray(
					array(
						'purged' => 2,
						'mode'   => 'hard',
					),
					200
				)
			)
		);
		self::assertNull(
			Purge::failure(
				ClearResponse::fromArray(
					array(
						'cleared'         => true,
						'entries_removed' => 3,
					),
					200
				)
			)
		);
	}

	public function test_a_refused_purge_is_a_failure(): void {
		$r = PurgeResponse::fromArray(
			array(
				'purged' => 0,
				'mode'   => 'soft',
				'state'  => 'refused',
			),
			200
		);
		self::assertNotNull( Purge::failure( $r ) );
	}

	public function test_an_unconfirmed_clear_is_a_failure(): void {
		self::assertSame( 'HTTP 200 — not an acknowledgement', Purge::failure( ClearResponse::fromArray( array( 'cleared' => false ), 200 ) ) );
	}
}
