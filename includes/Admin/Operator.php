<?php
/**
 * What every Trident Cache admin screen shares.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin;

use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Admin\InstanceResult;
use Qoliber\TridentWoo\Plugin;
use Qoliber\TridentWoo\Purge\WpHttpTransport;

/**
 * The capability, the fleet of instances, POST forms with their nonces and
 * confirm step, and the notices a screen leaves for the next page load.
 *
 * Every operator action goes through {@see Menu::handle()}: POST only, a nonce
 * per screen and operation, the capability, and — for the destructive ones — a
 * ticked confirm box checked on the server.
 */
final class Operator {

	/** Top-level menu slug; each screen is `trident-cache` or `trident-cache-<screen>`. */
	public const MENU = 'trident-cache';

	/** The admin-post.php action every operator form posts to. */
	public const ACTION = 'trident_op';

	/** Admin API timeout for screen reads and actions (seconds). */
	public const TIMEOUT = 5;

	private ?Fleet $fleet = null;

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( public readonly Plugin $plugin ) {
	}

	/**
	 * Who may use the operator screens. `manage_options` by default; the
	 * `trident_admin_capability` filter can narrow or widen it.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$cap = apply_filters( 'trident_admin_capability', 'manage_options' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * @return bool
	 */
	public static function allowed(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * The configured instances, over the WordPress HTTP API.
	 *
	 * @return Fleet
	 */
	public function fleet(): Fleet {
		return $this->fleet ??= new Fleet( $this->plugin->purger->instances(), new WpHttpTransport( self::TIMEOUT ) );
	}

	/**
	 * A screen's URL.
	 *
	 * @param string                    $screen Screen slug ('' = dashboard).
	 * @param array<string, string|int> $args   Extra query arguments.
	 * @return string
	 */
	public static function url( string $screen = '', array $args = array() ): string {
		$page = '' === $screen ? self::MENU : self::MENU . '-' . $screen;
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The nonce action of one operation on one screen.
	 *
	 * @param string $screen Screen slug.
	 * @param string $op     Operation.
	 * @return string
	 */
	public static function nonce_action( string $screen, string $op ): string {
		return 'trident_op_' . $screen . '_' . $op;
	}

	/**
	 * Opening tag and hidden fields of an operator form.
	 *
	 * @param string $screen Screen slug.
	 * @param string $op     Operation.
	 * @param string $style  Inline style for the form.
	 * @return string
	 */
	public function form( string $screen, string $op, string $style = '' ): string {
		$html  = sprintf( '<form method="post" action="%s"%s>', esc_url( admin_url( 'admin-post.php' ) ), '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' );
		$html .= sprintf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );
		$html .= sprintf( '<input type="hidden" name="screen" value="%s">', esc_attr( $screen ) );
		$html .= sprintf( '<input type="hidden" name="op" value="%s">', esc_attr( $op ) );
		$html .= wp_nonce_field( self::nonce_action( $screen, $op ), '_wpnonce', true, false );
		return $html;
	}

	/**
	 * The confirm box a destructive operation needs ticked.
	 *
	 * @param string $text What the operator confirms.
	 * @return string
	 */
	public static function confirm( string $text ): string {
		return sprintf(
			'<label class="trident-confirm"><input type="checkbox" name="confirm" value="1" required> %s</label> ',
			esc_html( $text )
		);
	}

	/**
	 * A one-button form.
	 *
	 * @param string                $screen    Screen slug.
	 * @param string                $op        Operation.
	 * @param string                $label     Button label.
	 * @param array<string, string> $fields    Hidden fields.
	 * @param string                $css_class Button class.
	 * @param string|null           $confirm   Confirm text; null = no confirm step.
	 * @return string
	 */
	public function button( string $screen, string $op, string $label, array $fields = array(), string $css_class = 'button', ?string $confirm = null ): string {
		$html = $this->form( $screen, $op, 'display:inline-block;margin:0 .5em .5em 0' );
		foreach ( $fields as $name => $value ) {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
		}
		if ( null !== $confirm ) {
			$html .= self::confirm( $confirm );
		}
		$html .= sprintf( '<button type="submit" class="button %s">%s</button></form>', esc_attr( $css_class ), esc_html( $label ) );
		return $html;
	}

	/**
	 * A select of the configured instances ('' = all).
	 *
	 * @return string
	 */
	public function instance_select(): string {
		$instances = $this->fleet()->instances();
		if ( count( $instances ) < 2 ) {
			return '';
		}
		$html = '<select name="instance"><option value="">' . esc_html__( 'All instances', 'trident-cache-woocommerce' ) . '</option>';
		foreach ( $instances as $instance ) {
			$html .= sprintf( '<option value="%1$s">%1$s</option>', esc_attr( $instance->name ) );
		}
		return $html . '</select> ';
	}

	/**
	 * The instances an action targets: the posted one, or all.
	 *
	 * @param array<string, string> $post Sanitised POST.
	 * @return list<string>
	 */
	public static function targets( array $post ): array {
		$name = $post['instance'] ?? '';
		return '' === $name ? array() : array( $name );
	}

	/**
	 * Print a notice for every instance that could not answer a read.
	 *
	 * An unreachable instance is an error; a feature that is not enabled in
	 * that instance's configuration is information, not a failure.
	 *
	 * @param array<int, InstanceResult<mixed>> $results Results.
	 * @param string                            $what    What was being read.
	 * @return void
	 */
	public static function problems( array $results, string $what ): void {
		foreach ( $results as $result ) {
			if ( $result->isOk() ) {
				continue;
			}
			if ( $result->isFeatureDisabled() ) {
				printf(
					'<div class="notice notice-info inline"><p>%s</p></div>',
					esc_html( sprintf( '%s: %s is not enabled in this instance\'s configuration.', $result->name(), $what ) )
				);
				continue;
			}
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						'%s: %s — %s',
						$result->name(),
						$result->isUnreachable() ? __( 'unreachable', 'trident-cache-woocommerce' ) : __( 'error', 'trident-cache-woocommerce' ),
						$result->reason()
					)
				)
			);
		}
	}

	/**
	 * Turn action results into notices: one line per instance.
	 *
	 * @param string                            $what     Action label.
	 * @param array<int, InstanceResult<mixed>> $results  Results.
	 * @param callable(mixed): string           $describe Text for a successful value.
	 * @param (callable(mixed): ?string)|null   $failure  Why an answer that arrived is still not a
	 *                                                   success (e.g. Trident did not acknowledge a
	 *                                                   purge); null = it is one.
	 * @return void
	 */
	public static function report( string $what, array $results, callable $describe, ?callable $failure = null ): void {
		if ( array() === $results ) {
			self::notice( 'error', __( 'No Trident instance configured.', 'trident-cache-woocommerce' ) );
			return;
		}
		foreach ( $results as $result ) {
			$why = $result->isOk() && null !== $failure ? $failure( $result->value ) : null;
			if ( $result->isOk() && null !== $why ) {
				self::notice( 'error', sprintf( '%s — %s: not acknowledged (%s)', $what, $result->name(), $why ) );
			} elseif ( $result->isOk() ) {
				self::notice( 'success', sprintf( '%s — %s: %s', $what, $result->name(), $describe( $result->value ) ) );
			} else {
				self::notice( 'error', sprintf( '%s — %s: %s', $what, $result->name(), $result->reason() ) );
			}
		}
	}

	/**
	 * Queue a notice for the current user's next admin page.
	 *
	 * Kept server-side (a per-user transient), not in the redirect URL: a
	 * crafted link cannot put text of its choosing on an admin screen.
	 *
	 * @param string $type success|error|warning|info.
	 * @param string $text Plain text.
	 * @return void
	 */
	public static function notice( string $type, string $text ): void {
		$key       = self::notice_key();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type' => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'text' => $text,
		);
		set_transient( $key, array_slice( $notices, -30 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear the queued notices.
	 *
	 * @return void
	 */
	public static function flush_notices(): void {
		$key     = self::notice_key();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
				esc_html( (string) ( $notice['text'] ?? '' ) )
			);
		}
	}

	/**
	 * @return string
	 */
	private static function notice_key(): string {
		return 'trident_admin_notices_' . get_current_user_id();
	}

	/**
	 * Human-readable bytes.
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function bytes( int|float $bytes ): string {
		$formatted = size_format( max( 0, (int) $bytes ), 1 );
		return false === $formatted ? '0 B' : (string) $formatted;
	}

	/**
	 * Human-readable seconds.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function seconds( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return intdiv( $seconds, 60 ) . 'm ' . ( $seconds % 60 ) . 's';
		}
		if ( $seconds < 86400 ) {
			return intdiv( $seconds, 3600 ) . 'h ' . intdiv( $seconds % 3600, 60 ) . 'm';
		}
		return intdiv( $seconds, 86400 ) . 'd ' . intdiv( $seconds % 86400, 3600 ) . 'h';
	}

	/**
	 * Every scalar field of an admin response as a two-column table, nested
	 * objects flattened to dotted names (two levels). For status payloads whose
	 * fields depend on the engine's state: nothing the engine reports is
	 * dropped, and nothing is invented.
	 *
	 * @param array<string, mixed> $data   Decoded response.
	 * @param string               $prefix Dotted prefix (recursion).
	 * @param int                  $depth  Levels left.
	 * @return string Escaped HTML.
	 */
	public static function details( array $data, string $prefix = '', int $depth = 2 ): string {
		$rows = '';
		foreach ( $data as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				if ( $depth > 0 && array() !== $value && ! array_is_list( $value ) ) {
					$rows .= self::details( $value, $name, $depth - 1 );
				} elseif ( array_is_list( $value ) && array() === array_filter( $value, 'is_array' ) ) {
					$rows .= sprintf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $name ), esc_html( implode( ', ', array_map( 'strval', $value ) ) ) );
				}
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$rows .= sprintf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $name ), esc_html( null === $value ? '-' : (string) $value ) );
		}
		if ( '' !== $prefix ) {
			return $rows;
		}
		return '<table class="widefat striped trident-details"><tbody>' . $rows . '</tbody></table>';
	}

	/**
	 * Start a table: `<table>` and its header row.
	 *
	 * @param array<int, string> $headings Column headings (plain text).
	 * @return string
	 */
	public static function table( array $headings ): string {
		$html = '<table class="widefat striped trident-table"><thead><tr>';
		foreach ( $headings as $heading ) {
			$html .= '<th>' . esc_html( $heading ) . '</th>';
		}
		return $html . '</tr></thead><tbody>';
	}

	/**
	 * One row of already-escaped cells.
	 *
	 * @param array<int, string> $cells Escaped HTML cells.
	 * @return string
	 */
	public static function row( array $cells ): string {
		return '<tr><td>' . implode( '</td><td>', $cells ) . '</td></tr>';
	}

	/**
	 * A row saying the table is empty.
	 *
	 * @param int    $columns Columns.
	 * @param string $text    Text.
	 * @return string
	 */
	public static function empty_row( int $columns, string $text ): string {
		return sprintf( '<tr><td colspan="%d"><em>%s</em></td></tr>', $columns, esc_html( $text ) );
	}
}
