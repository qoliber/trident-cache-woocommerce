<?php
/**
 * The outbox table.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Purge;

use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\OutboxStore;

/**
 * `{prefix}trident_wc_purge_outbox`, written through `$wpdb` (renamed from the pre-1.0 `trident_purge_outbox` by install()).
 *
 * Times are Unix seconds from PHP, not MySQL `NOW()`: the database and PHP may
 * disagree about the time zone, and a backoff computed on one clock and compared
 * on the other is off by hours.
 */
final class WpdbOutboxStore implements OutboxStore {

	public const TABLE          = 'trident_wc_purge_outbox';
	public const SCHEMA_VERSION = '3';

	/** The pre-1.0 name — generic enough for another Trident plugin to take. */
	public const LEGACY_TABLE  = 'trident_purge_outbox';
	public const SCHEMA_OPTION = 'trident_woo_schema';

	/**
	 * @param \wpdb $db WordPress database.
	 */
	public function __construct( private readonly \wpdb $db ) {
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->db->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the table.
	 *
	 * `instance` is binary-collated: instance names are case-sensitive, and
	 * under the default case-insensitive collation a row owed to "Edge-1"
	 * would be handed to "edge-1" (the Magento stack found exactly this with a
	 * real MariaDB). It keeps the TABLE's character set: schema 1 used
	 * `ascii` for it, and with two character sets in one table wpdb treats
	 * the table as ASCII and refuses — silently, `false` — every query with a
	 * non-ASCII byte. The failure reason "HTTP 401 — …" has an em dash, so no
	 * failed delivery was ever recorded: the row stayed at attempts 0 and was
	 * retried on every request with no backoff and no error to show. Found by
	 * tests/woocommerce-e2e 68, not by the unit tests (no wpdb there).
	 *
	 * @return void
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $this->db->get_charset_collate();
		$table   = $this->table();
		$legacy  = $this->db->prefix . self::LEGACY_TABLE;
		// Schema 3: the table's own name. Pending purges move with it.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our tables.
		if ( ! $this->exists() && $legacy === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $legacy ) ) ) ) {
			$this->db->query( "RENAME TABLE {$legacy} TO {$table}" );
		}
		// phpcs:enable
		$binary = $this->db->charset ? $this->db->charset . '_bin' : 'utf8mb4_bin';
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  instance varchar(64) COLLATE {$binary} NOT NULL,
  tags longtext NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL,
  next_attempt_at bigint(20) unsigned NOT NULL,
  last_error varchar(1000) DEFAULT NULL,
  last_error_at bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY due (next_attempt_at,id),
  KEY instance (instance)
) {$charset};"
		);
		// dbDelta compares column TYPES only; a schema-1 table keeps its ascii
		// column unless it is changed explicitly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our table, our collation.
		$this->db->query( "ALTER TABLE {$table} MODIFY instance varchar(64) COLLATE {$binary} NOT NULL" );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Whether the table exists (the plugin was updated without activation).
	 *
	 * @return bool
	 */
	public function exists(): bool {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe.
		return $table === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public function uninstall(): void {
		$table  = $this->table();
		$legacy = $this->db->prefix . self::LEGACY_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names are ours.
		$this->db->query( "DROP TABLE IF EXISTS {$table}, {$legacy}" );
		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Record tags owed to one instance.
	 *
	 * @param string             $instance Instance name.
	 * @param array<int, string> $tags     Tags.
	 * @param int                $now      Unix time.
	 * @param int                $due_at   When other drainers may take it (the writer's grace).
	 * @return int Row id.
	 * @throws \RuntimeException When the row cannot be written — the caller must
	 *                           know the purge was not recorded.
	 */
	public function record( string $instance, array $tags, int $now, int $due_at ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $this->db->insert(
			$this->table(),
			array(
				'instance'        => $instance,
				'tags'            => (string) wp_json_encode( array_values( $tags ) ),
				'attempts'        => 0,
				'created_at'      => $now,
				'next_attempt_at' => $due_at,
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'outbox insert failed: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged/CLI text, not HTML.
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Rows that still exist, by id, whatever their due time.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @return array<int, OutboxEntry>
	 */
	public function byIds( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table = $this->table();
		$in    = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		return $this->entries( (array) $this->db->get_results( "SELECT id, instance, tags, attempts FROM {$table} WHERE id IN ({$in}) ORDER BY id ASC", ARRAY_A ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, OutboxEntry>
	 */
	private function entries( array $rows ): array {
		$entries = array();
		foreach ( $rows as $row ) {
			$tags      = json_decode( (string) $row['tags'], true );
			$entries[] = new OutboxEntry(
				(int) $row['id'],
				(string) $row['instance'],
				is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array(),
				(int) $row['attempts']
			);
		}
		return $entries;
	}

	/**
	 * Due entries, oldest first.
	 *
	 * @param int                $limit          Entries to read.
	 * @param int                $now            Unix time.
	 * @param bool               $ignore_backoff Also entries backing off after a failed attempt (never
	 *                                           rows still in their writer's grace period).
	 * @param array<int, string> $instances      Only these instances (exact match).
	 * @return array<int, OutboxEntry>
	 */
	public function due( int $limit, int $now, bool $ignore_backoff, array $instances ): array {
		if ( array() === $instances ) {
			return array();
		}
		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $instances ), '%s' ) );
		$args         = $instances;
		$where        = "instance IN ({$placeholders})";
		$where       .= $ignore_backoff ? ' AND ( next_attempt_at <= %d OR attempts > 0 )' : ' AND next_attempt_at <= %d';
		$args[]       = $now;
		$args[]       = max( 1, $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT id, instance, tags, attempts FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d", $args ), ARRAY_A );
		return $this->entries( (array) $rows );
	}

	/**
	 * Drop acknowledged entries.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @return void
	 */
	public function remove( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return;
		}
		$table = $this->table();
		$in    = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$this->check( $this->db->query( "DELETE FROM {$table} WHERE id IN ({$in})" ), 'remove' );
	}

	/**
	 * Keep entries after a failed attempt; schedule each one's next attempt.
	 *
	 * @param array<int, OutboxEntry> $entries Entries sent and not acknowledged.
	 * @param string                  $reason  Why.
	 * @param int                     $now     Unix time.
	 * @return void
	 */
	public function fail( array $entries, string $reason, int $now ): void {
		// One UPDATE per attempt count: each entry keeps its own schedule.
		$by_attempts = array();
		foreach ( $entries as $entry ) {
			$by_attempts[ $entry->attempts ][] = $entry->id;
		}
		$table  = $this->table();
		$reason = substr( $reason, 0, 1000 );
		foreach ( $by_attempts as $attempts => $ids ) {
			$failures = (int) $attempts + 1;
			$in       = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$result = $this->db->query(
				$this->db->prepare(
					"UPDATE {$table} SET attempts = %d, next_attempt_at = %d, last_error = %s, last_error_at = %d WHERE id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$failures,
					Backoff::nextAttemptAt( $failures, $now ),
					$reason,
					$now
				)
			);
			$this->check( $result, 'fail' );
		}
	}

	/**
	 * A write the outbox depends on did not happen: say so. wpdb reports a
	 * refused query only through its return value, and an unrecorded failure
	 * means an entry retried on every request with no backoff and no reason.
	 *
	 * @param int|bool $result wpdb::query() result.
	 * @param string   $what   Operation.
	 * @return void
	 */
	private function check( $result, string $what ): void {
		if ( false === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator-visible, rare.
			error_log( sprintf( 'Trident purge outbox: %s failed: %s', $what, $this->db->last_error ) );
		}
	}

	/**
	 * Drop everything owed to an instance.
	 *
	 * @param string $instance Instance name (exact).
	 * @return int Entries removed.
	 */
	public function forget( string $instance ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->delete( $this->table(), array( 'instance' => $instance ), array( '%s' ) );
	}

	/**
	 * Pending count, age, last failure, per-instance counts.
	 *
	 * @param int $now Unix time.
	 * @return array{pending: int, oldest_age: int|null, last_error: string|null, last_error_at: int|null, by_instance: array<string, int>}
	 */
	public function stats( int $now ): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = (array) $this->db->get_row( "SELECT COUNT(*) AS pending, MIN(created_at) AS oldest FROM {$table}", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failure = (array) $this->db->get_row( "SELECT last_error, last_error_at FROM {$table} WHERE last_error IS NOT NULL ORDER BY last_error_at DESC, id DESC LIMIT 1", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups      = (array) $this->db->get_results( "SELECT instance, COUNT(*) AS n FROM {$table} GROUP BY instance", ARRAY_A );
		$by_instance = array();
		foreach ( $groups as $group ) {
			$by_instance[ (string) $group['instance'] ] = (int) $group['n'];
		}
		ksort( $by_instance );
		return array(
			'pending'       => (int) ( $row['pending'] ?? 0 ),
			'oldest_age'    => isset( $row['oldest'] ) ? max( 0, $now - (int) $row['oldest'] ) : null,
			'last_error'    => isset( $failure['last_error'] ) ? (string) $failure['last_error'] : null,
			'last_error_at' => isset( $failure['last_error_at'] ) ? (int) $failure['last_error_at'] : null,
			'by_instance'   => $by_instance,
		);
	}
}
