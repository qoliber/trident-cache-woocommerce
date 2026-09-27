<?php
/**
 * The Trident Cache admin menu.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin;

use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentWoo\Admin\Screens;
use Qoliber\TridentWoo\Admin\Screens\Screen;

/**
 * A top-level "Trident Cache" menu: the operator screens over the shared
 * admin client, and the existing settings page as its "Settings" entry.
 *
 * Every operator action arrives at {@see self::handle()}: POST only, an
 * operation the screen declares, the capability, the nonce of that screen and
 * operation, and the confirm box for the destructive ones — in that order,
 * before the screen runs anything.
 */
final class Menu {

	/** The settings page's old address (Settings → Trident Cache). */
	public const LEGACY_SETTINGS = 'trident-cache';

	/**
	 * @var list<Screen>
	 */
	private array $screens;

	/**
	 * @param Operator     $op       Shared helpers.
	 * @param SettingsPage $settings The settings page.
	 */
	public function __construct( private readonly Operator $op, private readonly SettingsPage $settings ) {
		$screens = array(
			new Screens\Dashboard( $op ),
			new Screens\Purge( $op ),
			new Screens\Entries( $op ),
			new Screens\Tags( $op ),
			new Screens\Coverage( $op ),
			new Screens\Warmer( $op ),
			new Screens\Launch( $op ),
			new Screens\Reflect( $op ),
			new Screens\Denoisers( $op ),
			new Screens\Bans( $op ),
			new Screens\Backends( $op ),
			new Screens\Discovery( $op ),
			new Screens\Events( $op ),
		);
		usort( $screens, static fn ( Screen $a, Screen $b ): int => $a->position() <=> $b->position() );
		$this->screens = $screens;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'legacy_redirect' ) );
		add_action( 'admin_post_' . Operator::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . Screens\Events::AJAX, fn () => Screens\Events::ajax( $this->op ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'admin_head', array( $this, 'styles' ) );
	}

	/**
	 * The screens, in menu order.
	 *
	 * @return list<Screen>
	 */
	public function screens(): array {
		return $this->screens;
	}

	/**
	 * @return void
	 */
	public function menu(): void {
		$cap = Operator::capability();
		add_menu_page( 'Trident Cache', 'Trident Cache', $cap, Operator::MENU, array( $this, 'render' ), 'dashicons-performance', 58.9 );
		foreach ( $this->screens as $screen ) {
			$slug = '' === $screen->slug() ? Operator::MENU : Operator::MENU . '-' . $screen->slug();
			add_submenu_page( Operator::MENU, 'Trident Cache — ' . $screen->title(), $screen->title(), $cap, $slug, array( $this, 'render' ) );
		}
		// The settings keep their own capability: a Shop Manager may change the
		// caching settings (never the connection) as before.
		add_submenu_page( Operator::MENU, 'Trident Cache — ' . __( 'Settings', 'trident-cache-woocommerce' ), __( 'Settings', 'trident-cache-woocommerce' ), SettingsPage::CAPABILITY, Operator::MENU . '-settings', array( $this->settings, 'render' ) );
	}

	/**
	 * The screen a page request is for.
	 *
	 * @param string $page The `page` query argument.
	 * @return Screen|null
	 */
	private function screen_for_page( string $page ): ?Screen {
		foreach ( $this->screens as $screen ) {
			$slug = '' === $screen->slug() ? Operator::MENU : Operator::MENU . '-' . $screen->slug();
			if ( $slug === $page ) {
				return $screen;
			}
		}
		return null;
	}

	/**
	 * A screen by its slug.
	 *
	 * @param string $slug Slug.
	 * @return Screen|null
	 */
	private function screen( string $slug ): ?Screen {
		foreach ( $this->screens as $screen ) {
			if ( $screen->slug() === $slug ) {
				return $screen;
			}
		}
		return null;
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! Operator::allowed() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'trident-cache-woocommerce' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : Operator::MENU;
		$screen = $this->screen_for_page( $page ) ?? $this->screens[0];
		echo '<div class="wrap trident-admin">';
		printf( '<h1>Trident Cache — %s</h1>', esc_html( $screen->title() ) );
		Operator::flush_notices();
		foreach ( $this->op->plugin->instance_errors as $error ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( 'TRIDENT_INSTANCES: ' . $error ) );
		}
		$screen->render();
		echo '</div>';
	}

	/**
	 * The one entry for every operator action.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			wp_die( esc_html__( 'Operator actions are POST only.', 'trident-cache-woocommerce' ), 405 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, before anything runs.
		$slug = isset( $_POST['screen'] ) ? sanitize_key( wp_unslash( (string) $_POST['screen'] ) ) : '';
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		// phpcs:enable
		$screen = $this->screen( $slug );
		if ( null === $screen || ! array_key_exists( $op, $screen->ops() ) ) {
			wp_die( esc_html__( 'Unknown operation.', 'trident-cache-woocommerce' ), 400 );
		}
		if ( ! Operator::allowed() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'trident-cache-woocommerce' ), 403 );
		}
		check_admin_referer( Operator::nonce_action( $slug, $op ) );
		$post = self::sanitised_post();
		if ( $screen->ops()[ $op ] && '1' !== ( $post['confirm'] ?? '' ) ) {
			Operator::notice( 'error', __( 'Not done: tick the confirmation box to run this action.', 'trident-cache-woocommerce' ) );
		} else {
			$screen->handle( $op, $post );
		}
		wp_safe_redirect( $screen->back_url( $post ) );
		exit;
	}

	/**
	 * Every scalar POST field, unslashed, with control characters (other than
	 * newlines, for the URL lists) removed and bounded in length.
	 *
	 * Not sanitize_text_field(): it strips percent-encoded octets and anything
	 * tag-like, which corrupts exactly what these forms carry — URLs with
	 * encoded slugs (/produkt/%C5%BC…/), query strings, regular expressions.
	 * The values are never echoed raw (every screen escapes on output), go to
	 * the admin API as JSON, and each screen validates what it uses: the
	 * page URLs must be this site's, ids and enums are checked.
	 *
	 * @return array<string, string>
	 */
	private static function sanitised_post(): array {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
		foreach ( $_POST as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) || in_array( $key, array( 'action', '_wpnonce', '_wp_http_referer' ), true ) ) {
				continue;
			}
			$clean                       = (string) preg_replace( '/[^\P{Cc}\n\r\t]/u', '', wp_unslash( (string) $value ) );
			$out[ sanitize_key( $key ) ] = trim( mb_substr( $clean, 0, 20000 ) );
		}
		return $out;
	}

	/**
	 * The old Settings → Trident Cache address lands on the new settings page.
	 *
	 * @return void
	 */
	public function legacy_redirect(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect.
		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && self::LEGACY_SETTINGS === $_GET['page'] ) {
			wp_safe_redirect( Operator::url( 'settings' ) );
			exit;
		}
	}

	/**
	 * "Trident Cache" in the admin bar; on the storefront, "Purge this page".
	 *
	 * A POST form (hidden) with its nonce, submitted by the menu item: the
	 * purge is never a GET a crafted link could trigger.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ): void {
		if ( ! Operator::allowed() || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'trident-cache',
				'title' => 'Trident',
				'href'  => Operator::url(),
			)
		);
		if ( is_admin() || array() === $this->op->fleet()->instances() ) {
			return;
		}
		[ $host, $scheme ] = Screens\Purge::site();
		$uri               = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by Purge::own_url() on submit, escaped here.
		$form              = $this->op->form( 'purge', 'page', 'display:none' )
			. sprintf( '<input type="hidden" name="url" value="%s">', esc_attr( $scheme . '://' . $host . $uri ) )
			. '</form>';
		$bar->add_node(
			array(
				'parent' => 'trident-cache',
				'id'     => 'trident-purge-page',
				'title'  => esc_html__( 'Purge this page', 'trident-cache-woocommerce' ),
				'href'   => '#',
				'meta'   => array(
					'html'    => str_replace( '<form ', '<form id="trident-purge-page-form" ', $form ),
					'onclick' => "document.getElementById('trident-purge-page-form').submit();return false;",
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public function dashboard_widget(): void {
		if ( ! Operator::allowed() ) {
			return;
		}
		wp_add_dashboard_widget( 'trident_cache_status', 'Trident Cache', array( $this, 'render_widget' ) );
	}

	/**
	 * Hit rate and entries per instance, and the purge queue — read at most
	 * once a minute, so the WordPress dashboard does not wait on the admin API.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$rows = get_transient( 'trident_dashboard_widget' );
		if ( ! is_array( $rows ) ) {
			$rows = array();
			foreach ( $this->op->fleet()->each( static fn ( TridentClient $c ) => $c->stats() ) as $result ) {
				$rows[] = $result->isOk()
					? array( $result->name(), sprintf( '%s%% hits · %s entries · %s', number_format_i18n( $result->value->getHitRatioPercent(), 1 ), number_format_i18n( $result->value->entries ), Operator::bytes( $result->value->memoryUsed ) ), true )
					: array( $result->name(), $result->reason(), false );
			}
			set_transient( 'trident_dashboard_widget', $rows, MINUTE_IN_SECONDS );
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			printf( '<li><strong>%s</strong>: <span class="%s">%s</span></li>', esc_html( (string) $row[0] ), $row[2] ? 'trident-ok' : 'trident-bad', esc_html( (string) $row[1] ) );
		}
		if ( array() === $rows ) {
			printf( '<li>%s</li>', esc_html__( 'No Trident instance configured.', 'trident-cache-woocommerce' ) );
		}
		echo '</ul>';
		$stats = $this->op->plugin->store->stats( time() );
		printf( '<p>%s · <a href="%s">%s</a></p>', esc_html( sprintf( 'Purges pending: %d', $stats['pending'] ) ), esc_url( Operator::url() ), esc_html__( 'Open Trident Cache', 'trident-cache-woocommerce' ) );
	}

	/**
	 * @return void
	 */
	public function styles(): void {
		echo '<style>.trident-ok{color:#008a20;font-weight:600}.trident-bad{color:#d63638;font-weight:600}.trident-admin .trident-table{margin:.5em 0 1.5em}.trident-admin .trident-details{max-width:50em;margin:.5em 0 1.5em}.trident-admin .trident-details th{width:18em}.trident-confirm{margin-right:.6em;white-space:nowrap}</style>';
	}
}
