<?php
/**
 * WooCommerce's "Recently viewed" tracking, moved to the browser.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Personal;

/**
 * WooCommerce tracks views with `wc_track_product_view()`: whenever the
 * Recently Viewed widget is active, EVERY product page render sets the
 * `woocommerce_recently_viewed` cookie. A response with Set-Cookie is never
 * stored, so the widget alone switched caching off for every product page.
 *
 * The plugin removes that server-side tracking and appends the product id to
 * the same cookie (same format: ids joined with "|", session-only) in the
 * browser. The rendered page stays identical for everyone; the cookie is a
 * session cookie for Cache\WooPolicy (a page rendered FOR it shows that
 * visitor's history, so it is never stored).
 */
final class RecentlyViewed {

	public const COOKIE = 'woocommerce_recently_viewed';

	/**
	 * @return void
	 */
	public function register(): void {
		// Before WooCommerce's own hook (template_redirect, 20).
		add_action( 'template_redirect', array( $this, 'replace_tracking' ), 19 );
	}

	/**
	 * @return void
	 */
	public function replace_tracking(): void {
		if ( ! is_singular( 'product' ) || ! is_active_widget( false, false, 'woocommerce_recently_viewed_products', true ) ) {
			return;
		}
		remove_action( 'template_redirect', 'wc_track_product_view', 20 );
		$id   = (int) get_queried_object_id();
		$path = COOKIEPATH ? COOKIEPATH : '/';
		add_action(
			'wp_footer',
			static function () use ( $id, $path ): void {
				$js = '(function(){var n=' . wp_json_encode( self::COOKIE ) . ',id=' . $id . ',v="";'
					. 'document.cookie.split("; ").forEach(function(c){if(c.indexOf(n+"=")===0){v=decodeURIComponent(c.slice(n.length+1));}});'
					. 'var ids=v?v.split("|").map(Number).filter(function(x){return x>0&&x!==id;}):[];ids.push(id);'
					. 'if(ids.length>15){ids=ids.slice(-15);}'
					. 'document.cookie=n+"="+encodeURIComponent(ids.join("|"))+"; path="+' . wp_json_encode( $path ) . '+"; SameSite=Lax"+(location.protocol==="https:"?"; Secure":"");})();';
				echo wp_get_inline_script_tag( $js, array( 'id' => 'trident-recently-viewed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script, ids cast to int.
			},
			100
		);
	}
}
