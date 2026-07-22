# The Realm Malta — Child Theme

Child of **Storefront** (`Template: storefront` in [style.css](style.css)). Holds the front-end (templates, SCSS, JS), the Account Creation flow, and two admin reporting tools (Cost Prices + Profit Analytics). See the [root CLAUDE.md](../../../CLAUDE.md) for the wider site picture.

## Bootstrap

[functions.php](functions.php) requires every class file and instantiates each one (no autoloader). Order matters — `TRM_Core` is required first so the others can extend it.

```php
new TRM_Marketing_Handler();
new TRM_WC_Hooks();
new TRM_Header_Hooks();
new TRM_Mega_Nav();
new TRM_Cart();
new TRM_MB_Hooks();
new TRM_ACF_Hooks();
new TRM_AJAX_Hooks();
new TRM_Cost_Price();
new TRM_Profit_Analytics();
new TRM_Coming_Soon();
new TRM_Preorder();
new TRM_New_Releases();
new TRM_Core();
```

Enqueues:
- Site CSS: `assets/css/layout.css` (compiled from SCSS, cache-busted with `time()`).
- Admin CSS: `assets/css/admin/admin.css` (also `time()`-versioned).
- Slick carousel (CDN) → `slick-css`, `slick-theme-css`, `slick-js`.
- `assets/js/wc-product-cat-carousel.js` (depends on `jquery`, `slick-js`).
- `assets/js/ajax.js` — depends on `jquery` (and `selectWoo` if WC is active). Localised as `trmAjax` with `ajaxUrl`, `nonce` (`realm_register_customer`), `membershipNonce` (`realm_apply_membership`), and user-facing message strings.
- `select2` style + `selectWoo` script (when WC is active) — used to make the phone-prefix dropdown searchable.

## Class map (depth)

### `TRM_Core` — [classes/trm-core.php](classes/trm-core.php)

Base class. Two helpers used everywhere:

- `render_template(string $path, array $vars = [], string $require = 'once'): string`
  - Loads `assets/views/{$path}.php`, `extract()`s `$vars`, returns the captured output.
  - `$require = 'always'` uses `require` instead of `require_once`.
  - **All variables passed in `$vars` become locals in the view via `extract()`** — be careful when adding new keys (they shadow existing locals).
- `write_log($data)` — only writes when `WP_DEBUG === true`.

### `TRM_Marketing_Handler` — [classes/trm-marketing.php](classes/trm-marketing.php)

Top-level admin menu **"The Realm Marketing"** (`trm-marketing`, cap `manage_woocommerce`). Currently just a landing page (`admin/marketing/trm-marketing-landing-page`). Placeholder for future marketing tooling.

### `TRM_WC_Hooks` — [classes/wc-hooks.php](classes/wc-hooks.php)

The bulk of the WC customisation lives here. Hooks registered:

**Removed:**
- `woocommerce_template_single_meta` from the single product summary.
- Storefront credit link (`storefront_credit_link → false`).

**Custom product data fields (Inventory tab):**
- `_product_code` — "GW Product Code" — added in `add_custom_product_data_fields()`, saved in `save_custom_product_data_fields()`.
- `_ssc_code` — "GW SSC Code".

**Product CSV import (`woocommerce_product_import_pre_insert_product_object`, prio 99):**
- `category` CSV column → builds nested `product_cat` terms from `Parent > Child > Grandchild;Other > Path` strings (`;` separates root branches, `>` separates ancestry).
- `commodity_code` → maps to one of `standard` / `reduced-rate` / `zero-rate` tax classes via the `COMM_CODE_MAP` constant (hard-coded list of GW commodity codes). Fallthrough leaves WC's default.
- `product_code` → `_product_code`; `ssc_code` → `_ssc_code`.

**Member coupon UX:**
- `woocommerce_coupon_message` (prio 99): rewrites the "Applied"/"Removed" message to "Member Discount Applied!/Removed!" when the coupon code contains `storedisc` or `onlineonly`. This is the marker for member-discount coupons — don't rename those substrings without coordinating with the members-manager plugin.

**Stock display (single product):**
- `woocommerce_get_availability_text` + `woocommerce_get_availability_class` (prio 99):
  - In stock + managing stock → "Product available in store" / class `available-in-store`.
  - On backorder + managing stock → "Product available on order" / class `available-on-order`.

**Product category carousel class fix:**
- `product_cat_class` (prio 99): strips `first` / `last` classes from `product-category-item` so the slick carousel rendering doesn't break.

**Frontend product visibility:**
- `pre_get_posts` (prio 99) → `exclude_products_without_price`. Adds a meta query requiring `_price EXISTS AND _price != ''`. Applies to product archives + Query Loop + product taxonomies. **Skips: admin, AJAX, REST, single product pages.** If a product needs to be visible without a price, this is the gate.

**Category-archive ordering + pool (`product_cat` only):**
- `posts_clauses` (prio 99) → `order_category_archive_in_stock_first`. Gated to the frontend **main query** on a `product_cat` archive (`!is_admin()` + `is_main_query()` + `is_tax('product_cat')`); bails on everything else (main shop, search, tag archives, Query Loop, single product, secondary queries). Two effects: **(1) ordering** — prepends `CASE WHEN stock_status='instock' THEN 0 ELSE 1 END ASC` as the PRIMARY sort so in-stock products list before On Order (backorder) ones, keeping WC's chosen "Sort by" order as the secondary sort within each group (falls back to `menu_order, post_title` when WC set none); **(2) pool** — WHERE restricts to `stock_status IN ('instock','onbackorder')` AND `_manage_stock = 'yes'`, so out-of-stock and non-managed products vanish from category archives. Joins its **own alias** `trm_sml` of `{prefix}wc_product_meta_lookup` (indexed `stock_status`) + `trm_ms` of `postmeta` (`_manage_stock`) — aliased so it never collides with WC's own un-aliased lookup join for price/popularity/rating sorts (both joins are 1:1 on product_id, no fan-out). Prio 99 so it runs **after** WC's ordering `posts_clauses` (prio 10) and can read/prepend to its ORDER BY. **Layers on top of** the two priority-99 `pre_get_posts` gates (no-`_price` + Coming Soon), which keep running. Note: for variable products the guard reads the PARENT's `_manage_stock` and the lookup's aggregated parent `stock_status`.

**Sub-category carousel:**
- `woocommerce_before_shop_loop` (prio 99) → `render_sub_category_filter`. On a `product_cat` archive, queries immediate child terms and renders [woocommerce/sub-category-filter.php](woocommerce/sub-category-filter.php) via `wc_get_template()`. Frontend slick carousel is in `assets/js/wc-product-cat-carousel.js`.

**Marketing-role order flagging:**
- `woocommerce_checkout_order_processed` (prio 99) → if the user's primary role is `marketing`, adds order meta `trm_is_marketing_order = 'yes'`.

**Billing meta mirror:**
- `woocommerce_checkout_order_processed` (prio 100) → copies the order's billing fields (`billing_first_name/last_name/company/address_1/address_2/city/state/postcode/country/phone/email`) onto the customer's user meta. Skips empty values (won't wipe existing meta). Guest checkouts (no user_id) skipped.

**Disable My Account registration:**
- `option_woocommerce_enable_myaccount_registration`: returns `'no'` on the account page. **Registration is handled by the custom Account Creation block** (`TRM_ACF_Hooks` + `TRM_AJAX_Hooks`).

### `TRM_MB_Hooks` — [classes/metabox-hooks.php](classes/metabox-hooks.php)

Registers a Meta Box panel **"Games Workshop Info"** on the product post type with three cloneable text-list fields:

- `trm_gw_old_barcodes` — Previous Barcodes
- `trm_gw_old_product_codes` — Previous Product Codes
- `trm_gw_old_ssc_codes` — Previous SSC Codes

These are written by the `realm-gw-excel-uploader` plugin during bulk imports.

### `TRM_ACF_Hooks` — [classes/acf-hooks.php](classes/acf-hooks.php)

Registers one ACF block via `acf_register_block_type`:

- Name: `account-creation`
- Category: `trm-blocks`
- Render template: [parts/blocks/block-account-creation.php](parts/blocks/block-account-creation.php)
- ACF field definitions: [acf-json/group_6963b3110a92e.json](acf-json/group_6963b3110a92e.json) (auto-synced)

### `TRM_AJAX_Hooks` — [classes/ajax-hooks.php](classes/ajax-hooks.php)

Two AJAX actions, both registered for logged-in + logged-out:

#### `realm_register_customer` — `handle_customer_registration()`

Server-side flow:

1. Nonce check (`realm_register_customer`).
2. Sanitise inputs. **Passwords are intentionally NOT sanitised** (`wp_unslash` only) — preserves special chars.
3. Validate required fields → if missing, return `{code: 'validation'}`.
4. Validate password match + strength: ≥ 8 chars, ≥ 1 digit, ≥ 1 special char (custom regex).
5. Validate email format (`is_email`).
6. Validate phone prefix matches `^\+[\d\-]+$`.
7. Validate mobile number does NOT start with `+` (return code `mobile_plus_sign` if it does).
8. If `is_realm_member`, require `membership_number`.
9. Build `$billing_phone = "{prefix} {mobile}"`. Normalise to digits only for the dup check.
10. Duplicate checks (return `{code: 'duplicate'}` on any hit):
    - `email_exists($email)`.
    - `billing_phone` user meta exact match.
    - All users with a `billing_phone`, normalise digits, compare.
    - `rmm_membership_number` user meta exact match (only when supplied).
11. Generate unique `user_login` from email local part (`{base}{counter}` on collision).
12. `wp_insert_user(role: customer)` with the user's password.
13. Set user meta: `billing_first_name`, `billing_last_name`, `billing_email`, `billing_phone`, `realm_phone_prefix`, `realm_mobile_number`, and (if member) **both** `rmm_membership_number` and `realm_membership_number` for backwards compat.
14. `wp_new_user_notification($user_id, null, 'admin')` — **admin notification only**. Deliberate: user already has their password, so we suppress the user reset-password email.
15. Generate `realm_membership_token_{user_id}` transient (32 chars, 1-hour TTL) and return it with the user ID in the success payload.

#### `realm_apply_membership` — `handle_membership_application()`

Token-gated. Validates the transient, **deletes it on use** (one-shot), then sets user meta `rmm_membership_status = 'review'`. The Realm Members Manager admin UI then surfaces these for review.

### `TRM_Mega_Nav` — [classes/mega-nav.php](classes/mega-nav.php)

Custom category off-canvas navigation (added in item 19; replaces Max Mega Menu). Removes `storefront_primary_navigation` and renders an **"All Categories"** pill in the lower header bar (`render_nav_trigger`, `storefront_header` @ 50) that opens a left drawer built live from the `product_cat` hierarchy — 3 levels, WooCommerce display-order, empty branches/Uncategorized skipped, cached in the transient `trm_mega_nav_tree_v2` (6h, flushed on category create/edit/delete). Also registers a `trm_main_menu` nav location rendered horizontally (`render_main_menu` @ 52). Drawer markup is rendered **once** at `wp_footer` (`render_drawer`) via [assets/views/nav/mega-nav.php](assets/views/nav/mega-nav.php); behaviour in [assets/js/trm-mega-nav.js](assets/js/trm-mega-nav.js) (desktop fly-out columns / mobile slide-over panes; open/close/overlay/scroll-lock/Esc); styles in [assets/scss/mega-nav.scss](assets/scss/mega-nav.scss).

- **Drawer open hook:** every trigger is a `<button data-trm-nav-trigger aria-controls="trm-nav-drawer" aria-expanded="false">`. The JS binds via `querySelectorAll('[data-trm-nav-trigger]')` (already plural) and syncs `aria-expanded` across **all** triggers on open/close — so adding another trigger with that attribute needs **zero JS changes**. (`data-trm-nav-open` is a different, drawer-internal hook that opens sub-panes — don't confuse the two.)
- **Persistent rail + mobile FAB (item 30):** `render_cat_rail()` (also `wp_footer`, front-end-only guard, reuses the cached tree — no second drawer/tree render) outputs ONE `.trm-cat-rail` > `.trm-cat-rail__btn` carrying the same `data-trm-nav-trigger` hook, so it opens the same drawer as the pill with no JS change. CSS (in `mega-nav.scss`) presents it as a mobile-first bottom-right **FAB** (lifted above Storefront's fixed handheld footer bar) and, at `min-width:768px`, a slim full-height **left rail** overlaying content (no layout shift). z-index `$trm-nav-z − 10` (99980) — above content, **below** the drawer/overlay so an open drawer covers it. The header pill is unchanged.

### `TRM_Cost_Price` + `TRM_Cost_Price_DB` + `TRM_Cost_Price_CSV` + `TRM_Cost_Price_Upload` — [classes/cost-price/](classes/cost-price/)

Admin page: **WooCommerce → Cost Prices** (`trm-cost-price`, `manage_woocommerce`).

Tabs: `dashboard` (default) and `upload`.

**DB table:** `{prefix}trm_product_cost_price`
```
id             BIGINT UNSIGNED PK
product_id     BIGINT UNSIGNED (indexed)
sku            VARCHAR(191) (indexed)
cost_price     DECIMAL(10,2)
effective_date DATE (indexed)                  -- the date the price was VALID FROM
uploaded_at    DATETIME (indexed)              -- audit: when the row was recorded
uploaded_by    BIGINT UNSIGNED
source         VARCHAR(20) DEFAULT 'upload'     -- 'upload' (CSV) | 'inline' (today edit) | 'manual' (backdated entry)
```

Schema versioned via the `trm_cost_price_db_version` option (currently `1.2`). `maybe_install_db()` runs on `after_switch_theme` AND `admin_init`, so the table self-heals / migrates if missing or out of date (dbDelta added `source` at 1.1 and `effective_date` at 1.2; the 1.2 install backfills `effective_date = DATE(uploaded_at)` for legacy rows).

**`effective_date` vs `uploaded_at` — the key distinction.** `uploaded_at` is purely the audit timestamp ("when did we record this"); `effective_date` is what drives *which cost applies to a sale*. This split (added in item 26) lets a cost price be **backdated** so past sales report true profit.

**Append-only** (uploads/backdated entries; inline today-edits collapse — see below). **"Current" cost price** for a product = the row with the **greatest `effective_date` on/before today, id as tie-break** — NOT `MAX(id)`. A backdated correction has a higher id but an earlier effective date, so `MAX(id)` would wrongly treat it as current; every "current" query (`get_current_price`, `get_all_current_prices`, `get_current_prices_by_product_ids`, `get_current_price_with_product`) uses a greatest-effective-date lookup / anti-join instead. Full history powers Profit Analytics.

**Inline "today" editing.** Each dashboard row's *current* cost price is editable in place (AJAX `trm_cost_price_inline_update`, nonce `trm_cost_price_inline`, cap `manage_woocommerce`, handled by `TRM_Cost_Price::ajax_inline_update()` → `TRM_Cost_Price_DB::upsert_inline_price()`). Inline edits write `source = 'inline'`, `effective_date = today`. **De-noising rule:** if the current row is itself a today-effective `inline` edit made within the last hour, the edit overwrites it in place instead of appending — so rapid corrections don't churn history. `upload`/`manual` rows are never overwritten. JS: [assets/js/admin/cost-price.js](assets/js/admin/cost-price.js).

**Backdated (dated) cost prices — item 26.** Each product's history `<details>` panel lists every record by effective date (current row badged), and lets each be **edited / deleted** and new **backdated** prices added (price + "valid from" date). AJAX (same nonce/cap): `trm_cost_price_add_dated` → `insert_dated_price()` (`source='manual'`, append-only), `trm_cost_price_update_row` → `update_row()` (edits `cost_price`/`effective_date`, leaves `uploaded_at`), `trm_cost_price_delete_row` → `delete_row()`. Each response returns the product's recomputed current price so the main cell + current badge refresh live. Future dates are rejected server-side.

**WooCommerce product export.** The current cost price (current value only, no history) is exposed as a "Cost Price" column in WC's built-in product CSV exporter via `woocommerce_product_export_column_names` / `_product_default_columns` / `_product_column_trm_cost_price` (`TRM_Cost_Price::add_export_column()` / `export_column_value()`). Variations resolve by `variation_id`.

**Upload (`admin-post.php?action=trm_cost_price_upload`):**
- Nonce + `manage_woocommerce` cap.
- File type must be `csv` (via `wp_check_filetype`).
- Parsed by `TRM_Cost_Price_CSV::parse_upload()` — auto-detects header row (first column non-numeric); accepts `sku, cost_price`. Duplicate SKUs in the same file: last row wins.
- Bad rows go into the transient `trm_cp_upload_errors_{user_id}` (60s TTL) and surface on the next page render.
- **Rows whose cost price hasn't changed since the last upload are skipped** and reported as `unchanged` (epsilon comparison, `abs(diff) < 0.001`). Only changed rows are inserted.

**Helper:** `TRM_Cost_Price_DB::get_product_id_by_barcode($barcode)` queries `{prefix}barcode_lookup` (written by the **realm-barcode-scanner** plugin). Returns 0 if the table is missing — barcode resolution silently degrades when that plugin is disabled.

### `TRM_Profit_Analytics` + `TRM_Profit_Analytics_DB` — [classes/profit-analytics/](classes/profit-analytics/)

Admin page: **WooCommerce → Profit Analytics** (`trm-profit-analytics`, `manage_woocommerce`).

- Date range filter via `?date_from=&date_to=` (default: last 7 days). Invalid / inverted dates fall back / swap.
- Reads **WC Analytics lookup tables** (HPOS-compatible): `wc_order_product_lookup` (line items) JOIN `wc_order_stats` (for status filter). Only `wc-completed` and `wc-processing` count.
- Revenue uses `product_net_revenue` (after discounts, before tax/shipping).
- For variable products, the effective product ID is `COALESCE(NULLIF(variation_id, 0), product_id)` — matches how cost prices are keyed by SKU.
- Cost-price-period binning: for each line item, finds the latest cost price whose **`effective_date`** is on or before the order date (not `uploaded_at` — so backdated prices bin correctly). Aggregation key = `product_id . '_' . cp_id`, so a product whose cost price changed mid-range will appear as multiple rows. `period_start` = `MAX(cp.effective_date, range_start)`; `period_end` = next CP change − 1s, or `range_end`. History is fetched ordered by `effective_date ASC, id ASC`.
- Output sorted by product title ASC, then `period_start` ASC.

### `TRM_Coming_Soon` — [classes/coming-soon/trm-coming-soon.php](classes/coming-soon/trm-coming-soon.php)

Pre-order / "Coming Soon" handling. Settings picker (`trm_coming_soon_category`, term ID) under WC → Settings → General; a Meta Box **"Release Information"** panel with a single `trm_release_date` date field (`Y-m-d`); a single-product release-date line; a `pre_get_posts` (prio 99) gate hiding Coming Soon products from every listing except the Coming Soon archive; and a daily WP-Cron (`trm_remove_coming_soon_expired`, ~00:01 local) that strips the category once `trm_release_date <= today` (lexicographic string compare — **never** `CAST … AS DATE`).

- **`trm_release_date` now allows past dates** (item 32): the `js_options.minDate => 0` restriction was removed so a mis-entered release date can be corrected after launch. Backdating a still-Coming-Soon product makes the next cron run strip the category (the `<= today` match). Storage format (`Y-m-d`) and everything else about the field is unchanged.
- **WP-Cron lag limitation:** WP-Cron only fires on the first request after the scheduled time, so on a quiet Saturday morning with no traffic the Coming Soon category may not yet be stripped from that day's releases — which can leave the New Releases carousel empty on the week's most important day. There's no clean code fix; the real fix is a server-level cron hitting `wp-cron.php` (a hosting change). Do **not** work around it by relaxing the Coming Soon veto — that veto is load-bearing for deliberately delayed launches.

### `TRM_New_Releases` — [classes/new-releases/trm-new-releases.php](classes/new-releases/trm-new-releases.php)

"New Releases" carousel (item 32) — an ACF block surfacing every product released in the current release week, grouped by top-level game system. Extends `TRM_Core`.

- **Block** `acf/new-releases-carousel` (category `trm-blocks`), one PHP-registered ACF field **Title** (group `group_trm_new_releases`, `block == acf/new-releases-carousel`) — **no acf-json**. `render_callback` → `render_block()` → includes [parts/blocks/block-new-releases-carousel.php](parts/blocks/block-new-releases-carousel.php); `enqueue_assets` → Slick + `assets/js/trm-new-releases.js`.
- **Window** `[most recent Saturday … today]`, inclusive, in `wp_timezone()`. On a Saturday the start IS today (branches on ISO weekday `'6'`, not `modify('last saturday')`). Deliberately never extends into the future.
- **Qualify:** `trm_release_date` in-window (meta_query `BETWEEN`, **`type => CHAR`** — never `DATE`), published, `_price` present, `product_visibility NOT IN (outofstock, exclude-from-catalog)` (= in stock or on backorder, catalogue-visible), and `product_cat NOT IN` the Coming Soon term (+children) when one is configured. Self-sufficient query (doesn't rely on the global `pre_get_posts` gates, which are redundant-but-harmless here).
- **Ordering:** grouped by top-level `product_cat` ancestor (`get_ancestors`), groups in WooCommerce display order — the **same** ordering `TRM_Mega_Nav` uses (parent=0 terms by `order` termmeta then name, Uncategorized skipped → sorted last). Newest-first within a group (`post_date DESC, ID DESC` + PHP 8 stable `usort` on the category ordinal). Each product appears once (min ordinal across its trees).
- **Display cap** option `trm_new_releases_max_products` (WC → Settings → General, section "The Realm — New Releases"): empty/0/non-numeric/negative → **20**, `> 50` → **50**. Applied after the sort. Query ceiling `QUERY_CEILING = 200` (`fields => ids`, `no_found_rows`); cap-trim and ceiling-hit are logged via `write_log()`.
- **Cache:** transient **`trm_new_releases_ids`** = `{ window_start, ids }` (uncapped, sorted), 1h TTL. A stored `window_start` ≠ current → miss (self-invalidates on week roll-over; cap applied on read, never cached). Explicit `delete_transient` on `save_post_product`, `trashed_post`/`untrashed_post`/`deleted_post` (product only), `created_product_cat`/`edited_product_cat`/`delete_product_cat`, and `trm_remove_coming_soon_expired` (prio 99, after the cron strips categories).
- **Cards** reuse `wc_get_template_part('content','product')` inside a hand-written `<ul class="products columns-4 trm-new-releases__track">` (the Slick track). Sets `$post` + `$product` globals per item (the `the_post` action doesn't fire on `setup_postdata`). Empty state renders **"No products released this week."** on front end AND editor.
- **"New!" thumbnail badge:** a triangular bottom-right corner badge (`.trm-nr-badge`, clip-path triangle + rotated label) over each card's thumbnail. Injected by wrapping the loop thumbnail via `woocommerce_before_shop_loop_item_title` (prio 9 open `.trm-nr-thumb`, prio 11 badge + close) — **added and removed around this block's loop render only** (`open_thumb_wrap` / `render_thumb_badge`), so no other WooCommerce product loop is affected. Decorative (`aria-hidden`; the section already reads as "New Releases").

## WooCommerce template overrides

In [woocommerce/](woocommerce/):

- `cart/cart.php`, `cart/cart-totals.php`
- `checkout/form-checkout.php`, `checkout/form-coupon.php`, `checkout/review-order.php`
- `single-product/stock.php` — minimal; the actual availability text/class comes from `TRM_WC_Hooks` filters.
- `single-product/related.php`
- `single-product/add-to-cart/simple.php`
- `sub-category-filter.php` — custom (not a WC core template); loaded explicitly by `TRM_WC_Hooks::render_sub_category_filter()`.

Most overrides are at WC template versions 9.x–10.x — when bumping WC, check the `@version` header against the live WC template and re-merge.

## Other front-end templates

- [single-event.php](single-event.php) — single template for the `event` CPT (owned by the `realm-events-manager` plugin). Mirrors Storefront's `single.php`; renders the event's ACF fields via `get_field()` (banner, date, time, location, participants) plus `event_category` / `game_system` terms, and a new-tab "Register" button when `event_register_link` is set. Styled by `assets/scss/single-event.scss`.

## Asset pipeline

SCSS source in [assets/scss/](assets/scss/), compiled to a single `assets/css/layout.css`:

```bash
# inside the theme folder:
npm run compile:sass     # one-off
npm run watch:sass       # dev
```

Entry: [assets/scss/styles.scss](assets/scss/styles.scss) → uses `variables`, `font`, `general`, `header`, `footer`, `mega-nav`, `cart`, `wc-shop`, `wc-cart`, `wc-single-product`, `wc-product-cat`, `new-releases`, `single-event`, `event-registration`, `live-search`, `on-order`, `preorder`.

Front-end JS of note: `assets/js/trm-new-releases.js` (New Releases carousel — Slick init on `.trm-new-releases__track`, guarded against double-init; enqueued by the block's `enqueue_assets`, not in `functions.php`).

Admin CSS is hand-written (no SCSS): `assets/css/admin/admin.css`, `cost-price.css`, `profit-analytics.css`.

## Conventions

- Views go under `assets/views/{section}/{name}.php`. Render via `$this->render_template('section/name', [...])`.
- New WC hooks: register in `TRM_WC_Hooks::register_hook_callbacks()` and add a one-line PHPDoc on the method. Other hook classes follow the same pattern.
- New admin pages: add a class, extend `TRM_Core`, hook `admin_menu`, render via `render_template()`.
- All custom user/post/option keys use the `trm_` prefix; membership-specific keys use `rmm_` (owned by the Realm Members Manager plugin) — keep that boundary.
- Block editor is disabled; new front-end UI = Storefront templates, WC template overrides, ACF blocks, or shortcodes — **not** core blocks.

## Local-only quirks

- `wp_enqueue_*` versions use `time()` — fine for dev, needs revisiting for prod (cache headers will defeat it).
- Production runs WordPress 7; this XAMPP install is 6.7.1. Target WP 7 hook signatures when in doubt.
