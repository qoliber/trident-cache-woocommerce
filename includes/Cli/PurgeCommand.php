<?php
/**
 * `wp trident purge …`
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Cli;

use Qoliber\TridentWoo\Plugin;

/**
 * Purges and the outbox.
 */
final class PurgeCommand {

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Purges not yet acknowledged by Trident. Exits 1 when something is stuck.
	 *
	 * Exit 1 when: a purge has waited longer than 15 minutes, purges are owed to an
	 * instance that is no longer configured, TRIDENT_INSTANCES has errors, or the
	 * stored token cannot be decrypted. Suitable for monitoring.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trident purge status
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function status( $args, $assoc ): void {
		unset( $args, $assoc );
		$stats   = $this->plugin->store->stats( time() );
		$healthy = true;
		\WP_CLI::line( sprintf( 'pending:      %d', $stats['pending'] ) );
		\WP_CLI::line( sprintf( 'oldest age:   %s', null === $stats['oldest_age'] ? '-' : $stats['oldest_age'] . 's' ) );
		\WP_CLI::line(
			sprintf(
				'last failure: %s',
				null === $stats['last_error'] ? '-' : $stats['last_error'] . ' (' . gmdate( 'Y-m-d H:i:s', (int) $stats['last_error_at'] ) . ' UTC)'
			)
		);
		\WP_CLI::line( 'instances:' );
		$configured = array();
		foreach ( $this->plugin->purger->instances() as $instance ) {
			$configured[ $instance->name ] = true;
			\WP_CLI::line( sprintf( '  %-20s %-40s pending %d', $instance->name, $instance->apiUrl, $stats['by_instance'][ $instance->name ] ?? 0 ) );
		}
		if ( array() === $configured ) {
			\WP_CLI::line( '  (none — set the API URL, or TRIDENT_INSTANCES in wp-config.php)' );
		}
		foreach ( $this->plugin->instance_errors as $error ) {
			$healthy = false;
			\WP_CLI::warning( 'TRIDENT_INSTANCES entry skipped — ' . $error );
		}
		foreach ( $stats['by_instance'] as $name => $count ) {
			if ( ! isset( $configured[ $name ] ) ) {
				$healthy = false;
				\WP_CLI::warning(
					sprintf(
						'%d purge(s) owed to "%s", which is no longer configured. They are kept, not sent. If it comes back, they are delivered; if it is gone for good: wp trident purge drain --forget=%s',
						$count,
						$name,
						$name
					)
				);
			}
		}
		if ( $this->plugin->settings->token_unreadable() ) {
			$healthy = false;
			\WP_CLI::warning( 'The stored API token cannot be decrypted (the site keys changed). Enter it again.' );
		}
		if ( ( $stats['oldest_age'] ?? 0 ) > Plugin::STALE_AFTER ) {
			$healthy = false;
			\WP_CLI::warning( sprintf( 'Purges have waited longer than %ds. Check that WP-Cron (or a system cron running `wp cron event run --due-now`) runs and that Trident accepts the API token.', Plugin::STALE_AFTER ) );
		}
		if ( ! $healthy ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Deliver pending purges now, ignoring the retry backoff.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Entries to read.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--forget=<instance>]
	 * : Drop every purge owed to an instance that is gone for good, instead of
	 *   delivering. Refused for an instance that is still configured.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trident purge drain
	 *     wp trident purge drain --forget=edge-2
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function drain( $args, $assoc ): void {
		unset( $args );
		if ( isset( $assoc['forget'] ) ) {
			$name = (string) $assoc['forget'];
			foreach ( $this->plugin->purger->instances() as $instance ) {
				if ( $instance->name === $name ) {
					\WP_CLI::error( sprintf( '"%s" is configured; its purges are delivered, not forgotten. Remove it from the configuration first.', $name ) );
				}
			}
			\WP_CLI::success( sprintf( 'Forgot %d purge(s) owed to "%s".', $this->plugin->store->forget( $name ), $name ) );
			return;
		}
		$report = $this->plugin->purger->drain( max( 1, (int) ( $assoc['limit'] ?? Plugin::BATCH_DRAIN_LIMIT ) ), true );
		foreach ( $report->instances as $name => $result ) {
			\WP_CLI::line(
				sprintf(
					'  %-20s delivered %d, failed %d%s',
					$name,
					$result['delivered'],
					$result['failed'],
					null !== $result['error'] ? ' — ' . $result['error'] : ''
				)
			);
		}
		$pending = $this->plugin->store->stats( time() )['pending'];
		if ( $report->failed > 0 ) {
			\WP_CLI::error( sprintf( '%d purge(s) not acknowledged; %d pending.', $report->failed, $pending ) );
		}
		\WP_CLI::success( sprintf( 'Delivered %d purge(s); %d pending.', $report->delivered, $pending ) );
	}

	/**
	 * Purge every cached page of this site (tag `all`), and deliver now.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function all( $args, $assoc ): void {
		unset( $args, $assoc );
		$this->plugin->purge_all();
		$this->deliver_now();
	}

	/**
	 * Purge tags (the site prefix is added), and deliver now.
	 *
	 * ## OPTIONS
	 *
	 * <tag>...
	 * : Tags, e.g. wc_p_42 wc_cat_7.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function tags( $args, $assoc ): void {
		unset( $assoc );
		$this->plugin->hooks->tags( array_map( 'strval', $args ) );
		$this->deliver_now();
	}

	/**
	 * Purge a product (its page and every list it is on), and deliver now.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product id.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function product( $args, $assoc ): void {
		unset( $assoc );
		$this->plugin->hooks->product( (int) ( $args[0] ?? 0 ) );
		$this->deliver_now();
	}

	/**
	 * @return void
	 */
	private function deliver_now(): void {
		// The purge just recorded is this process's own row (in its grace period).
		$report = $this->plugin->purger->deliverOwn();
		if ( $report->failed > 0 ) {
			\WP_CLI::error( sprintf( 'Recorded, but %d purge(s) not acknowledged yet — they stay in the outbox and are retried.', $report->failed ) );
		}
		\WP_CLI::success( sprintf( 'Delivered %d purge(s).', $report->delivered ) );
	}
}
