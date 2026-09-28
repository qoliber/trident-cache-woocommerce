<?php
/**
 * Run by run.sh inside the Composer-installed site: the plugin's main file,
 * loaded on the site's autoloader with just enough WordPress, must boot — not
 * report missing dependencies — and every class it ships must load against the
 * library in the site's vendor/.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

// Bedrock loads the site's autoloader first (config/application.php).
require '/site/vendor/autoload.php';

const PLUGIN_DIR = '/site/web/app/plugins/trident-cache-woocommerce';
define( 'ABSPATH', '/site/web/wp/' );

/**
 * @param string $message What is wrong.
 */
function fail( string $message ): never {
	fwrite( STDERR, "FAIL: $message\n" );
	exit( 1 );
}

is_file( PLUGIN_DIR . '/trident-cache-woocommerce.php' ) || fail( 'composer/installers did not put the plugin in web/app/plugins/' );
is_dir( PLUGIN_DIR . '/vendor' ) && fail( 'the plugin carries its own vendor/ — not a site-level install' );
is_dir( PLUGIN_DIR . '/vendor-prefixed' ) && fail( 'the plugin carries vendor-prefixed/ — not a site-level install' );
is_file( '/site/vendor/qoliber/trident-php/composer.json' ) || fail( 'the library is not in the site\'s vendor/' );

// Just enough WordPress for the plugin's main file.
$GLOBALS['hooks'] = array();
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['hooks'][] = $hook;
	return true;
}
function register_activation_hook( string $file, $callback ): void {}
function register_deactivation_hook( string $file, $callback ): void {}
function plugins_url( string $path, string $file ): string {
	return 'https://shop.test/app/plugins/' . basename( dirname( $file ) ) . '/' . $path;
}

require PLUGIN_DIR . '/trident-cache-woocommerce.php';

in_array( 'admin_notices', $GLOBALS['hooks'], true ) && fail( 'the plugin reported missing dependencies' );
in_array( 'plugins_loaded', $GLOBALS['hooks'], true ) || fail( 'the plugin did not register its boot' );

// Every class the plugin ships, loaded: whatever library class or interface
// one extends or implements must resolve from the site's vendor/.
$classes = 0;
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PLUGIN_DIR . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( PLUGIN_DIR . '/includes/' ), -4 );
	$class    = 'Qoliber\\TridentWoo\\' . str_replace( '/', '\\', $relative );
	class_exists( $class ) || interface_exists( $class ) || trait_exists( $class ) || fail( "$class does not load" );
	++$classes;
}

// The browser script is served from the plugin, not from a vendor/ the web
// server may not expose.
$url    = \Qoliber\TridentWoo\Plugin::library_asset_url( 'js/trident-sections.js' );
$prefix = 'https://shop.test/app/plugins/trident-cache-woocommerce/';
str_starts_with( $url, $prefix ) || fail( "the sections script is not served from the plugin: $url" );
is_file( PLUGIN_DIR . '/' . substr( $url, strlen( $prefix ) ) ) || fail( "the sections script is missing from the installed plugin: $url" );

printf( "composer install: plugin booted on the site's autoloader, %d classes loaded, sections script at %s\n", $classes, $url );
