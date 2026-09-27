<?php
/**
 * Trident Cache → Purge.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\SiteUrl;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Delivery\DrainReport;
use Qoliber\Trident\Response\ClearResponse;
use Qoliber\Trident\Response\PurgeResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * Purge by URL, tag, product, pattern (with a preview), host, this site, or
 * the whole cache — and the admin bar's "Purge this page".
 *
 * Tags, products and "this site" go through the plugin's durable outbox, the
 * same path a product save takes: prefixed with this site's tag prefix,
 * delivered to every instance and retried until acknowledged. URLs, patterns
 * and hosts have no outbox form; they go to the instances directly and each
 * instance's answer is reported.
 */
final class Purge extends Screen {

	/** Most URLs one form submission purges. */
	public const MAX_URLS = 50;

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'purge';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Purge', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 10;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'urls'    => false,
			'page'    => false,
			'tags'    => false,
			'product' => false,
			'pattern' => true,
			'host'    => true,
			'site'    => true,
			'clear'   => true,
		);
	}

	/**
	 * This site's host as Trident keys it (`host[:port]`) and scheme.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function site(): array {
		$home   = (string) home_url( '/' );
		$host   = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$port   = wp_parse_url( $home, PHP_URL_PORT );
		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		return array( $host . ( null !== $port ? ':' . $port : '' ), '' !== $scheme ? $scheme : 'https' );
	}

	/**
	 * A URL of this site, split for the admin API; null for anything else.
	 *
	 * A path is taken as this site's. An absolute URL must be on this site's
	 * host: the screen purges this shop, not whatever a pasted link names.
	 *
	 * @param string $url URL or path.
	 * @return SiteUrl|null
	 */
	public static function own_url( string $url ): ?SiteUrl {
		[ $host, $scheme ] = self::site();
		try {
			$page = SiteUrl::parse( $url, $host, $scheme );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		return $page->host === $host ? $page : null;
	}

	/**
	 * @return void
	 */
	public function render(): void {
		[ $host ] = self::site();
		$mode     = static fn (): string => '<select name="mode"><option value="hard">' . esc_html__( 'hard — remove now', 'trident-cache-woocommerce' ) . '</option><option value="soft">' . esc_html__( 'soft — serve stale while refreshing', 'trident-cache-woocommerce' ) . '</option></select> ';
		$select   = $this->op->instance_select();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- form()/instance_select()/confirm() escape; the rest is esc_*.
		echo '<h2>' . esc_html__( 'Pages', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'purge', 'urls' );
		printf( '<p><textarea name="urls" rows="4" cols="80" class="large-text code" placeholder="%s" required></textarea></p>', esc_attr( '/shop/' . "\n" . home_url( '/product/example/' ) ) );
		printf( '<p class="description">%s</p>', esc_html( sprintf( 'One URL or path per line, on %s (up to %d).', $host, self::MAX_URLS ) ) );
		echo '<p>' . $mode() . $select . '<button class="button button-primary">' . esc_html__( 'Purge pages', 'trident-cache-woocommerce' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Products and tags', 'trident-cache-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Delivered like a product save: to every instance, retried until acknowledged, in the configured purge mode.', 'trident-cache-woocommerce' ) . '</p>';
		echo $this->op->form( 'purge', 'product', 'display:inline-block;margin-right:2em' );
		echo '<input type="text" name="products" placeholder="' . esc_attr__( 'Product IDs, e.g. 14, 22', 'trident-cache-woocommerce' ) . '" required> <button class="button">' . esc_html__( 'Purge products', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'purge', 'tags', 'display:inline-block' );
		echo '<input type="text" name="tags" placeholder="' . esc_attr__( 'Tags, e.g. wc_shop, wc_cat_30', 'trident-cache-woocommerce' ) . '" required> <button class="button">' . esc_html__( 'Purge tags', 'trident-cache-woocommerce' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Pattern', 'trident-cache-woocommerce' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only preview.
		$pattern = isset( $_GET['pattern'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pattern'] ) ) : '';
		printf( '<form method="get" action="%s" style="display:inline-block">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Operator::MENU . '-purge' ) );
		printf( '<input type="text" class="regular-text code" name="pattern" value="%s" placeholder="%s" required> <button class="button">%s</button></form>', esc_attr( $pattern ), esc_attr( ':' . $host . ':/product/' ), esc_html__( 'Preview', 'trident-cache-woocommerce' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf( 'A regular expression matched against the cache key, METHOD:scheme:host:/path — e.g. :%s:/product/ for this shop\'s product pages. It is not limited to this host: every site on the instance that matches is purged. Preview first.', $host ) )
		);
		if ( '' !== $pattern ) {
			$this->render_preview( $pattern );
			echo $this->op->form( 'purge', 'pattern' );
			printf( '<input type="hidden" name="pattern" value="%s">', esc_attr( $pattern ) );
			echo '<p>' . $mode() . $select . Operator::confirm( sprintf( 'Purge every entry matching %s', $pattern ) ) . '<button class="button">' . esc_html__( 'Purge pattern', 'trident-cache-woocommerce' ) . '</button></p></form>';
		}

		echo '<h2>' . esc_html__( 'Everything', 'trident-cache-woocommerce' ) . '</h2>';
		echo '<p>' . $this->op->button( 'purge', 'site', __( 'Purge this site', 'trident-cache-woocommerce' ), array(), 'button-primary', sprintf( 'Purge every page of %s', $host ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Only this site\'s pages (its "all" tag): other sites on the same Trident keep theirs.', 'trident-cache-woocommerce' ) . '</p>';
		echo $this->op->form( 'purge', 'host' );
		printf( '<p><input type="text" name="host" value="%s" required> %s%s<button class="button">%s</button></p></form>', esc_attr( $host ), $select, Operator::confirm( __( 'Purge every entry of this host', 'trident-cache-woocommerce' ) ), esc_html__( 'Purge host', 'trident-cache-woocommerce' ) );
		echo '<p>' . $this->op->button( 'purge', 'clear', __( 'Clear the entire Trident cache', 'trident-cache-woocommerce' ), array(), 'button button-link-delete', __( 'Remove EVERY entry of EVERY site on these Trident instances', 'trident-cache-woocommerce' ) ) . '</p>';
		// phpcs:enable
	}

	/**
	 * What a pattern would purge, per instance.
	 *
	 * @param string $pattern URL pattern.
	 * @return void
	 */
	private function render_preview( string $pattern ): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ) => $c->purgePreview( $pattern ) );
		Operator::problems( $results, __( 'purge preview', 'trident-cache-woocommerce' ) );
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$preview = $result->value;
			printf(
				'<p><strong>%s</strong>: %s</p>',
				esc_html( $result->name() ),
				esc_html( sprintf( '%d entries would be purged (%s)', $preview->wouldPurge, Operator::bytes( $preview->estimatedBytes ) ) )
			);
			if ( array() !== $preview->keys ) {
				echo '<ul class="trident-sample">';
				foreach ( array_slice( $preview->keys, 0, 20 ) as $key ) {
					echo '<li><code>' . esc_html( (string) $key ) . '</code></li>';
				}
				echo '</ul>';
			}
		}
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		$soft    = 'soft' === ( $post['mode'] ?? 'hard' );
		$targets = Operator::targets( $post );
		$fleet   = $this->op->fleet();
		switch ( $op ) {
			case 'urls':
			case 'page':
				$pages = array();
				$lines = preg_split( '/\R/', (string) ( $post['urls'] ?? $post['url'] ?? '' ) );
				foreach ( array_slice( false === $lines ? array() : $lines, 0, self::MAX_URLS ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$page = self::own_url( $line );
					if ( null === $page ) {
						Operator::notice( 'error', sprintf( 'Not a URL of this site: %s', $line ) );
						continue;
					}
					$pages[] = $page;
				}
				foreach ( $pages as $page ) {
					$label = $page->path;
					Operator::report(
						sprintf( 'Purge %s', $label ),
						$fleet->on( 'page' === $op ? array() : $targets, static fn ( TridentClient $c ): PurgeResponse => $c->purgeUrl( $page->absolute(), $soft && 'page' !== $op ) ),
						static fn ( PurgeResponse $r ): string => self::purged( $r ),
						array( self::class, 'failure' )
					);
				}
				return;
			case 'tags':
				$tags = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $post['tags'] ?? '' ) ) ), 'strlen' ) );
				$this->op->plugin->hooks->tags( $tags );
				$this->delivered( sprintf( 'Purge tags %s', implode( ', ', $tags ) ), $this->op->plugin->purger->deliverOwn() );
				return;
			case 'product':
				$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $post['products'] ?? '' ) ) ), static fn ( int $id ): bool => $id > 0 ) );
				foreach ( $ids as $id ) {
					$this->op->plugin->hooks->product( $id );
				}
				$this->delivered( sprintf( 'Purge products %s', implode( ', ', $ids ) ), $this->op->plugin->purger->deliverOwn() );
				return;
			case 'pattern':
				$pattern = (string) ( $post['pattern'] ?? '' );
				Operator::report( sprintf( 'Purge pattern %s', $pattern ), $fleet->on( $targets, static fn ( TridentClient $c ): PurgeResponse => $c->purgeUrlPattern( $pattern, $soft ) ), static fn ( PurgeResponse $r ): string => self::purged( $r ), array( self::class, 'failure' ) );
				return;
			case 'host':
				$host = strtolower( (string) ( $post['host'] ?? '' ) );
				Operator::report( sprintf( 'Purge host %s', $host ), $fleet->on( $targets, static fn ( TridentClient $c ): PurgeResponse => $c->purgeHost( $host, $soft ) ), static fn ( PurgeResponse $r ): string => self::purged( $r ), array( self::class, 'failure' ) );
				return;
			case 'site':
				$this->op->plugin->purge_all();
				$this->delivered( __( 'Purge this site', 'trident-cache-woocommerce' ), $this->op->plugin->purger->deliverOwn() );
				return;
			case 'clear':
				// A clear is answered with the clear schema (entries removed, bytes
				// freed) and has no soft/hard mode.
				Operator::report( __( 'Clear the entire cache', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): ClearResponse => $c->clearCache() ), static fn ( ClearResponse $r ): string => sprintf( '%d entries removed, %s freed', $r->entriesRemoved, Operator::bytes( $r->bytesFreed ) ), array( self::class, 'failure' ) );
				return;
		}
	}

	/**
	 * "Purge this page" goes back to the page it was pressed on.
	 *
	 * @param array<string, string> $post Sanitised POST.
	 * @return string
	 */
	public function back_url( array $post ): string {
		$page = isset( $post['url'] ) ? self::own_url( $post['url'] ) : null;
		// Rebuilt from the parsed parts, never the posted string: only this
		// site's scheme, host and a path can come out.
		return null !== $page ? $page->absolute() : parent::back_url( $post );
	}

	/**
	 * Why a purge or clear answer is not a success, or null when Trident
	 * acknowledged it: a refused purge, a clear that did not say `cleared`.
	 *
	 * @param PurgeResponse|ClearResponse $r Response.
	 * @return string|null
	 */
	public static function failure( PurgeResponse|ClearResponse $r ): ?string {
		return $r->isAcknowledged() ? null : ( $r->failure ?? 'not acknowledged' );
	}

	/**
	 * @param PurgeResponse $r Response.
	 * @return string
	 */
	private static function purged( PurgeResponse $r ): string {
		return sprintf( '%d purged (%s)', $r->purgedCount, (string) ( $r->mode ?? 'hard' ) );
	}

	/**
	 * @param string      $what   Label.
	 * @param DrainReport $report Delivery report.
	 * @return void
	 */
	private function delivered( string $what, DrainReport $report ): void {
		Operator::notice( $report->failed > 0 ? 'warning' : 'success', sprintf( '%s: %d delivered (%d cache entries purged), %d not acknowledged%s', $what, $report->delivered, $report->purged, $report->failed, $report->failed > 0 ? ' — they stay queued and are retried automatically' : '' ) );
		foreach ( $report->instances as $name => $result ) {
			if ( null !== $result['error'] ) {
				Operator::notice( 'error', sprintf( '%s — %s: %s', $what, $name, $result['error'] ) );
			}
		}
	}
}
