<?php
/**
 * Wiring.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo;

use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\Trident\Delivery\Purger;
use Qoliber\Trident\Tags\TagSet;
use Qoliber\TridentWoo\Admin\SettingsPage;
use Qoliber\TridentWoo\Cache\ResponseController;
use Qoliber\TridentWoo\Cache\SessionDetach;
use Qoliber\TridentWoo\Cache\WooPolicy;
use Qoliber\TridentWoo\Cache\NonceGuard;
use Qoliber\TridentWoo\Esi\Context as EsiContext;
use Qoliber\TridentWoo\Esi\PrivateTokens;
use Qoliber\TridentWoo\Esi\SharedBlocks;
use Qoliber\TridentWoo\Personal\RecentlyViewed;
use Qoliber\TridentWoo\Personal\Sections;
use Qoliber\TridentWoo\Purge\PurgeHooks;
use Qoliber\TridentWoo\Purge\WpdbOutboxStore;
use Qoliber\TridentWoo\Purge\WpHttpTransport;
use Qoliber\TridentWoo\Tags\Names;
use Qoliber\TridentWoo\Tags\TagCollector;

/**
 * Builds the object graph once per request.
 */
final class Plugin {

	public const CRON_HOOK     = 'trident_wc_purge_drain';
	public const CRON_SCHEDULE = 'trident_wc_every_minute';

	/** Pre-1.0 hook name (generic enough for another Trident plugin to take). */
	public const LEGACY_CRON_HOOK = 'trident_purge_drain';

	/** Purges older than this make `wp trident purge status` exit 1 (same as the Magento module). */
	public const STALE_AFTER = 900;

	/** Entries one cron/CLI drain reads. */
	public const BATCH_DRAIN_LIMIT = 500;

	/** @var self|null */
	private static ?self $instance = null;

	public readonly Settings $settings;
	public readonly WpdbOutboxStore $store;
	public readonly Purger $purger;
	public readonly PurgeHooks $hooks;

	/** @var array<int, string> */
	public readonly array $instance_errors;

	/**
	 * @return void
	 */
	private function __construct() {
		global $wpdb;
		$this->settings         = new Settings();
		$this->store            = new WpdbOutboxStore( $wpdb );
		[ $instances, $errors ] = $this->settings->instances();
		$this->instance_errors  = $errors;
		$mode                   = (string) $this->settings->get( 'purge_mode' );
		$transport              = new WpHttpTransport();
		$this->purger           = new Purger(
			$this->store,
			$instances,
			static fn ( Instance $i ): PurgeClient => new PurgeClient( $i, $transport ),
			$mode,
			static function ( callable $callback ): void {
				// Late on `shutdown`: after every write of the save has happened,
				// and after the response has gone to the browser, so the visitor
				// (or the admin who saved a product) is not kept waiting.
				add_action(
					'shutdown',
					static function () use ( $callback ): void {
						if ( ! self::finish_request() ) {
							// The response cannot be closed first: delivering now would
							// hold a checkout or an admin save for the admin API's
							// timeout. The rows become due after their grace period
							// and cron delivers them.
							return;
						}
						$callback();
					},
					1000
				);
			},
			static fn (): int => time()
		);
		$this->hooks            = new PurgeHooks( $this->purger, (string) $this->settings->get( 'tag_prefix' ) );
	}

	/**
	 * URL of an asset shipped by qoliber/trident-php (`assets/…` in the library).
	 * The plugin carries its own copy under `assets/lib/`, kept byte-equal to
	 * the library's by LibraryAssetsTest: installed with the site's Composer,
	 * the library sits in a `vendor/` that is usually outside the web root.
	 *
	 * @param string $path Path below the library's `assets/`, e.g. `js/trident-sections.js`.
	 * @return string
	 */
	public static function library_asset_url( string $path ): string {
		return plugins_url( 'assets/lib/' . $path, TRIDENT_WOO_FILE );
	}

	/**
	 * Close the response before delivering. True when delivery may run now:
	 * CLI and cron have nobody waiting; PHP-FPM and LiteSpeed can finish the
	 * request early. Filter `trident_finish_request_before_purge` returns false
	 * to deliver inline anyway (it then blocks the request).
	 *
	 * @return bool
	 */
	public static function finish_request(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		if ( ! apply_filters( 'trident_finish_request_before_purge', true ) ) {
			return true;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return true;
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return true;
		}
		return false;
	}

	/**
	 * @return self
	 */
	public static function get(): self {
		return self::$instance ??= new self();
	}

	/**
	 * `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$plugin = self::get();
		if ( get_option( WpdbOutboxStore::SCHEMA_OPTION ) !== WpdbOutboxStore::SCHEMA_VERSION ) {
			// Updated without re-activation: create/upgrade the table now.
			$plugin->store->install();
		}
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- a purge must not wait 15 minutes.
		add_action( self::CRON_HOOK, array( $plugin, 'cron_drain' ) );
		if ( wp_next_scheduled( self::LEGACY_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}

		$settings_page = new SettingsPage( $plugin );
		if ( is_admin() ) {
			$settings_page->register();
		}
		// The operator screens, the dashboard widget and the admin bar's
		// "Purge this page" (on the storefront too). Registered before the
		// "enabled" check, like the settings: an operator can still look.
		( new Admin\Menu( new Admin\Operator( $plugin ), $settings_page ) )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'trident', new Cli\Command( $plugin ) );
		}
		// The placeholder shortcode stays registered when caching is off, so a
		// theme that uses it never prints "[trident_section …]" as text.
		add_shortcode( 'trident_section', array( new Sections(), 'shortcode' ) );
		if ( ! $plugin->settings->get( 'enabled' ) ) {
			return;
		}

		$plugin->hooks->register();
		$prefix = (string) $plugin->settings->get( 'tag_prefix' );
		$policy = WooPolicy::create(
			(int) $plugin->settings->get( 'ttl' ),
			(int) $plugin->settings->get( 'swr' ),
			(array) apply_filters( 'trident_session_cookies', WooPolicy::SESSION_COOKIES )
		);
		$esi    = new EsiContext( $plugin->settings );
		$tokens = new PrivateTokens( $esi );
		$tags   = new TagCollector(
			new TagSet( $prefix, Names::OVERFLOW ),
			$esi->assemble_mode(),
			static fn ( string $sidebar ): bool => $esi->active() && $esi->widget_area_shared( $sidebar )
		);
		( new ResponseController( $policy, $tags, (bool) $plugin->settings->get( 'debug_headers' ), new NonceGuard(), $esi, $tokens ) )->register();
		// A shopper's cold page is rendered as the anonymous page and stored,
		// instead of rendered for their session and thrown away.
		( new SessionDetach(
			(array) apply_filters( 'trident_session_cookies', WooPolicy::SESSION_COOKIES ),
			(bool) $plugin->settings->get( 'debug_headers' )
		) )->register();
		( new SharedBlocks( $esi, $tags, (array) apply_filters( 'trident_session_cookies', WooPolicy::SESSION_COOKIES ) ) )->register();
		$tokens->register();
		( new RecentlyViewed() )->register();
		if ( $plugin->settings->get( 'personal_sections' ) ) {
			( new Sections() )->register();
		}
		if ( $plugin->settings->get( 'cart_fragments' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_cart_fragments' ), 20 );
		}
	}

	/**
	 * WooCommerce 7.8+ enqueues `wc-cart-fragments` only where a mini-cart
	 * widget is used. On a cached page it is what replaces the empty mini-cart
	 * with the visitor's own, from sessionStorage, keyed by the cart hash
	 * cookie — so it must be on every cacheable page.
	 *
	 * @return void
	 */
	public static function enqueue_cart_fragments(): void {
		// Block themes render the mini-cart with the Interactivity API, which
		// loads the cart from the Store API itself; the classic fragments script
		// would only add an uncached request per visitor.
		if ( ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) || wp_is_block_theme() ) {
			return;
		}
		wp_enqueue_script( 'wc-cart-fragments' );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 60,
			'display'  => 'Every minute (Trident purge outbox)',
		);
		return $schedules;
	}

	/**
	 * WP-Cron: retry what is due.
	 *
	 * @return void
	 */
	public function cron_drain(): void {
		$this->purger->drain( self::BATCH_DRAIN_LIMIT );
	}

	/**
	 * Tags purged by "Purge all" (this site only — other sites on the same
	 * Trident keep their entries).
	 *
	 * @return int Rows recorded.
	 */
	public function purge_all(): int {
		return $this->hooks->tags( array( Tags\Names::ALL ) );
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;
		( new WpdbOutboxStore( $wpdb ) )->install();
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: stop the cron job. The outbox is kept — a reactivated
	 * plugin still delivers what it owes.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
	}
}
