<?php
/**
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * `assets/lib/` is the plugin's copy of the library's browser assets. The
 * plugin serves that copy (Plugin::library_asset_url()) because a site that
 * installs it with Composer keeps the library in a `vendor/` outside the web
 * root. A change to the library's script must reach the copy in the same change.
 */
final class LibraryAssetsTest extends TestCase {

	public function test_the_plugin_serves_the_library_script_unchanged(): void {
		$plugin = dirname( __DIR__, 2 );
		// In the Trident repository: the library in this tree, not the copy
		// Composer made of it at install time. A clone of the mirror: the
		// installed library.
		$library = is_dir( $plugin . '/../../../php-library/assets' )
			? $plugin . '/../../../php-library'
			: (string) InstalledVersions::getInstallPath( 'qoliber/trident-php' );
		$files = glob( $library . '/assets/js/*.js' );
		self::assertNotEmpty( $files, 'no library scripts found under ' . $library );
		foreach ( $files as $file ) {
			$copy = $plugin . '/assets/lib/js/' . basename( $file );
			self::assertFileExists( $copy, 'copy the library\'s assets/js/' . basename( $file ) . ' to assets/lib/js/' );
			self::assertFileEquals( $file, $copy, 'assets/lib/js/' . basename( $file ) . ' differs from the library\'s — copy it again' );
		}
		self::assertCount( count( $files ), (array) glob( $plugin . '/assets/lib/js/*.js' ), 'assets/lib/js/ has a script the library no longer ships' );
	}
}
