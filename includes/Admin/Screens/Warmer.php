<?php
/**
 * Trident Cache → Warmer.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Payload;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * Each instance's warmer: its state, schedule and last run; run the
 * configured sources now, cancel a run, or warm this shop's own pages.
 */
final class Warmer extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'warmer';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Warmer', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 50;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'run'       => false,
			'cancel'    => false,
			'catalogue' => false,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): Payload => $c->warmerStatus() );
		Operator::problems( $results, __( 'the cache warmer', 'trident-cache-woocommerce' ) );
		$select = $this->op->instance_select();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- details()/button()/form() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			printf( '<h2>%s — %s</h2>', esc_html( $result->name() ), esc_html( $result->value->string( 'state', '?' ) ) );
			echo Operator::details( $result->value->all() );
		}
		echo '<h2>' . esc_html__( 'Actions', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'warmer', 'run', 'display:inline-block;margin-right:1em' ) . $select . '<button class="button button-primary">' . esc_html__( 'Run the configured sources now', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'warmer', 'cancel', 'display:inline-block;margin-right:1em' ) . $select . '<button class="button">' . esc_html__( 'Cancel the current run', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'warmer', 'catalogue', 'display:inline-block' ) . $select . '<button class="button">' . esc_html__( 'Warm this shop\'s pages', 'trident-cache-woocommerce' ) . '</button></form>';
		// phpcs:enable
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		$fleet   = $this->op->fleet();
		$targets = Operator::targets( $post );
		switch ( $op ) {
			case 'run':
				Operator::report( __( 'Warmer run', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->warmerRun() ), static fn ( Payload $p ): string => sprintf( '%s, %d URL(s) queued', $p->string( 'status', 'started' ), $p->int( 'queued' ) ) );
				return;
			case 'cancel':
				Operator::report( __( 'Warmer cancel', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->warmerCancel() ), static fn ( Payload $p ): string => $p->string( 'status', 'cancelled' ) );
				return;
			case 'catalogue':
				$urls = Coverage::catalogue();
				Operator::report( __( 'Warm this shop', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->warmerQueue( $urls ) ), static fn ( Payload $p ): string => sprintf( '%d URL(s) queued', $p->int( 'received', $p->int( 'queued', count( $urls ) ) ) ) );
				return;
		}
	}
}
