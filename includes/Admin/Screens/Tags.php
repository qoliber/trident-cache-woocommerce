<?php
/**
 * Trident Cache → Tags.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\PurgeResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * The tags each instance has indexed, how many entries carry each, and a purge
 * by the exact tag name the instance holds (already prefixed — unlike the
 * Purge screen's tag field, which takes this site's unprefixed tags).
 */
final class Tags extends Screen {

	private const PER_PAGE = 100;

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'tags';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Tags', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 30;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array( 'tag' => false );
	}

	/**
	 * @return void
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell and form is built with esc_* or the escaping Operator helpers.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation.
		$prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prefix'] ) ) : '';
		$sort   = isset( $_GET['sort'] ) && 'name' === $_GET['sort'] ? 'name' : 'count';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Operator::MENU . '-' . $this->slug() ) );
		printf(
			'<p><label>%s <input type="text" name="prefix" value="%s" placeholder="wc_p_"></label> <label>%s <select name="sort"><option value="count"%s>%s</option><option value="name"%s>%s</option></select></label> <button class="button">%s</button></p></form>',
			esc_html__( 'Tags starting with', 'trident-cache-woocommerce' ),
			esc_attr( $prefix ),
			esc_html__( 'Sort', 'trident-cache-woocommerce' ),
			selected( $sort, 'count', false ),
			esc_html__( 'entries', 'trident-cache-woocommerce' ),
			selected( $sort, 'name', false ),
			esc_html__( 'name', 'trident-cache-woocommerce' ),
			esc_html__( 'Filter', 'trident-cache-woocommerce' )
		);
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ) => $c->cacheTags( self::PER_PAGE, 0, null, $sort, '' !== $prefix ? $prefix : null ) );
		Operator::problems( $results, __( 'tag browsing', 'trident-cache-woocommerce' ) );
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$tags = $result->value;
			printf( '<h2>%s</h2><p>%s</p>', esc_html( $result->name() ), esc_html( sprintf( '%d tags%s', $tags->total, $tags->hasMore ? sprintf( ', first %d shown', self::PER_PAGE ) : '' ) ) );
			echo Operator::table( array( __( 'Tag', 'trident-cache-woocommerce' ), __( 'Entries', 'trident-cache-woocommerce' ), '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $tags->tags as $tag ) {
				echo Operator::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped.
					array(
						sprintf(
							'<a href="%s"><code>%s</code></a>',
							esc_url(
								Operator::url(
									'entries',
									array(
										'instance' => $result->name(),
										'tag'      => $tag->tag,
									)
								)
							),
							esc_html( $tag->tag )
						),
						esc_html( (string) $tag->entries ),
						$this->op->button( 'tags', 'tag', __( 'Purge', 'trident-cache-woocommerce' ), array( 'tag' => $tag->tag ) ),
					)
				);
			}
			if ( array() === $tags->tags ) {
				echo Operator::empty_row( 3, __( 'No tags.', 'trident-cache-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		$tag = trim( (string) ( $post['tag'] ?? '' ) );
		if ( 'tag' !== $op || '' === $tag ) {
			return;
		}
		Operator::report(
			sprintf( 'Purge tag %s', $tag ),
			$this->op->fleet()->on( Operator::targets( $post ), static fn ( TridentClient $c ): PurgeResponse => $c->purgeTag( $tag ) ),
			static fn ( PurgeResponse $r ): string => sprintf( '%d purged', $r->purgedCount )
		);
	}
}
