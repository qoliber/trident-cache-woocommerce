<?php
/**
 * Trident Cache → Dashboard.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Operator;
use Qoliber\TridentWoo\Plugin;

/**
 * Each instance's health and hit rate, memory, latency and backends, and this
 * site's purge queue.
 */
final class Dashboard extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return '';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Dashboard', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 0;
	}

	/**
	 * One instance's dashboard figures. The status read decides whether the
	 * instance answers at all; the rest are read one by one, so a single
	 * failing endpoint blanks one figure, not the instance.
	 *
	 * @param TridentClient $client Client.
	 * @return array<string, mixed>
	 */
	public static function read( TridentClient $client ): array {
		$status = $client->status();
		return array(
			'status'   => $status,
			'stats'    => Fleet::attempt( static fn () => $client->stats() ),
			'latency'  => Fleet::attempt( static fn () => $client->latencyStats() ),
			'memory'   => Fleet::attempt( static fn () => $client->memoryStats() ),
			'backends' => Fleet::attempt( static fn () => $client->backends() ),
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): array => self::read( $c ) );
		if ( array() === $results ) {
			printf( '<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>', esc_html__( 'No Trident instance is configured.', 'trident-cache-woocommerce' ), esc_url( Operator::url( 'settings' ) ), esc_html__( 'Settings', 'trident-cache-woocommerce' ) );
		}
		Operator::problems( $results, __( 'this', 'trident-cache-woocommerce' ) );

		echo '<h2>' . esc_html__( 'Instances', 'trident-cache-woocommerce' ) . '</h2>';
		echo Operator::table( array( __( 'Instance', 'trident-cache-woocommerce' ), __( 'Status', 'trident-cache-woocommerce' ), __( 'Hit rate', 'trident-cache-woocommerce' ), __( 'Hits / misses / passes', 'trident-cache-woocommerce' ), __( 'Entries', 'trident-cache-woocommerce' ), __( 'Cache memory', 'trident-cache-woocommerce' ), __( 'Process RSS', 'trident-cache-woocommerce' ), __( 'Latency p50 / p95 / p99', 'trident-cache-woocommerce' ), __( 'Backends', 'trident-cache-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- table() escapes.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				echo Operator::row( array( esc_html( $result->name() ), '<span class="trident-bad">' . esc_html( $result->isUnreachable() ? __( 'unreachable', 'trident-cache-woocommerce' ) : __( 'error', 'trident-cache-woocommerce' ) ) . '</span>', '-', '-', '-', '-', '-', '-', '-' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped.
				continue;
			}
			$v       = $result->value;
			$status  = $v['status'];
			$stats   = $v['stats'];
			$latency = $v['latency']?->latency;
			$memory  = $v['memory'];
			$back    = $v['backends'];
			echo Operator::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped.
				array(
					esc_html( $result->name() ),
					sprintf( '<span class="trident-ok">%s</span> %s<br><small>%s</small>', esc_html( $status->string( 'status', 'ok' ) ), esc_html( $status->string( 'version' ) ), esc_html( sprintf( 'license: %s', $status->string( 'license', '-' ) ) ) ),
					null === $stats ? '-' : '<strong>' . esc_html( number_format_i18n( $stats->getHitRatioPercent(), 1 ) ) . '%</strong>',
					null === $stats ? '-' : esc_html( sprintf( '%s / %s / %s', number_format_i18n( $stats->hits ), number_format_i18n( $stats->misses ), number_format_i18n( $stats->passes ) ) ),
					null === $stats ? '-' : esc_html( number_format_i18n( $stats->entries ) ),
					null === $stats ? '-' : esc_html( sprintf( '%s of %s', Operator::bytes( $stats->memoryUsed ), Operator::bytes( $stats->maxMemory ) ) ),
					null === $memory ? '-' : esc_html( Operator::bytes( $memory->rssBytes > 0 ? $memory->rssBytes : $memory->totalBytes ) ),
					is_array( $latency ) ? esc_html( sprintf( '%s / %s / %s ms', $latency['p50_ms'] ?? '-', $latency['p95_ms'] ?? '-', $latency['p99_ms'] ?? '-' ) ) : '-',
					null === $back ? '-' : esc_html( sprintf( '%d healthy of %d', $back->healthy, $back->total ) ),
				)
			);
		}
		if ( array() === $results ) {
			echo Operator::empty_row( 9, __( 'No Trident instance configured.', 'trident-cache-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '</tbody></table>';

		$this->render_queue();
	}

	/**
	 * This site's purge outbox.
	 *
	 * @return void
	 */
	private function render_queue(): void {
		$stats = $this->op->plugin->store->stats( time() );
		echo '<h2>' . esc_html__( 'Purge queue', 'trident-cache-woocommerce' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					'Pending: %d · oldest: %s · last failure: %s',
					$stats['pending'],
					null === $stats['oldest_age'] ? '-' : Operator::seconds( (int) $stats['oldest_age'] ),
					null === $stats['last_error'] ? '-' : $stats['last_error'] . ' (' . human_time_diff( (int) $stats['last_error_at'] ) . ' ago)'
				)
			)
		);
		if ( ( $stats['oldest_age'] ?? 0 ) > Plugin::STALE_AFTER ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html__( 'Purges have been pending for over 15 minutes: customers may see old prices or stock.', 'trident-cache-woocommerce' ) );
		}
		if ( array() !== $stats['by_instance'] ) {
			echo Operator::table( array( __( 'Instance', 'trident-cache-woocommerce' ), __( 'Pending', 'trident-cache-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $stats['by_instance'] as $name => $count ) {
				echo Operator::row( array( esc_html( (string) $name ), esc_html( (string) (int) $count ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		printf( '<p><a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p>', esc_url( Operator::url( 'purge' ) ), esc_html__( 'Purge…', 'trident-cache-woocommerce' ), esc_url( Operator::url( 'settings' ) ), esc_html__( 'Settings and delivery', 'trident-cache-woocommerce' ) );
	}
}
