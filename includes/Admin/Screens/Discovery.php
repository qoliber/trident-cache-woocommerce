<?php
/**
 * Trident Cache → DNS discovery.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\DiscoveryListResponse;
use Qoliber\Trident\Response\DiscoveryRefreshResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * Backends whose addresses each instance resolves from DNS, what they resolved
 * to, and a re-resolve on demand.
 */
final class Discovery extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'discovery';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'DNS discovery', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 110;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array( 'refresh' => false );
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): DiscoveryListResponse => $c->discoveryList() );
		Operator::problems( $results, __( 'DNS discovery', 'trident-cache-woocommerce' ) );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- table()/row()/button() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			printf( '<h2>%s</h2>', esc_html( $result->name() ) );
			echo Operator::table( array( __( 'Backend', 'trident-cache-woocommerce' ), __( 'Hostname', 'trident-cache-woocommerce' ), __( 'Resolved', 'trident-cache-woocommerce' ), __( 'Last resolved', 'trident-cache-woocommerce' ), '' ) );
			foreach ( $result->value->backends as $backend ) {
				echo Operator::row(
					array(
						esc_html( $backend->name ),
						'<code>' . esc_html( $backend->hostname ) . '</code>',
						esc_html( implode( ', ', array_map( 'strval', $backend->resolvedIps ) ) ),
						esc_html( (string) ( $backend->lastResolved ?? '-' ) ),
						$this->op->button(
							'discovery',
							'refresh',
							__( 'Re-resolve', 'trident-cache-woocommerce' ),
							array(
								'name'     => $backend->name,
								'instance' => $result->name(),
							)
						),
					)
				);
			}
			if ( array() === $result->value->backends ) {
				echo Operator::empty_row( 5, __( 'No backend uses DNS discovery on this instance.', 'trident-cache-woocommerce' ) );
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
		if ( 'refresh' !== $op || '' === $name ) {
			return;
		}
		Operator::report(
			sprintf( 'Re-resolve %s', $name ),
			$this->op->fleet()->on( Operator::targets( $post ), static fn ( TridentClient $c ): DiscoveryRefreshResponse => $c->discoveryRefresh( $name ) ),
			static fn ( DiscoveryRefreshResponse $r ): string => sprintf( '%d address(es)', count( $r->ips ) )
		);
	}
}
