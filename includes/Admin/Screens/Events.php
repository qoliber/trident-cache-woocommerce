<?php
/**
 * Trident Cache → Live events.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Api;
use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Events\EventStream;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * What the instances are doing right now: the busiest URLs and recent errors
 * (read with the page), and a live feed polled while the page is open.
 *
 * A PHP page cannot hold Trident's SSE stream open, so the feed polls: each
 * poll reads every instance's stream for {@see self::WINDOW} seconds and
 * returns what arrived. Engine events carry the full request — headers,
 * cookies, the client's IP — and none of that leaves this class: only the
 * fields in {@see self::FIELDS} are sent to the browser.
 */
final class Events extends Screen {

	/** Seconds each poll reads a stream. */
	public const WINDOW = 2;

	/** The streams the engine offers. */
	public const STREAMS = array( 'requests', 'cache', 'backends', 'errors' );

	/** The admin-ajax action and nonce of the feed. */
	public const AJAX = 'trident_events_poll';

	/**
	 * The only event fields a browser receives; everything else (headers,
	 * cookies, client IP, anything added later) is dropped.
	 */
	public const FIELDS = array( 'timestamp', 'method', 'path', 'host', 'status', 'cache_status', 'duration_ms', 'bytes_sent', 'event', 'action', 'key', 'url', 'tag', 'tags', 'mode', 'purged', 'backend', 'healthy', 'state', 'reason', 'error', 'message', 'kind' );

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'events';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Live events', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 120;
	}

	/**
	 * Keep an event's allowed scalar fields.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, string|int|float|bool>
	 */
	public static function safe_fields( array $data ): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];
			if ( is_array( $value ) && array_is_list( $value ) && array() === array_filter( $value, static fn ( $v ): bool => ! is_scalar( $v ) ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			if ( is_scalar( $value ) ) {
				$out[ $field ] = is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value;
			}
		}
		return $out;
	}

	/**
	 * Read every instance's stream for the same window, in parallel, and
	 * return what arrived: one poll takes one window however many instances
	 * there are.
	 *
	 * @param array<int, Instance> $instances Instances.
	 * @param string               $stream    One of {@see self::STREAMS}.
	 * @return array{events: list<array<string, mixed>>, errors: list<string>}
	 */
	public static function bursts( array $instances, string $stream ): array {
		$requests = array();
		$files    = array();
		foreach ( $instances as $i => $instance ) {
			$files[ $i ]    = wp_tempnam( 'trident-sse' );
			$requests[ $i ] = array(
				'url'     => $instance->apiUrl . '/admin/events/' . $stream,
				'headers' => array_merge( Api::headers( $instance, false ), array( 'Accept' => 'text/event-stream' ) ),
				'type'    => 'GET',
				'options' => array(
					'timeout'          => self::WINDOW,
					'connect_timeout'  => self::WINDOW,
					'follow_redirects' => false,
					'filename'         => $files[ $i ],
					'useragent'        => 'trident-cache-woocommerce/' . TRIDENT_WOO_VERSION,
				),
			);
		}
		$responses = array() === $requests ? array() : \WpOrg\Requests\Requests::request_multiple( $requests );
		$events    = array();
		$errors    = array();
		foreach ( $instances as $i => $instance ) {
			$chunk = is_readable( $files[ $i ] ) ? (string) file_get_contents( $files[ $i ] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file.
			wp_delete_file( $files[ $i ] );
			$response = $responses[ $i ] ?? null;
			if ( $response instanceof \WpOrg\Requests\Response ) {
				if ( 200 !== (int) $response->status_code ) {
					$errors[] = sprintf( '%s: HTTP %d', $instance->name, (int) $response->status_code );
				}
			} elseif ( '' === $chunk ) {
				// The stream never ends by itself: a timeout WITH data is the
				// normal end of a poll; without data, the instance is not there.
				$errors[] = sprintf( '%s: %s', $instance->name, $response instanceof \Throwable ? $response->getMessage() : 'no response' );
			}
			foreach ( EventStream::parseChunk( $chunk ) as $event ) {
				if ( in_array( $event->getType(), array( 'connected', 'keepalive', 'message' ), true ) && null === $event->get( 'path' ) ) {
					continue;
				}
				$events[] = array_merge(
					array(
						'instance' => $instance->name,
						'type'     => $event->getType(),
					),
					self::safe_fields( $event->getData() )
				);
			}
		}
		return array(
			'events' => $events,
			'errors' => $errors,
		);
	}

	/**
	 * One poll of the feed (admin-ajax): POST, nonce, capability.
	 *
	 * @param \Qoliber\TridentWoo\Admin\Operator $op Shared helpers.
	 * @return void
	 */
	public static function ajax( Operator $op ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			wp_send_json_error( array( 'message' => 'POST only' ), 405 );
		}
		if ( ! Operator::allowed() ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		if ( false === check_ajax_referer( self::AJAX, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Bad nonce' ), 403 );
		}
		$stream = isset( $_POST['stream'] ) ? sanitize_key( wp_unslash( (string) $_POST['stream'] ) ) : 'requests';
		$stream = in_array( $stream, self::STREAMS, true ) ? $stream : 'requests';
		$wanted = isset( $_POST['instance'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['instance'] ) ) : '';
		$chosen = array_values(
			array_filter(
				$op->fleet()->instances(),
				static fn ( Instance $instance ): bool => '' === $wanted || $instance->name === $wanted
			)
		);
		$burst  = self::bursts( $chosen, $stream );
		$events = $burst['events'];
		$errors = $burst['errors'];
		wp_send_json_success(
			array(
				'stream' => $stream,
				'events' => $events,
				'errors' => $errors,
			)
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each(
			static fn ( TridentClient $c ): array => array(
				'top'    => $c->topUrls( 10 ),
				'errors' => Fleet::attempt( static fn () => $c->errorStats( 10 ) ),
			)
		);
		Operator::problems( $results, __( 'traffic statistics', 'trident-cache-woocommerce' ) );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- table()/row() escape.
		echo '<h2>' . esc_html__( 'Live feed', 'trident-cache-woocommerce' ) . '</h2>';
		echo '<p><select id="trident-events-stream">';
		foreach ( self::STREAMS as $stream ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $stream ) );
		}
		echo '</select> ';
		$instances = $this->op->fleet()->instances();
		if ( count( $instances ) > 1 ) {
			echo '<select id="trident-events-instance"><option value="">' . esc_html__( 'All instances', 'trident-cache-woocommerce' ) . '</option>';
			foreach ( $instances as $instance ) {
				printf( '<option value="%1$s">%1$s</option>', esc_attr( $instance->name ) );
			}
			echo '</select> ';
		}
		printf( '<button type="button" class="button button-primary" id="trident-events-toggle" data-start="%s" data-stop="%s">%s</button> <span id="trident-events-state"></span></p>', esc_attr__( 'Start', 'trident-cache-woocommerce' ), esc_attr__( 'Stop', 'trident-cache-woocommerce' ), esc_html__( 'Start', 'trident-cache-woocommerce' ) );
		echo '<table class="widefat striped trident-table" id="trident-events"><thead><tr><th>' . esc_html__( 'Instance', 'trident-cache-woocommerce' ) . '</th><th>' . esc_html__( 'Event', 'trident-cache-woocommerce' ) . '</th><th>' . esc_html__( 'Details', 'trident-cache-woocommerce' ) . '</th></tr></thead><tbody></tbody></table>';
		$config = array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::AJAX,
			'nonce'  => wp_create_nonce( self::AJAX ),
		);
		?>
		<script>
		( function () {
			var cfg = <?php echo wp_json_encode( $config ); ?>;
			var btn = document.getElementById( 'trident-events-toggle' ), state = document.getElementById( 'trident-events-state' );
			var body = document.querySelector( '#trident-events tbody' ), running = false;
			function cell( text ) { var td = document.createElement( 'td' ); td.textContent = text; return td; }
			function poll() {
				if ( ! running ) { return; }
				var form = new FormData(), inst = document.getElementById( 'trident-events-instance' );
				form.append( 'action', cfg.action ); form.append( '_wpnonce', cfg.nonce );
				form.append( 'stream', document.getElementById( 'trident-events-stream' ).value );
				form.append( 'instance', inst ? inst.value : '' );
				fetch( cfg.url, { method: 'POST', body: form, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
					var data = res && res.data ? res.data : {};
					state.textContent = ( data.errors && data.errors.length ) ? data.errors.join( '; ' ) : '';
					( data.events || [] ).forEach( function ( ev ) {
						var tr = document.createElement( 'tr' ), details = [];
						Object.keys( ev ).forEach( function ( k ) { if ( k !== 'instance' && k !== 'type' ) { details.push( k + '=' + ev[ k ] ); } } );
						tr.appendChild( cell( ev.instance ) ); tr.appendChild( cell( ev.type ) ); tr.appendChild( cell( details.join( '  ' ) ) );
						body.insertBefore( tr, body.firstChild );
					} );
					while ( body.rows.length > 200 ) { body.deleteRow( body.rows.length - 1 ); }
				} ).catch( function ( e ) { state.textContent = String( e ); } ).then( function () { if ( running ) { setTimeout( poll, 500 ); } } );
			}
			btn.addEventListener( 'click', function () {
				running = ! running; btn.textContent = running ? btn.dataset.stop : btn.dataset.start;
				if ( running ) { poll(); }
			} );
		} )();
		</script>
		<?php
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			$top = $result->value['top'];
			printf( '<h2>%s — %s</h2>', esc_html( $result->name() ), esc_html( sprintf( 'busiest URLs, last %s', Operator::seconds( (int) $top->windowSecs ) ) ) );
			echo Operator::table( array( __( 'Path', 'trident-cache-woocommerce' ), __( 'Requests', 'trident-cache-woocommerce' ), __( 'Hit rate', 'trident-cache-woocommerce' ), __( 'Errors', 'trident-cache-woocommerce' ), __( 'Avg', 'trident-cache-woocommerce' ) ) );
			foreach ( $top->urls as $url ) {
				echo Operator::row(
					array(
						'<code>' . esc_html( (string) ( $url['path'] ?? '' ) ) . '</code>',
						esc_html( (string) (int) ( $url['requests'] ?? 0 ) ),
						esc_html( number_format_i18n( (float) ( $url['hit_ratio'] ?? 0 ), 1 ) . '%' ), // Already a percentage.
						esc_html( (string) (int) ( $url['errors'] ?? 0 ) ),
						esc_html( sprintf( '%s ms', number_format_i18n( (float) ( $url['avg_duration_ms'] ?? 0 ), 1 ) ) ),
					)
				);
			}
			if ( array() === $top->urls ) {
				echo Operator::empty_row( 5, __( 'No traffic in the window.', 'trident-cache-woocommerce' ) );
			}
			echo '</tbody></table>';
			$errors = $result->value['errors'];
			if ( null === $errors ) {
				continue;
			}
			printf( '<h3>%s</h3>', esc_html( sprintf( 'Errors: %d of %d requests', $errors->totalErrors, $errors->totalRequests ) ) );
			echo Operator::table( array( __( 'When', 'trident-cache-woocommerce' ), 'HTTP', __( 'Path', 'trident-cache-woocommerce' ) ) );
			foreach ( $errors->recent as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}
				$when = isset( $error['timestamp_ms'] ) ? human_time_diff( (int) ( (int) $error['timestamp_ms'] / 1000 ) ) . ' ago' : '-';
				echo Operator::row( array( esc_html( $when ), esc_html( (string) ( $error['status'] ?? '' ) ), '<code>' . esc_html( (string) ( $error['path'] ?? '' ) ) . '</code>' ) );
			}
			if ( array() === $errors->recent ) {
				echo Operator::empty_row( 3, __( 'No recent errors.', 'trident-cache-woocommerce' ) );
			}
			echo '</tbody></table>';
		}
		// phpcs:enable
	}
}
