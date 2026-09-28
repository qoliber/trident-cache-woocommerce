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
		echo $this->op->form( 'warmer', 'run' );
		printf( '<p><textarea name="sitemaps" rows="3" cols="80" class="large-text code" placeholder="%s"></textarea></p>', esc_attr( '/wp-sitemap.xml' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'Optional: sitemap URL(s) on this site, one per line (.xml or .xml.gz; an index expands). Empty: the warmer\'s configured sources.', 'trident-cache-woocommerce' ) );
		echo '<p>' . $select . '<button class="button button-primary">' . esc_html__( 'Run the warmer now', 'trident-cache-woocommerce' ) . '</button></p></form>';
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
				// Only this site's sitemaps: on a shared Trident a shop may not warm
				// another's. A path is taken as this site's.
				$sitemaps = array();
				$lines    = preg_split( '/\R/', (string) ( $post['sitemaps'] ?? '' ) );
				foreach ( false === $lines ? array() : $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$own = Purge::own_url( $line );
					if ( null === $own ) {
						Operator::notice( 'error', sprintf( 'Not a sitemap of this site: %s', $line ) );
						return;
					}
					$sitemaps[] = $own->absolute();
				}
				Operator::report( __( 'Warmer run', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->warmerRun( $sitemaps ) ), static fn ( Payload $p ): string => sprintf( '%s, %d URL(s) queued (%s)', $p->string( 'status', 'started' ), $p->int( 'queued' ), $p->string( 'source', 'config' ) ) );
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
