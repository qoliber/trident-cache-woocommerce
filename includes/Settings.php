<?php
/**
 * Plugin settings: options, overridden by wp-config.php constants.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo;

use Qoliber\Trident\Delivery\Instances;

/**
 * Reads and writes the settings.
 *
 * Constants win over the settings page, and the page shows them read-only:
 *   TRIDENT_API_URL, TRIDENT_API_TOKEN, TRIDENT_INSTANCES (X03), TRIDENT_PURGE_MODE.
 */
final class Settings {

	public const OPTION       = 'trident_woo_settings';
	public const TOKEN_OPTION = 'trident_woo_api_token';

	/** Defaults — also the list of known keys. */
	public const DEFAULTS = array(
		'enabled'           => true,
		'api_url'           => '',
		'purge_mode'        => 'soft',
		'ttl'               => 3600,
		'swr'               => 86400,
		'tag_prefix'        => '',
		'debug_headers'     => false,
		'cart_fragments'    => true,
		'personal_sections' => true,
		// ESI — first-class: shared blocks become fragments with their own tags,
		// per-request tokens are punched out. Must match Trident's [esi].
		'esi_enabled'       => true,
		'esi_mode'          => 'hole_punch',
		'esi_menus'         => '*',
		'esi_widgets'       => '*',
		'esi_navigation'    => true,
		'esi_private'       => true,
		'esi_ttl'           => 3600,
		'esi_addresses'     => '',
	);

	/** @var array<string, mixed>|null */
	private ?array $values = null;

	/**
	 * @param string $key Setting.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		if ( 'api_url' === $key && defined( 'TRIDENT_API_URL' ) ) {
			return (string) constant( 'TRIDENT_API_URL' );
		}
		if ( 'purge_mode' === $key && defined( 'TRIDENT_PURGE_MODE' ) ) {
			return 'hard' === constant( 'TRIDENT_PURGE_MODE' ) ? 'hard' : 'soft';
		}
		$this->values ??= array_merge( self::DEFAULTS, (array) get_option( self::OPTION, array() ) );
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Write one setting (validated). The token goes to its own, non-autoloaded
	 * option, encrypted.
	 *
	 * @param string $key   Setting.
	 * @param mixed  $value Value.
	 * @return string|null Error, or null.
	 */
	public function set( string $key, mixed $value ): ?string {
		if ( 'api_token' === $key ) {
			update_option( self::TOKEN_OPTION, $this->secret()->encrypt( trim( (string) $value ) ), false );
			return null;
		}
		if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
			return sprintf( 'unknown setting "%s"', $key );
		}
		$clean = $this->sanitize( $key, $value );
		if ( null === $clean ) {
			return sprintf( 'invalid value for "%s"', $key );
		}
		$values = array_merge( self::DEFAULTS, (array) get_option( self::OPTION, array() ) );
		if ( 'api_url' === $key && self::origin( (string) $values['api_url'] ) !== self::origin( (string) $clean ) ) {
			// A token belongs to the server it was issued by: never send it to a
			// different one because somebody edited the URL.
			delete_option( self::TOKEN_OPTION );
		}
		$values[ $key ] = $clean;
		update_option( self::OPTION, $values, true );
		$this->values = null;
		return null;
	}

	/**
	 * The scheme://host:port of a URL, for comparing API endpoints.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( strtolower( trim( $url ) ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = $parts['scheme'] ?? 'http';
		$port   = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $parts['host'] . ':' . $port;
	}

	/**
	 * @param string $key   Setting.
	 * @param mixed  $value Raw value.
	 * @return mixed Clean value, or null when invalid.
	 */
	public function sanitize( string $key, mixed $value ): mixed {
		switch ( $key ) {
			case 'enabled':
			case 'debug_headers':
			case 'cart_fragments':
			case 'personal_sections':
			case 'esi_enabled':
			case 'esi_navigation':
			case 'esi_private':
				return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
			case 'api_url':
				$url = trim( (string) $value );
				return '' === $url || 1 === preg_match( '#^https?://[^\s/]+#i', $url ) ? rtrim( $url, '/' ) : null;
			case 'purge_mode':
				return in_array( $value, array( 'soft', 'hard' ), true ) ? $value : null;
			case 'esi_mode':
				return in_array( $value, array( 'hole_punch', 'assemble' ), true ) ? $value : null;
			case 'ttl':
			case 'esi_ttl':
				$n = (int) $value;
				return $n >= 1 && $n <= 31536000 ? $n : null;
			case 'swr':
				$n = (int) $value;
				return $n >= 0 && $n <= 31536000 ? $n : null;
			case 'tag_prefix':
				$p = strtolower( trim( (string) $value ) );
				return 1 === preg_match( '/^[a-z0-9_\-]{0,20}$/', $p ) ? $p : null;
			case 'esi_menus':
			case 'esi_widgets':
				// Comma-separated theme locations / sidebar ids; `*` = all.
				$items = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
				foreach ( $items as $item ) {
					if ( '*' !== $item && 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $item ) ) {
						return null;
					}
				}
				return implode( ',', $items );
			case 'esi_addresses':
				// Extra Trident addresses/CIDRs, comma-separated.
				$items = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
				foreach ( $items as $item ) {
					if ( 1 !== preg_match( '#^[0-9A-Fa-f:.]+(/\d{1,3})?$#', $item ) ) {
						return null;
					}
				}
				return implode( ',', $items );
		}
		return null;
	}

	/**
	 * Admin API token: TRIDENT_API_TOKEN, else the stored one.
	 *
	 * @return string '' when none (or when it can no longer be decrypted).
	 */
	public function api_token(): string {
		if ( defined( 'TRIDENT_API_TOKEN' ) ) {
			return (string) constant( 'TRIDENT_API_TOKEN' );
		}
		return (string) $this->secret()->decrypt( (string) get_option( self::TOKEN_OPTION, '' ) );
	}

	/**
	 * Whether a stored token exists but cannot be decrypted (site keys rotated).
	 *
	 * @return bool
	 */
	public function token_unreadable(): bool {
		if ( defined( 'TRIDENT_API_TOKEN' ) ) {
			return false;
		}
		$stored = (string) get_option( self::TOKEN_OPTION, '' );
		return '' !== $stored && null === $this->secret()->decrypt( $stored );
	}

	/**
	 * Where a value comes from, for the settings page.
	 *
	 * @param string $key api_url|api_token|instances|purge_mode.
	 * @return string|null Constant name when defined in wp-config.php.
	 */
	public function constant_for( string $key ): ?string {
		$map = array(
			'api_url'    => 'TRIDENT_API_URL',
			'api_token'  => 'TRIDENT_API_TOKEN',
			'instances'  => 'TRIDENT_INSTANCES',
			'purge_mode' => 'TRIDENT_PURGE_MODE',
		);
		return isset( $map[ $key ] ) && defined( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/**
	 * X03: configured instances and parse errors.
	 *
	 * @return array{0: list<\Qoliber\Trident\Delivery\Instance>, 1: list<string>}
	 */
	public function instances(): array {
		return Instances::parse(
			defined( 'TRIDENT_INSTANCES' ) ? constant( 'TRIDENT_INSTANCES' ) : null,
			(string) $this->get( 'api_url' ),
			$this->api_token()
		);
	}

	/**
	 * Remove everything the plugin stored (uninstall).
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		global $wpdb;
		delete_option( self::OPTION );
		delete_option( self::TOKEN_OPTION );
		// Cached Trident addresses (transients keyed per configuration).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_trident_woo_' ) . '%', $wpdb->esc_like( '_transient_timeout_trident_woo_' ) . '%' ) );
	}

	/**
	 * @return Secret
	 */
	private function secret(): Secret {
		return new Secret( wp_salt( 'auth' ) );
	}
}
