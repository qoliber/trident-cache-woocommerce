<?php
/**
 * Unit-test bootstrap: the plugin's WordPress-free classes, the library they
 * build on (Composer path repository), and the one WordPress function they
 * touch, shimmed. Nothing here loads WordPress; the WordPress glue is tested
 * live by tests/woocommerce-e2e.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
