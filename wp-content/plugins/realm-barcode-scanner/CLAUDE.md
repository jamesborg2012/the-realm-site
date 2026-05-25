# Realm Barcode Scanner

Custom plugin that turns a phone/laptop camera into a barcode scanner for two purposes:

1. **Admin stock adjustment** — staff scan products and increment/set stock.
2. **Frontend cart-by-scan** — customers (in-store kiosk or own device) scan products to add them to the cart.

Header: `v0.1.0`, author James Borg.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
realm-barcode-scanner.php       # one big class — see below
assets/
├── rbs.js                      # admin stock-adjust UI
└── rbs-cart.js                 # frontend cart shortcode UI
```

Everything is in one class: `RealmBarcodeScanner` ([realm-barcode-scanner.php](realm-barcode-scanner.php)).

## Custom DB table

`{prefix}barcode_lookup`

| Column      | Type                  | Notes                  |
|-------------|-----------------------|------------------------|
| `id`        | BIGINT PK             |                        |
| `barcode`   | string                | UNIQUE                 |
| `product_id`| BIGINT                | indexed                |
| `created_at`| DATETIME              |                        |
| `last_seen` | DATETIME              |                        |

Important: **this table is also read by the theme's Cost Prices feature** (`TRM_Cost_Price_DB::get_product_id_by_barcode()`). If this plugin is deactivated, barcode → product resolution silently no-ops there (guarded by `SHOW TABLES LIKE`).

## Admin menu

`rbs-scan` (cap `manage_options`) — the stock-adjustment UI page.

## Shortcode

`[rbs_add_to_cart_scanner]` — frontend cart scanner UI (uses `rbs-cart.js`).

## AJAX

| Action              | Auth      | Purpose                            |
|---------------------|-----------|------------------------------------|
| `rbs_add_to_cart`   | both      | Add product to WC cart by barcode  |

Fires the standard `woocommerce_ajax_added_to_cart` action after insertion so the mini-cart updates.

## REST API (namespace `rbs/v1`)

| Route                  | Method | Notes                                       |
|------------------------|--------|---------------------------------------------|
| `/lookup`              | POST   | Resolve barcode → product (auth required)   |
| `/adjust`              | POST   | Adjust stock (`mode: inc | set`)            |
| `/lookup-public`       | POST   | Public barcode lookup (used by cart UI)     |

## Frontend JS

- **`rbs.js`** (admin stock adjust): uses native [`BarcodeDetector`](https://developer.mozilla.org/en-US/docs/Web/API/BarcodeDetector) for EAN-13 / QR / Code-128 where available, falls back to Quagga. Displays product name + SKU + current stock; user picks `increment` or `set` mode.
- **`rbs-cart.js`** (frontend cart): same detection chain; on scan it shows product price + qty input, then fires `rbs_add_to_cart`.

## Cross-component dependencies

- The theme reads `wp_barcode_lookup` from `TRM_Cost_Price_DB`. Don't drop the table without coordinating.
- A separate 3rd-party plugin (`barcode-scanner-lite-pos-…`) may also touch barcode data — verify any schema changes don't conflict.

## Conventions

- Use the `rbs_` prefix for new actions/REST routes/JS globals.
- New REST routes go under the `rbs/v1` namespace.
- Capability gate for write operations: `manage_options`. Public-facing reads use `lookup-public`.
