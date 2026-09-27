<?php
/**
 * Trident Cache → Launch mode.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\Trident\Admin\Payload;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\Trident\Response\LaunchResponse;
use Qoliber\TridentWoo\Admin\Operator;

/**
 * Launch mode: visitors see the maintenance page while the shop is warmed,
 * then it goes live at once. Start, complete and abort all change what every
 * visitor gets, so each needs the confirm box.
 */
final class Launch extends Screen {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'launch';
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return __( 'Launch mode', 'trident-cache-woocommerce' );
	}

	/**
	 * @return int
	 */
	public function position(): int {
		return 60;
	}

	/**
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array(
			'start'    => true,
			'complete' => true,
			'abort'    => true,
		);
	}

	/**
	 * @return void
	 */
	public function render(): void {
		$results = $this->op->fleet()->each( static fn ( TridentClient $c ): Payload => $c->launch() );
		Operator::problems( $results, __( 'launch mode', 'trident-cache-woocommerce' ) );
		$select = $this->op->instance_select();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- details()/form()/confirm() escape.
		foreach ( $results as $result ) {
			if ( ! $result->isOk() ) {
				continue;
			}
			printf( '<h2>%s — %s</h2>', esc_html( $result->name() ), esc_html( $result->value->string( 'state', '?' ) ) );
			echo Operator::details( $result->value->all() );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		echo '<h2>' . esc_html__( 'Start a launch', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'launch', 'start' );
		printf( '<p><label>%s<br><textarea name="urls" rows="4" class="large-text code" placeholder="%s"></textarea></label></p>', esc_html__( 'URLs to warm (empty: this shop\'s own pages)', 'trident-cache-woocommerce' ), esc_attr( home_url( '/' ) ) );
		printf( '<p><label>%s <input type="text" name="bypass_ips" value="%s" class="regular-text"></label></p>', esc_html__( 'IPs that see the real shop (comma-separated)', 'trident-cache-woocommerce' ), esc_attr( $ip ) );
		printf( '<p><label><input type="checkbox" name="auto_complete" value="1"> %s</label></p>', esc_html__( 'Go live automatically when warming finishes', 'trident-cache-woocommerce' ) );
		echo '<p>' . $select . Operator::confirm( __( 'Visitors get the maintenance page until the launch completes', 'trident-cache-woocommerce' ) ) . '<button class="button button-primary">' . esc_html__( 'Start launch', 'trident-cache-woocommerce' ) . '</button></p></form>';
		echo '<h2>' . esc_html__( 'Finish', 'trident-cache-woocommerce' ) . '</h2>';
		echo $this->op->form( 'launch', 'complete', 'display:inline-block;margin-right:2em' ) . $select . Operator::confirm( __( 'Go live now', 'trident-cache-woocommerce' ) ) . '<button class="button button-primary">' . esc_html__( 'Complete', 'trident-cache-woocommerce' ) . '</button></form>';
		echo $this->op->form( 'launch', 'abort', 'display:inline-block' ) . '<input type="text" name="reason" placeholder="' . esc_attr__( 'Reason', 'trident-cache-woocommerce' ) . '"> ' . $select . Operator::confirm( __( 'Abort the launch', 'trident-cache-woocommerce' ) ) . '<button class="button button-link-delete">' . esc_html__( 'Abort', 'trident-cache-woocommerce' ) . '</button></form>';
		// phpcs:enable
	}

	/**
	 * @param string                $op   Operation.
	 * @param array<string, string> $post Sanitised POST.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		$fleet    = $this->op->fleet();
		$targets  = Operator::targets( $post );
		$describe = static fn ( LaunchResponse $r ): string => (string) ( $r->message ?? $r->status ?? 'ok' );
		switch ( $op ) {
			case 'start':
				$split = preg_split( '/\R/', (string) ( $post['urls'] ?? '' ) );
				$lines = array_filter( array_map( 'trim', false === $split ? array() : $split ) );
				$urls  = array();
				foreach ( array() === $lines ? Coverage::catalogue() : $lines as $line ) {
					$page = Purge::own_url( $line );
					if ( null !== $page ) {
						$urls[] = $page->absolute();
					}
				}
				$ips     = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $post['bypass_ips'] ?? '' ) ) ), static fn ( string $ip ): bool => false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) );
				$options = array(
					'urls'          => $urls,
					'bypass_ips'    => $ips,
					'auto_complete' => '1' === ( $post['auto_complete'] ?? '' ),
				);
				Operator::report( __( 'Launch start', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): LaunchResponse => $c->launchStart( $options ) ), $describe );
				return;
			case 'complete':
				Operator::report( __( 'Launch complete', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): LaunchResponse => $c->launchComplete() ), $describe );
				return;
			case 'abort':
				$reason = (string) ( $post['reason'] ?? '' );
				Operator::report( __( 'Launch abort', 'trident-cache-woocommerce' ), $fleet->on( $targets, static fn ( TridentClient $c ): LaunchResponse => $c->launchAbort( null, '' !== $reason ? $reason : null ) ), $describe );
				return;
		}
	}
}
