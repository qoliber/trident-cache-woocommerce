<?php
/**
 * Encryption at rest for the admin API token.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo;

/**
 * Libsodium secretbox with a key derived from the site's secret keys. Pure: the
 * key material is passed in.
 *
 * The threat this answers is the common one for a shop: a database dump (a
 * backup, a staging copy, an SQL-injection read) that includes `wp_options`.
 * The Trident admin token purges or clears the edge, so it must not sit there
 * in plain text. The key comes from `wp_salt( 'auth' )`, i.e. AUTH_KEY and
 * AUTH_SALT — which are outside the database ONLY when both are defined in
 * wp-config.php with real values. If either is missing, WordPress generates it
 * and stores it in the database (`auth_key`/`auth_salt` site options), next to
 * the ciphertext, and the encryption no longer protects against a dump; the
 * settings page then says so (keys_in_config()). Neither case protects
 * against someone who can read wp-config.php — for that, define
 * TRIDENT_API_TOKEN there (or read it from the environment) and the plugin
 * never stores the token at all.
 */
final class Secret {

	private const PREFIX = 'tv1:';

	/**
	 * @param string $key_material Site secret (wp_salt('auth')).
	 */
	public function __construct( private readonly string $key_material ) {
	}

	/**
	 * Whether AUTH_KEY and AUTH_SALT are real constants (not WordPress's
	 * database fallback, not the sample value).
	 *
	 * @param array<string, string> $constants AUTH_KEY / AUTH_SALT as defined (missing = not defined).
	 * @return bool
	 */
	public static function keys_in_config( array $constants ): bool {
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			$value = $constants[ $name ] ?? '';
			if ( '' === $value || 'put your unique phrase here' === $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $plain Token.
	 * @return string Stored form.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext.
	}

	/**
	 * @param string $stored Stored form.
	 * @return string|null Token, or null when it cannot be decrypted (the site
	 *                     keys were rotated) — the admin must enter it again.
	 */
	public function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);
		return false === $plain ? null : $plain;
	}

	/**
	 * @return string 32-byte key.
	 */
	private function key(): string {
		return hash( 'sha256', 'trident-cache-woocommerce|' . $this->key_material, true );
	}
}
