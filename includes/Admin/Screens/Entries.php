<?php
/**
 * Trident Cache → Cached pages.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Admin\SiteUrl;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\PurgeResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * What one instance holds: entries with sort and tag filter, a purge per entry
 * (by storage key — exactly that variant), and an entry's detail: its tags,
 * variants and the engine's own verdict for the URL.
 */
final class Entries extends Screen {

	private const PER_PAGE = 50;

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'entries';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Cached pages', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 20;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array( 'entry' => false );
	}

	/**
	 * The instance a read-only view shows: the requested one, else the first.
	 *
	 * @return string
	 */
	private function viewed_instance(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$name = isset( $_GET['instance'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['instance'] ) ) : '';
		if ( $this->op->fleet()->has( $name ) ) {
			return $name;
		}
		$first = $this->op->fleet()->instances()[0] ?? null;
		return null === $first ? '' : $first->name;
	}

	/**
	 * A GET field for this screen.
	 *
	 * @param string $name Field.
	 * @return string
	 */
	private static function query( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		return isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ) : '';
	}

	/**
	 * Instance tabs.
	 *
	 * @param string                $current Current instance.
	 * @param array<string, string> $args    Query to keep.
	 * @return void
	 */
	private function tabs( string $current, array $args ): void {
		$instances = $this->op->fleet()->instances();
		if ( count( $instances ) < 2 ) {
			return;
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $instances as $instance ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$instance->name === $current ? ' nav-tab-active' : '',
				esc_url( Operator::url( $this->slug(), array_merge( $args, array( 'instance' => $instance->name ) ) ) ),
				esc_html( $instance->name )
			);
		}
		echo '</h2>';
	}

	/**
	 * @return void
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell and form is built with esc_* or the escaping Operator helpers.
		$instance = $this->viewed_instance();
		if ( '' === $instance ) {
			printf( '<p>%s</p>', esc_html__( 'No Trident instance configured.', 'trident-cache-woocommerce' ) );
			return;
		}
		$url = self::query( 'url' );
		if ( '' !== $url ) {
			$this->tabs( $instance, array( 'url' => $url ) );
			$this->render_detail( $instance, $url );
			return;
		}
		$sort   = in_array( self::query( 'sort' ), array( 'age', 'hits', 'size' ), true ) ? self::query( 'sort' ) : 'age';
		$tag    = self::query( 'tag' );
		$offset = max( 0, (int) self::query( 'offset' ) );
		$this->tabs(
			$instance,
			array_filter(
				array(
					'sort' => $sort,
					'tag'  => $tag,
				)
			)
		);

		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="instance" value="%s">', esc_attr( Operator::MENU . '-' . $this->slug() ), esc_attr( $instance ) );
		printf( '<p><label>%s <input type="text" name="tag" value="%s" placeholder="wc_shop"></label> ', esc_html__( 'Tag', 'trident-cache-woocommerce' ), esc_attr( $tag ) );
		printf( '<label>%s <select name="sort">', esc_html__( 'Sort', 'trident-cache-woocommerce' ) );
		foreach ( array( 'age', 'hits', 'size' ) as $option ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $sort, $option, false ) );
		}
		printf( '</select></label> <button class="button">%s</button></p></form>', esc_html__( 'Filter', 'trident-cache-woocommerce' ) );
		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="instance" value="%s">', esc_attr( Operator::MENU . '-' . $this->slug() ), esc_attr( $instance ) );
		printf( '<p><label>%s <input type="text" class="regular-text" name="url" placeholder="/shop/" required></label> <button class="button">%s</button></p></form>', esc_html__( 'Look up a URL', 'trident-cache-woocommerce' ), esc_html__( 'Inspect', 'trident-cache-woocommerce' ) );

		$results = $this->op->fleet()->on( array( $instance ), static fn ( TridentClient $c ) => $c->cacheEntries( self::PER_PAGE, $offset, null, $sort, '' !== $tag ? $tag : null ) );
		Operator::problems( $results, __( 'cache browsing', 'trident-cache-woocommerce' ) );
		$list = $results[0] ?? null;
		if ( null === $list || ! $list->isOk() ) {
			return;
		}
		$page = $list->value;
		printf( '<p>%s</p>', esc_html( sprintf( '%s: %d entries%s', $instance, $page->total, '' !== $tag ? ' tagged ' . $tag : '' ) ) );
		echo Operator::table( array( __( 'URL', 'trident-cache-woocommerce' ), __( 'Status', 'trident-cache-woocommerce' ), 'HTTP', __( 'Size', 'trident-cache-woocommerce' ), __( 'Age', 'trident-cache-woocommerce' ), 'TTL', __( 'Hits', 'trident-cache-woocommerce' ), __( 'Tags', 'trident-cache-woocommerce' ), '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $page->entries as $entry ) {
			$key  = SiteUrl::fromKey( $entry->key );
			$path = null === $key ? $entry->key : $key['page']->path;
			$tags = $entry->tags;
			echo Operator::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped.
				array(
					sprintf(
						'<a href="%s"><code>%s</code></a>%s',
						esc_url(
							Operator::url(
								$this->slug(),
								array(
									'instance' => $instance,
									'url'      => null === $key ? $path : $key['page']->absolute(),
								)
							)
						),
						esc_html( $path ),
						null === $key ? '' : '<br><small>' . esc_html( (string) $key['page']->host ) . '</small>'
					),
					esc_html( $entry->status ),
					esc_html( (string) $entry->statusCode ),
					esc_html( Operator::bytes( $entry->contentLength ) ),
					esc_html( Operator::seconds( $entry->age ) ),
					esc_html( Operator::seconds( $entry->ttlRemaining ) ),
					esc_html( (string) $entry->hits ),
					esc_html( implode( ', ', array_slice( $tags, 0, 4 ) ) . ( count( $tags ) > 4 ? sprintf( ' +%d', count( $tags ) - 4 ) : '' ) ),
					$this->op->button(
						'entries',
						'entry',
						__( 'Purge', 'trident-cache-woocommerce' ),
						array(
							'instance' => $instance,
							'hash'     => $entry->storageKey,
							'label'    => $path,
						)
					),
				)
			);
		}
		if ( array() === $page->entries ) {
			echo Operator::empty_row( 9, __( 'Nothing cached.', 'trident-cache-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
		$nav = array();
		if ( $offset > 0 ) {
			$nav[] = sprintf(
				'<a class="button" href="%s">&larr; %s</a>',
				esc_url(
					Operator::url(
						$this->slug(),
						array_filter(
							array(
								'instance' => $instance,
								'sort'     => $sort,
								'tag'      => $tag,
								'offset'   => max( 0, $offset - self::PER_PAGE ),
							)
						)
					)
				),
				esc_html__( 'Previous', 'trident-cache-woocommerce' )
			);
		}
		if ( $page->hasMore ) {
			$nav[] = sprintf(
				'<a class="button" href="%s">%s &rarr;</a>',
				esc_url(
					Operator::url(
						$this->slug(),
						array_filter(
							array(
								'instance' => $instance,
								'sort'     => $sort,
								'tag'      => $tag,
								'offset'   => $offset + self::PER_PAGE,
							)
						)
					)
				),
				esc_html__( 'Next', 'trident-cache-woocommerce' )
			);
		}
		echo '<p>' . implode( ' ', $nav ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		// phpcs:enable
	}

	/**
	 * One URL on one instance: the stored entry, its variants and the verdict.
	 *
	 * @param string $instance Instance.
	 * @param string $url      URL or path.
	 * @return void
	 */
	private function render_detail( string $instance, string $url ): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell and form is built with esc_* or the escaping Operator helpers.
		$page = Purge::own_url( $url );
		if ( null === $page ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( sprintf( 'Not a URL of this site: %s', $url ) ) );
			return;
		}
		$absolute = $page->absolute();
		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( Operator::url( $this->slug(), array( 'instance' => $instance ) ) ), esc_html__( 'All cached pages', 'trident-cache-woocommerce' ) );
		printf( '<h2><code>%s</code></h2>', esc_html( $absolute ) );
		$results = $this->op->fleet()->on(
			array( $instance ),
			static fn ( TridentClient $c ): array => array(
				'entry'    => $c->cacheEntry( $page->path, $page->host ),
				// Secondary: a failing one blanks its figure, not the entry.
				'variants' => Fleet::attempt( static fn () => $c->cacheVariants( $absolute ) ),
				'explain'  => Fleet::attempt( static fn () => $c->explain( $absolute ) ),
			)
		);
		Operator::problems( $results, __( 'cache lookup', 'trident-cache-woocommerce' ) );
		$result = $results[0] ?? null;
		if ( null === $result || ! $result->isOk() ) {
			return;
		}
		$entry    = $result->value['entry'];
		$variants = $result->value['variants'];
		$explain  = $result->value['explain'];
		if ( null !== $explain && 'http' === $page->scheme ) {
			printf(
				'<p><strong>%s</strong> — %s (%s)</p>',
				esc_html( strtoupper( $explain->string( 'verdict', '?' ) ) ),
				esc_html( $explain->string( 'reason' ) ),
				esc_html( $explain->bool( 'cacheable' ) ? __( 'cacheable', 'trident-cache-woocommerce' ) : __( 'not cacheable', 'trident-cache-woocommerce' ) )
			);
		} elseif ( null !== $explain ) {
			// The engine explains without TLS context: its hit/miss part is
			// looked up under the http key, so only the rule verdict applies.
			printf(
				'<p><strong>%s</strong> — %s</p>',
				esc_html( $explain->bool( 'cacheable' ) ? __( 'Cacheable', 'trident-cache-woocommerce' ) : __( 'Not cacheable', 'trident-cache-woocommerce' ) ),
				esc_html( $explain->bool( 'cacheable' ) ? __( 'by Trident\'s rules', 'trident-cache-woocommerce' ) : $explain->string( 'reason' ) )
			);
		}
		if ( ! $entry->found ) {
			printf( '<p>%s</p>', esc_html__( 'Not in this instance\'s cache.', 'trident-cache-woocommerce' ) );
			return;
		}
		echo '<table class="widefat striped trident-table" style="max-width:60em"><tbody>';
		$rows = array(
			__( 'Status', 'trident-cache-woocommerce' )   => (string) $entry->status,
			'HTTP'                                        => (string) $entry->statusCode,
			__( 'Content type', 'trident-cache-woocommerce' ) => (string) $entry->contentType,
			__( 'Size', 'trident-cache-woocommerce' )     => Operator::bytes( (int) $entry->contentLength ),
			__( 'Age', 'trident-cache-woocommerce' )      => Operator::seconds( (int) $entry->age ),
			__( 'TTL left', 'trident-cache-woocommerce' ) => Operator::seconds( (int) $entry->ttlRemaining ),
			__( 'Grace left', 'trident-cache-woocommerce' ) => null === $entry->graceRemaining ? '-' : Operator::seconds( $entry->graceRemaining ),
			__( 'Hits', 'trident-cache-woocommerce' )     => (string) $entry->hits,
			'Vary'                                        => implode( ', ', $entry->vary ),
			__( 'Variants', 'trident-cache-woocommerce' ) => null === $variants ? '-' : sprintf( '%d (%s)', $variants->int( 'variant_count' ), Operator::bytes( $variants->int( 'total_bytes' ) ) ),
			__( 'Tags', 'trident-cache-woocommerce' )     => implode( ', ', $entry->tags ),
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th style="width:12em">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
		if ( null !== $entry->storageKey ) {
			echo '<p>' . $this->op->button(
				'entries',
				'entry',
				__( 'Purge this entry', 'trident-cache-woocommerce' ),
				array(
					'instance' => $instance,
					'hash'     => $entry->storageKey,
					'label'    => $page->path,
				),
				'button-primary'
			) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes.
		}
		// phpcs:enable
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		if ( 'entry' !== $op ) {
			return;
		}
		$hash = (string) ( $post['hash'] ?? '' );
		if ( 1 !== preg_match( '/^[0-9a-f]{2,512}$/', $hash ) ) {
			Operator::notice( 'error', __( 'Not a cache entry key.', 'trident-cache-woocommerce' ) );
			return;
		}
		Operator::report(
			sprintf( 'Purge %s', (string) ( $post['label'] ?? $hash ) ),
			$this->op->fleet()->on( Operator::targets( $post ), static fn ( TridentClient $c ): PurgeResponse => $c->purgeHash( $hash ) ),
			static fn ( PurgeResponse $r ): string => sprintf( '%d purged', $r->purgedCount )
		);
	}

	/**
	 * Back to the instance the purge was pressed on.
	 *
	 * @param array<string, string> $post Sanitised POST.
	 * @return string
	 */
	public function back_url( array $post ): string {
		return Operator::url( $this->slug(), array_filter( array( 'instance' => (string) ( $post['instance'] ?? '' ) ) ) );
	}
}
