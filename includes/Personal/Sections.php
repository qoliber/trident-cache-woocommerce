<?php
/**
 * Personal bits on shared pages, rendered client-side from a local cache.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Personal;

/**
 * Personal sections: "Hello, Anna", a wishlist count, anything per-visitor that
 * is not the cart.
 *
 * The cart already has this machinery in WooCommerce: `wc-cart-fragments` keeps
 * the mini-cart HTML in sessionStorage and re-fetches it only when the
 * `woocommerce_cart_hash` cookie changes. This class applies the same pattern to
 * everything else:
 *
 *  - The cached page carries empty placeholders, identical for every visitor:
 *    `<span data-trident-section="customer_name" hidden></span>`.
 *  - The data comes from `/?wc-ajax=trident_sections` (never cached), as JSON.
 *  - The browser keeps it in localStorage together with a VERSION: the
 *    `trident_pv` cookie joined with WooCommerce's `woocommerce_cart_hash`. The
 *    server rotates `trident_pv` whenever personal data changes (login, logout,
 *    profile update, `do_action( 'trident_personal_data_changed' )`); the cart
 *    hash changes with the cart. Same version → render from storage, no request.
 *  - No cookie → nothing personal to show → no request at all. An anonymous
 *    visitor costs the origin nothing.
 *
 * Values are inserted with textContent, never as HTML.
 */
final class Sections {

	public const COOKIE = 'trident_pv';
	public const ACTION = 'trident_sections';

	/**
	 * The cookies whose values make up the version, in order: ours, rotated on
	 * personal-data changes, and WooCommerce's cart hash, which changes with
	 * the cart. The browser joins the same cookies the same way.
	 */
	public const VERSION_COOKIES = array( self::COOKIE, 'woocommerce_cart_hash' );

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'wc_ajax_' . self::ACTION, array( $this, 'serve' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// The version is PER USER (user meta) for logged-in customers: an admin
		// editing a customer rotates THAT customer's version, and the customer's
		// next request (logged-in requests always reach PHP) picks it up. For
		// guests it is the cookie itself.
		add_action( 'init', array( $this, 'sync_cookie' ) );
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'wp_logout', array( $this, 'rotate' ), 20, 0 );
		add_action( 'profile_update', array( $this, 'bump_user' ), 20, 1 );
		add_action( 'woocommerce_save_account_details', array( $this, 'bump_user' ), 20, 1 );
		add_action( 'woocommerce_checkout_update_customer', array( $this, 'on_checkout_customer' ), 20, 1 );
		add_action( 'trident_personal_data_changed', array( $this, 'changed' ), 20, 1 );
	}

	/**
	 * A user's current version (created on first use).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public function user_version( int $user_id ): string {
		$version = (string) get_user_meta( $user_id, self::COOKIE, true );
		if ( '' === $version ) {
			$version = bin2hex( random_bytes( 8 ) );
			update_user_meta( $user_id, self::COOKIE, $version );
		}
		return $version;
	}

	/**
	 * Personal data of `$user_id` changed — whoever changed it.
	 *
	 * @param int|string $user_id User.
	 * @return void
	 */
	public function bump_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$version = bin2hex( random_bytes( 8 ) );
		update_user_meta( $user_id, self::COOKIE, $version );
		if ( get_current_user_id() === $user_id ) {
			$this->set_cookie( $version );
		}
	}

	/**
	 * A logged-in visitor whose cookie does not carry their version (an admin
	 * edited them, they logged in elsewhere) gets it now.
	 *
	 * @return void
	 */
	public function sync_cookie(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$version = $this->user_version( get_current_user_id() );
		if ( ( $_COOKIE[ self::COOKIE ] ?? '' ) !== $version ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
			$this->set_cookie( $version );
		}
	}

	/**
	 * @param string   $login Login.
	 * @param \WP_User $user  User.
	 * @return void
	 */
	public function on_login( $login, $user ): void {
		unset( $login );
		if ( $user instanceof \WP_User ) {
			$this->set_cookie( $this->user_version( (int) $user->ID ) );
		}
	}

	/**
	 * @param \WC_Customer|mixed $customer Customer saved at checkout.
	 * @return void
	 */
	public function on_checkout_customer( $customer ): void {
		$id = ( is_object( $customer ) && method_exists( $customer, 'get_id' ) ) ? (int) $customer->get_id() : 0;
		if ( $id > 0 ) {
			$this->bump_user( $id );
		} else {
			$this->rotate(); // A guest: the version is the cookie itself.
		}
	}

	/**
	 * `do_action( 'trident_personal_data_changed' [, $user_id ] )`.
	 *
	 * @param int|string|null $user_id User whose data changed; the current visitor when omitted.
	 * @return void
	 */
	public function changed( $user_id = null ): void {
		$user_id = null === $user_id || '' === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id > 0 ) {
			$this->bump_user( $user_id );
		} else {
			$this->rotate();
		}
	}

	/**
	 * Sections for the current visitor. Filter `trident_personal_sections` to add
	 * your own (a wishlist count, loyalty points …); values must be scalars.
	 *
	 * @return array<string, scalar>
	 */
	public function sections(): array {
		$sections = array(
			'customer_name' => '',
			'logged_in'     => is_user_logged_in(),
			'cart_count'    => 0,
		);
		if ( is_user_logged_in() ) {
			$user                      = wp_get_current_user();
			$sections['customer_name'] = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->display_name;
		} elseif ( function_exists( 'WC' ) && WC()->customer ) {
			// A guest who filled in the checkout form is remembered by the session.
			$sections['customer_name'] = (string) WC()->customer->get_billing_first_name();
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$sections['cart_count'] = (int) WC()->cart->get_cart_contents_count();
		}
		$filtered = apply_filters( 'trident_personal_sections', $sections );
		$out      = array();
		foreach ( (array) $filtered as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ sanitize_key( (string) $key ) ] = $value;
			}
		}
		return $out;
	}

	/**
	 * `?wc-ajax=trident_sections`.
	 *
	 * @return void
	 */
	public function serve(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		wp_send_json(
			array(
				'version'  => $this->version(),
				'sections' => $this->sections(),
			)
		);
	}

	/**
	 * The version the data was computed for, as the browser computes it.
	 *
	 * @return string
	 */
	public function version(): string {
		$values = array();
		foreach ( self::VERSION_COOKIES as $name ) {
			$values[] = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';
		}
		return implode( '|', $values );
	}

	/**
	 * Personal data changed: give the browser a new version.
	 *
	 * The response that sets this cookie is not stored (Set-Cookie makes it
	 * `no-store`, see Cache\Policy), and it is a login/logout/POST anyway.
	 *
	 * @return void
	 */
	public function rotate(): void {
		$this->set_cookie( bin2hex( random_bytes( 8 ) ) );
	}

	/**
	 * @param string $value Version.
	 * @return void
	 */
	private function set_cookie( string $value ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => time() + 30 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false, // The script must read it; it carries no secret.
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * @return void
	 */
	public function enqueue(): void {
		// The platform-neutral loader comes from qoliber/trident-php (shared with
		// the other platform modules); the WooCommerce binding only re-checks
		// after WooCommerce's own AJAX cart events.
		wp_register_script(
			'trident-sections',
			\Qoliber\TridentWoo\Plugin::library_asset_url( 'js/trident-sections.js' ),
			array(),
			TRIDENT_WOO_VERSION,
			array( 'in_footer' => true )
		);
		// Static for every visitor — safe on a shared page.
		wp_add_inline_script(
			'trident-sections',
			'window.tridentSections = ' . wp_json_encode(
				array(
					'endpoint'       => \WC_AJAX::get_endpoint( self::ACTION ),
					'versionCookies' => self::VERSION_COOKIES,
					'maxAge'         => HOUR_IN_SECONDS,
				)
			) . ';',
			'before'
		);
		wp_enqueue_script(
			'trident-woo-sections',
			plugins_url( 'assets/js/trident-woo-sections.js', TRIDENT_WOO_FILE ),
			array( 'trident-sections' ),
			TRIDENT_WOO_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * `[trident_section name="customer_name" before="Hello, " after="!"]` — an
	 * empty placeholder; the script fills it and un-hides it when there is a value.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'name'   => '',
				'before' => '',
				'after'  => '',
			),
			(array) $atts,
			'trident_section'
		);
		if ( '' === $atts['name'] ) {
			return '';
		}
		return sprintf(
			'<span data-trident-section="%s" data-trident-before="%s" data-trident-after="%s" hidden></span>',
			esc_attr( sanitize_key( $atts['name'] ) ),
			esc_attr( $atts['before'] ),
			esc_attr( $atts['after'] )
		);
	}
}
