<?php
/**
 * Trident Cache → Reflect mode.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Admin\Payload;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * Reflect mode: while the shop is struggling, Trident serves what it has and
 * defers purges. Enabling and disabling change what every visitor gets and
 * when purges land, so both need the confirm box.
 */
final class Reflect extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'reflect';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Reflect mode', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 70;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'enable'  => true,
			'disable' => true,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each(
			static fn ( TridentClient $c ): array => array(
				'status' => $c->reflectStatus(),
				'queue'  => Fleet::attempt( static fn () => $c->reflectQueue() ),
			)
		);
		Operator::problems( $results, __( 'reflect mode', 'trident-cache-woocommerce' ) );
		$select = $this->op->instance_select();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- details()/form()/confirm() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$status = $result->value['status'];
			printf( '<h2>%s — %s</h2>', esc_html( $result->name() ), esc_html( $status->bool( 'enabled' ) || $status->bool( 'active' ) ? __( 'reflecting', 'trident-cache-woocommerce' ) : __( 'off', 'trident-cache-woocommerce' ) ) );
			echo Operator::details( $status->all() );
			$queue = $result->value['queue'];
			if ( null !== $queue ) {
				printf( '<p>%s</p>', esc_html( sprintf( 'Deferred purges: %d', $queue->int( 'total', count( $queue->rows( 'items' ) ) ) ) ) );
			}
		}
		echo '<h2>' . esc_html__( 'Enable', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'reflect', 'enable' );
		echo '<p><select name="level"><option value="">' . esc_html__( 'configured default', 'trident-cache-woocommerce' ) . '</option><option value="full">full</option><option value="selective">selective</option><option value="ttl_extension">ttl_extension</option></select> ';
		echo '<input type="text" name="duration" placeholder="30m" size="6"> <input type="text" name="reason" placeholder="' . esc_attr__( 'Reason', 'trident-cache-woocommerce' ) . '"> ' . $select . '</p>';
		echo '<p>' . Operator::confirm( __( 'Serve cached pages and defer purges on these instances', 'trident-cache-woocommerce' ) ) . '<button class="button button-primary">' . esc_html__( 'Enable reflect mode', 'trident-cache-woocommerce' ) . '</button></p></form>';
		echo '<h2>' . esc_html__( 'Disable', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'reflect', 'disable' );
		echo '<p><select name="mode"><option value="replay">' . esc_html__( 'replay — run the deferred purges', 'trident-cache-woocommerce' ) . '</option><option value="hard">' . esc_html__( 'hard — soft-purge what was deferred', 'trident-cache-woocommerce' ) . '</option></select> ' . $select . '</p>';
		echo '<p>' . Operator::confirm( __( 'Stop reflecting and apply the deferred purges', 'trident-cache-woocommerce' ) ) . '<button class="button">' . esc_html__( 'Disable reflect mode', 'trident-cache-woocommerce' ) . '</button></p></form>';
		// phpcs:enable
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		$fleet    = $this->op->fleet();
		$targets  = Operator::targets( $post );
		$describe = static fn ( Payload $p ): string => $p->string( 'status', $p->string( 'message', 'ok' ) );
		if ( 'enable' === $op ) {
			$level    = in_array( $post['level'] ?? '', array( 'full', 'selective', 'ttl_extension' ), true ) ? $post['level'] : null;
			$duration = 1 === preg_match( '/^\d+[smhd]$/', (string) ( $post['duration'] ?? '' ) ) ? $post['duration'] : null;
			$reason   = '' !== ( $post['reason'] ?? '' ) ? $post['reason'] : null;
			Operator::report( __( 'Reflect enable', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->reflectEnable( $level, $duration, $reason ) ), $describe );
		} elseif ( 'disable' === $op ) {
			$mode = 'hard' === ( $post['mode'] ?? '' ) ? 'hard' : 'replay';
			Operator::report( __( 'Reflect disable', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->reflectDisable( $mode ) ), $describe );
		}
	}
}
