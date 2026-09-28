# Trident Cache for WooCommerce

Full-page caching for WooCommerce behind the [Trident](https://trident-cache.com)
HTTP cache. The plugin makes WooCommerce pages safe to share, tells Trident what
each page contains, and invalidates exactly those pages when the shop changes —
durably, so a purge Trident did not take is retried instead of lost.

| | |
|---|---|
| Requires | WordPress 7.0+, WooCommerce 9.0+, PHP 8.1+ |
| Tested | WordPress 7.1.2, WooCommerce 11.1.2, Storefront 4.6.2 and Twenty Twenty-Five 1.5, PHP 8.3 live (unit tests on 8.1–8.5), the Trident 1.8.0 candidate (the purge acknowledgement also accepts the 1.6/1.7 response schema) |
| Evidence | `tests/woocommerce-e2e` — a live stack (nginx → Trident → nginx → PHP-FPM → MariaDB) and a fail-closed case suite |
| Dependencies | `qoliber/trident-php` ^1.8: bundled prefixed in the release zip, or resolved by the site's Composer (see [Library boundary](#library-boundary-and-the-release-zip)) |

## What it does

1. **Cache tags.** Every cacheable response carries `X-Cache-Tags`: the product,
   the archive it is, the terms it mentions, every product rendered in a loop on
   it (related, upsells, grids), menus/widgets when rendered inline, and `all`.
   Bounded to Trident's 200-tag default; see [Tags](#tags).
2. **Cacheability.** `Cache-Control: public, max-age=0, s-maxage=<ttl>,
   stale-while-revalidate=<swr>` for anonymous catalogue pages;
   `private, no-store` for everything else — decided **after** the page has
   rendered, from what the response actually is. See [Rules](#what-is-never-stored).
3. **Durable purges** on product, price, stock, term, post, comment, menu,
   widget and site-setting changes. Recorded in a table first, delivered at the
   end of the request, removed only when Trident acknowledges; retried with
   backoff by WP-Cron and `wp trident purge drain`. Several Trident instances,
   each delivered and retried on its own. See [Purges](#purges).
4. **Private content from a local cache.** Cached pages are identical for every
   visitor; the mini-cart comes from WooCommerce's own cart fragments
   (sessionStorage, invalidated by the cart-hash cookie), other personal bits
   from `[trident_section]` placeholders with the same pattern. See
   [Private content](#private-content-local-cache-not-esi).
5. **ESI**: shared blocks (menus, widget areas, the navigation block) as
   fragments with their own TTL and tags; per-request tokens punched out as
   private fragments or removed; a guard that keeps any other guest nonce out
   of the shared cache. See [ESI](#esi-shared-blocks-and-per-request-tokens).
6. **Admin**: a top-level **Trident Cache** menu — live Trident state and
   operator tools over the shared client (see [Admin screens](#admin-screens)),
   and **Settings**: connection (token stored encrypted, never shown),
   soft/hard purge, TTLs, "Purge all", "Deliver pending now", "Test
   connection", the outbox per instance, and the Trident config for this site.
   Uninstall removes the table, options and cron job.
7. **WP-CLI**: `wp trident purge status|drain|all|tags|product`,
   `wp trident settings get|set`, `wp trident check`, `wp trident config`.

## Install

1. Install the release zip (Plugins → Add New → Upload, or `wp plugin install
   trident-cache-woocommerce-<version>.zip`) and activate it. On a site managed
   with Composer (Bedrock and the like, `composer/installers` in the site's
   `composer.json`), require it instead:

   ```bash
   composer require qoliber/trident-cache-woocommerce:^1.8@beta
   wp plugin activate trident-cache-woocommerce
   ```

   `@beta` while the 1.8 line is published as `1.8.0-beta.1` (see
   [Versioning](#versioning)).
2. Configure Trident with [`../trident.toml`](../trident.toml) — or merge the
   output of `wp trident config`, which has this site's hosts and page paths.
3. Point the plugin at Trident's admin API, either on Trident Cache → Settings
   or in `wp-config.php`:

```php
define( 'TRIDENT_API_URL', 'http://127.0.0.1:9301' );
define( 'TRIDENT_API_TOKEN', getenv( 'TRIDENT_ADMIN_TOKEN' ) );
// Several instances (X03) — takes precedence over the URL above:
define( 'TRIDENT_INSTANCES', array(
	'edge-1' => array( 'api_url' => 'http://10.0.0.11:9301' ),
	'edge-2' => array( 'api_url' => 'http://10.0.0.12:9301', 'api_token' => '...' ),
) );
define( 'TRIDENT_PURGE_MODE', 'soft' ); // or 'hard'
```

4. Run WP-Cron from the system, not from traffic — cached pages never reach PHP,
   so traffic-triggered cron stops running exactly when the cache works:

```
define( 'DISABLE_WP_CRON', true );
* * * * *  cd /var/www/shop && wp cron event run --due-now >/dev/null
```

5. `wp trident check` — every instance reachable, token accepted, licensed.

### The Trident settings that matter

| setting | why |
|---|---|
| `[server] preserve_host = true` | otherwise WordPress sees `Host: <backend>` and answers 301 to `home`; the cache stores the redirect |
| `[cache.key] cache_with_cookies = true` | cookies do not switch caching off; the plugin decides per response (see below) |
| `[cache.tags] headers = ["X-Cache-Tags"]` | without it tag purges match nothing |
| `bypass_query_params` with `add-to-cart`, `wc-ajax`, … | WooCommerce's non-AJAX add-to-cart links are GET requests |
| `pass_patterns` for wp-admin, wp-json, cart, checkout, my-account | safety net if the plugin is ever off; anchored so `/cart` does not catch `/cartoon-mug/` |
| `[[rules.request]]` on the `Cookie` header | logged-in users pass. A **pattern**, because `wordpress_logged_in_<md5(siteurl)>` has a per-site suffix and `bypass_cookies` matches names exactly |
| `[esi] enabled = true`, `mode = "hole_punch"`, `propagate_headers = ["Host"]` (no `Cookie`) | fragment fetches do not honour `preserve_host`; without `Cookie` a shared fragment is one entry, not one per visitor |
| `[[rules.response]] html_needs_explicit_public` | HTML is stored only when the plugin said `public`; without it a deactivated plugin would let `[ttl] default` store pages rendered for a cart |

**Multi-currency or multilingual plugins** (WPML, Polylang, Aelia, CURCY…):
a language prefix in the path is already a separate cache key; a currency or
language chosen by cookie must be added to `[cache.key] vary_cookies`, or one
visitor's currency is served to the next.

## What is never stored

`Cache\Policy` is pure and unit-tested; in order:

| request / response | why |
|---|---|
| not GET/HEAD | state changes |
| admin, AJAX, REST, cron, CLI, feeds | not a page (REST responses without a `Cache-Control` get `private, no-store`: the Store API cart is personal) |
| logged in, or a `wordpress_logged_in_*`/`wp-postpass_*`/`comment_author_*` cookie | personal |
| cart, checkout, my-account (filter `trident_is_private_page`) | personal |
| preview, customizer | unpublished |
| `add-to-cart`, `wc-ajax`, `_wpnonce`, `remove_item`, `rest_route`, … in the query | state change / one-off |
| `DONOTCACHEPAGE`, password-protected post | convention / personal |
| status ≠ 200 | a 404 or redirect is not what the next visitor asked for |
| the response sets a cookie | a stored `Set-Cookie` hands one visitor's session to the next |
| the application already sent `private`/`no-store`/`no-cache` | respect it |
| **the request carries a WooCommerce session or history cookie** (`wp_woocommerce_session_*`, `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `woocommerce_recently_viewed`; filter `trident_session_cookies` — see below) | the render may contain that cart. Cart and session cookies alone: the render is made **anonymous** and stored (below). With any other personalising cookie: **served** from cache when stored, never **stored** from here |

The last row is the design decision. The usual WooCommerce page-cache rule is
"bypass once `woocommerce_items_in_cart` is set" — which switches the cache off
for the visitors who are about to buy, on every page they view until the order.
Here they keep getting cached catalogue pages.

### A shopper's cold page is rendered anonymously and stored

A page nobody has cached yet, opened by a visitor with a cart, used to be
rendered for their session, sent `no-store` and thrown away. Every shopper paid
a full render (~55 ms) for it until an anonymous visitor happened by. Now, on a
cacheable page request (GET/HEAD, not logged in, no bypass parameter, not under
the cart, checkout or account page), the plugin hides WooCommerce's own session
and cart cookies (`wp_woocommerce_session_*`, `woocommerce_items_in_cart`,
`woocommerce_cart_hash`) from the render **before WooCommerce loads the
session**. The page is then exactly the anonymous page, so it is stored and
every later visitor gets it from the cache.

Nothing is lost for the shopper: the browser keeps its cookies, the session and
cart are untouched, and the mini-cart comes from the cart fragments, as on every
cached page they are served. With debug headers on, the origin response says
`X-Trident-Session: detached`.

It does not apply when any **other** personalising cookie is present
(`woocommerce_recently_viewed`, or anything added with `trident_session_cookies`):
those renders stay `no-store`. If a cart or checkout lives at a path the early
check cannot know (a cart block on another page), the page redirects once to
the same URL with `?trident-nocache=1`, where the session is kept. Mark such
pages with `trident_is_private_page`, or opt out per request:

```php
add_filter( 'trident_detach_session', function ( bool $detach, string $path ): bool {
	return ! str_starts_with( $path, '/quick-order/' ) && $detach;
}, 10, 2 );
```

Never detached: a request whose path contains a WooCommerce endpoint
(`order-pay`, `order-received`, …, under any page), a `?session=` cart-token
link, REST (`rest_get_url_prefix()`), or a request where WooCommerce had already
started before the plugin's `init` hook (something loaded the session early). A
detached render that nevertheless ends up with a non-empty cart is marked
`DONOTCACHEPAGE` and is not stored.

Known limitations: a notice queued before a redirect to a cold catalogue page
(for example after a login) is shown on the next non-detached page, usually the
cart, instead of that page. A cart or checkout **block** placed on a page other
than WooCommerce's designated cart/checkout page is rendered with an empty cart
and fills itself from the Store API; mark such a page with
`trident_is_private_page` or `trident_detach_session`.

## Personalisation cookies: tell the plugin about yours

> **If a theme or plugin renders anything per visitor from a cookie, add that
> cookie to `trident_session_cookies`.** A page rendered for a request that
> carries one is then sent `private, no-store` (the visitor still receives
> cached pages; only their own renders are not stored). Missing it means one
> visitor's render can be stored and served to others.

```php
add_filter( 'trident_session_cookies', function ( array $prefixes ): array {
	$prefixes[] = 'yith_wcwl_session_';   // a wishlist
	$prefixes[] = 'wmc_current_currency'; // a currency switcher
	return $prefixes;
} );
```

Built in: the WooCommerce session and cart cookies and
`woocommerce_recently_viewed`. WooCommerce sets that last one on **every**
product render while the Recently Viewed widget is active — which alone made
product pages uncacheable (a response with `Set-Cookie` is never stored). The
plugin moves the tracking to the browser (same cookie, same format) and keeps
a widget area that holds a per-visitor widget (`trident_personal_widgets`:
Recently Viewed, the cart widget) inline instead of a shared ESI fragment.
Cookies that select a *variant* everyone with the same value may share (a
currency, a language) belong in Trident's `[cache.key] vary_cookies` instead.

## Private content: local cache, not ESI

A cached page is one set of bytes for everybody. Everything personal is added in
the browser:

- **Mini-cart** — WooCommerce's `wc-cart-fragments` (the plugin enqueues it on
  every cacheable page; WooCommerce 7.8+ only does where a mini-cart widget is
  used). It keeps the fragments in `sessionStorage` under the cart hash, and
  compares that hash with the `woocommerce_cart_hash` cookie on each page: same
  → render from storage, no request; different → one
  `POST ?wc-ajax=get_refreshed_fragments`.
- **Other personal bits** — `[trident_section name="customer_name" before="Hello, "]`
  (or the `data-trident-section` attribute in a template) renders an empty,
  hidden placeholder. `assets/js/trident-sections.js` fills it from
  `localStorage`, keyed by a version made of the `trident_pv` cookie and the
  cart hash. For logged-in customers the version is stored per user (user
  meta) and rotated whenever that user's data changes — including when an
  admin edits them — and synced into the cookie on the customer's next request;
  for guests it is the cookie, rotated on logout and checkout details.
  `do_action( 'trident_personal_data_changed', $user_id )` rotates it for your
  own data. Stale or
  missing → one `GET ?wc-ajax=trident_sections`. **No cookie → no request.**
  Values are inserted with `textContent`. Add sections with the
  `trident_personal_sections` filter (a wishlist count, loyalty points).

Why this beats ESI private fragments (`<esi:include>` per visitor):

| | local cache + fragments | ESI private fragments |
|---|---|---|
| origin cost per page view | none when the version is unchanged; one small AJAX call when it changed | one PHP render per fragment **per page view**, on every HIT |
| cache entries | one per page | one per page + one per fragment **per visitor** (the Magento stack measured 4 sessions → 4 copies of a 1.4 MiB menu when `Cookie` keyed the fragment) |
| HIT latency | the cached page, ~1–2 ms | waits for the slowest private fragment |
| failure mode | the page is complete without the personal bits; they fill in or stay empty | a slow/failed fragment blocks or empties part of the page; Trident has no `<esi:try>` to fall back |
| leak risk | none on the shared layer: nothing personal is ever in cached bytes | a fragment cached with the wrong key serves one visitor's data to another |
| works without Trident / on a CDN | yes | only where an ESI processor runs |

ESI stays the right tool for **shared** blocks, and for tokens that must exist before scripts run — see below.

## ESI: shared blocks and per-request tokens

ESI is on by default and must match Trident's `[esi]` (`enabled = true`,
`mode = "hole_punch"` recommended — the sample config). ESI markup is emitted
only for requests whose immediate peer is a Trident address (loopback, the
hosts of the configured admin URLs, `esi_addresses`), and never for logged-in
visitors; everything else is rendered inline. Every block uses the ESI 1.0
fallback pattern from the library (`Qoliber\Trident\Esi\Markup`):

```html
<!--esi <esi:include src="/?trident-esi=menu&a=…&s=…"/> --><esi:remove>…inline…</esi:remove>
```

— the inline render stays in the page (so its scripts and client-side state
are enqueued where the browser needs them); an ESI processor replaces it with
the fragment, anything else shows it. Fragment URLs are signed
(`Qoliber\Trident\Esi\FragmentUrl`, HMAC with the site's nonce salt).

**Shared blocks** — one cache entry each, own TTL ("Fragment TTL") and tags:

| block | fragment tags | a change purges |
|---|---|---|
| classic menus (`wp_nav_menu`, by theme location, `esi_menus`) | `menu`, `esi_menu`, `all` | the menu fragment only |
| widget areas (by sidebar id, `esi_widgets`) | `widgets`, `esi_widgets`, `all`, + posts they list | the widget fragment only |
| navigation block (block themes, `esi_navigation`) | `menu`, `esi_navigation`, `all` | the navigation fragment only |

A fragment request that carries a login or a session cookie gets its own
render sent `private, no-store`; only a cookie-less (guest) render — rendered
as user 0 — is ever stored as the shared fragment. Menu-cart plugins that
print the cart count into the menu therefore show the guest count on cached
pages; they normally refresh it through WooCommerce's cart fragments.

Pages do not carry `menu`/`widgets` then, so a menu edit purges one entry and
every cached page shows the new menu on its next assembly. In Trident
`assemble` mode the fragment's tags do not reach the stored assembled page
(measured on the 1.8.0 candidate), so there pages carry the tags themselves
(`esi_mode = assemble`).

**Per-request tokens.** Measured on WordPress 7.1.2 + WooCommerce 11.1.2 by
diffing the pages two guest sessions get (`tests/woocommerce-e2e` 58): ordinary
catalogue pages carry **no** token, in Storefront and in Twenty Twenty-Five
(the block mini-cart loads the cart and its nonce from the Store API itself).
Pages with a React-rendered WooCommerce block (All Products, the legacy
filters) carry three:

| token | where | handling |
|---|---|---|
| Store API nonce + timestamp | `<script id="wc-blocks-middleware-js-before">` | **private ESI fragment** — the script rendered fresh per request, never stored |
| `wp_rest` nonce (apiFetch nonce middleware) | `<script id="wp-api-fetch-js-after">` | **private ESI fragment** |
| preloaded Store API cart response: `Nonce`, `Nonce-Timestamp`, and a guest **`Cart-Token`** (a JWT naming a guest session) | inside `<script id="wc-settings-js-before">` | **removed** — wcSettings is built from the page's blocks and cannot be a fragment; without the preload the block fetches `/wc/store/v1/cart` itself (uncached) and gets this visitor's cart, nonce and token |

WooCommerce binds guest nonces to the session only for actions starting with
`woocommerce`; `wc_store_api` and `wp_rest` guest nonces are the same for every
guest until the next nonce tick, so the risk of a cached copy is expiry, and a
fragment rendered without cookies is right for every guest. A private fragment
costs one PHP request that stops at `init` (~25 ms here, a full page ~60 ms);
in `assemble` mode a private fragment would make the whole page uncacheable, so
there such pages are simply not stored.

**Nothing uncatalogued gets through** (`Cache\NonceGuard`): the plugin records
every nonce WordPress creates for a logged-out visitor during the render
(`nonce_user_logged_out` fires for each), and after the render looks for their
values — and for `Cart-Token` — in the HTML outside the punched-out fallbacks.
Any hit makes the response `no-store`, with the action named in
`X-Trident-Decision` (`no-store; session token <action>`).

Limits: Trident implements `include`/`remove`/comments, not `<esi:try>`, so a
shared fragment that fails with no stale copy renders empty
(`X-Trident-ESI: failed=1`); a shared fragment cannot know the current page, so
`trident-esi-menu.js` restores `current-menu-item`; numbered `aria-label`s WordPress
gives a second and third navigation block on a page (" 2", " 3") are not
reproduced by the fragments.

## Tags

| tag | carried by | purged by |
|---|---|---|
| `all` | every cacheable response | "Purge all", site-wide options, theme switch, customizer |
| `wc_p_<id>` | the product page; any page that renders it in a loop | the product or a variation changing (save, price, stock, trash) |
| `wc_cat_<id>`, `wc_tag_<id>`, `wc_attr_<id>` | the archive (product grid) of that term | a product in it changing (old **and** new terms); the term |
| `wc_shop` | shop page, product archives | any product changing |
| `wc_list_overflow` | a page whose list did not fit the 200-tag budget | any product, post or term changing |
| `term_<id>` | pages that **mention** the term (breadcrumb, product meta) | the term being edited |
| `post_<id>`, `home`, `archive_<type>` | posts/pages, the posts page, archives | that post, its approved comments |
| `menu`, `widgets` | pages rendering them inline; the menu fragment | menu / widget changes |

"Is" vs "mentions" is what keeps purges narrow: saving a product purges its page
and the grids that list it, not the pages of its sibling products that only show
the category name. A prefix (Settings → Tag prefix) separates several shops on
one Trident.

## Purges

The contract is the Magento module's X02/X03, ported:

1. **Record.** The change's hook writes the tags to
   `{prefix}trident_wc_purge_outbox` (one row per instance, ≤ 1000 tags) through
   `$wpdb`. **WordPress and WooCommerce do not wrap a product save in a
   transaction**: the post status transition (which records the purge) runs
   before WooCommerce writes the new price. So a new row is recorded with a
   **grace period** (`next_attempt_at = now + 120 s`): WP-Cron, another
   request's end-of-request drain and `wp trident purge drain` all leave it
   alone while the saving request finishes.
2. **Deliver at the end of the request** — on `shutdown`, the request delivers
   **its own rows by id**, ignoring the grace, after every write of the save has
   happened (so a soft purge's background refresh never re-renders the old
   data) and after the response is closed (`fastcgi_finish_request()` or
   `litespeed_finish_request()`; the admin who saved does not wait). Where the
   response cannot be closed early, the request does not deliver at all — the
   rows become due after the grace and cron delivers them, instead of a
   checkout waiting on the admin API. A process that dies before shutdown
   leaves its rows for cron the same way.
3. **Remove only on acknowledgement**: HTTP 200 and a body with integer
   `purged`, string `mode`, and a `state` — if present — other than `refused`;
   or HTTP 202 with `state: "recorded"` (reflect mode: durably queued, applied
   when reflect ends).
   A 401, 429, 5xx, timeout, HTML error page, redirect or 200-with-error keeps
   the row: `attempts + 1`, `next_attempt_at = now + min(2^(attempts-1), 300)s`,
   `last_error`.
4. **Retry**: WP-Cron every minute (`trident_wc_purge_drain`), the next request that
   purges, `wp trident purge drain` (ignores the retry backoff after a failure:
   "deliver now, I fixed the token" — never a writer's grace period), or the "Deliver pending now" button. Requests are merged up to
   1000 unique tags; a drain gives up on an instance after one unreachable
   attempt or three rejections, and never waits on one instance for another.
5. **Watch**: `wp trident purge status` exits 1 when a purge is older than
   15 minutes, when rows are owed to an instance no longer configured
   (`--forget=<name>` drops them; refused for a configured one), when
   `TRIDENT_INSTANCES` has errors, or when the stored token cannot be decrypted.
   The admin shows the same as a notice.

Purges are idempotent, so the failure modes cost an extra purge, never a lost
one. If the table is missing (plugin files updated without activation, before
the schema check re-creates it) purges fall back to best-effort direct delivery.

## Library boundary and the release zip

The platform-neutral half lives in the shared library
[`qoliber/trident-php`](../../../php-library) (1.3.0+), so Magento, Shopware,
Sylius and PrestaShop reuse the same, separately tested code:

| from the library | used here as |
|---|---|
| `Delivery\Purger`, `Drainer`, `Packer`, `Backoff`, `Acknowledgement`, `Instances`, `PurgeClient` | the X02/X03 delivery contract |
| `Delivery\OutboxStore`, `Delivery\Transport` (interfaces) | implemented by `Purge\WpdbOutboxStore` (`$wpdb`) and `Purge\WpHttpTransport` (WordPress HTTP API) |
| `Tags\TagSet` | the bounded tag set `Tags\TagCollector` fills |
| `Cache\Policy` | configured with WooCommerce's lists by `Cache\WooPolicy` |
| `assets/js/trident-sections.js` | the personal-sections loader, served from the plugin's copy in `assets/lib/js/`; `assets/js/trident-woo-sections.js` is the WooCommerce binding |

The plugin keeps what is WordPress/WooCommerce: which hooks purge which tags
(`Purge\PurgeHooks`, `Tags\Names`), which objects on a page produce which tags
(`Tags\TagCollector`), the response controller, settings, admin page, CLI,
ESI, personal sections, the outbox table.

**The release zip bundles the library prefixed.** WordPress loads every
plugin's autoloader into one process; two plugins bundling different versions
of a library (or of `psr/http-message`, which half the ecosystem ships) would
load each other's classes. `bin/build-zip.sh` copies `qoliber/trident-php` and
its PSR dependencies with [Strauss](https://github.com/BrianHenryIE/strauss)
(a dev dependency) into `vendor-prefixed/` under `Qoliber\TridentWoo\Vendor\`,
rewrites the plugin's references, checks that nothing unprefixed is left and
that the prefixed classes load, and writes `dist/trident-cache-woocommerce-<version>.zip`.
The e2e stack installs that zip (`wp plugin install`), not the source tree, so
the tested artifact is the shipped one.

**Required with the site's Composer, the library is not prefixed.** It goes to
the site's `vendor/` like every other package, and the site's autoloader (loaded
before WordPress) provides it: one Composer resolves one version of it and of the
PSR interfaces for the whole site, which is what the prefixing protects the zip
from. `tests/composer-install/run.sh` installs the plugin that way — a Bedrock
layout, `composer/installers`, no `vendor/` in the plugin — and checks that it
boots and that every class it ships loads (CI, `scripts/ci/php-integrations.sh`).

**The browser script is the plugin's own copy.** A site's `vendor/` is usually
outside the web root, so the plugin serves `assets/lib/js/trident-sections.js`,
which `tests/Unit/LibraryAssetsTest.php` keeps byte-equal to the library's (and
`bin/build-zip.sh` to the library the zip bundles). Changing the library's script
means copying it here in the same change.

In a development checkout the plugin loads Composer's `vendor/autoload.php`
(the library via a path repository, `../../../php-library`).

## Development

```bash
composer install            # the library via the path repository, PHPUnit, WPCS, Strauss
composer test               # plugin unit tests (WooCommerce lists, tag vocabulary, secrets, ESI args)
vendor/bin/phpcs            # WordPress-Extra + WordPress-Docs (PSR-4 file names allowed)
bin/build-zip.sh            # dist/trident-cache-woocommerce-<version>.zip, library prefixed
# PHP floor:
docker run --rm -u "$(id -u)" -v "$PWD":/app -w /app php:8.1-cli vendor/bin/phpunit
```

The delivery, tag-set and policy logic is tested in the library
(`integrations/php-library`, `composer test`). The WordPress glue is tested live
by `tests/woocommerce-e2e`, against the built zip.

## Admin screens

Trident Cache in the admin menu — the Magento module's screen set, rendered
over `qoliber/trident-php`'s `Admin\` client (no API code in the plugin). Every
screen shows each configured instance separately; an instance that is down
is named with its reason and the others still render.

| screen | what |
|---|---|
| Dashboard | per instance: status, version, licence, hit rate, hits/misses/passes, entries, cache memory, process RSS, latency p50/p95/p99, backend health; this site's purge queue. Also a WordPress dashboard widget (read at most once a minute) |
| Purge | pages (URLs or paths of this site — anything else is refused), products and tags (through the durable outbox, like a save; the notice says how many cache entries Trident removed), a URL pattern with a preview first, a host, this site, or the whole cache (entries removed and bytes freed). On the storefront the admin bar has **Purge this page** |
| Cached pages | one instance's entries: sort, tag filter, paging, purge one entry (by storage key: exactly that variant); an entry's detail — tags, variants, TTL, grace, and the engine's own verdict (`explain`) |
| Tags | tags per instance with entry counts; link to their entries; purge by the exact tag name |
| Coverage | how many of this shop's own pages (home, shop, newest products, categories, pages — or a pasted list) each instance holds, without requesting them |
| Warmer | state, schedule, last run; run the configured sources, cancel, or warm this shop's pages |
| Launch mode, Reflect mode | status; start / complete / abort, enable / disable |
| Denoisers | what the query and path denoisers learned; pin, unpin, forget one path zone or query scope, reset; the portable `trident-waf-v1` export as tables — only this shop's dead zones (and `*` ones) and the noise parameters learned for this shop; on a shared Trident other sites' entries are not shown. Pins are scoped to this shop's host |
| Bans | the ban log; record, delete |
| Backends, DNS discovery | health and traffic per origin; drain, restore; re-resolve |
| Live events | busiest URLs and recent errors; a live feed polled while the page is open |

Security:
- Every operator screen needs `manage_options`. The `trident_admin_capability`
  filter changes that. **Settings** keeps its own split: `manage_woocommerce`
  for the caching settings, `manage_options` for the connection.
- Every action is a POST to one handler that checks, in order: the operation
  exists; the capability; a nonce bound to that screen and operation; and, for
  destructive actions, a ticked confirmation checked on the server. The
  destructive actions are purge a pattern, host, the site or the whole cache,
  launch start/complete/abort, reflect enable/disable, denoiser reset, forget a
  denoiser zone or scope, record
  or delete a ban, and drain a backend.
- Notices travel in a per-user transient, never in the redirect URL.
- Trident's events carry full request headers, cookies and client IPs. The
  live feed sends the browser only an allow-list of fields (method, path,
  status, cache status, timing, …).

The old address, `options-general.php?page=trident-cache`, redirects to
**Trident Cache → Settings**.

## Hooks

| hook | type | use |
|---|---|---|
| `trident_is_private_page` | filter (bool) | mark more pages private |
| `trident_session_cookies` | filter (string[]) | cookie prefixes that mean "personal render" — **add every personalisation cookie your site reads** |
| `trident_personal_widgets` | filter (string[]) | widget id bases that keep their widget area out of shared ESI fragments |
| `trident_personal_sections` | filter (array) | add personal sections |
| `trident_personal_data_changed` | action | rotate the personal-data version |
| `trident_finish_request_before_purge` | filter (bool) | disable `fastcgi_finish_request()` before delivery |
| `trident_admin_capability` | filter (string) | who may use the operator screens (default `manage_options`) |
| `trident_detach_session` | filter (bool, path) | keep a request's session attached (see above) |

Do not run this plugin together with the generic WordPress plugin in
`integrations/frameworks/wordpress` or `integrations/cms/wordpress/plugin*` —
both would send cache headers.

## Versioning

Versions follow Trident: this plugin 1.8.x works with Trident 1.8. MAJOR.MINOR moves
with the engine (every Trident X.Y.0 release is also a release of this package,
changed or not); the PATCH number is this package's own. The 1.8 line is published as `1.8.0-beta.1` until the plugin has run on a production shop. The
admin screens warn when a connected Trident runs another release line.

## This repository is a mirror

`qoliber/trident-cache-woocommerce` is developed in the Trident repository together with the
shared library [`qoliber/trident-php`](https://github.com/qoliber/trident-php)
and the live end-to-end test stacks, and published to
[github.com/qoliber/trident-cache-woocommerce](https://github.com/qoliber/trident-cache-woocommerce) automatically:
every commit there is a "Sync from trident-cache@…" snapshot. **Please open
issues there**; pull requests against the mirror cannot be merged, because the
next sync would overwrite them. Releases are the tags of that repository.
