<?php
/**
 * Trident Cache → Backends.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\BackendActionResponse;
use Qoliber\Trident\Response\BackendsResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * The origins each instance forwards to: health, traffic and errors; drain one
 * for maintenance (confirmed — its traffic moves elsewhere or stops) and
 * restore it.
 */
final class Backends extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'backends';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Backends', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 100;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'drain'   => true,
			'restore' => false,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): BackendsResponse => $c->backends() );
		Operator::problems( $results, __( 'backends', 'trident-cache-woocommerce' ) );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- table()/row()/button() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$list = $result->value;
			printf( '<h2>%s</h2><p>%s</p>', esc_html( $result->name() ), esc_html( sprintf( '%d backends: %d healthy, %d unhealthy', $list->total, $list->healthy, $list->unhealthy ) ) );
			echo Operator::table( array( __( 'Backend', 'trident-cache-woocommerce' ), __( 'Address', 'trident-cache-woocommerce' ), __( 'Status', 'trident-cache-woocommerce' ), __( 'Requests', 'trident-cache-woocommerce' ), __( 'Errors', 'trident-cache-woocommerce' ), __( 'Active', 'trident-cache-woocommerce' ), __( 'Avg', 'trident-cache-woocommerce' ), '' ) );
			foreach ( $list->backends as $backend ) {
				if ( ! is_array( $backend ) ) {
					continue;
				}
				$name    = (string) ( $backend['name'] ?? '' );
				$status  = (string) ( $backend['status'] ?? '' );
				$drained = in_array( $status, array( 'draining', 'drained', 'maintenance' ), true );
				echo Operator::row(
					array(
						esc_html( $name ),
						'<code>' . esc_html( sprintf( '%s:%s', (string) ( $backend['host'] ?? '' ), (string) ( $backend['port'] ?? '' ) ) ) . '</code>',
						sprintf( '<span class="%s">%s</span>', ! empty( $backend['healthy'] ) ? 'trident-ok' : 'trident-bad', esc_html( $status ) ),
						esc_html( number_format_i18n( (int) ( $backend['total_requests'] ?? 0 ) ) ),
						esc_html( number_format_i18n( (int) ( $backend['total_errors'] ?? 0 ) ) ),
						esc_html( (string) (int) ( $backend['active_connections'] ?? 0 ) ),
						esc_html( sprintf( '%s ms', (string) ( $backend['avg_response_ms'] ?? '-' ) ) ),
						$drained
							? $this->op->button(
								'backends',
								'restore',
								__( 'Restore', 'trident-cache-woocommerce' ),
								array(
									'name'     => $name,
									'instance' => $result->name(),
								)
							)
							: $this->op->button(
								'backends',
								'drain',
								__( 'Drain', 'trident-cache-woocommerce' ),
								array(
									'name'     => $name,
									'instance' => $result->name(),
								),
								'button',
								__( 'Stop sending traffic here', 'trident-cache-woocommerce' )
							),
					)
				);
			}
			echo '</tbody></table>';
		}
		// phpcs:enable
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		$name = (string) ( $post['name'] ?? '' );
		if ( '' === $name || ! in_array( $op, array( 'drain', 'restore' ), true ) ) {
			return;
		}
		Operator::report(
			sprintf( '%s %s', 'drain' === $op ? 'Drain' : 'Restore', $name ),
			$this->op->fleet()->on( Operator::targets( $post ), static fn ( TridentClient $c ): BackendActionResponse => 'drain' === $op ? $c->drainBackend( $name ) : $c->restoreBackend( $name ) ),
			static fn ( BackendActionResponse $r ): string => (string) ( $r->message ?? ( $r->success ? 'ok' : 'refused' ) )
		);
	}
}
