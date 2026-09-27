<?php
/**
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

namespace Qoliber\TridentWoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentWoo\Tags\Names;
use Qoliber\Trident\Tags\TagSet;

final class TagsTest extends TestCase {

	public function test_overflow_tag_is_the_one_product_purges_send(): void {
		$set = new TagSet( '', Names::OVERFLOW, 3 );
		$set->addAll( array( 'all', 'wc_p_1', 'wc_p_2', 'wc_p_3' ) );
		self::assertSame( array( 'all', 'wc_p_1', Names::OVERFLOW ), $set->toArray() );
		self::assertContains( Names::OVERFLOW, Names::product_purge( 1, array(), array() ) );
	}

	public function test_product_purge_hits_page_lists_and_overflow_but_not_mentions(): void {
		$tags = Names::product_purge(
			42,
			array(),
			array(
				'product_cat' => array( 7, 8 ),
				'product_tag' => array( 9 ),
				'pa_color'    => array( 11 ),
				'category'    => array( 99 ), // not a product taxonomy
			)
		);
		self::assertSame( array( 'wc_p_42', 'wc_cat_7', 'wc_cat_8', 'wc_tag_9', 'wc_attr_11', 'wc_shop', 'wc_list_overflow' ), $tags );
		self::assertNotContains( 'term_7', $tags, 'sibling product pages only MENTION the category' );
	}

	public function test_variation_purges_its_parent(): void {
		self::assertContains( 'wc_p_10', Names::product_purge( 11, array( 10 ), array() ) );
	}

	/**
	 * A listing that overflowed its tag budget dropped LISTED tags — posts
	 * (the_post) as well as products — and carries only the overflow tag for
	 * them, so post and term purges must send it too.
	 */
	public function test_post_and_term_purges(): void {
		self::assertSame( array( 'post_5', 'archive_post', 'home', 'widgets', 'term_3', 'wc_list_overflow' ), Names::post_purge( 5, 'post', array( 3 ) ) );
		self::assertSame( array( 'post_6', 'archive_page', 'wc_list_overflow' ), Names::post_purge( 6, 'page', array() ) );
		self::assertSame( array( 'term_7', 'wc_cat_7', 'wc_shop', 'wc_list_overflow' ), Names::term_purge( 7, 'product_cat' ) );
		self::assertSame( array( 'term_3', 'wc_list_overflow' ), Names::term_purge( 3, 'category' ) );
	}

	public function test_only_site_wide_options_purge_everything(): void {
		self::assertTrue( Names::option_purges_all( 'blogname' ) );
		self::assertTrue( Names::option_purges_all( 'woocommerce_currency' ) );
		self::assertTrue( Names::option_purges_all( 'theme_mods_storefront' ) );
		self::assertFalse( Names::option_purges_all( '_transient_wc_count_comments' ) );
		self::assertFalse( Names::option_purges_all( 'woocommerce_admin_notices' ) );
		self::assertFalse( Names::option_purges_all( 'cron' ) );
		self::assertTrue( Names::option_purges_widgets( 'widget_block' ) );
		self::assertTrue( Names::option_purges_widgets( 'sidebars_widgets' ) );
		self::assertFalse( Names::option_purges_widgets( 'blogname' ) );
	}
}
