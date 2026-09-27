<?php
/**
 * `wp trident …`
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cli;

use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\TridentWoo\Plugin;
use Qoliber\TridentWoo\Purge\WpHttpTransport;
use Qoliber\TridentWoo\Settings;

/**
 * Trident Cache for WooCommerce.
 */
final class Command {

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {
		\WP_CLI::add_command( 'trident purge', new PurgeCommand( $plugin ) );
	}

	/**
	 * Read or change a setting.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : get or set.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : enabled, api_url, api_token, purge_mode, ttl, swr, tag_prefix, debug_headers,
	 *   cart_fragments, personal_sections, esi_menus, esi_ttl.
	 *
	 * [<value>]
	 * : New value (set).
	 *
	 * ## EXAMPLES
	 *
	 *     wp trident settings get
	 *     wp trident settings set api_url http://127.0.0.1:9301
	 *     wp trident settings set api_token "$TOKEN"
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function settings( $args, $assoc ): void {
		unset( $assoc );
		$action = $args[0] ?? 'get';
		$key    = $args[1] ?? null;
		$s      = $this->plugin->settings;
		if ( 'set' === $action ) {
			if ( null === $key || ! array_key_exists( 2, $args ) ) {
				\WP_CLI::error( 'usage: wp trident settings set <key> <value>' );
			}
			$error = $s->set( (string) $key, $args[2] );
			if ( null !== $error ) {
				\WP_CLI::error( $error );
			}
			\WP_CLI::success( sprintf( '%s updated.', $key ) );
			return;
		}
		$keys = null !== $key ? array( (string) $key ) : array_merge( array_keys( Settings::DEFAULTS ), array( 'api_token' ) );
		foreach ( $keys as $k ) {
			if ( 'api_token' === $k ) {
				$value = '' === $s->api_token() ? '(not set)' : '(set' . ( null !== $s->constant_for( 'api_token' ) ? ', TRIDENT_API_TOKEN' : ', encrypted' ) . ')';
			} else {
				$value = $s->get( $k );
				$value = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				$const = $s->constant_for( $k );
				$value = null !== $const ? $value . ' (' . $const . ')' : $value;
			}
			\WP_CLI::line( sprintf( '%-18s %s', $k, $value ) );
		}
	}

	/**
	 * Check every configured Trident instance: reachable, token accepted, licensed.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function check( $args, $assoc ): void {
		unset( $args, $assoc );
		$instances = $this->plugin->purger->instances();
		if ( array() === $instances ) {
			\WP_CLI::error( 'No Trident instance configured.' );
		}
		$ok = true;
		foreach ( $instances as $instance ) {
			$result = ( new PurgeClient( $instance, new WpHttpTransport() ) )->status();
			$ok     = $ok && $result['ok'];
			\WP_CLI::line( sprintf( '  %-20s %-40s %s %s', $instance->name, $instance->apiUrl, $result['ok'] ? 'OK  ' : 'FAIL', $result['message'] ) );
		}
		if ( ! $ok ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the Trident configuration this site needs (hosts, cookie names).
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function config( $args, $assoc ): void {
		unset( $args, $assoc );
		\WP_CLI::line( \Qoliber\TridentWoo\Admin\ConfigSnippet::toml() );
	}
}
