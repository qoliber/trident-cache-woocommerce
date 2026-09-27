<?php
/**
 * Whether ESI markup is emitted for this request, and fragment URLs.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Esi;

use Qoliber\Trident\Esi\FragmentUrl;
use Qoliber\Trident\Esi\TridentOrigin;
use Qoliber\TridentWoo\Settings;

/**
 * Request-level ESI switchboard.
 *
 * ESI markup is emitted only for requests an ESI processor will see: the
 * setting is on, the visitor is not logged in (their pages are not shared, and
 * a logged-in menu may differ), and the request came FROM a Trident instance —
 * checked on the connection (REMOTE_ADDR), because a header could be sent by
 * anyone. An administrator on the backend port gets plain inline markup.
 */
final class Context {

	public const QUERY = 'trident-esi';

	/** @var bool|null */
	private ?bool $active = null;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Whether this request is itself a fragment request.
	 *
	 * @return bool
	 */
	public static function is_fragment_request(): bool {
		return isset( $_GET[ self::QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Whether to emit ESI markup in this response.
	 *
	 * @return bool
	 */
	public function active(): bool {
		if ( null !== $this->active ) {
			return $this->active;
		}
		$this->active = $this->settings->get( 'esi_enabled' )
			&& ! self::is_fragment_request()
			&& ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! is_user_logged_in()
			&& $this->from_trident();
		return $this->active;
	}

	/**
	 * Whether Trident stores assembled pages (fragment tags do not reach them).
	 *
	 * @return bool
	 */
	public function assemble_mode(): bool {
		return 'assemble' === $this->settings->get( 'esi_mode' );
	}

	/**
	 * The request's immediate peer is a Trident instance: the hosts of the
	 * configured admin URLs (resolved, cached for five minutes), loopback, and
	 * the addresses in the `esi_addresses` setting.
	 *
	 * @return bool
	 */
	public function from_trident(): bool {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $remote && TridentOrigin::matches( $remote, $this->trident_addresses() );
	}

	/**
	 * @return list<string>
	 */
	public function trident_addresses(): array {
		$hosts = array();
		foreach ( $this->settings->instances()[0] as $instance ) {
			$host = (string) wp_parse_url( $instance->apiUrl, PHP_URL_HOST );
			if ( '' !== $host ) {
				$hosts[] = trim( $host, '[]' );
			}
		}
		sort( $hosts );
		// Keyed on the configured hosts: a changed API URL must not keep the
		// previous host's addresses for the cache's lifetime (and leave the real
		// Trident unrecognised — no ESI, no token punch-out — until it expires).
		$key    = 'trident_woo_esi_addr_' . md5( implode( ',', $hosts ) );
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			$cached = array( '127.0.0.1', '::1' );
			foreach ( $hosts as $host ) {
				if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
					$cached[] = $host;
					continue;
				}
				$ips = gethostbynamel( $host );
				if ( is_array( $ips ) ) {
					$cached = array_merge( $cached, $ips );
				}
			}
			set_transient( $key, array_values( array_unique( $cached ) ), 5 * MINUTE_IN_SECONDS );
		}
		$extra = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'esi_addresses' ) ) ) );
		return array_values( array_unique( array_merge( $cached, $extra ) ) );
	}

	/**
	 * A signed, root-relative fragment URL.
	 *
	 * @param string               $type Fragment type.
	 * @param array<string, mixed> $args Arguments.
	 * @return string
	 */
	public function url( string $type, array $args ): string {
		return $this->signer()->build( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), self::QUERY, $type, $args );
	}

	/**
	 * @return FragmentUrl
	 */
	public function signer(): FragmentUrl {
		return new FragmentUrl( wp_salt( 'nonce' ) . '|trident-esi' );
	}

	/**
	 * Whether a widget area may be a SHARED fragment: listed in `esi_widgets`
	 * and holding no widget that renders per-visitor data (filter
	 * `trident_personal_widgets`, widget id bases). Such an area stays inline,
	 * where the page's own cacheability rules cover it.
	 *
	 * @param string $sidebar Sidebar id.
	 * @return bool
	 */
	public function widget_area_shared( string $sidebar ): bool {
		if ( ! $this->listed( 'esi_widgets', $sidebar ) ) {
			return false;
		}
		$personal = (array) apply_filters( 'trident_personal_widgets', array( 'woocommerce_recently_viewed_products', 'woocommerce_widget_cart' ) );
		$widgets  = wp_get_sidebars_widgets();
		foreach ( (array) ( $widgets[ $sidebar ] ?? array() ) as $widget_id ) {
			foreach ( $personal as $base ) {
				if ( str_starts_with( (string) $widget_id, $base . '-' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether `$item` is in a comma-separated list setting (`*` = all).
	 *
	 * @param string $setting Setting name.
	 * @param string $item    Location / sidebar id.
	 * @return bool
	 */
	public function listed( string $setting, string $item ): bool {
		$list = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( $setting ) ) ) );
		return in_array( '*', $list, true ) || in_array( $item, $list, true );
	}
}
