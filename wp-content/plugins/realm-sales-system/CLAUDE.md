# Realm Sales System

Custom plugin providing a **backend order-placing module** ("Sales System") so staff can ring up in-store sales without driving the front-end checkout. Member lookup, camera barcode scanning, member discounts, and one-click WooCommerce order creation.

Header: `v0.3.0`, author James Borg. See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## Hard dependency

**Requires `realm-barcode-scanner` to be active.** Activation is blocked (`wp_die`) when it is missing, and if the scanner is deactivated later this plugin self-deactivates with an admin notice (`plugins_loaded` guard in [realm-sales-system.php](realm-sales-system.php)). Barcode → product resolution reuses `RealmBarcodeScanner::resolve_product_fast()` / `resolve_product_slow_from_meta()`, falling back to `wc_get_product_id_by_sku()`.

## File layout

```
realm-sales-system.php          # plugin header, dependency enforcement, bootstrap
includes/
├── class-rss-core.php          # RSS_Core (singleton): menu, asset enqueue, page render
└── class-rss-ajax.php          # RSS_Ajax: verify member, lookup barcode, place order
views/
├── landing.php                 # "The Realm Order Placing Module" placeholder
└── new-order.php               # New Order UI — shared by the standard + marketing pages (see $rss_is_marketing)
assets/
├── new-order.js                # cart state, camera scanner, confirm modal, live totals (mode-aware via rssData.mode)
└── new-order.css
```

No custom DB tables. No autoloader — `realm-sales-system.php` `require`s the two classes.

## Admin menu (`RSS_Core`, cap `manage_woocommerce`)

| Slug                   | Page                                              |
|------------------------|---------------------------------------------------|
| `rss-sales-system`     | Landing (placeholder text, to be amended)         |
| `rss-new-order`        | New Order — the standard order-placing UI         |
| `rss-marketing-order`  | New Marketing Order — internal marketing purchases |

Assets enqueue on **both** order screens (hooks `…_page_rss-new-order` and `…_page_rss-marketing-order`); Quagga loaded from cdn (unpkg), same source as `realm-barcode-scanner`.

### Marketing orders (mode flag)

The standard and marketing pages share one view (`new-order.php`) and one script (`new-order.js`), parametrised by a **mode flag**:

- `RSS_Core::render_marketing_order()` sets `$rss_is_marketing = true` before requiring the view; the view drops the **member section**, the **customer Name/Surname/Email fields** and the cart's **Discount column** (and its Total Discount footer row), narrowing the cart from 6 columns to 5.
- Enqueue passes `rssData.mode = 'marketing'`; `new-order.js` reads it into `isMarketing` (and `cartCols`) so cart rendering omits the discount cell and `placeOrder()` posts to `rss_place_marketing_order` with no member/customer payload.
- The scanner, product search, confirm modal and live totals are single-sourced — only the member/discount/customer bits are gated.

## AJAX (admin-only, no nopriv; nonce `rss_sales_system`, cap `manage_woocommerce`)

| Action               | Purpose                                                                 |
|----------------------|-------------------------------------------------------------------------|
| `rss_verify_member`   | Resolve member number → name/surname/email/user_id + store discount %. Flags expired/inactive (no discount, but still loads details). |
| `rss_lookup_barcode`  | Resolve a scanned code → product; captures the **live** `get_price()`. In **marketing mode** (`mode = 'marketing'`) it instead returns the product's **cost price** as every price base and rejects a product with no cost price. |
| `rss_search_products` | Manual product search (camera-free fallback) by name / SKU / barcode. Returns up to 25 **purchasable, in-stock** products (`is_in_stock()`, so backorder is included), **excluding the `online-only` product brand**. Each hit carries the same price fields as `rss_lookup_barcode` so a result feeds straight into the confirm modal. In **marketing mode** each hit's price is the **cost price**; a hit with no cost price is returned flagged `cost_missing` (the client shows it but disables Add). |
| `rss_place_order`     | Validate inputs and create the WooCommerce order.                       |
| `rss_place_marketing_order` | Create an **internal marketing order** — no member, no customer fields, no discounts. Attributed to the `realm.marketing` user, or the first user with the `marketing` role, else errors (no order created). |

## Order creation behaviour

- **Per-line discounts.** Each cart line carries its own discount — either a **percentage** or a **fixed amount** (`disc_type` / `disc_value`, posted per item). New lines default to the member's store discount % (`rmm_member_store_discount`, default 18; 0 for guests/expired); verifying/clearing a member resets every line to that %. Staff override individual lines (e.g. honorary members on old stock). A **fixed amount is money off the inclusive/shelf price** (what the customer saves) and is backed out to ex-VAT via the product's own incl/excl ratio. The discount unit and computation live in `lineMath()` (JS) and `RSS_Ajax::line_amounts()` (PHP) — **keep the two identical** so the cart matches the order to the cent. No coupons; WC records `discount_total` from each line's `subtotal`/`total`. The member is resolved only to attribute the order (even if the discount lapsed).
- Prices are read directly from the product at scan time and **frozen** in the cart table; only quantity/discount recompute afterwards. `rss_lookup_barcode` returns both the ex-VAT (`price_excl`) and inclusive (`price_incl`) unit price.
- **Cart figures are computed on the ex-VAT base** so they match what the order records: the table's Unit Price / Discount / Subtotal columns are ex-VAT (matching the order's `discount_total`), while the footer **Total to Pay is inclusive** (matching the order `total`). The JS rounds each line's subtotal and discounted total independently (mirroring WC) so the totals match the order to the cent. Store runs VAT-inclusive pricing (`prices_include_tax = yes`, MT 18%).
- Customer: hidden `user_id` → `set_customer_id()`; otherwise a guest order. Billing first/last/email from the form (address stays optional, per change item 3).
- Order details textarea → `set_customer_note()`. Tagged with order meta `_rss_sales_system_order = 'yes'` (and `_rss_member_number` when applicable), `created_via = 'rss-sales-system'`, payment method title "In-Store Sale".
- `calculate_totals(true)` (VAT base-country fallback, consistent with change item 12) → `set_date_paid(now)` → `update_status('completed')` (WC reduces stock once on the transition). Result: **Completed + paid**.

### Marketing order creation (`rss_place_marketing_order`)

Same skeleton as `rss_place_order` (server-side recompute, completed + paid), but:

- **Customer** = resolved by `resolve_marketing_user()` (`realm.marketing` login → first `marketing`-role user → null). Null aborts the order with an error. Billing first/last/email are read from that account (display name fallback) since the page has no customer fields.
- **Cost pricing + zero VAT (the defining behaviour).** Every line is charged at the product's **current cost price** (what The Realm paid the supplier), resolved server-side via the theme's `TRM_Cost_Price_DB::get_current_price()` — NOT `get_price()`. The cost is used **verbatim** as the net line total (`subtotal = total = cost × qty`) and **all tax is zeroed**: each `WC_Order_Item_Product` has `set_taxes([])` (line `total_tax`/`subtotal_tax` = 0) and the order calls **`calculate_totals(false)`** so WooCommerce sums the stored line totals **without** re-deriving VAT from the store's tax classes (which would add VAT on this tax-inclusive store). Result: **order tax total = 0, order total = Σ(cost × qty)**, identical to the on-screen "Total to Pay".
  - Cost lookup keys on the **variation id** when the resolved product is a variation, else the product id (matches the rest of the system). A stored `0.00` is a **valid** cost (charged at €0); only a **missing row** (`get_current_price()` returns `null`) is "no cost price".
  - **No cost price → hard error.** A product with no cost price is rejected on the lookup/search (`Cannot add {name} — no cost price recorded.`) so it never enters the cart. `place_marketing_order` **pre-validates every line before `wc_create_order()`** (so a missing cost creates nothing) and `add_items_to_order` re-checks as a guard. If the cost-price system is unavailable (`class_exists('TRM_Cost_Price_DB')` false) all adds are rejected and placement aborts.
- **No discounts:** the shared `add_items_to_order($order, $items, $allow_discounts, $is_marketing)` is called with `false, true`; the marketing branch ignores discounts entirely and prices at cost.
- **Marketing meta:** sets `_rss_marketing_order = 'yes'` and `trm_is_marketing_order = 'yes'` (the theme's `TRM_WC_Hooks::check_marketing_order()` only fires on checkout, which `wc_create_order()` bypasses — so we set it directly to stay inline with the marketing logic). Order-tracker exclusion is already satisfied because the customer carries the `marketing` role. Payment method title is "In-Store Sale (Marketing)".

**Cross-component dependency (plugin → theme).** Marketing pricing calls the child theme's `TRM_Cost_Price_DB` (`get_current_price()`), inverting the usual theme→plugin direction. Guarded with `class_exists` + `method_exists`; if the theme/cost-price system is absent, marketing orders cannot be priced and are cleanly refused (the standard order flow is unaffected).

## Conventions

- Prefix everything new with `rss_` (options/meta/actions) and `RSS_` (classes).
- All AJAX is admin-only and guarded by nonce + `manage_woocommerce`; never add nopriv variants here.
- Don't trust client-posted prices — the place-order endpoint re-resolves products and the member, and recomputes everything server-side.
