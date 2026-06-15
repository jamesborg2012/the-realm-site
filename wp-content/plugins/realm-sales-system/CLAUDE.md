# Realm Sales System

Custom plugin providing a **backend order-placing module** ("Sales System") so staff can ring up in-store sales without driving the front-end checkout. Member lookup, camera barcode scanning, member discounts, and one-click WooCommerce order creation.

Header: `v0.1.0`, author James Borg. See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

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
└── new-order.php               # New Order UI
assets/
├── new-order.js                # cart state, camera scanner, confirm modal, live totals
└── new-order.css
```

No custom DB tables. No autoloader — `realm-sales-system.php` `require`s the two classes.

## Admin menu (`RSS_Core`, cap `manage_woocommerce`)

| Slug                | Page                                              |
|---------------------|---------------------------------------------------|
| `rss-sales-system`  | Landing (placeholder text, to be amended)         |
| `rss-new-order`     | New Order — the order-placing UI                  |

Assets enqueue only on the New Order screen (hook `rss-sales-system_page_rss-new-order`); Quagga loaded from cdn (unpkg), same source as `realm-barcode-scanner`.

## AJAX (admin-only, no nopriv; nonce `rss_sales_system`, cap `manage_woocommerce`)

| Action               | Purpose                                                                 |
|----------------------|-------------------------------------------------------------------------|
| `rss_verify_member`  | Resolve member number → name/surname/email/user_id + store discount %. Flags expired/inactive (no discount, but still loads details). |
| `rss_lookup_barcode` | Resolve a scanned code → product; captures the **live** `get_price()`.  |
| `rss_place_order`    | Validate inputs and create the WooCommerce order.                       |

## Order creation behaviour

- Member discount = **store discount only** (`rmm_member_store_discount`, default 18), applied to **all** line items when the member is active and not expired (online-only brand split is intentionally ignored for in-store sales). Applied per line by setting line `total = subtotal × (1 − pct)`, so WC records `discount_total` with no coupon side effects.
- Prices are read directly from the product at scan time and **frozen** in the cart table; only quantity/discount recompute afterwards.
- Customer: hidden `user_id` → `set_customer_id()`; otherwise a guest order. Billing first/last/email from the form (address stays optional, per change item 3).
- Order details textarea → `set_customer_note()`. Tagged with order meta `_rss_sales_system_order = 'yes'` (and `_rss_member_number` when applicable), `created_via = 'rss-sales-system'`, payment method title "In-Store Sale".
- `calculate_totals(true)` (VAT base-country fallback, consistent with change item 12) → `set_date_paid(now)` → `update_status('completed')` (WC reduces stock once on the transition). Result: **Completed + paid**.

## Conventions

- Prefix everything new with `rss_` (options/meta/actions) and `RSS_` (classes).
- All AJAX is admin-only and guarded by nonce + `manage_woocommerce`; never add nopriv variants here.
- Don't trust client-posted prices — the place-order endpoint re-resolves products and the member, and recomputes everything server-side.
