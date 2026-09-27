<?php
/**
 * Sends Cache-Control and cache tags once the page is complete.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cache;

use Qoliber\Trident\Cache\Policy;
use Qoliber\Trident\Cache\RequestContext;
use Qoliber\Trident\Cache\Decision;
use Qoliber\TridentWoo\Esi\Context;
use Qoliber\TridentWoo\Esi\PrivateTokens;
use Qoliber\TridentWoo\Tags\TagCollector;

/**
 * Buffers the front-end response and decides at the END.
 *
 * Deciding at the start (the usual `send_headers` approach) has to guess: a
 * theme or plugin can still set a cookie, change the status, or render a list of
 * products whose tags the page must carry. The buffer's callback runs after the
 * body is complete and before any header is sent, so the decision uses what the
 * response actually is.
 */
final class ResponseController {

	/** @var bool */
	private bool $decided = false;

	/**
	 * @param Policy        $policy        Rules.
	 * @param TagCollector  $collector     Tags of this page.
	 * @param bool          $debug_headers Send X-Trident-Decision.
	 * @param NonceGuard    $guard         Guest nonces created during the render.
	 * @param Context       $esi           ESI context.
	 * @param PrivateTokens $tokens        Token punch-out.
	 */
	public function __construct(
		private readonly Policy $policy,
		private readonly TagCollector $collector,
		private readonly bool $debug_headers,
		private readonly NonceGuard $guard,
		private readonly Context $esi,
		private readonly PrivateTokens $tokens
	) {
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->collector->register();
		$this->guard->register();
		// Priority 0, before WooCommerce's wc-ajax router exits on the same hook
		// (it runs at 0 too, but was added first) — wc-ajax is excluded anyway.
		add_action( 'template_redirect', array( $this, 'start' ), 0 );
		// REST responses carry no Cache-Control by default; some are personal
		// (the Store API cart). Never let a shared cache guess.
		add_filter( 'rest_post_dispatch', array( $this, 'rest_headers' ), 999 );
	}

	/**
	 * @return void
	 */
	public function start(): void {
		if ( headers_sent() || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_trackback() || is_robots()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI )
			|| isset( $_GET['wc-ajax'] ) || Context::is_fragment_request() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		ob_start( array( $this, 'finish' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * For a response that may be shared, the page is also cleaned of
	 * per-request tokens before anything is sent: known ones are punched out as
	 * private ESI fragments (or, for the Store API cart preload, removed), and
	 * if any guest nonce value is still in the HTML the response is not stored.
	 *
	 * @param string $html  Buffered output.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string Output.
	 */
	public function finish( $html, $phase = 0 ) {
		if ( $this->decided || headers_sent() ) {
			return $html;
		}
		$this->decided = true;
		$out           = (string) $html;
		// Something flushed or ended the buffer before the page was complete
		// ("flush early" plugins): the headers go out with this chunk, before
		// the rest of the page — its tags, cookies and tokens — exists. A
		// non-final phase says so; an HTML page without its closing tag says so
		// when the buffer was ENDED early (that phase is marked final). Never
		// stored.
		$partial = 0 === ( (int) $phase & PHP_OUTPUT_HANDLER_FINAL )
			? 'partial flush'
			: ( self::is_html_response() && false === stripos( $out, '</html>' ) ? 'incomplete page' : null );
		if ( null !== $partial ) {
			header( 'Cache-Control: ' . Decision::privateNoStore( $partial )->cacheControl );
			if ( $this->debug_headers ) {
				header( 'X-Trident-Decision: no-store; ' . $partial );
			}
			return $out;
		}
		try {
			$decision = $this->policy->decide( $this->context() );
			if ( $decision->cacheable ) {
				[ $out, $decision ] = $this->shareable( $out, $decision );
			}
			header( 'Cache-Control: ' . $decision->cacheControl );
			if ( $decision->cacheable ) {
				header_remove( 'Pragma' );
				header_remove( 'Expires' );
				$this->collector->add_identity();
				$value = $this->collector->tags()->headerValue();
				if ( '' !== $value ) {
					header( 'X-Cache-Tags: ' . $value );
				}
			} else {
				$out = (string) $html;
			}
			if ( $this->debug_headers ) {
				header( 'X-Trident-Decision: ' . ( $decision->cacheable ? 'cacheable' : 'no-store; ' . $decision->reason ) );
			}
		} catch ( \Throwable $e ) {
			// Never break the page over a header; never let it be stored either.
			header( 'Cache-Control: private, no-store' );
			return (string) $html;
		}
		return $out;
	}

	/**
	 * Whether the response being buffered is HTML (no Content-Type yet counts:
	 * WordPress sends text/html by default).
	 *
	 * @return bool
	 */
	private static function is_html_response(): bool {
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'content-type:' ) ) {
				return false !== stripos( $line, 'text/html' );
			}
		}
		return true;
	}

	/**
	 * Make a cacheable page shareable, or say why it is not.
	 *
	 * @param string   $html     Page.
	 * @param Decision $decision Cacheable decision.
	 * @return array{0: string, 1: Decision}
	 */
	private function shareable( string $html, Decision $decision ): array {
		$html = PrivateTokens::strip_cart_preload( $html );
		$scan = $html;
		// Private fragments only where Trident will assemble them; in assemble
		// mode a private fragment would make the page uncacheable anyway.
		if ( $this->esi->active() && ! $this->esi->assemble_mode() && $this->esi->settings()->get( 'esi_private' ) ) {
			$rewritten = $this->tokens->rewrite( $html );
			$html      = $rewritten['html'];
			$scan      = $rewritten['scan'];
		}
		$leaks = $this->guard->leaks( $scan );
		if ( array() !== $leaks ) {
			return array( $html, Decision::privateNoStore( 'session token ' . implode( ',', $leaks ) ) );
		}
		return array( $html, $decision );
	}

	/**
	 * @param \WP_REST_Response|mixed $response REST response.
	 * @return mixed
	 */
	public function rest_headers( $response ) {
		if ( $response instanceof \WP_REST_Response ) {
			$headers = $response->get_headers();
			$has     = false;
			foreach ( array_keys( $headers ) as $name ) {
				if ( 0 === strcasecmp( (string) $name, 'Cache-Control' ) ) {
					$has = true;
				}
			}
			if ( ! $has ) {
				$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			}
		}
		return $response;
	}

	/**
	 * Gather the request/response facts.
	 *
	 * @return RequestContext
	 */
	private function context(): RequestContext {
		$sets_cookie   = false;
		$cache_control = '';
		foreach ( headers_list() as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name = strtolower( trim( substr( $line, 0, $colon ) ) );
			if ( 'set-cookie' === $name ) {
				$sets_cookie = true;
			} elseif ( 'cache-control' === $name ) {
				$cache_control = trim( substr( $line, $colon + 1 ) );
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- read-only classification.
		$query  = array_map( static fn ( $v ): string => is_scalar( $v ) ? (string) $v : '', (array) $_GET );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		// phpcs:enable
		$private = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
		$private = (bool) apply_filters( 'trident_is_private_page', $private );
		$post    = get_queried_object();
		return new RequestContext(
			method: $method,
			path: (string) wp_parse_url( $uri, PHP_URL_PATH ),
			query: $query,
			cookieNames: array_map( 'strval', array_keys( $_COOKIE ) ),
			isPage: true,
			isLoggedIn: is_user_logged_in(),
			isPrivatePage: $private,
			isPreview: is_preview() || is_customize_preview(),
			passwordProtected: $post instanceof \WP_Post && post_password_required( $post ),
			doNotCache: defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			status: (int) http_response_code(),
			setsCookie: $sets_cookie,
			cacheControl: $cache_control
		);
	}
}
