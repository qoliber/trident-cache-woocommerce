=== Trident Cache for WooCommerce ===
Contributors: qoliber
Tags: cache, woocommerce, full page cache, purge, esi
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 11.1
Stable tag: 1.0.0-beta.1
License: MIT

Full-page caching for WooCommerce behind the Trident HTTP cache: cache tags, safe cacheability, durable purges, private content from a local cache, optional ESI for shared blocks.

== Description ==

* Cache tags on every cacheable page (products, the products listed on it, categories, tags, attributes, posts, menus), bounded to Trident's per-entry limit.
* `public, s-maxage` for anonymous catalogue pages; `private, no-store` for cart, checkout, my-account, logged-in users, AJAX/REST and any response that sets a cookie.
* Shoppers with a cart still get cached pages; their mini-cart comes from WooCommerce's cart fragments in the browser.
* Purges are recorded in a table first and removed only when Trident acknowledges them; WP-Cron and WP-CLI retry with backoff. Several Trident instances supported.
* Optional ESI for nav menus.

Tested with WordPress 7.1.2, WooCommerce 11.1.2 and Storefront 4.6.2 on PHP 8.3, against a live Trident stack (tests/woocommerce-e2e in the Trident repository).

See README.md for the design, the Trident configuration and the WP-CLI commands.

== Changelog ==

= Unreleased =
* The Denoisers screen's WAF-export view (this shop's dead zones and noise parameters) now comes from the shared library (qoliber/trident-php Admin\WafView, 1.6.0); the plugin's own copy is gone.
* On qoliber/trident-php 1.5.0: tag and product purges report how many cache entries Trident removed; "Clear the entire cache" reports entries removed and bytes freed (the clear schema, not a purge count); the Denoisers screen can forget one learned path zone or query scope (with a confirmation) and shows the trident-waf-v1 export as tables, filtered to this shop's host; pin, unpin and forget say what the engine confirmed; a purge or clear Trident did not acknowledge is reported as a failure, not a success.
* A top-level "Trident Cache" admin menu with the Magento module's screens: dashboard (per-instance hit rate, memory, entries, health, and the purge queue), purge (pages, products, tags, pattern with preview, host, site, whole cache, plus "Purge this page" in the admin bar), cached pages with entry detail, tags, coverage, warmer, launch mode, reflect mode, denoisers, bans, backends, DNS discovery and live events, and a WordPress dashboard widget. Built on the shared qoliber/trident-php admin client. Each screen shows every instance separately; an unreachable one is reported, never fatal. Behind manage_options (filter trident_admin_capability); every action is POST with a nonce, and destructive ones need a confirmation. The settings moved to Trident Cache → Settings; the old address redirects.
* A shopper's first view of a page nobody has cached yet is now rendered as the anonymous page and stored, instead of rendered for their session, sent no-store and thrown away. Before, every visitor with a cart paid a full render on such pages until an anonymous visitor came by. The cart and session are untouched; the mini-cart comes from the cart fragments, as on every cached page.

= 1.0.0-beta.1 =
* First release.
