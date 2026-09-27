<?php
/**
 * Trident Cache → Denoisers.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Admin\Payload;
use Qoliber\Trident\Admin\WafView;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * What the query and path denoisers learned (which parameters are noise,
 * which paths are dead zones), and the operator's corrections: pin a
 * parameter or zone, unpin it, or forget everything learned.
 */
final class Denoisers extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'denoisers';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Denoisers', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 80;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'query_pin'    => false,
			'query_unpin'  => false,
			'path_pin'     => false,
			'path_unpin'   => false,
			'zone_delete'  => true,
			'scope_delete' => true,
			'reset'        => true,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): Payload => $c->denoiserReport() );
		Operator::problems( $results, __( 'the denoisers', 'trident-cache-woocommerce' ) );
		$select = $this->op->instance_select();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- details()/form()/confirm() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$report = $result->value;
			printf( '<h2>%s</h2>', esc_html( $result->name() ) );
			foreach ( array(
				'query_denoiser' => __( 'Query denoiser', 'trident-cache-woocommerce' ),
				'path_denoiser'  => __( 'Path denoiser', 'trident-cache-woocommerce' ),
			) as $key => $label ) {
				$part = $report->get( $key );
				printf( '<h3>%s — %s</h3>', esc_html( $label ), esc_html( $report->bool( $key . '.enabled' ) ? __( 'enabled', 'trident-cache-woocommerce' ) : __( 'not enabled', 'trident-cache-woocommerce' ) ) );
				if ( is_array( $part ) ) {
					echo Operator::details( $part );
				}
			}
		}
		echo '<h2>' . esc_html__( 'Query parameters', 'trident-cache-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html( sprintf( 'Pins apply to %s only; other sites on the same Trident are not affected.', Purge::site()[0] ) ) . '</p>';
		echo $this->op->form( 'denoisers', 'query_pin', 'display:inline-block;margin-right:2em' );
		echo '<input type="text" name="param" placeholder="utm_id" required> <select name="class"><option value="noise">' . esc_html__( 'noise (ignore)', 'trident-cache-woocommerce' ) . '</option><option value="signal">' . esc_html__( 'signal (keep)', 'trident-cache-woocommerce' ) . '</option></select> ';
		echo '<input type="text" name="path_prefix" value="/" size="10"> ' . $select . '<button class="button">' . esc_html__( 'Pin', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'denoisers', 'query_unpin', 'display:inline-block' ) . '<input type="text" name="param" placeholder="utm_id" required> <input type="text" name="path_prefix" value="/" size="10"> ' . $select . '<button class="button">' . esc_html__( 'Unpin', 'trident-cache-woocommerce' ) . '</button></form>';
		echo '<h2>' . esc_html__( 'Path zones', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'denoisers', 'path_pin', 'display:inline-block;margin-right:2em' );
		echo '<input type="text" name="path_prefix" placeholder="/search/" required> <select name="status"><option value="dead">' . esc_html__( 'dead (do not cache)', 'trident-cache-woocommerce' ) . '</option><option value="alive">' . esc_html__( 'alive (cache)', 'trident-cache-woocommerce' ) . '</option></select> ' . $select . '<button class="button">' . esc_html__( 'Pin', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'denoisers', 'path_unpin', 'display:inline-block' ) . '<input type="text" name="path_prefix" placeholder="/search/" required> ' . $select . '<button class="button">' . esc_html__( 'Unpin', 'trident-cache-woocommerce' ) . '</button></form>';
		echo '<h2>' . esc_html__( 'Forget one learned zone or scope', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'denoisers', 'zone_delete', 'display:inline-block;margin-right:2em' ) . '<input type="text" name="path_prefix" placeholder="/search/" required> ' . $select . Operator::confirm( __( 'Forget this path zone', 'trident-cache-woocommerce' ) ) . '<button class="button">' . esc_html__( 'Forget path zone', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'denoisers', 'scope_delete', 'display:inline-block' ) . '<input type="text" name="path_prefix" value="/" size="10"> ' . $select . Operator::confirm( __( 'Forget this query scope', 'trident-cache-woocommerce' ) ) . '<button class="button">' . esc_html__( 'Forget query scope', 'trident-cache-woocommerce' ) . '</button></form>';
		echo '<h2>' . esc_html__( 'Reset', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'denoisers', 'reset' ) . '<p><select name="which"><option value="query">' . esc_html__( 'query denoiser', 'trident-cache-woocommerce' ) . '</option><option value="path">' . esc_html__( 'path denoiser', 'trident-cache-woocommerce' ) . '</option></select> ' . $select . Operator::confirm( __( 'Forget everything this denoiser learned', 'trident-cache-woocommerce' ) ) . '<button class="button button-link-delete">' . esc_html__( 'Reset', 'trident-cache-woocommerce' ) . '</button></p></form>';
		echo '<h2>' . esc_html__( 'WAF export (trident-waf-v1) — this site', 'trident-cache-woocommerce' ) . '</h2>';
		[ $host ] = Purge::site();
		echo '<p class="description">' . esc_html(
			sprintf(
				'The export covers every site on the Trident. Shown here: dead zones for %1$s and for all hosts (*), and the noise parameters learned for %1$s. The export\'s own parameter list carries no host, so it is not shown.',
				$host
			)
		) . '</p>';
		$exports = $this->op->fleet()->each( static fn ( TridentClient $c ): array => array( $c->wafExport()->raw(), Fleet::attempt( static fn () => $c->denoiserQueryScopes()->raw() ) ?? array() ) );
		foreach ( $exports as $export ) {
			if ( ! $export->isOk() ) {
				continue;
			}
			[ $waf, $scopes ] = $export->value;
			printf( '<h3>%s — %s</h3>', esc_html( $export->name() ), esc_html( is_string( $waf['format'] ?? null ) ? $waf['format'] : '' ) );
			echo Operator::table( array( __( 'Dead zone host', 'trident-cache-woocommerce' ), __( 'Path prefix', 'trident-cache-woocommerce' ), __( 'Action', 'trident-cache-woocommerce' ) ) );
			$zones = WafView::deadZones( $waf, array( $host ) );
			foreach ( $zones as $zone ) {
				// A `*` row is an old wildcard pin: the engine has no wildcard
				// fallback, so it applies to nothing — say so, and how to remove it.
				$host_cell = esc_html( $zone['host'] ) . ( $zone['inert'] ? ' <em>' . esc_html__( '(inert — applies to no request; unpin with host *)', 'trident-cache-woocommerce' ) . '</em>' : '' );
				echo Operator::row( array( $host_cell, '<code>' . esc_html( $zone['prefix'] ) . '</code>', esc_html( $zone['action'] ) ) );
			}
			if ( array() === $zones ) {
				echo Operator::empty_row( 3, __( 'No dead zones for this site.', 'trident-cache-woocommerce' ) );
			}
			echo '</tbody></table>';
			echo Operator::table( array( __( 'Noise parameter', 'trident-cache-woocommerce' ), __( 'Learned in scope', 'trident-cache-woocommerce' ) ) );
			$noise = WafView::noise( array_values( $scopes ), array( $host ) );
			foreach ( $noise as $param ) {
				echo Operator::row( array( '<code>' . esc_html( $param['param'] ) . '</code>', esc_html( $param['scope'] ) ) );
			}
			if ( array() === $noise ) {
				echo Operator::empty_row( 2, __( 'No noise parameters learned for this site.', 'trident-cache-woocommerce' ) );
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
		$fleet   = $this->op->fleet();
		$targets = Operator::targets( $post );
		$param   = (string) ( $post['param'] ?? '' );
		$prefix  = '/' . ltrim( (string) ( $post['path_prefix'] ?? '/' ), '/' );
		// Scoped to this shop: '*' would change the cache key of every site
		// on a shared Trident.
		[ $host ] = Purge::site();
		// The engine confirms with `pinned` / `unpinned` / `deleted`; say which.
		$describe = static fn ( Payload $p ): string => match ( true ) {
			$p->bool( 'pinned' )   => 'pinned' . ( $p->has( 'status' ) ? ' (' . $p->string( 'status' ) . ')' : ( $p->has( 'class' ) ? ' (' . $p->string( 'class' ) . ')' : '' ) ),
			$p->bool( 'unpinned' ) => 'unpinned',
			$p->bool( 'deleted' )  => 'forgotten',
			default                => $p->string( 'message', $p->string( 'status', 'ok' ) ),
		};
		switch ( $op ) {
			case 'query_pin':
				$class = 'signal' === ( $post['class'] ?? '' ) ? 'signal' : 'noise';
				Operator::report( sprintf( 'Pin %s as %s', $param, $class ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserQueryPin( $param, $class, $host, $prefix ) ), $describe );
				return;
			case 'query_unpin':
				Operator::report( sprintf( 'Unpin %s', $param ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserQueryUnpin( $param, $host, $prefix ) ), $describe );
				return;
			case 'path_pin':
				$status = 'alive' === ( $post['status'] ?? '' ) ? 'alive' : 'dead';
				Operator::report( sprintf( 'Pin %s as %s', $prefix, $status ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserPathPin( $status, $host, $prefix ) ), $describe );
				return;
			case 'path_unpin':
				Operator::report( sprintf( 'Unpin %s', $prefix ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserPathUnpin( $host, $prefix ) ), $describe );
				return;
			case 'zone_delete':
				Operator::report( sprintf( 'Forget path zone %s', $prefix ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserPathZoneDelete( $host, $prefix ) ), $describe );
				return;
			case 'scope_delete':
				Operator::report( sprintf( 'Forget query scope %s', $prefix ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserQueryScopeDelete( $host, $prefix ) ), $describe );
				return;
			case 'reset':
				$which = 'path' === ( $post['which'] ?? '' ) ? 'path' : 'query';
				Operator::report( sprintf( 'Reset the %s denoiser', $which ), $fleet->on( $targets, static fn ( TridentClient $c ): Payload => $c->denoiserReset( $which ) ), $describe );
				return;
		}
	}
}
