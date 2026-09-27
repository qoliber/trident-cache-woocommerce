<?php
/**
 * Trident Cache → Bans.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\BanCreateResponse;
use Qoliber\Trident\Response\BansResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * The ban log each instance keeps (every soft purge is recorded as a ban
 * with what it affected), and recording or removing one by hand.
 */
final class Bans extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'bans';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Bans', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 90;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'create' => true,
			'delete' => true,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): BansResponse => $c->bans( 100 ) );
		Operator::problems( $results, __( 'bans', 'trident-cache-woocommerce' ) );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- table()/row()/button()/form() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$bans = $result->value;
			printf( '<h2>%s</h2><p>%s</p>', esc_html( $result->name() ), esc_html( sprintf( '%d bans, %d active', $bans->total, $bans->active ) ) );
			echo Operator::table( array( 'ID', __( 'Type', 'trident-cache-woocommerce' ), __( 'Pattern', 'trident-cache-woocommerce' ), __( 'Affected', 'trident-cache-woocommerce' ), __( 'Age', 'trident-cache-woocommerce' ), __( 'Active', 'trident-cache-woocommerce' ), '' ) );
			foreach ( $bans->bans as $ban ) {
				if ( ! is_array( $ban ) ) {
					continue;
				}
				$id = (string) ( $ban['id'] ?? '' );
				echo Operator::row(
					array(
						esc_html( $id ),
						esc_html( (string) ( $ban['ban_type'] ?? '' ) ),
						'<code>' . esc_html( (string) ( $ban['pattern'] ?? '' ) ) . '</code>',
						esc_html( (string) (int) ( $ban['affected'] ?? 0 ) ),
						esc_html( Operator::seconds( (int) ( $ban['age_secs'] ?? 0 ) ) ),
						esc_html( ! empty( $ban['active'] ) ? __( 'yes', 'trident-cache-woocommerce' ) : __( 'no', 'trident-cache-woocommerce' ) ),
						$this->op->button(
							'bans',
							'delete',
							__( 'Delete', 'trident-cache-woocommerce' ),
							array(
								'id'       => $id,
								'instance' => $result->name(),
							),
							'button-small',
							__( 'Delete', 'trident-cache-woocommerce' )
						),
					)
				);
			}
			if ( array() === $bans->bans ) {
				echo Operator::empty_row( 7, __( 'No bans.', 'trident-cache-woocommerce' ) );
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Record a ban', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'bans', 'create' );
		echo '<p><select name="type"><option value="url">url</option><option value="tag">tag</option><option value="tags">tags</option><option value="pattern">pattern</option></select> ';
		echo '<input type="text" class="regular-text code" name="pattern" required> ' . $this->op->instance_select() . Operator::confirm( __( 'Record this ban', 'trident-cache-woocommerce' ) ) . '<button class="button">' . esc_html__( 'Create', 'trident-cache-woocommerce' ) . '</button></p></form>';
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
		if ( 'create' === $op ) {
			$type    = in_array( $post['type'] ?? '', array( 'url', 'tag', 'tags', 'pattern' ), true ) ? $post['type'] : 'url';
			$pattern = (string) ( $post['pattern'] ?? '' );
			Operator::report( sprintf( 'Ban %s %s', $type, $pattern ), $fleet->on( $targets, static fn ( TridentClient $c ): BanCreateResponse => $c->createBan( $pattern, $type ) ), static fn ( BanCreateResponse $r ): string => sprintf( 'ban #%s recorded', (string) $r->id ) );
		} elseif ( 'delete' === $op ) {
			$id = (string) ( $post['id'] ?? '' );
			if ( 1 !== preg_match( '/^\d+$/', $id ) ) {
				Operator::notice( 'error', __( 'Not a ban id.', 'trident-cache-woocommerce' ) );
				return;
			}
			Operator::report( sprintf( 'Delete ban #%s', $id ), $fleet->on( $targets, static fn ( TridentClient $c ): bool => $c->deleteBan( $id ) ), static fn ( bool $deleted ): string => $deleted ? 'deleted' : 'not found' );
		}
	}
}
