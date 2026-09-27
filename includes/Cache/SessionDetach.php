<?php
/**
 * Render a cart visitor's cacheable page the way an anonymous visitor sees it.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cache;

use Qoliber\Trident\Cache\Policy;

/**
 * A visitor with something in the cart carries WooCommerce's session cookies.
 * A page rendered for that session may print their cart into the HTML, so the
 * policy sends it `private, no-store` — correct, but it meant that a page no
 * anonymous visitor had opened yet was rendered, sent and thrown away for every
 * shopper, each paying a full render until an anonymous visitor happened by.
 *
 * On a cacheable page request this hides WooCommerce's own session cookies from
 * the render, before WooCommerce loads the session (`init`, priority 0). The
 * page is then exactly the anonymous page: it is stored, and every visitor gets
 * it from the cache. Nothing is lost for the shopper — the browser keeps its
 * cookies, the session is untouched, and the mini-cart comes from the cart
 * fragments, exactly as it does on every cached page they are served.
 *
 * Never on the cart, checkout or account pages, never for a logged-in visitor,
 * and never when any OTHER personalising cookie is present (the recently viewed
 * history, or anything added with the `trident_session_cookies` filter): those
 * keep the `no-store` render.
 */
final class SessionDetach {

	/** WooCommerce's own session and cart cookies: the ones detached. */
	public const DETACHABLE = array( 'wp_woocommerce_session_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash' );

	/** Response header naming the detach when debug headers are on. */
	public const HEADER = 'X-Trident-Session';

	/**
	 * Names of the cookies hidden from this render.
	 *
	 * @var array<int, string>
	 */
	private array $detached = array();

	/**
	 * @param array<int, string> $session_cookies Session cookie prefixes (filtered).
	 * @param bool               $debug_headers   Send the X-Trident-Session header.
	 */
	public function __construct(
		private readonly array $session_cookies,
		private readonly bool $debug_headers
	) {}

	/**
	 * Hook in ahead of WooCommerce's session.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_detach' ), -1 );
		// `wp` 0: after the query is parsed, before WooCommerce's own `wp`
		// hooks (priority 99 sets the session cookie on order-pay).
		add_action( 'wp', array( $this, 'guard' ), 0 );
		add_action( 'send_headers', array( $this, 'debug_header' ) );
	}

	/**
	 * Which cookies to hide, or none.
	 *
	 * Pure, so the rule is testable without WordPress: nothing when the visitor
	 * is logged in, and nothing when any session cookie present is not one of
	 * WooCommerce's own — a render reading that cookie must stay private.
	 *
	 * @param array<int, string> $cookie_names       Cookie names on the request.
	 * @param array<int, string> $session_prefixes   Every personalising prefix (filtered list).
	 * @param array<int, string> $logged_in_prefixes Logged-in cookie prefixes.
	 * @return array<int, string> Names to hide.
	 */
	public static function detachable( array $cookie_names, array $session_prefixes, array $logged_in_prefixes ): array {
		if ( Policy::hasCookie( $cookie_names, $logged_in_prefixes ) ) {
			return array();
		}
		$hide = array();
		foreach ( $cookie_names as $name ) {
			if ( ! Policy::hasCookie( array( $name ), $session_prefixes ) ) {
				continue;
			}
			if ( ! Policy::hasCookie( array( $name ), self::DETACHABLE ) ) {
				return array();
			}
			$hide[] = $name;
		}
		return $hide;
	}

	/**
	 * `init` -1: hide the session cookies when this is a cacheable page request.
	 *
	 * @return void
	 */
	public function maybe_detach(): void {
		// Too late once WooCommerce has started: a theme or plugin that loaded the
		// session or cart earlier already holds this shopper's data.
		if ( did_action( 'woocommerce_init' ) || ( function_exists( 'WC' ) && null !== WC()->session ) ) {
			return;
		}
		if ( ! $this->is_cacheable_page_request() ) {
			return;
		}
		$names = self::detachable( array_map( 'strval', array_keys( $_COOKIE ) ), $this->session_cookies, WooPolicy::LOGGED_IN_COOKIES );
		$path  = $this->request_path();
		if ( array() === $names || ! (bool) apply_filters( 'trident_detach_session', true, $path ) ) {
			return;
		}
		foreach ( $names as $name ) {
			unset( $_COOKIE[ $name ] );
		}
		$this->detached = $names;
	}

	/**
	 * `wp` 0: check the detached render once the query is parsed.
	 *
	 * The page turned out private after all (a cart or checkout at a path the
	 * early check could not know): the session is gone from this render, so
	 * send the visitor to the same URL with the bypass parameter, where it is
	 * kept. And if the cart is somehow not empty (something loaded it despite
	 * the hidden cookies), the render carries a cart and must not be stored.
	 *
	 * @return void
	 */
	public function guard(): void {
		if ( array() === $this->detached ) {
			return;
		}
		$private = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
		if ( (bool) apply_filters( 'trident_is_private_page', $private ) ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt with add_query_arg and redirected same-site.
			wp_safe_redirect( add_query_arg( 'trident-nocache', '1', $uri ), 302 );
			exit;
		}
		if ( function_exists( 'WC' ) && null !== WC()->cart && ! WC()->cart->is_empty() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- the page-cache convention every cache honours.
		}
	}

	/**
	 * Name the detach on the response when debug headers are on.
	 *
	 * @return void
	 */
	public function debug_header(): void {
		if ( $this->debug_headers && array() !== $this->detached && ! headers_sent() ) {
			header( self::HEADER . ': detached' );
		}
	}

	/**
	 * Whether cookies were hidden from this render.
	 *
	 * @return bool
	 */
	public function detached(): bool {
		return array() !== $this->detached;
	}

	/**
	 * A GET/HEAD front-end page request the cache may store: not admin, AJAX,
	 * cron or REST, no bypass parameter, and not under the cart, checkout or
	 * account page.
	 *
	 * @return bool
	 */
	private function is_cacheable_page_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- read-only classification.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		// `session`: a cart-token link. WooCommerce keeps the shopper's own
		// session when it already cloned from the token — with the cookie hidden
		// it would clone a fresh one and replace their cart.
		foreach ( array_merge( WooPolicy::BYPASS_QUERY, array( 'session' ) ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return false;
			}
		}
		// phpcs:enable
		$path = $this->request_path();
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( str_starts_with( trailingslashit( $path ), $home . '/' . trim( rest_get_url_prefix(), '/' ) . '/' ) ) {
			return false;
		}
		// WooCommerce endpoints (order-pay, order-received, …) can be reached
		// under any page; order-pay sets the session cookie, which with the
		// cookie hidden would replace the shopper's session.
		if ( function_exists( 'WC' ) && null !== WC()->query ) {
			$segments = array_filter( explode( '/', $path ) );
			foreach ( WC()->query->get_query_vars() as $slug ) {
				if ( '' !== (string) $slug && in_array( (string) $slug, $segments, true ) ) {
					return false;
				}
			}
		}
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;
			if ( $id <= 0 ) {
				continue;
			}
			// Plain permalinks address the page by id.
			if ( isset( $_GET['page_id'] ) && (int) $_GET['page_id'] === $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
			$link = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
			if ( '' !== $link && '/' !== $link && str_starts_with( trailingslashit( $path ), trailingslashit( $link ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The request path.
	 *
	 * @return string
	 */
	private function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}
}
