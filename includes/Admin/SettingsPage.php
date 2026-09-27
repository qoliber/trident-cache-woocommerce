<?php
/**
 * Trident Cache → Settings.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin;

use Qoliber\Trident\Delivery\DrainReport;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\TridentWoo\Plugin;
use Qoliber\TridentWoo\Purge\WpHttpTransport;

/**
 * Settings, "Purge all", and the outbox status.
 */
final class SettingsPage {

	/** Who may change the caching settings (the connection needs CONNECTION_CAPABILITY). */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Where purges go and with which credential. A Shop Manager
	 * (manage_woocommerce) who could change the API URL could point it at a
	 * server of their own, press "Test connection", and receive the Trident
	 * ADMIN token in the Authorization header — so these need manage_options.
	 */
	private const CONNECTION_CAPABILITY = 'manage_options';

	/** Settings only CONNECTION_CAPABILITY may change. */
	private const CONNECTION_SETTINGS = array( 'api_url', 'api_token', 'esi_addresses' );

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'save', 'purge_all', 'drain', 'forget', 'test' ) as $action ) {
			add_action( 'admin_post_trident_' . $action, array( $this, 'handle_' . $action ) );
		}
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TRIDENT_WOO_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * @param array<int, string> $links Links.
	 * @return array<int, string>
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Settings', 'trident-cache-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Warnings that must not hide on the settings page only.
	 *
	 * @return void
	 */
	public function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$stats = $this->plugin->store->stats( time() );
		if ( ( $stats['oldest_age'] ?? 0 ) > Plugin::STALE_AFTER ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( 'Trident: %d cache purge(s) have not been delivered for over %d minutes — customers may see old prices or stock.', $stats['pending'], (int) ( Plugin::STALE_AFTER / 60 ) ) ),
				esc_url( $this->url() ),
				esc_html__( 'Details', 'trident-cache-woocommerce' )
			);
		}
		$constants = array();
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = (string) constant( $name );
			}
		}
		if ( current_user_can( 'manage_options' ) && null === $this->plugin->settings->constant_for( 'api_token' )
			&& '' !== $this->plugin->settings->api_token() && ! \Qoliber\TridentWoo\Secret::keys_in_config( $constants ) ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Trident: AUTH_KEY / AUTH_SALT are not defined in wp-config.php, so WordPress keeps them in the database — next to the encrypted Trident token, which a database dump then exposes. Define them in wp-config.php (and re-enter the token), or set TRIDENT_API_TOKEN there.', 'trident-cache-woocommerce' ) );
		}
		if ( $this->plugin->settings->token_unreadable() ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Trident: the stored API token can no longer be decrypted (the site keys changed). Enter it again on Trident Cache → Settings.', 'trident-cache-woocommerce' ) );
		}
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s         = $this->plugin->settings;
		$stats     = $this->plugin->store->stats( time() );
		$instances = $this->plugin->purger->instances();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['trident_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['trident_msg'] ) ) : '';
		$field   = static function ( string $key, string $label, string $html, string $help = '' ): void {
			printf( '<tr><th scope="row"><label for="trident-%1$s">%2$s</label></th><td>', esc_attr( $key ), esc_html( $label ) );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* below.
			if ( '' !== $help ) {
				printf( '<p class="description">%s</p>', wp_kses( $help, array( 'code' => array() ) ) );
			}
			echo '</td></tr>';
		};
		$admin   = current_user_can( self::CONNECTION_CAPABILITY );
		$locked  = static fn ( ?string $constant ): string => null !== $constant ? ' disabled' : '';
		$conn    = static fn ( ?string $constant ): string => ( null !== $constant || ! $admin ) ? ' disabled' : '';
		$check   = static fn ( string $key, bool $on ): string => sprintf( '<input type="checkbox" id="trident-%1$s" name="%1$s" value="1"%2$s>', esc_attr( $key ), $on ? ' checked' : '' );
		?>
		<div class="wrap">
			<h1>Trident Cache — <?php esc_html_e( 'Settings', 'trident-cache-woocommerce' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Purge queue', 'trident-cache-woocommerce' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						'Pending: %d · oldest: %s · last failure: %s',
						$stats['pending'],
						null === $stats['oldest_age'] ? '-' : $stats['oldest_age'] . 's',
						null === $stats['last_error'] ? '-' : $stats['last_error'] . ' (' . human_time_diff( (int) $stats['last_error_at'] ) . ' ago)'
					)
				);
				?>
			</p>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr><th><?php esc_html_e( 'Instance', 'trident-cache-woocommerce' ); ?></th><th>API</th><th><?php esc_html_e( 'Pending', 'trident-cache-woocommerce' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				$configured = array();
				foreach ( $instances as $instance ) {
					$configured[ $instance->name ] = true;
					printf( '<tr><td>%s</td><td><code>%s</code></td><td>%d</td><td></td></tr>', esc_html( $instance->name ), esc_html( $instance->apiUrl ), (int) ( $stats['by_instance'][ $instance->name ] ?? 0 ) );
				}
				foreach ( $stats['by_instance'] as $name => $count ) {
					if ( isset( $configured[ $name ] ) ) {
						continue;
					}
					printf(
						'<tr><td>%s <em>(%s)</em></td><td>-</td><td>%d</td><td>%s</td></tr>',
						esc_html( $name ),
						esc_html__( 'no longer configured', 'trident-cache-woocommerce' ),
						(int) $count,
						$this->button( 'forget', __( 'Forget', 'trident-cache-woocommerce' ), array( 'instance' => $name ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
				}
				if ( array() === $instances && array() === $stats['by_instance'] ) {
					printf( '<tr><td colspan="4">%s</td></tr>', esc_html__( 'No Trident instance configured.', 'trident-cache-woocommerce' ) );
				}
				?>
				</tbody>
			</table>
			<?php foreach ( $this->plugin->instance_errors as $error ) : ?>
				<p class="notice notice-error inline"><?php echo esc_html( 'TRIDENT_INSTANCES: ' . $error ); ?></p>
			<?php endforeach; ?>
			<p>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes.
				echo $this->button( 'purge_all', __( 'Purge all', 'trident-cache-woocommerce' ), array(), 'button-primary' );
				echo ' ' . $this->button( 'drain', __( 'Deliver pending now', 'trident-cache-woocommerce' ) );
				if ( current_user_can( self::CONNECTION_CAPABILITY ) ) {
					echo ' ' . $this->button( 'test', __( 'Test connection', 'trident-cache-woocommerce' ) );
				}
				// phpcs:enable
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trident_save">
				<?php wp_nonce_field( 'trident_save' ); ?>
				<h2><?php esc_html_e( 'Connection', 'trident-cache-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'enabled', __( 'Enabled', 'trident-cache-woocommerce' ), $check( 'enabled', (bool) $s->get( 'enabled' ) ), __( 'Cache headers, tags and purges. Off: the plugin does nothing on the storefront.', 'trident-cache-woocommerce' ) );
					$constant = $s->constant_for( 'instances' ) ?? $s->constant_for( 'api_url' );
					$field(
						'api_url',
						__( 'Admin API URL', 'trident-cache-woocommerce' ),
						sprintf( '<input type="url" class="regular-text" id="trident-api_url" name="api_url" value="%s" placeholder="http://127.0.0.1:9301"%s>', esc_attr( (string) $s->get( 'api_url' ) ), $conn( $constant ) ),
						null !== $constant ? sprintf( 'Set in wp-config.php (<code>%s</code>) — it takes precedence.', $constant ) : 'Trident\'s <code>[admin] address</code>. Several instances: define <code>TRIDENT_INSTANCES</code> in wp-config.php.'
					);
					$tconst = $s->constant_for( 'api_token' );
					$field(
						'api_token',
						__( 'Admin API token', 'trident-cache-woocommerce' ),
						sprintf( '<input type="password" class="regular-text" id="trident-api_token" name="api_token" value="" autocomplete="new-password" placeholder="%s"%s>', esc_attr( '' !== $s->api_token() ? __( '(saved — leave empty to keep)', 'trident-cache-woocommerce' ) : '' ), $conn( $tconst ) ),
						null !== $tconst ? 'Set in wp-config.php (<code>TRIDENT_API_TOKEN</code>).' : 'Stored encrypted with the site keys from wp-config.php; never shown again. Or define <code>TRIDENT_API_TOKEN</code>.'
					);
					$mconst = $s->constant_for( 'purge_mode' );
					$field(
						'purge_mode',
						__( 'Purge mode', 'trident-cache-woocommerce' ),
						sprintf(
							'<select id="trident-purge_mode" name="purge_mode"%s><option value="soft"%s>soft</option><option value="hard"%s>hard</option></select>',
							$locked( $mconst ),
							selected( $s->get( 'purge_mode' ), 'soft', false ),
							selected( $s->get( 'purge_mode' ), 'hard', false )
						),
						'<code>soft</code>: the old page is served while Trident refreshes it in the background (no visitor waits). <code>hard</code>: removed at once; the next visitor renders it.'
					);
					?>
				</table>
				<h2><?php esc_html_e( 'Caching', 'trident-cache-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'ttl', __( 'Page TTL (s-maxage, seconds)', 'trident-cache-woocommerce' ), sprintf( '<input type="number" min="1" id="trident-ttl" name="ttl" value="%d">', (int) $s->get( 'ttl' ) ), 'Purges keep pages fresh; the TTL only bounds what a missed purge could cost.' );
					$field( 'swr', __( 'stale-while-revalidate (seconds)', 'trident-cache-woocommerce' ), sprintf( '<input type="number" min="0" id="trident-swr" name="swr" value="%d">', (int) $s->get( 'swr' ) ) );
					$field( 'tag_prefix', __( 'Tag prefix', 'trident-cache-woocommerce' ), sprintf( '<input type="text" id="trident-tag_prefix" name="tag_prefix" value="%s" pattern="[a-z0-9_-]{0,20}">', esc_attr( (string) $s->get( 'tag_prefix' ) ) ), 'Only when several sites share one Trident, e.g. <code>shop1_</code>. Changing it orphans the tags of pages already cached — purge all afterwards.' );
					$field( 'debug_headers', __( 'Debug header', 'trident-cache-woocommerce' ), $check( 'debug_headers', (bool) $s->get( 'debug_headers' ) ), 'Send <code>X-Trident-Decision</code> (why a page was or was not cacheable).' );
					?>
				</table>
				<h2><?php esc_html_e( 'Private content', 'trident-cache-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'cart_fragments', __( 'Cart fragments on every page', 'trident-cache-woocommerce' ), $check( 'cart_fragments', (bool) $s->get( 'cart_fragments' ) ), 'Loads WooCommerce\'s <code>wc-cart-fragments</code>, which renders the visitor\'s own mini-cart on the shared page from sessionStorage.' );
					$field( 'personal_sections', __( 'Personal sections', 'trident-cache-woocommerce' ), $check( 'personal_sections', (bool) $s->get( 'personal_sections' ) ), 'The <code>[trident_section]</code> placeholders ("Hello, Anna", counts), filled from a local cache.' );
					?>
				</table>
				<h2>ESI</h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'esi_enabled', __( 'ESI', 'trident-cache-woocommerce' ), $check( 'esi_enabled', (bool) $s->get( 'esi_enabled' ) ), 'Shared blocks as fragments and per-request tokens punched out. Needs <code>[esi] enabled = true</code> in Trident. Only requests that come from a Trident address get ESI markup; everything else is rendered inline.' );
					$field(
						'esi_mode',
						__( 'Trident [esi] mode', 'trident-cache-woocommerce' ),
						sprintf(
							'<select id="trident-esi_mode" name="esi_mode"><option value="hole_punch"%s>hole_punch</option><option value="assemble"%s>assemble</option></select>',
							selected( $s->get( 'esi_mode' ), 'hole_punch', false ),
							selected( $s->get( 'esi_mode' ), 'assemble', false )
						),
						'Must match Trident. <code>hole_punch</code> (recommended): a menu edit purges one fragment; tokens are private fragments. <code>assemble</code>: Trident stores assembled pages — pages carry the menu tag, and a page with a token is not stored.'
					);
					$locations = array_keys( get_registered_nav_menus() );
					$field(
						'esi_menus',
						__( 'Menus (classic themes)', 'trident-cache-woocommerce' ),
						sprintf( '<input type="text" class="regular-text" id="trident-esi_menus" name="esi_menus" value="%s" placeholder="*">', esc_attr( (string) $s->get( 'esi_menus' ) ) ),
						'Theme locations, comma-separated, or <code>*</code> for all (' . esc_html( implode( ', ', $locations ) ) . '). Empty: inline.'
					);
					$field(
						'esi_widgets',
						__( 'Widget areas', 'trident-cache-woocommerce' ),
						sprintf( '<input type="text" class="regular-text" id="trident-esi_widgets" name="esi_widgets" value="%s" placeholder="*">', esc_attr( (string) $s->get( 'esi_widgets' ) ) ),
						'Sidebar ids, comma-separated, or <code>*</code>. Only widgets that are the same for every visitor.'
					);
					$field( 'esi_navigation', __( 'Navigation block (block themes)', 'trident-cache-woocommerce' ), $check( 'esi_navigation', (bool) $s->get( 'esi_navigation' ) ) );
					$field( 'esi_private', __( 'Punch out per-request tokens', 'trident-cache-woocommerce' ), $check( 'esi_private', (bool) $s->get( 'esi_private' ) ), 'Store API and REST nonces in pages with React-rendered WooCommerce blocks. Off: such pages are not stored.' );
					$field( 'esi_addresses', __( 'Extra Trident addresses', 'trident-cache-woocommerce' ), sprintf( '<input type="text" class="regular-text" id="trident-esi_addresses" name="esi_addresses" value="%s" placeholder="10.0.0.0/8"%s>', esc_attr( (string) $s->get( 'esi_addresses' ) ), $conn( null ) ), 'Addresses/CIDRs Trident connects from, beyond loopback and the hosts of the admin URLs.' );
					$field( 'esi_ttl', __( 'Fragment TTL (seconds)', 'trident-cache-woocommerce' ), sprintf( '<input type="number" min="1" id="trident-esi_ttl" name="esi_ttl" value="%d">', (int) $s->get( 'esi_ttl' ) ) );
					?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Trident configuration for this site', 'trident-cache-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Also printed by `wp trident config`.', 'trident-cache-woocommerce' ); ?></p>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;max-width:60em;overflow:auto"><?php echo esc_html( ConfigSnippet::toml() ); ?></pre>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function handle_save(): void {
		$this->guard( 'trident_save' );
		$s      = $this->plugin->settings;
		$errors = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified it.
		foreach ( array( 'enabled', 'debug_headers', 'cart_fragments', 'personal_sections', 'esi_enabled', 'esi_navigation', 'esi_private' ) as $key ) {
			$s->set( $key, isset( $_POST[ $key ] ) );
		}
		$admin   = current_user_can( self::CONNECTION_CAPABILITY );
		$url_was = (string) $s->get( 'api_url' );
		$had_tok = '' !== $s->api_token();
		foreach ( array( 'api_url', 'purge_mode', 'ttl', 'swr', 'tag_prefix', 'esi_menus', 'esi_widgets', 'esi_mode', 'esi_ttl', 'esi_addresses' ) as $key ) {
			if ( in_array( $key, self::CONNECTION_SETTINGS, true ) && ! $admin ) {
				continue;
			}
			if ( isset( $_POST[ $key ] ) && null === $s->constant_for( $key ) ) {
				$error = $s->set( $key, sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) );
				if ( null !== $error ) {
					$errors[] = $error;
				}
			}
		}
		$token = isset( $_POST['api_token'] ) ? trim( (string) wp_unslash( $_POST['api_token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a secret, stored encrypted, never echoed.
		// phpcs:enable
		if ( $admin && '' !== $token && null === $s->constant_for( 'api_token' ) ) {
			$s->set( 'api_token', $token );
		}
		if ( $had_tok && '' === $s->api_token() && (string) $s->get( 'api_url' ) !== $url_was ) {
			$errors[] = __( 'The API host changed, so the stored token was cleared — enter the token for the new host.', 'trident-cache-woocommerce' );
		}
		$this->back( array() === $errors ? __( 'Settings saved.', 'trident-cache-woocommerce' ) : implode( '; ', $errors ) );
	}

	/**
	 * @return void
	 */
	public function handle_purge_all(): void {
		$this->guard( 'trident_purge_all' );
		$this->plugin->purge_all();
		$report = $this->plugin->purger->deliverOwn();
		$this->back( $this->describe( __( 'Purge all', 'trident-cache-woocommerce' ), $report ) );
	}

	/**
	 * @return void
	 */
	public function handle_drain(): void {
		$this->guard( 'trident_drain' );
		$report = $this->plugin->purger->drain( Plugin::BATCH_DRAIN_LIMIT, true );
		$this->back( $this->describe( __( 'Delivery', 'trident-cache-woocommerce' ), $report ) );
	}

	/**
	 * @return void
	 */
	public function handle_forget(): void {
		$this->guard( 'trident_forget' );
		$name = isset( $_POST['instance'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['instance'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $this->plugin->purger->instances() as $instance ) {
			if ( $instance->name === $name ) {
				$this->back( __( 'That instance is configured; its purges are delivered, not forgotten.', 'trident-cache-woocommerce' ) );
			}
		}
		$this->back( sprintf( 'Forgot %d purge(s) owed to "%s".', $this->plugin->store->forget( $name ), $name ) );
	}

	/**
	 * @return void
	 */
	public function handle_test(): void {
		$this->guard( 'trident_test', self::CONNECTION_CAPABILITY );
		$lines = array();
		foreach ( $this->plugin->purger->instances() as $instance ) {
			$result  = ( new PurgeClient( $instance, new WpHttpTransport() ) )->status();
			$lines[] = $instance->name . ': ' . ( $result['ok'] ? 'OK — ' : 'FAILED — ' ) . $result['message'];
		}
		$this->back( array() === $lines ? __( 'No Trident instance configured.', 'trident-cache-woocommerce' ) : implode( ' | ', $lines ) );
	}

	/**
	 * @param string      $what   Label.
	 * @param DrainReport $report Drain report.
	 * @return string
	 */
	private function describe( string $what, DrainReport $report ): string {
		$msg = sprintf( '%s: %d delivered, %d not acknowledged.', $what, $report->delivered, $report->failed );
		foreach ( $report->instances as $name => $result ) {
			if ( null !== $result['error'] ) {
				$msg .= sprintf( ' %s: %s.', $name, $result['error'] );
			}
		}
		if ( $report->failed > 0 ) {
			$msg .= ' ' . __( 'They stay queued and are retried automatically.', 'trident-cache-woocommerce' );
		}
		return $msg;
	}

	/**
	 * A POST button with its own nonce.
	 *
	 * @param string                $action Action suffix.
	 * @param string                $label  Label.
	 * @param array<string, string> $fields Hidden fields.
	 * @param string                $css_class  Button class.
	 * @return string
	 */
	private function button( string $action, string $label, array $fields = array(), string $css_class = 'button' ): string {
		$html  = sprintf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		$html .= sprintf( '<input type="hidden" name="action" value="trident_%s">', esc_attr( $action ) );
		$html .= wp_nonce_field( 'trident_' . $action, '_wpnonce', true, false );
		foreach ( $fields as $name => $value ) {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
		}
		$html .= sprintf( '<button type="submit" class="button %s">%s</button></form>', esc_attr( $css_class ), esc_html( $label ) );
		return $html;
	}

	/**
	 * @param string $action     Nonce action.
	 * @param string $capability Required capability.
	 * @return void
	 */
	private function guard( string $action, string $capability = self::CAPABILITY ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'trident-cache-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param string $message Notice.
	 * @return never
	 */
	private function back( string $message ): never {
		wp_safe_redirect( add_query_arg( 'trident_msg', rawurlencode( $message ), $this->url() ) );
		exit;
	}

	/**
	 * @return string
	 */
	private function url(): string {
		return Operator::url( 'settings' );
	}
}
