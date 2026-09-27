<?php
/**
 * Per-request tokens on shared pages: punched out as private ESI fragments.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Esi;

use Qoliber\Trident\Esi\FragmentResponse;
use Qoliber\Trident\Esi\Markup;

/**
 * Tokens WordPress and WooCommerce inline into pages, and how each is kept out
 * of the shared cache entry. Measured on WordPress 7.1.2 + WooCommerce 11.1.2
 * (tests/woocommerce-e2e 58): ordinary catalogue pages carry none — in both
 * Storefront and Twenty Twenty-Five — but any page with a React-rendered
 * WooCommerce block (All Products, the legacy filters) carries three:
 *
 * | token | where | handling |
 * |---|---|---|
 * | Store API nonce + timestamp | `<script id="wc-blocks-middleware-js-before">` (`wcBlocksMiddlewareConfig`) | private ESI fragment: the script, fresh per request |
 * | `wp_rest` nonce | `<script id="wp-api-fetch-js-after">` (apiFetch nonce middleware) | private ESI fragment: the script, fresh per request |
 * | a preloaded Store API cart response with `Nonce`, `Nonce-Timestamp` and a guest **`Cart-Token`** (a session JWT) | inside `<script id="wc-settings-js-before">` | the cart preload is removed — see strip_cart_preload() |
 *
 * The scripts must exist before the blocks' scripts run, which a private
 * fragment guarantees (Trident assembles it into the HTML). Private fragments
 * are fetched on every assembly and never stored; in Trident `assemble` mode a
 * private fragment makes the whole page uncacheable, so there the page is not
 * stored instead (NonceGuard).
 *
 * WooCommerce binds guest nonces to the session only for actions starting
 * with `woocommerce` (WC_Session_Handler::maybe_update_nonce_user_logged_out);
 * `wc_store_api` and `wp_rest` guest nonces are the same for every visitor
 * until the next nonce tick. The risk of a cached copy is therefore EXPIRY (a
 * page served from cache past the tick answers 403 on the first cart action),
 * and a fragment rendered without cookies is exactly right for every guest.
 * Logged-in visitors never get cached pages.
 */
final class PrivateTokens {

	/** Inline scripts carrying a token: handle => position. */
	public const SCRIPTS = array(
		'wc-blocks-middleware' => 'before',
		'wp-api-fetch'         => 'after',
	);

	/**
	 * Where the token sits in each script — blanked in the inline fallback, so
	 * the stored template holds no token at all. The fallback only runs where
	 * nothing processes ESI; there the scripts recover by themselves (apiFetch
	 * refetches a nonce from its nonceEndpoint, the Store API middleware takes
	 * the `Nonce` header of the first response).
	 */
	private const TOKEN_PATTERNS = array(
		'wc-blocks-middleware' => array( "/(storeApiNonce:\\s*')[^']*(')/", "/(wcStoreApiNonceTimestamp:\\s*')[^']*(')/" ),
		'wp-api-fetch'         => array( '/(createNonceMiddleware\\(\\s*")[^"]*(")/' ),
	);

	/**
	 * @param Context $context ESI context.
	 */
	public function __construct( private readonly Context $context ) {
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Early: the scripts are registered on `init`; nothing else is needed.
		add_action( 'init', array( $this, 'serve' ), 9999 );
	}

	/**
	 * Replace the token scripts with private includes (inline fallback kept).
	 *
	 * @param string $html Page.
	 * @return array{html: string, scan: string, punched: list<string>} `scan` is
	 *         the page as the guard must see it (fallbacks included — they hold
	 *         no token).
	 */
	public function rewrite( string $html ): array {
		$scan    = $html;
		$punched = array();
		foreach ( self::SCRIPTS as $handle => $position ) {
			$id      = $handle . '-js-' . $position;
			$pattern = '#<script id="' . preg_quote( $id, '#' ) . '"[^>]*>.*?</script>#s';
			if ( 1 !== preg_match( $pattern, $html, $m ) ) {
				continue;
			}
			$fallback  = (string) preg_replace( self::TOKEN_PATTERNS[ $handle ], '${1}${2}', $m[0] );
			$include   = Markup::includeWithFallback( $this->context->url( 'token', array( 'handle' => $handle ) ), $fallback );
			$html      = str_replace( $m[0], $include, $html );
			$scan      = str_replace( $m[0], $fallback, $scan );
			$punched[] = $handle;
		}
		return array(
			'html'    => $html,
			'scan'    => $scan,
			'punched' => $punched,
		);
	}

	/**
	 * Remove the preloaded `/wc/store/v1/cart` response from `wc-settings`.
	 *
	 * It carries the render's own Store API nonce and a guest `Cart-Token`
	 * (a JWT naming a guest session), and it cannot be a fragment: wcSettings is
	 * built from the blocks the page rendered. The preload is an optimisation —
	 * without it the block fetches `/wc/store/v1/cart` on mount, uncached, and
	 * gets THIS visitor's cart, nonce and token. A client-side refresh is the
	 * correct handling here, and WooCommerce already implements it.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function strip_cart_preload( string $html ): string {
		return (string) preg_replace_callback(
			"#wp\\.apiFetch\\.use\\( wp\\.apiFetch\\.createPreloadingMiddleware\\( JSON\\.parse\\( decodeURIComponent\\( '([^']*)' \\) \\) \\) \\);#",
			static function ( array $m ): string {
				$requests = json_decode( rawurldecode( $m[1] ), true );
				if ( ! is_array( $requests ) ) {
					return '';
				}
				foreach ( array_keys( $requests ) as $path ) {
					if ( str_starts_with( (string) $path, '/wc/store/v1/cart' ) ) {
						unset( $requests[ $path ] );
					}
				}
				if ( array() === $requests ) {
					return '';
				}
				return "wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( JSON.parse( decodeURIComponent( '" . rawurlencode( (string) wp_json_encode( $requests ) ) . "' ) ) ) );";
			},
			$html
		);
	}

	/**
	 * Serve `?trident-esi=token&a=…&s=…`: the inline script, freshly rendered.
	 *
	 * @return void
	 */
	public function serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, signed, read-only.
		if ( ! Context::is_fragment_request() || 'token' !== sanitize_key( wp_unslash( (string) $_GET[ Context::QUERY ] ) ) ) {
			return;
		}
		$args = $this->context->signer()->verify( 'token', (string) wp_unslash( (string) ( $_GET['a'] ?? '' ) ), (string) wp_unslash( (string) ( $_GET['s'] ?? '' ) ) );
		// phpcs:enable
		$handle = is_array( $args ) ? (string) ( $args['handle'] ?? '' ) : '';
		if ( ! isset( self::SCRIPTS[ $handle ] ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		$position = self::SCRIPTS[ $handle ];
		$data     = (string) wp_scripts()->get_inline_script_data( $handle, $position );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		foreach ( FragmentResponse::private() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( $this->context->settings()->get( 'debug_headers' ) ) {
			header( 'X-Trident-Decision: esi-private; ' . $handle );
		}
		echo wp_get_inline_script_tag( $data, array( 'id' => $handle . '-js-' . $position ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-rendered script.
		exit;
	}
}
