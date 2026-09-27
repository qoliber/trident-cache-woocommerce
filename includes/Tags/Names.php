<?php
/**
 * The tag vocabulary, and which tags a change invalidates.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tags;

/**
 * Tag names and purge plans. Pure.
 *
 * The scheme separates what a page IS from what it MENTIONS, because the two
 * are invalidated by different changes:
 *
 * | tag                | carried by                                   | purged by                              |
 * |--------------------|----------------------------------------------|----------------------------------------|
 * | `all`              | every cacheable response                     | "Purge all", site-wide settings        |
 * | `wc_p_<id>`        | the product page; any page listing it        | that product (or a variation) changing |
 * | `wc_cat_<id>`      | the category ARCHIVE (its product grid)      | a product in it changing; the term     |
 * | `wc_tag_<id>`      | the product-tag archive                      | a product in it changing; the term     |
 * | `wc_attr_<id>`     | the attribute-term archive                   | a product in it changing; the term     |
 * | `wc_shop`          | the shop page and product archives           | any product changing                   |
 * | `wc_list_overflow` | a page whose list did not fit the tag budget | any product changing                   |
 * | `term_<id>`        | pages mentioning the term (breadcrumbs)      | the term being edited                  |
 * | `post_<id>`        | a post or page                               | that post; its comments                |
 * | `home`             | the front page / posts page                  | any post changing                      |
 * | `menu`, `widgets`  | pages rendering them inline; ESI fragments   | menu / widget changes                  |
 *
 * So saving product 42 purges its page and the archives that LIST it, but not
 * the pages of its sibling products, which only MENTION the category. A category
 * page does not need every product tag to stay correct: a product change purges
 * `wc_cat_<id>` of each of its categories — including one it was just added to.
 */
final class Names {

	public const ALL      = 'all';
	public const SHOP     = 'wc_shop';
	public const OVERFLOW = 'wc_list_overflow';
	public const HOME     = 'home';
	public const MENU     = 'menu';
	public const WIDGETS  = 'widgets';
	public const SEARCH   = 'search';

	/**
	 * @param int $id Product (or parent product) id.
	 * @return string
	 */
	public static function product( int $id ): string {
		return 'wc_p_' . $id;
	}

	/**
	 * @param int $term_id product_cat term id.
	 * @return string
	 */
	public static function category_list( int $term_id ): string {
		return 'wc_cat_' . $term_id;
	}

	/**
	 * @param int $term_id product_tag term id.
	 * @return string
	 */
	public static function tag_list( int $term_id ): string {
		return 'wc_tag_' . $term_id;
	}

	/**
	 * @param int $term_id pa_* term id.
	 * @return string
	 */
	public static function attribute_list( int $term_id ): string {
		return 'wc_attr_' . $term_id;
	}

	/**
	 * @param int $term_id Any term id.
	 * @return string
	 */
	public static function term( int $term_id ): string {
		return 'term_' . $term_id;
	}

	/**
	 * @param int $post_id Post or page id.
	 * @return string
	 */
	public static function post( int $post_id ): string {
		return 'post_' . $post_id;
	}

	/**
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function archive( string $post_type ): string {
		return 'archive_' . $post_type;
	}

	/**
	 * @param string $block ESI block name.
	 * @return string
	 */
	public static function esi( string $block ): string {
		return 'esi_' . $block;
	}

	/**
	 * Which list-tag a product taxonomy term maps to.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term id.
	 * @return string|null Null for non-product taxonomies.
	 */
	public static function product_term_list( string $taxonomy, int $term_id ): ?string {
		if ( 'product_cat' === $taxonomy ) {
			return self::category_list( $term_id );
		}
		if ( 'product_tag' === $taxonomy ) {
			return self::tag_list( $term_id );
		}
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			return self::attribute_list( $term_id );
		}
		return null;
	}

	/**
	 * Tags to purge when a product changes (save, price, stock, trash, delete).
	 *
	 * @param int                            $id         Product id.
	 * @param array<int, int>                $parents    Parent product ids (a variation's parent).
	 * @param array<string, array<int, int>> $terms Taxonomy => term ids, OLD and new
	 *                                        ones: a product moved out of a category
	 *                                        must disappear from that archive too.
	 * @return array<int, string>
	 */
	public static function product_purge( int $id, array $parents, array $terms ): array {
		$tags = array( self::product( $id ) );
		foreach ( $parents as $parent ) {
			if ( $parent > 0 ) {
				$tags[] = self::product( $parent );
			}
		}
		foreach ( $terms as $taxonomy => $ids ) {
			foreach ( $ids as $term_id ) {
				$list = self::product_term_list( (string) $taxonomy, (int) $term_id );
				if ( null !== $list ) {
					$tags[] = $list;
				}
			}
		}
		$tags[] = self::SHOP;
		$tags[] = self::OVERFLOW;
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Tags to purge when a post or page changes.
	 *
	 * @param int             $id        Post id.
	 * @param string          $post_type Post type.
	 * @param array<int, int> $term_ids  Its terms (old and new).
	 * @return array<int, string>
	 */
	public static function post_purge( int $id, string $post_type, array $term_ids ): array {
		$tags = array( self::post( $id ), self::archive( $post_type ) );
		if ( 'post' === $post_type ) {
			// The posts page lists posts; pages are not listed anywhere by default.
			// Widget areas list them too (Recent Posts) — inline on pages, or as
			// the widget fragments.
			$tags[] = self::HOME;
			$tags[] = self::WIDGETS;
		}
		foreach ( $term_ids as $term_id ) {
			$tags[] = self::term( (int) $term_id );
		}
		// A listing that overflowed its tag budget dropped the posts it lists.
		$tags[] = self::OVERFLOW;
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Tags to purge when a term is edited or deleted.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, string>
	 */
	public static function term_purge( int $term_id, string $taxonomy ): array {
		$tags = array( self::term( $term_id ) );
		$list = self::product_term_list( $taxonomy, $term_id );
		if ( null !== $list ) {
			$tags[] = $list;
			// Category names and counts show on the shop page and in widgets.
			$tags[] = self::SHOP;
		}
		// Term names show in listings that may have overflowed their tag budget.
		$tags[] = self::OVERFLOW;
		return $tags;
	}

	/**
	 * Whether a changed option affects every page (site title, currency, price
	 * display, permalinks …). Only these purge `all`; WooCommerce writes dozens
	 * of bookkeeping options on ordinary requests (transients, sessions,
	 * scheduled-action state) and those must not empty the cache.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function option_purges_all( string $option ): bool {
		static $exact = array(
			'blogname'                                     => true,
			'blogdescription'                              => true,
			'show_on_front'                                => true,
			'page_on_front'                                => true,
			'page_for_posts'                               => true,
			'posts_per_page'                               => true,
			'date_format'                                  => true,
			'time_format'                                  => true,
			'permalink_structure'                          => true,
			'category_base'                                => true,
			'tag_base'                                     => true,
			'site_icon'                                    => true,
			'stylesheet'                                   => true,
			'template'                                     => true,
			'woocommerce_currency'                         => true,
			'woocommerce_currency_pos'                     => true,
			'woocommerce_price_thousand_sep'               => true,
			'woocommerce_price_decimal_sep'                => true,
			'woocommerce_price_num_decimals'               => true,
			'woocommerce_tax_display_shop'                 => true,
			'woocommerce_prices_include_tax'               => true,
			'woocommerce_price_display_suffix'             => true,
			'woocommerce_shop_page_id'                     => true,
			'woocommerce_shop_page_display'                => true,
			'woocommerce_category_archive_display'         => true,
			'woocommerce_default_catalog_orderby'          => true,
			'woocommerce_catalog_columns'                  => true,
			'woocommerce_catalog_rows'                     => true,
			'woocommerce_hide_out_of_stock_items'          => true,
			'woocommerce_demo_store'                       => true,
			'woocommerce_demo_store_notice'                => true,
			'woocommerce_permalinks'                       => true,
			'woocommerce_placeholder_image'                => true,
			'woocommerce_enable_reviews'                   => true,
			'woocommerce_review_rating_verification_label' => true,
			'woocommerce_enable_review_rating'             => true,
			'woocommerce_weight_unit'                      => true,
			'woocommerce_dimension_unit'                   => true,
			'woocommerce_coming_soon'                      => true,
			'woocommerce_store_pages_only'                 => true,
		);
		return isset( $exact[ $option ] ) || str_starts_with( $option, 'theme_mods_' );
	}

	/**
	 * Whether a changed option is a widget setting (purges `widgets`).
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function option_purges_widgets( string $option ): bool {
		return 'sidebars_widgets' === $option || str_starts_with( $option, 'widget_' );
	}
}
