<?php
/**
 * One Trident Cache admin screen.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Admin\Screens;

use Qoliber\TridentWoo\Admin\Operator;

/**
 * A screen renders over the shared client and names the operations it
 * accepts. {@see \Qoliber\TridentWoo\Admin\Menu::handle()} checks the method,
 * nonce, capability and confirm step before {@see self::handle()} runs.
 */
abstract class Screen {

	/**
	 * @param Operator $op Shared admin helpers.
	 */
	public function __construct( protected readonly Operator $op ) {
	}

	/**
	 * Slug: '' for the dashboard, else the part after `trident-cache-`.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Menu and page title.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * The page body (inside `.wrap`, after the title and notices).
	 *
	 * @return void
	 */
	abstract public function render(): void;

	/**
	 * Operations this screen accepts: name => needs an explicit confirm.
	 *
	 * @return array<string, bool>
	 */
	public function ops(): array {
		return array();
	}

	/**
	 * Run an operation. Leaves notices with {@see Operator::notice()}.
	 *
	 * @param string                $op   Operation (one of {@see self::ops()}).
	 * @param array<string, string> $post Sanitised POST fields.
	 * @return void
	 */
	public function handle( string $op, array $post ): void {
		unset( $op, $post );
	}

	/**
	 * Where to go after an operation.
	 *
	 * @param array<string, string> $post Sanitised POST fields.
	 * @return string
	 */
	public function back_url( array $post ): string {
		unset( $post );
		return Operator::url( $this->slug() );
	}

	/**
	 * Menu position hint (lower = higher up).
	 *
	 * @return int
	 */
	public function position(): int {
		return 50;
	}
}
