<?php
/**
 * Trident Cache → Coverage.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * How much of the shop each instance holds: this site's own pages (home, shop,
 * the newest products, categories and pages) or a list pasted in, looked up
 * without requesting them — so the check itself warms nothing.
 */
final class Coverage extends Screen {

	/** Most URLs one check sends. */
	public const MAX_URLS = 200;

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'coverage';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Coverage', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 40;
	}

	/**
	 * This shop's own pages: home, shop, newest products, categories, pages.
	 *
	 * @param int $limit Most URLs.
	 * @return list<string> Absolute URLs.
	 */
	public static function catalogue( int $limit = 100 ): array {
		$urls = array( home_url( '/' ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
		}
		$posts = get_posts(
			array(
				'post_type'        => array( 'product', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $id ) {
			$urls[] = (string) get_permalink( (int) $id );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 30,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		// The cart, checkout and account pages are never cached.
		$private = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$private[] = wc_get_page_permalink( $page );
			}
		}
		$urls = array_values( array_unique( array_diff( array_filter( $urls ), $private ) ) );
		return array_slice( $urls, 0, $limit );
	}

	/**
	 * @return void
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only check.
		$run    = isset( $_GET['check'] );
		$pasted = isset( $_GET['urls'] ) ? sanitize_textarea_field( wp_unslash( (string) $_GET['urls'] ) ) : '';
		// phpcs:enable
		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="check" value="1">', esc_attr( Operator::MENU . '-' . $this->slug() ) );
		printf( '<p><textarea name="urls" rows="4" class="large-text code" placeholder="%s">%s</textarea></p>', esc_attr__( 'Empty: this shop\'s own pages. Or one URL/path per line.', 'trident-cache-woocommerce' ), esc_textarea( $pasted ) );
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Check coverage', 'trident-cache-woocommerce' ) );
		if ( ! $run ) {
			return;
		}
		$lines  = '' !== trim( $pasted ) ? preg_split( '/\R/', $pasted ) : self::catalogue();
		$paths  = array();
		$host   = null;
		$scheme = null;
		foreach ( array_slice( is_array( $lines ) ? $lines : array(), 0, self::MAX_URLS ) as $line ) {
			$page = '' === trim( (string) $line ) ? null : Purge::own_url( (string) $line );
			if ( null !== $page ) {
				$paths[] = $page->path;
				$host    = $page->host;
				$scheme  = $page->scheme;
			}
		}
		$paths = array_values( array_unique( $paths ) );
		if ( array() === $paths ) {
			printf( '<p>%s</p>', esc_html__( 'No URL of this site to check.', 'trident-cache-woocommerce' ) );
			return;
		}
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ) => $c->coverage( $paths, $host, $scheme ) );
		Operator::problems( $results, __( 'coverage', 'trident-cache-woocommerce' ) );
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$coverage = $result->value;
			printf(
				'<h2>%s</h2><p><strong>%s%%</strong> %s</p>',
				esc_html( $result->name() ),
				esc_html( number_format_i18n( $coverage->float( 'percent_cached' ), 1 ) ),
				esc_html( sprintf( '— %d of %d pages cached', $coverage->int( 'cached' ), $coverage->int( 'total' ) ) )
			);
			echo Operator::table( array( __( 'Page', 'trident-cache-woocommerce' ), __( 'Cached', 'trident-cache-woocommerce' ), __( 'Age', 'trident-cache-woocommerce' ), 'TTL', __( 'Size', 'trident-cache-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $coverage->rows( 'results' ) as $row ) {
				$cached = ! empty( $row['cached'] );
				echo Operator::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped.
					array(
						'<code>' . esc_html( (string) ( $row['url'] ?? '' ) ) . '</code>',
						$cached ? '<span class="trident-ok">' . esc_html__( 'yes', 'trident-cache-woocommerce' ) . '</span> ' . esc_html( (string) ( $row['status'] ?? '' ) ) : '<span class="trident-bad">' . esc_html__( 'no', 'trident-cache-woocommerce' ) . '</span>',
						$cached ? esc_html( Operator::seconds( (int) ( $row['age_s'] ?? 0 ) ) ) : '-',
						$cached ? esc_html( Operator::seconds( (int) ( $row['ttl_s'] ?? 0 ) ) ) : '-',
						$cached ? esc_html( Operator::bytes( (int) ( $row['size'] ?? 0 ) ) ) : '-',
					)
				);
			}
			echo '</tbody></table>';
		}
	}
}
