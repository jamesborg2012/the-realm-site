# The Realm Malta — Project Notes for Claude

WordPress + WooCommerce store for a Malta-based hobby/gaming retailer (Games Workshop products, membership programme, in-store + online sales). This file documents what exists today so future Claude sessions can act without re-deriving everything.

## Stack

| Component       | Version (prod)   | Version (local)  | Location                                                 |
|-----------------|------------------|------------------|----------------------------------------------------------|
| WordPress core  | **7.x**          | 6.7.1            | `wp-includes/version.php` (local)                        |
| PHP runtime     | 8.0+             | 8.0+ (XAMPP)     | both                                                     |
| WooCommerce     | 9.6.0            | 9.6.0            | `wp-content/plugins/woocommerce`                         |
| Storefront      | 4.6.1            | 4.6.1            | `wp-content/themes/storefront` (parent theme)            |
| Child theme     | "The Realm Malta"| same             | `wp-content/themes/the-realm-malta`                      |
| DB              | MySQL            | MySQL (XAMPP)    | DB name `the_realm`, `$table_prefix = 'wp_'`             |

**Production runs WordPress 7.x; the local XAMPP install is still on 6.7.1.** Keep that divergence in mind when reading core hook signatures or deprecation notices locally — the canonical target is WP 7.

Local dev: `WP_DEBUG = true`, `WP_DEBUG_LOG = true`, `WP_DEBUG_DISPLAY = false` (errors go to `wp-content/debug.log`).

## Repository layout (the bits that matter)

```
wp-content/
├── themes/
│   ├── storefront/                  # parent (don't modify)
│   └── the-realm-malta/             # child theme (all custom front-end here)
└── plugins/
    ├── woocommerce/                 # core
    ├── advanced-custom-fields/      # used for the "Account Creation" Gutenberg block
    ├── meta-box/                    # used for the GW product meta fields
    ├── classic-editor/              # Gutenberg disabled site-wide
    ├── classic-widgets/
    ├── disable-gutenberg/
    ├── megamenu/                    # 3rd-party mega menu
    ├── members/                     # 3rd-party role/cap manager
    ├── akismet/, hello.php          # default
    ├── barcode-scanner-lite-pos-… / # 3rd-party POS barcode plugin — note: writes a wp_barcode_lookup table that our custom code reads
    ├── realm-barcode-scanner/       # CUSTOM
    ├── realm-events-manager/        # CUSTOM
    ├── realm-gw-excel-uploader/     # CUSTOM
    ├── realm-gw-order-tracker/      # CUSTOM
    ├── realm-members-manager/       # CUSTOM
    └── realm-sales-system/          # CUSTOM — backend order-placing module (requires realm-barcode-scanner)
```

The Gutenberg block editor is disabled (Classic Editor + Disable Gutenberg + Classic Widgets). The one place we *do* use blocks is the ACF-registered Account Creation block (rendered from `parts/blocks/block-account-creation.php`).

---

## Child theme — `the-realm-malta`

Bootstrap is [functions.php](wp-content/themes/the-realm-malta/functions.php). It requires every class file and instantiates each class. There is no autoloader.

### Class map

| Class                  | File                                                    | Purpose                                                                 |
|------------------------|---------------------------------------------------------|-------------------------------------------------------------------------|
| `TRM_Core`             | [classes/trm-core.php](wp-content/themes/the-realm-malta/classes/trm-core.php) | Base class — `render_template()` (loads from `assets/views/`) + `write_log()`. Every other class extends this. |
| `TRM_Marketing_Handler`| [classes/trm-marketing.php](wp-content/themes/the-realm-malta/classes/trm-marketing.php) | Adds top-level admin menu **"The Realm Marketing"** (`trm-marketing`, cap `manage_woocommerce`). Currently a landing page only. |
| `TRM_WC_Hooks`         | [classes/wc-hooks.php](wp-content/themes/the-realm-malta/classes/wc-hooks.php) | Core WC customisations (see below).                                     |
| `TRM_MB_Hooks`         | [classes/metabox-hooks.php](wp-content/themes/the-realm-malta/classes/metabox-hooks.php) | Registers a Meta Box panel **"Games Workshop Info"** on the product editor with cloneable text-list fields: `trm_gw_old_barcodes`, `trm_gw_old_product_codes`, `trm_gw_old_ssc_codes`. |
| `TRM_ACF_Hooks`        | [classes/acf-hooks.php](wp-content/themes/the-realm-malta/classes/acf-hooks.php) | Registers the **`acf/account-creation`** block (category `trm-blocks`). Render template: [parts/blocks/block-account-creation.php](wp-content/themes/the-realm-malta/parts/blocks/block-account-creation.php). |
| `TRM_AJAX_Hooks`       | [classes/ajax-hooks.php](wp-content/themes/the-realm-malta/classes/ajax-hooks.php) | AJAX endpoints `realm_register_customer` and `realm_apply_membership` (both have `nopriv` variants). |
| `TRM_Cost_Price`       | [classes/cost-price/trm-cost-price.php](wp-content/themes/the-realm-malta/classes/cost-price/trm-cost-price.php) | Admin tool for tracking per-product cost prices (own DB table). See **Cost Prices** below. |
| `TRM_Profit_Analytics` | [classes/profit-analytics/trm-profit-analytics.php](wp-content/themes/the-realm-malta/classes/profit-analytics/trm-profit-analytics.php) | Admin report that joins WC orders with cost-price history to produce profit/margin per product per cost-price-period. |

### `TRM_WC_Hooks` — what it changes

- Removes `woocommerce_template_single_meta` from the single product summary.
- `storefront_credit_link` → false (hides "Built with Storefront" link).
- Adds product data fields **GW Product Code** (`_product_code`) and **GW SSC Code** (`_ssc_code`) to the Inventory tab; saves them on product save.
- `woocommerce_product_import_pre_insert_product_object` → maps CSV columns:
  - `category` → builds nested `product_cat` terms from `Parent > Child > Grandchild;Other > Path` strings.
  - `commodity_code` → maps to one of `standard` / `reduced-rate` / `zero-rate` tax classes via the `COMM_CODE_MAP` constant (hard-coded list of GW commodity codes). Default falls through to whatever WC uses.
  - `product_code` → `_product_code`; `ssc_code` → `_ssc_code`.
- Coupon messages rewritten to "Member Discount Applied!/Removed!" when the coupon code contains `storedisc` or `onlineonly` (`update_coupon_message_member`).
- Custom stock availability text/class on single product pages: "Product available in store" (`available-in-store`) when in stock and managing stock; "Product available on order" (`available-on-order`) when on backorder.
- `pre_get_posts` priority 99: **excludes products without a `_price` from all frontend product queries** (archives, Query Loop, taxonomy archives). Single product pages unaffected. Admin/AJAX/REST queries unaffected.
- Renders the [sub-category filter](wp-content/themes/the-realm-malta/woocommerce/sub-category-filter.php) (slick carousel of immediate child terms) on `woocommerce_before_shop_loop` when viewing a `product_cat` archive.
- Marks orders by users with role `marketing` with order meta `trm_is_marketing_order = 'yes'` (on `woocommerce_checkout_order_processed`).
- Mirrors all `billing_*` checkout fields to user meta on order placement (`update_user_billing_meta_on_order`, priority 100).
- **Disables the My Account registration form** (`option_woocommerce_enable_myaccount_registration` returns `'no'` on the account page). Registration goes through the custom Account Creation block instead.

### Templates & view overrides

WooCommerce templates overridden in [woocommerce/](wp-content/themes/the-realm-malta/woocommerce/):

- `cart/cart.php`, `cart/cart-totals.php`
- `checkout/form-checkout.php`, `checkout/form-coupon.php`, `checkout/review-order.php`
- `single-product/add-to-cart/simple.php`, `single-product/related.php`, `single-product/stock.php`
- `sub-category-filter.php` — custom (not a WC core template; loaded explicitly via `wc_get_template()`).

Theme view templates live in `assets/views/admin/{cost-price,marketing,profit-analytics}/…` and are rendered via `TRM_Core::render_template()` (uses `extract()` to pass variables — be mindful when adding new ones).

### Asset pipeline

SCSS source in `assets/scss/`, compiled to a single `assets/css/layout.css`:

```bash
# inside the theme:
npm run compile:sass     # one-off
npm run watch:sass       # dev
```

Entry: [`assets/scss/styles.scss`](wp-content/themes/the-realm-malta/assets/scss/styles.scss) → uses `variables`, `font`, `general`, `mega-menu`, `wc-shop`, `wc-cart`, `wc-single-product`, `wc-product-cat`.

Admin CSS lives un-compiled in `assets/css/admin/` (`admin.css`, `cost-price.css`, `profit-analytics.css`).

JS:
- `assets/js/ajax.js` — Account Creation + membership AJAX (jQuery + selectWoo).
- `assets/js/wc-product-cat-carousel.js` — Slick carousel for the sub-category filter.
- Slick is loaded from cdnjs in `functions.php`.

**Cache-busting:** `wp_enqueue_*` uses `time()` as the version. Fine for dev; revisit before production.

### Account Creation block

ACF block registered in `TRM_ACF_Hooks`; renders [parts/blocks/block-account-creation.php](wp-content/themes/the-realm-malta/parts/blocks/block-account-creation.php).

Frontend form fields: First/Last Name, Email, Phone Prefix (full country list, Malta `+356` first/default), Mobile, Password (+ confirm), "Already a Realm Member?" checkbox → optional Membership Number.

Submit is AJAX (`assets/js/ajax.js`) → `wp_ajax_realm_register_customer` (`classes/ajax-hooks.php:24`). Server-side does:
1. Nonce check (`realm_register_customer`).
2. Field validation (incl. password ≥ 8 chars, requires a digit + a special char).
3. Phone prefix must match `^\+[\d\-]+$`; mobile must NOT start with `+`.
4. Duplicate checks: email, `billing_phone` (exact + normalized digits-only), and `rmm_membership_number` if supplied.
5. Username = sanitised local part of email, with numeric suffix on collision.
6. `wp_insert_user(role: customer)` with user-supplied password.
7. Sets meta: `billing_first_name/last_name/email/phone`, `realm_phone_prefix`, `realm_mobile_number`, and `rmm_membership_number` + `realm_membership_number` (kept in sync — both keys exist for backward compat).
8. `wp_new_user_notification($user_id, null, 'admin')` — **admin only**, on purpose (user already has their password, so we deliberately skip the reset email).
9. Generates a one-hour `realm_membership_token_{user_id}` transient and returns it.

After success, the JS shows a "Become a Member" CTA which posts to `realm_apply_membership` (validates the token, deletes it on use, sets `rmm_membership_status = 'review'`).

Membership price displayed on the CTA is `get_option("rmm_{$rounded}_months_membership")` where `$rounded` is the months-until-Dec rounded to the nearest 3 (1 or 2 → 12). Options are managed by the **Realm Members Manager** plugin.

---

## Custom plugins

All six `realm-*` plugins are first-party code authored by James Borg. Treat them as part of this codebase.

### `realm-members-manager` — the big one

Manages members, coupons, pricing tiers, checkout integration.

- Core classes:
  - `Realm_Members_Manager_Core` — singleton bootstrap, asset enqueue.
  - `Members_Manager` — admin pages + user profile member meta fields.
  - `RMM_Admin_Ajax_Handler` — `wp_ajax_load_member_data`, `create_new_member`, `update_member_details`.
  - `RMM_Ajax_Handler` — frontend `wp_ajax_apply_membership_number` (also nopriv), `register_new_member`.
  - `RMM_Shortcodes_Handler` — `[realm_membership_form]`.
  - `RMM_Settings_Handler` — pricing + discount settings (Settings API).
  - `RMM_Upload_Handler` — CSV bulk member import; auto-creates **two coupons per member**: a store coupon (excludes "online-only" brand) and an online-only coupon (limited to that brand).
  - `RMM_WC_Hooks_Handler` — outputs the member-number form on `woocommerce_before_checkout_form`, prefills checkout, assigns matched member to order.
- Admin menus: `realm-members`, `rmm-import-members` (both `manage_woocommerce`), `rmm-manage-membership-pricing`, `rmm-manage-member-discount` (both `manage_options`).
- User meta written: `rmm_membership_status` (`new` / `active` / `not_active` / `review`), `rmm_membership_number`, `rmm_membership_expire`, `rmm_membership_expires`, `rmm_membership_store_coupon`, `rmm_membership_online_coupon`.
- Options written: `rmm_3/6/9/12_months_membership` (price), `rmm_member_store_discount` (default 18), `rmm_member_online_only_discount` (default 8), `rmm_guest_discount`.
- **No custom DB tables.** Coupons are real WC coupons. Membership data lives in user meta.

### `realm-events-manager`

Gaming events calendar.

- CPT `event` (slug `events`) with `show_in_rest`.
- Taxonomies: `event_category`, `game_system` (both hierarchical, REST-enabled).
- **Event Details fields are ACF** (`TREM_Event_Fields`, registered via `acf_add_local_field_group`): `event_date`, `event_start_time`, `event_participants`, `event_location`, `event_register_link`, `event_banner` (image). Replaced the old Meta Box panel. Date/time are kept in the legacy `Y-m-d` / `H:i` DB formats via `acf/load_value`+`acf/update_value` filters (ACF pickers store `Ymd`/`H:i:s` natively) so the calendar/upcoming meta queries and existing data keep working.
- Single event page: [wp-content/themes/the-realm-malta/single-event.php](wp-content/themes/the-realm-malta/single-event.php) (child theme) renders the ACF fields + taxonomy terms + a new-tab register button; styled by `assets/scss/single-event.scss`.
- **"Events Calendar" ACF block** (`acf/events-calendar`, category `trm-blocks`, registered by `TREM_Block_Calendar`) renders an interactive calendar; AJAX `trem_load_calendar` + `trem_load_event` drive month nav and event detail modal. Replaced the old `[realm_events_calendar]` shortcode.
- **"Upcoming Events" ACF block** (`acf/upcoming-events`, registered by `TREM_Block_Upcoming_Events`) — agenda-style list of the next N upcoming events with optional `event_category` / `game_system` filters. ACF fields registered in PHP (not acf-json); config is per-block-instance.
- Assets: `assets/css/trem-calendar.css` + `assets/js/trem-calendar.js` (calendar block) and `assets/css/trem-upcoming-events.css` (upcoming block), enqueued via each block's `enqueue_assets` callback.

### `realm-barcode-scanner`

Camera/POS barcode workflow.

- Single class `RealmBarcodeScanner` in [realm-barcode-scanner.php](wp-content/plugins/realm-barcode-scanner/realm-barcode-scanner.php).
- Admin page `rbs-scan` (`manage_options`) — stock adjustment UI.
- Shortcode `[rbs_add_to_cart_scanner]` — frontend cart add-by-scan.
- Custom table `{prefix}barcode_lookup` (`id`, `barcode UNIQUE`, `product_id`, `created_at`, `last_seen`) — **also read by `TRM_Cost_Price_DB::get_product_id_by_barcode()` in the theme**; cross-component dependency.
- REST routes (namespace `rbs/v1`): `POST /lookup`, `POST /adjust`, `POST /lookup-public`.
- AJAX `rbs_add_to_cart`.
- JS: `assets/rbs.js` (admin — native `BarcodeDetector` w/ Quagga fallback), `assets/rbs-cart.js` (frontend cart scanner).

### `realm-gw-excel-uploader`

Bulk-update GW product meta from CSV (no AJAX, plain form POST).

- Classes: `Realm_Gw_Excel_Uploader_Core` (singleton), `GWEU_Pages` (admin page `gw-excel-uploader`, `manage_options`), `Realm_GWEU_Upload_Handler` (parses CSV, resolves products by SKU, updates `_product_code`, `_ssc_code`, and the cloneable Meta Box fields `trm_gw_old_barcodes`, `trm_gw_old_product_codes`, `trm_gw_old_ssc_codes`).
- No custom tables — postmeta only.

### `realm-gw-order-tracker`

Order-fulfilment dashboard for GW orders.

- Classes (under `src/` — looks PSR-style but loaded via `require`s in `Core.php`): `Core`, `Admin\Menu`, `Admin\Page`, `Ajax\Orders`, `Services\OrderQuery`.
- Admin menu `gw-order-tracker` (`manage_woocommerce`, position 58).
- AJAX: `gwot_fetch_orders` (paginated cards), `gwot_fetch_order_items` (expand to item table), `gwot_export_csv` (date-range CSV; **excludes products in the `product_brand` taxonomy term `online-only`**), `gwot_get_item_meta`, `gwot_update_item_meta`.
- Stores **order-item meta** (not product meta): `_gw_ordered_qty`, `_gw_received_qty`, `_gw_delivered_qty`.
- `OrderQuery` excludes orders whose customer has the `marketing` role.

### `realm-sales-system`

Backend order-placing module for in-store sales (alternative to WC's Add Order screen).

- **Hard dependency on `realm-barcode-scanner`** — activation is blocked when it's missing, and the plugin self-deactivates (with an admin notice) if the scanner is later turned off. Barcode resolution reuses `RealmBarcodeScanner::resolve_product_fast()` / `resolve_product_slow_from_meta()`.
- Classes: `RSS_Core` (singleton — top-level **"Sales System"** menu, cap `manage_woocommerce`, with **New Order** + **New Marketing Order** submenus + asset enqueue) and `RSS_Ajax`.
- Admin-only AJAX (nonce `rss_sales_system`, cap `manage_woocommerce`, no nopriv): `rss_verify_member` (member number → name/surname/email/user_id + store discount %, flags expired/inactive), `rss_lookup_barcode` (scan → product, captures live `get_price()`), `rss_place_order`, `rss_place_marketing_order`.
- Order creation: member **store discount only** (`rmm_member_store_discount`) applied to all lines via per-line `total = subtotal × (1 − pct)` (no coupon); customer attributed to the resolved user id or guest; `set_customer_note()` for the details textarea; `calculate_totals(true)` then `update_status('completed')` + `set_date_paid()` → **completed + paid**, stock reduced once. Tags order meta `_rss_sales_system_order`, `_rss_member_number`.
- **Marketing orders** (New Marketing Order page / `rss_place_marketing_order`): a mode-flagged reuse of the same view + JS with no member section, no customer fields and no discounts. Attributed to the `realm.marketing` user (or the first `marketing`-role user, else error); charged at full price; tagged `_rss_marketing_order` + `trm_is_marketing_order = 'yes'` so it stays inline with the theme's marketing-order logic and the order-tracker's `marketing`-role exclusion.
- No custom DB tables.

---

## Cross-cutting features

### Cost Prices (theme: `classes/cost-price/`)

- Admin page **WooCommerce → Cost Prices** (slug `trm-cost-price`, `manage_woocommerce`).
- Two tabs: **Dashboard** (paginated 50/page, with SKU search) and **Upload** (CSV upload).
- Custom table `{prefix}trm_product_cost_price` (`id`, `product_id`, `sku`, `cost_price DECIMAL(10,2)`, `effective_date DATE`, `uploaded_at`, `uploaded_by`, `source` ['upload'|'inline'|'manual']). Schema versioned via `trm_cost_price_db_version` option (currently `1.2`).
- **`effective_date` = the date a price was VALID FROM** (distinct from `uploaded_at`, the audit "recorded on" timestamp). This split (item 26) lets cost prices be **backdated** so past sales report true profit; the 1.2 migration backfills `effective_date = DATE(uploaded_at)`.
- CSV format: `sku, cost_price` (header optional; CSV rows get `effective_date = today`). Validation: rows whose cost price hasn't changed since the previous upload are reported as **unchanged** and skipped; bad rows go into a transient `trm_cp_upload_errors_{user_id}` for the next render.
- **"Current" cost price** = the row with the greatest `effective_date` on/before today (id tie-break), **not** `MAX(id)` — a backdated row has a higher id but earlier effective date and must not be treated as current. Full append-only history powers profit analytics over time.
- **Inline editing** (AJAX `trm_cost_price_inline_update`): each dashboard row's *current* cost price is editable in place (writes `effective_date = today`, `source='inline'`). An inline edit appends, EXCEPT when the current row is a today-effective inline edit from within the last hour — then it overwrites that row so quick corrections don't churn history. `upload`/`manual` rows are never overwritten.
- **Backdated entries** (item 26): each product's history panel lists records by effective date (current one badged) and lets them be edited/deleted (`trm_cost_price_update_row` / `trm_cost_price_delete_row`) and new past-dated prices added (`trm_cost_price_add_dated` → `source='manual'`). Future dates rejected server-side.
- **WC product export:** current cost price (current only, no history) is added as a "Cost Price" column to WooCommerce's built-in product CSV exporter (`woocommerce_product_export_*` filters in `TRM_Cost_Price`).

### Profit Analytics (theme: `classes/profit-analytics/`)

- Admin page **WooCommerce → Profit Analytics** (`trm-profit-analytics`, `manage_woocommerce`).
- Reads **WC Analytics lookup tables** (`wc_order_product_lookup`, `wc_order_stats`) — HPOS-compatible. Filters orders to status `wc-completed` / `wc-processing`. Revenue uses `product_net_revenue` (post-discount, pre-tax/shipping).
- Joins per-line-item revenue against the cost-price history table and bins each line into the cost-price-period that was active at the time of the order — matched by **`effective_date`**, so backdated cost prices bin correctly. A single product can appear as multiple rows in a date range if its cost price changed mid-range.
- For variable products, `variation_id` is used as the effective product_id when non-zero (matches how SKU→product lookup works).
- Default date range: last 7 days.

### Membership coupon naming convention

Coupon codes containing `storedisc` or `onlineonly` are treated as member discount coupons (`TRM_WC_Hooks::update_coupon_message_member`). Don't rename without grepping both the theme and the members-manager plugin.

### Cross-plugin dependencies (be careful)

- **`TRM_Cost_Price_DB::get_product_id_by_barcode()`** reads `wp_barcode_lookup` written by the `realm-barcode-scanner` plugin (and possibly the 3rd-party POS barcode plugin). Guarded by a `SHOW TABLES LIKE` check, so it's safe if missing, but barcode→product resolution silently degrades if the scanner plugin is disabled.
- **`Realm Members Manager`** sets the `rmm_membership_*` meta keys that the theme's AJAX registration reads (`rmm_membership_number` duplicate check, `rmm_membership_status = 'review'` write).
- **`realm-gw-order-tracker`** filters on a `product_brand` taxonomy with an `online-only` term. **This taxonomy is created/maintained by a custom CSV importer built for the client (not in this repo).** Don't expect it to be defined in PHP — it's seeded from the import.

---

## Conventions worth knowing

- All custom theme classes extend `TRM_Core` and use `render_template('admin/foo/bar', [...])` to render views from `assets/views/`. `extract()` is used, so view variables come in as locals.
- Custom plugin code is namespaced by prefix (`trm_` for theme, `rbs_` / `trem_` / `gweu_` / `gwot_` / `rmm_` / `rss_` for plugins). Stick to the existing prefix when adding new options, meta, or AJAX actions.
- Custom user roles in use: `customer` (standard), `marketing` (special — orders are flagged + excluded from the order tracker).
- Block editor is disabled almost everywhere; new front-end UI should be added as Storefront templates, WC template overrides, ACF blocks, or shortcodes — **not** as core blocks.
- WP_DEBUG is on in this XAMPP setup; `TRM_Core::write_log()` only writes when `WP_DEBUG === true`.

---

## Component-level docs

Deeper notes live alongside each component — they load automatically when Claude opens a file in that subtree:

- [Theme: the-realm-malta](wp-content/themes/the-realm-malta/CLAUDE.md)
- [Plugin: realm-members-manager](wp-content/plugins/realm-members-manager/CLAUDE.md)
- [Plugin: realm-events-manager](wp-content/plugins/realm-events-manager/CLAUDE.md)
- [Plugin: realm-barcode-scanner](wp-content/plugins/realm-barcode-scanner/CLAUDE.md)
- [Plugin: realm-gw-excel-uploader](wp-content/plugins/realm-gw-excel-uploader/CLAUDE.md)
- [Plugin: realm-gw-order-tracker](wp-content/plugins/realm-gw-order-tracker/CLAUDE.md)
- [Plugin: realm-sales-system](wp-content/plugins/realm-sales-system/CLAUDE.md)

## Still worth confirming

- Production hosting / deployment path. Cache-busting via `time()` and inline keys in `wp-config.php` will need to change for prod.
