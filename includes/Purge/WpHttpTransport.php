<?php
/**
 * Transport over the WordPress HTTP API.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Purge;

use Qoliber\Trident\Delivery\Transport;

/**
 * `wp_remote_request()` with explicit, short limits.
 */
final class WpHttpTransport implements Transport {

	/**
	 * Seconds. A wedged admin port must not hold a shop request for WordPress's
	 * default 5 s per purge, times every instance.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * @param int $timeout Total request timeout in seconds.
	 */
	public function __construct( int $timeout = 5 ) {
		$this->timeout = $timeout;
	}

	/**
	 * Send one request.
	 *
	 * @param string                $method  GET or POST.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Request body.
	 * @return array{status: int, body: string, error: string|null}
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): array {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $this->timeout,
			// A redirect is never an acknowledgement (a proxy bouncing to a login
			// page answers 200 at the end of it).
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => 'trident-cache-woocommerce/' . TRIDENT_WOO_VERSION,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => null,
		);
	}
}
