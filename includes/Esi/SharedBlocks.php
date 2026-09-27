<?php
/**
 * Shared blocks as ESI fragments: nav menus, widget areas, the navigation block.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Esi;

use Qoliber\Trident\Cache\Policy;
use Qoliber\Trident\Esi\FragmentResponse;
use Qoliber\TridentWoo\Cache\WooPolicy;
use Qoliber\Trident\Esi\Markup;
use Qoliber\TridentWoo\Tags\Names;

/**
 * Blocks that are the same for every visitor and appear on every page.
 *
 * Inline, a menu edit must purge the whole site. As a fragment it is one cache
 * entry with its own tags and TTL: the edit purges that entry, and every cached
 * page picks up the new menu on its next assembly (Trident `hole_punch`).
 *
 * Each block is emitted with the ESI fallback pattern (library `Markup`): the
 * inline render stays on the page inside `<esi:remove>`, so the page keeps the
 * block's side effects (enqueued scripts, client-side state) and anything that
 * is not an ESI processor shows the inline copy.
 *
 *  - classic menus: `pre_wp_nav_menu`, by theme location (`esi_menus`);
 *  - widget areas: `dynamic_sidebar_before/after`, by sidebar id (`esi_widgets`);
 *  - block themes: the `core/navigation` block (`esi_navigation`).
 */
final class SharedBlocks {

	/** @var bool Re-entrancy guard: the inline fallback renders the block normally. */
	private bool $rendering = false;

	/** @var array<int, string> Sidebar ids whose output is being captured. */
	private array $capturing = array();

	/**
	 * @param Context                               $context   ESI context.
	 * @param \Qoliber\TridentWoo\Tags\TagCollector $collector Collects what the fragment renders
	 *                                                            (a Recent Posts widget lists posts).
	 * @param array<int, string>                    $session_cookies Session cookie prefixes.
	 */
	public function __construct(
		private readonly Context $context,
		private readonly \Qoliber\TridentWoo\Tags\TagCollector $collector,
		private readonly array $session_cookies = WooPolicy::SESSION_COOKIES
	) {
	}

	/**
	 * Whether this fragment request carries a login or a session: then the
	 * render may contain that visitor's data (menu-cart plugins put the cart
	 * count INTO the menu) and must never become the shared fragment.
	 *
	 * @return bool
	 */
	private function personal_request(): bool {
		$names = array_map( 'strval', array_keys( $_COOKIE ) );
		return is_user_logged_in()
			|| Policy::hasCookie( $names, WooPolicy::LOGGED_IN_COOKIES )
			|| Policy::hasCookie( $names, $this->session_cookies );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_wp_nav_menu', array( $this, 'nav_menu' ), 10, 2 );
		add_action( 'dynamic_sidebar_before', array( $this, 'sidebar_before' ), 1, 1 );
		add_action( 'dynamic_sidebar_after', array( $this, 'sidebar_after' ), 999, 1 );
		add_filter( 'render_block_core/navigation', array( $this, 'navigation_block' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
	}

	/**
	 * `pre_wp_nav_menu`: include + inline fallback instead of the menu.
	 *
	 * @param string|null $output Short-circuit value.
	 * @param \stdClass   $args   wp_nav_menu() arguments.
	 * @return string|null
	 */
	public function nav_menu( $output, $args ) {
		if ( null !== $output || $this->rendering || ! $this->context->active() ) {
			return $output;
		}
		$location = is_object( $args ) ? (string) ( $args->theme_location ?? '' ) : '';
		if ( '' === $location || ! $this->context->listed( 'esi_menus', $location ) ) {
			return $output;
		}
		$portable = self::portable_args( (array) $args );
		if ( null === $portable ) {
			return $output; // A Walker object or callback cannot travel in a URL.
		}
		$portable['theme_location'] = $location;
		$this->rendering            = true;
		$inline                     = (string) wp_nav_menu(
			array_merge(
				(array) $args,
				array(
					'echo'                 => false,
					'trident_esi_fallback' => true,
				)
			)
		);
		$this->rendering            = false;
		// wp_nav_menu() echoes or returns this, as its own `echo` argument says.
		return Markup::includeWithFallback( $this->context->url( 'menu', $portable ), $inline );
	}

	/**
	 * Start capturing a widget area.
	 *
	 * @param int|string $index Sidebar id.
	 * @return void
	 */
	public function sidebar_before( $index ): void {
		$index = (string) $index;
		if ( $this->rendering || ! $this->context->active() || ! $this->context->widget_area_shared( $index ) ) {
			return;
		}
		$this->capturing[] = $index;
		ob_start();
	}

	/**
	 * Replace the captured widget area with include + fallback.
	 *
	 * @param int|string $index Sidebar id.
	 * @return void
	 */
	public function sidebar_after( $index ): void {
		$index = (string) $index;
		if ( array() === $this->capturing || end( $this->capturing ) !== $index ) {
			return;
		}
		array_pop( $this->capturing );
		$inline = (string) ob_get_clean();
		echo Markup::includeWithFallback( $this->context->url( 'widgets', array( 'sidebar' => $index ) ), $inline ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget output, rendered by WordPress.
	}

	/**
	 * `render_block_core/navigation`: the block theme's menu.
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public function navigation_block( $html, $block ) {
		if ( $this->rendering || ! $this->context->active() || ! $this->context->settings()->get( 'esi_navigation' ) || '' === trim( (string) $html ) ) {
			return $html;
		}
		$markup = serialize_block( (array) $block );
		if ( strlen( $markup ) > 2000 ) {
			return $html; // A navigation with inline links is too large for a URL; keep it inline.
		}
		return Markup::includeWithFallback( $this->context->url( 'navigation', array( 'block' => $markup ) ), (string) $html );
	}

	/**
	 * A shared fragment cannot know the current page: `current-menu-item` is
	 * restored in the browser.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( $this->context->active() ) {
			wp_enqueue_script( 'trident-esi-menu', plugins_url( 'assets/js/trident-esi-menu.js', TRIDENT_WOO_FILE ), array(), TRIDENT_WOO_VERSION, array( 'in_footer' => true ) );
		}
	}

	/**
	 * Serve `?trident-esi=menu|widgets|navigation&a=…&s=…`.
	 *
	 * @return void
	 */
	public function serve(): void {
		if ( ! Context::is_fragment_request() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, signed, read-only.
		$type    = sanitize_key( wp_unslash( (string) $_GET[ Context::QUERY ] ) );
		$encoded = (string) wp_unslash( (string) ( $_GET['a'] ?? '' ) );
		$sig     = (string) wp_unslash( (string) ( $_GET['s'] ?? '' ) );
		// phpcs:enable
		if ( ! in_array( $type, array( 'menu', 'widgets', 'navigation' ), true ) ) {
			return; // Private tokens are served earlier by PrivateTokens.
		}
		$args = $this->context->signer()->verify( $type, $encoded, $sig );
		if ( null === $args ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		// A request with a login or a session gets its own render, never stored;
		// only a cookie-less (guest) render may be the shared fragment.
		$personal = $this->personal_request();
		if ( ! $personal ) {
			wp_set_current_user( 0 );
		}
		$this->rendering = true;
		// The page collector's set: the_post adds whatever the fragment lists.
		$tags = $this->collector->tags();
		$tags->addAll( array( Names::ALL, Names::esi( $type ) ) );
		switch ( $type ) {
			case 'menu':
				$tags->add( Names::MENU );
				$html = (string) wp_nav_menu( array_merge( self::scalars( $args ), array( 'echo' => false ) ) );
				break;
			case 'widgets':
				$tags->add( Names::WIDGETS );
				ob_start();
				dynamic_sidebar( (string) ( $args['sidebar'] ?? '' ) );
				$html = (string) ob_get_clean();
				break;
			default:
				$tags->add( Names::MENU );
				$blocks = parse_blocks( (string) ( $args['block'] ?? '' ) );
				$html   = isset( $blocks[0] ) ? render_block( $blocks[0] ) : '';
		}
		$this->rendering = false;
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		$headers = $personal
			? FragmentResponse::private()
			: FragmentResponse::shared( (int) $this->context->settings()->get( 'esi_ttl' ), $tags );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( $this->context->settings()->get( 'debug_headers' ) ) {
			header( 'X-Trident-Decision: esi-fragment; ' . $type );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by WordPress.
		exit;
	}

	/**
	 * Keep only wp_nav_menu() arguments that survive a URL round trip.
	 *
	 * @param array<string, mixed> $args wp_nav_menu() arguments.
	 * @return array<string, scalar>|null Null when a non-scalar argument matters.
	 */
	public static function portable_args( array $args ): ?array {
		$out = array();
		foreach ( $args as $key => $value ) {
			if ( in_array( $key, array( 'echo', 'theme_location', 'menu', 'trident_esi_fallback' ), true ) ) {
				continue;
			}
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				// A Walker instance or a closure changes the markup and cannot be
				// reproduced by the fragment request.
				return null;
			}
			$out[ (string) $key ] = $value;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $args Decoded arguments.
	 * @return array<string, scalar>
	 */
	private static function scalars( array $args ): array {
		return array_filter( $args, 'is_scalar' );
	}
}
