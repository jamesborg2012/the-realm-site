# Realm GW Order Tracker

Admin dashboard for **fulfilment tracking of Games Workshop orders** — card-based order list, expandable item table, per-item received/delivered quantity tracking, CSV export for the GW supplier order.

Header: `v1.0.0`, author James Borg.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
realm-gw-order-tracker.php
src/
├── Core.php                         # Bootstrap
├── Admin/
│   ├── Menu.php                     # admin menu registration
│   └── Page.php                     # page shell + date filter
├── Ajax/
│   └── Orders.php                   # all AJAX handlers
└── Services/
    └── OrderQuery.php               # WC_Order_Query wrapper
views/
├── order-card.php                   # rendered per-order card
└── pagination.php
assets/js/admin.js
```

PSR-style folder layout, but **loaded via plain `require`** in `src/Core.php` (no Composer autoloader).

## Admin menu

Top-level — slug `gw-order-tracker`, cap `manage_woocommerce`, position 58.

## AJAX actions

| Action                     | Purpose                                                                 |
|----------------------------|-------------------------------------------------------------------------|
| `gwot_fetch_orders`        | Paginated list of order cards filtered by date range                    |
| `gwot_fetch_order_items`   | Expand a single order → item table with brand / online-only flags       |
| `gwot_export_csv`          | CSV export of aggregated quantities across a date range                 |
| `gwot_get_item_meta`       | Read the GW tracking meta for an order item                             |
| `gwot_update_item_meta`    | Write the GW tracking meta for an order item                            |

## Order item meta (the GW tracking data)

Stored on **WC order items** (not products):

| Meta key             | Type | Meaning                                |
|----------------------|------|----------------------------------------|
| `_gw_ordered_qty`    | int  | Quantity ordered from GW supplier      |
| `_gw_received_qty`   | int  | Quantity received from GW              |
| `_gw_delivered_qty`  | int  | Quantity delivered to the customer     |

## Order filtering rules (in `OrderQuery`)

- Filter by date range (`date_from`, `date_to`).
- **Excludes orders whose customer has the `marketing` role.** Pair this with the theme's `TRM_WC_Hooks::check_marketing_order()` which marks those orders with `trm_is_marketing_order = 'yes'`.

## CSV export rules

- Aggregates quantities across the chosen date range.
- **Excludes products in the `product_brand` taxonomy term `online-only`** — those are fulfilled differently and shouldn't be re-ordered from GW.

> **The `product_brand` taxonomy and the `online-only` term are seeded by a custom CSV importer built for the client (not in this repo).** They aren't registered in any plugin's PHP. Treat them as data, not schema.

## Frontend JS (`assets/js/admin.js`)

- "Fetch orders" button → AJAX paginated card load.
- "Export CSV" → builds query, fetches blob, triggers download.
- Per-card "Expand" → lazy-loads the item table; cell edits POST to `gwot_update_item_meta`.

## Cross-component dependencies

- Implicitly couples to `realm-members-manager`'s coupon-brand split (both depend on the `online-only` brand term).
- Reads the `marketing` user role that's checked by the theme's `TRM_WC_Hooks`.

## Conventions

- Prefix: `gwot_` for AJAX/options, `GwOrderTracker\\` (or rather PSR-style classes under `src/`).
- New admin AJAX → register in `Ajax\\Orders` and add the matching `add_action('wp_ajax_...')` wiring in `Core::init()`.
- Views live in `views/`, rendered by direct `include` from PHP handlers (no central renderer).
