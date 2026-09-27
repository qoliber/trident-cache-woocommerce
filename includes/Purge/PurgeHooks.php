<?php
/**
 * What invalidates what.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Purge;

use Qoliber\Trident\Delivery\Purger;
use Qoliber\Trident\Tags\TagSet;
use Qoliber\TridentWoo\Tags\Names;

/**
 * WordPress/WooCommerce hooks → purge intents. The tag arithmetic is in
 * {@see Names}; this class only maps events to it.
 */
final class PurgeHooks {

	/** Product taxonomies whose archives list products. */
	private const PRODUCT_TAXONOMY_PREFIXES = array( 'product_cat', 'product_tag', 'pa_' );

	/**
	 * @param Purger $purger Purger.
	 * @param string $prefix Tag prefix.
	 */
	public function __construct(
		private readonly Purger $purger,
		private readonly string $prefix
	) {
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Products: CRUD saves (admin, REST, CLI, imports, scheduled sales).
		add_action( 'woocommerce_new_product', array( $this, 'product' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'product' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'product' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'product' ), 10, 1 );
		// Stock: order placement and refunds write stock with direct SQL and
		// only fire these — no product save happens.
		add_action( 'woocommerce_product_set_stock', array( $this, 'product_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'product_object' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'product' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'product' ), 10, 1 );
		// Publish/unpublish/trash/delete of anything, products included.
		add_action( 'transition_post_status', array( $this, 'post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ), 10, 1 );
		// Term assignment: the OLD terms matter (moved out of a category).
		add_action( 'set_object_terms', array( $this, 'object_terms' ), 10, 6 );
		// Terms themselves.
		add_action( 'edited_term', array( $this, 'term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term' ), 10, 3 );
		// Comments and product reviews.
		add_action( 'wp_set_comment_status', array( $this, 'comment' ), 10, 1 );
		add_action( 'edit_comment', array( $this, 'comment' ), 10, 1 );
		add_action( 'comment_post', array( $this, 'comment' ), 10, 1 );
		// Menus.
		add_action( 'wp_update_nav_menu', array( $this, 'menu' ), 10, 0 );
		add_action( 'wp_delete_nav_menu', array( $this, 'menu' ), 10, 0 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'menu' ), 10, 0 );
		// Block themes: the navigation block's menu is a wp_navigation post;
		// templates, template parts and global styles shape every page.
		add_action( 'save_post_wp_navigation', array( $this, 'menu' ), 10, 0 );
		add_action( 'save_post_wp_template', array( $this, 'all' ), 10, 0 );
		add_action( 'save_post_wp_template_part', array( $this, 'all' ), 10, 0 );
		add_action( 'save_post_wp_global_styles', array( $this, 'all' ), 10, 0 );
		// Site-wide settings, widgets, theme.
		add_action( 'updated_option', array( $this, 'option' ), 10, 1 );
		add_action( 'added_option', array( $this, 'option' ), 10, 1 );
		add_action( 'switch_theme', array( $this, 'all' ), 10, 0 );
		add_action( 'customize_save_after', array( $this, 'all' ), 10, 0 );
		add_action( 'woocommerce_attribute_updated', array( $this, 'all' ), 10, 0 );
		add_action( 'woocommerce_attribute_deleted', array( $this, 'all' ), 10, 0 );
	}

	/**
	 * Purge an explicit list of (unprefixed) tags.
	 *
	 * @param array<int, string> $tags Tags.
	 * @return int Rows recorded.
	 */
	public function tags( array $tags ): int {
		$out = array();
		foreach ( $tags as $tag ) {
			$tag = TagSet::normalise( $this->prefix, (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}
		return $this->purger->purgeTags( $out );
	}

	/**
	 * @return void
	 */
	public function all(): void {
		$this->tags( array( Names::ALL ) );
	}

	/**
	 * @param int|string $product_id Product or variation id.
	 * @return void
	 */
	public function product( $product_id ): void {
		$this->purge_product( (int) $product_id, array() );
	}

	/**
	 * @param \WC_Product|mixed $product Product.
	 * @return void
	 */
	public function product_object( $product ): void {
		if ( $product instanceof \WC_Product ) {
			$this->purge_product( (int) $product->get_id(), array() );
		}
	}

	/**
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}
		$this->purge_post( $post, array() );
	}

	/**
	 * @param int|string $post_id Post id.
	 * @return void
	 */
	public function post_deleted( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$this->purge_post( $post, array() );
		}
	}

	/**
	 * `set_object_terms`: purge the lists the object left, too.
	 *
	 * @param int               $object_id  Object id.
	 * @param array<int, mixed> $terms      Terms given.
	 * @param array<int, int>   $tt_ids     New term-taxonomy ids.
	 * @param string            $taxonomy   Taxonomy.
	 * @param bool              $append     Append mode.
	 * @param array<int, int>   $old_tt_ids Previous term-taxonomy ids.
	 * @return void
	 */
	public function object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $append );
		$post = get_post( (int) $object_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		$old = array_map( 'intval', (array) $old_tt_ids );
		$new = array_map( 'intval', (array) $tt_ids );
		sort( $old );
		sort( $new );
		if ( $old === $new ) {
			return;
		}
		$term_ids = array();
		foreach ( array_unique( array_merge( $old, $new ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, (string) $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		$extra = array( (string) $taxonomy => $term_ids );
		if ( 'product' === $post->post_type || 'product_variation' === $post->post_type ) {
			$this->purge_product( (int) $post->ID, $extra );
		} else {
			$this->purge_post( $post, $term_ids );
		}
	}

	/**
	 * @param int|string $term_id  Term id.
	 * @param int|string $tt_id    Term taxonomy id.
	 * @param string     $taxonomy Taxonomy.
	 * @return void
	 */
	public function term( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		if ( 'nav_menu' === $taxonomy ) {
			$this->menu();
			return;
		}
		$this->tags( Names::term_purge( (int) $term_id, (string) $taxonomy ) );
	}

	/**
	 * @param int|string $comment_id Comment id.
	 * @return void
	 */
	public function comment( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		// An unapproved comment is not on any public page.
		if ( '1' !== (string) $comment->comment_approved && 'wp_set_comment_status' !== current_action() ) {
			return;
		}
		$post = get_post( (int) $comment->comment_post_ID );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			if ( 'product' === $post->post_type ) {
				$this->tags( array( Names::product( (int) $post->ID ) ) );
			} else {
				$this->tags( array( Names::post( (int) $post->ID ) ) );
			}
		}
	}

	/**
	 * @return void
	 */
	public function menu(): void {
		$this->tags( array( Names::MENU ) );
	}

	/**
	 * @param string $option Option name.
	 * @return void
	 */
	public function option( $option ): void {
		$option = (string) $option;
		if ( Names::option_purges_all( $option ) ) {
			$this->all();
		} elseif ( Names::option_purges_widgets( $option ) ) {
			$this->tags( array( Names::WIDGETS ) );
		}
	}

	/**
	 * @param int                            $id    Product or variation id.
	 * @param array<string, array<int, int>> $extra Extra term ids (old terms).
	 * @return void
	 */
	private function purge_product( int $id, array $extra ): void {
		if ( $id <= 0 ) {
			return;
		}
		$post    = get_post( $id );
		$parents = array();
		$subject = $id;
		if ( $post instanceof \WP_Post && 'product_variation' === $post->post_type ) {
			$parents[] = (int) $post->post_parent;
			$subject   = (int) $post->post_parent;
		}
		$terms = $extra;
		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
			if ( ! self::is_product_taxonomy( $taxonomy ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $subject, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $ids ) ) {
				$terms[ $taxonomy ] = array_merge( $terms[ $taxonomy ] ?? array(), array_map( 'intval', $ids ) );
			}
		}
		$this->tags( Names::product_purge( $id, $parents, $terms ) );
	}

	/**
	 * @param \WP_Post        $post     Post.
	 * @param array<int, int> $term_ids Extra (old) term ids.
	 * @return void
	 */
	private function purge_post( \WP_Post $post, array $term_ids ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || in_array( $post->post_type, array( 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'wp_global_styles', 'shop_order', 'shop_order_placehold', 'shop_coupon', 'scheduled-action' ), true ) ) {
			return;
		}
		if ( 'product' === $post->post_type || 'product_variation' === $post->post_type ) {
			$this->purge_product( (int) $post->ID, array() );
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( (int) $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $ids ) ) {
				$term_ids = array_merge( $term_ids, array_map( 'intval', $ids ) );
			}
		}
		$this->tags( Names::post_purge( (int) $post->ID, $post->post_type, $term_ids ) );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function is_product_taxonomy( string $taxonomy ): bool {
		foreach ( self::PRODUCT_TAXONOMY_PREFIXES as $prefix ) {
			if ( str_starts_with( $taxonomy, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
