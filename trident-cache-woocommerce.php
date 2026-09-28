<?php
/**
 * Plugin Name:          Trident Cache for WooCommerce
 * Plugin URI:           https://github.com/Trident-Cache/trident-cache/tree/main/integrations/ecommerce/woocommerce
 * Description:          Full-page caching for WooCommerce behind the Trident HTTP cache: cache tags, safe cacheability, durable purges, private content from a local cache, optional ESI for shared blocks.
 * Version:              1.8.0-beta.1
 * Author:               qoliber
 * Author URI:           https://qoliber.com
 * License:              MIT
 * Requires at least:    7.0
 * Tested up to:         7.1
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 * Text Domain:          trident-cache-woocommerce
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'TRIDENT_WOO_VERSION', '1.8.0-beta.1' );
define( 'TRIDENT_WOO_FILE', __FILE__ );
define( 'TRIDENT_WOO_DIR', __DIR__ );

// Dependencies: qoliber/trident-php (the shared, platform-neutral delivery,
// tag and cache-policy code). The release zip bundles it PREFIXED under
// vendor-prefixed/ (Strauss, see bin/build-zip.sh), so two plugins bundling
// different versions of the library — or of its PSR interfaces — cannot load
// each other's classes. A development checkout uses Composer's vendor/. A site
// that installs the plugin with Composer (Bedrock and the like) has the library
// in its own vendor/, and its autoloader is loaded before WordPress.
if ( is_file( TRIDENT_WOO_DIR . '/vendor-prefixed/autoload.php' ) ) {
	require_once TRIDENT_WOO_DIR . '/vendor-prefixed/autoload.php';
} elseif ( is_file( TRIDENT_WOO_DIR . '/vendor/autoload.php' ) ) {
	require_once TRIDENT_WOO_DIR . '/vendor/autoload.php';
} elseif ( ! class_exists( \Qoliber\Trident\Delivery\Purger::class ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>Trident Cache for WooCommerce: dependencies are missing. Install the release zip, require <code>qoliber/trident-cache-woocommerce</code> with the site\'s Composer, or run <code>composer install</code> in the plugin directory.</p></div>';
		}
	);
	return;
}

// The plugin's own classes: PSR-4 under includes/.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Qoliber\\TridentWoo\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = TRIDENT_WOO_DIR . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \Qoliber\TridentWoo\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Qoliber\TridentWoo\Plugin::class, 'deactivate' ) );

// Declare compatibility with WooCommerce's order tables: the plugin never reads
// or writes orders directly, it only listens to stock changes.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Qoliber\TridentWoo\Plugin::class, 'boot' ), 20 );
