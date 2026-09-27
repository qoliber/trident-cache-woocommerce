<?php
/**
 * Collects the tags of the page being rendered.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tags;

use Qoliber\Trident\Tags\TagSet;

/**
 * Listens while the page renders, then adds what the page IS.
 *
 * Collecting during the render is what makes lists purgeable: a product shown as
 * "related" on another product's page, or in a shortcode grid on the home page,
 * tags that page with `wc_p_<id>` through the `the_post` loop — so changing the
 * product's price also refreshes the pages that show it. That is only possible
 * because {@see \Qoliber\TridentWoo\Cache\ResponseController} sends the headers
 * after the body is complete.
 */
final class TagCollector {

	/**
	 * @param TagSet        $tags                 Target set.
	 * @param bool          $tag_esi_menus_inline Also tag pages whose shared blocks are ESI
	 *                                            fragments (Trident `assemble` mode, see on_menu()).
	 * @param \Closure|null $widgets_are_esi      fn (string $sidebar): bool — the widget area is
	 *                                            emitted as an ESI fragment on this page.
	 */
	public function __construct(
		private readonly TagSet $tags,
		private readonly bool $tag_esi_menus_inline = false,
		private readonly ?\Closure $widgets_are_esi = null
	) {
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'the_post', array( $this, 'on_post' ) );
		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'on_loop_product' ) );
		add_filter( 'wp_nav_menu', array( $this, 'on_menu' ), 10, 2 );
		add_action( 'dynamic_sidebar_before', array( $this, 'on_widgets' ), 10, 1 );
		add_filter( 'render_block_core/navigation', array( $this, 'on_navigation' ), 30 );
	}

	/**
	 * @return TagSet
	 */
	public function tags(): TagSet {
		return $this->tags;
	}

	/**
	 * A post set up in any loop.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function on_post( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'product' === $post->post_type ) {
			$this->tags->add( Names::product( (int) $post->ID ), TagSet::LISTED );
		} elseif ( 'product_variation' === $post->post_type ) {
			$this->tags->add( Names::product( (int) $post->post_parent ), TagSet::LISTED );
		} else {
			$this->tags->add( Names::post( (int) $post->ID ), TagSet::LISTED );
		}
	}

	/**
	 * A product in a WooCommerce loop (covers loops that bypass `the_post`).
	 *
	 * @return void
	 */
	public function on_loop_product(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			$this->tags->add( Names::product( (int) $product->get_id() ), TagSet::LISTED );
		}
	}

	/**
	 * A menu rendered inline (not as an ESI fragment).
	 *
	 * The inline copy inside an ESI include's fallback does not count: behind
	 * Trident in `hole_punch` mode the page shows the fragment, which carries
	 * the tag itself — that is the whole point of making the menu a fragment.
	 * In `assemble` mode Trident stores the ASSEMBLED page, and the fragment's
	 * tags do not reach it (measured on the 1.8.0 candidate: a menu edit purged
	 * the fragment and the assembled pages kept the old menu), so there the
	 * page must carry `menu` itself.
	 *
	 * @param string          $html Menu HTML.
	 * @param \stdClass|mixed $args wp_nav_menu() arguments.
	 * @return string Unchanged.
	 */
	public function on_menu( $html, $args = null ) {
		if ( is_object( $args ) && ! empty( $args->trident_esi_fallback ) && ! $this->tag_esi_menus_inline ) {
			return $html;
		}
		$this->tags->add( Names::MENU, TagSet::REFERENCE );
		return $html;
	}

	/**
	 * A widget area rendered inline.
	 *
	 * @param int|string $index Sidebar id.
	 * @return void
	 */
	public function on_widgets( $index = '' ): void {
		if ( null !== $this->widgets_are_esi && ! $this->tag_esi_menus_inline && ( $this->widgets_are_esi )( (string) $index ) ) {
			return;
		}
		$this->tags->add( Names::WIDGETS, TagSet::REFERENCE );
	}

	/**
	 * A navigation block (block themes). Wrapped as an ESI fragment it starts
	 * with the ESI comment, and — outside assemble mode — the fragment carries
	 * the tag instead of the page.
	 *
	 * @param string $html Rendered block.
	 * @return string Unchanged.
	 */
	public function on_navigation( $html ) {
		if ( ! $this->tag_esi_menus_inline && str_starts_with( (string) $html, '<!--esi ' ) ) {
			return $html;
		}
		$this->tags->add( Names::MENU, TagSet::REFERENCE );
		return $html;
	}

	/**
	 * What the page itself is. Called once the render is complete.
	 *
	 * @return void
	 */
	public function add_identity(): void {
		$this->tags->add( Names::ALL );
		if ( is_front_page() || is_home() ) {
			$this->tags->add( Names::HOME );
		}
		if ( is_search() ) {
			$this->tags->add( Names::SEARCH );
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_post_type_archive( 'product' ) ) ) {
			$this->tags->add( Names::SHOP );
		}
		$object = get_queried_object();
		if ( is_singular() && $object instanceof \WP_Post ) {
			$id = (int) $object->ID;
			if ( 'product' === $object->post_type ) {
				$this->tags->add( Names::product( $id ) );
				// The product page shows its categories, tags and attribute
				// values (breadcrumb, meta, attributes table): it MENTIONS them.
				foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
					if ( 'product_cat' !== $taxonomy && 'product_tag' !== $taxonomy && ! str_starts_with( $taxonomy, 'pa_' ) ) {
						continue;
					}
					$terms = get_the_terms( $id, $taxonomy );
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						$this->tags->add( Names::term( (int) $term->term_id ), TagSet::REFERENCE );
					}
				}
			} else {
				$this->tags->add( Names::post( $id ) );
				foreach ( get_object_taxonomies( $object->post_type ) as $taxonomy ) {
					$terms = get_the_terms( $id, $taxonomy );
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						$this->tags->add( Names::term( (int) $term->term_id ), TagSet::REFERENCE );
					}
				}
			}
		} elseif ( ( is_tax() || is_category() || is_tag() ) && $object instanceof \WP_Term ) {
			$term_id = (int) $object->term_id;
			$this->tags->add( Names::term( $term_id ) );
			$list = Names::product_term_list( (string) $object->taxonomy, $term_id );
			if ( null !== $list ) {
				$this->tags->add( $list );
			} elseif ( 'category' === $object->taxonomy || 'post_tag' === $object->taxonomy ) {
				$this->tags->add( Names::archive( 'post' ) );
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$this->tags->add( Names::archive( is_array( $type ) ? (string) reset( $type ) : (string) $type ) );
		} elseif ( is_home() || is_date() || is_author() ) {
			$this->tags->add( Names::archive( 'post' ) );
		}
	}
}
