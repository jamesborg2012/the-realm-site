# Realm Stock History

Self-contained, **disposable audit log** of every product stock movement. Records a signed quantity change, the stock level before/after, the movement type, the order (where one is involved), the acting user, an optional note, and a timestamp — then surfaces it on the product editor and a central admin page.

Header: `v1.0.0`, author James Borg. Prefix **`rsh_` / `RSH_`** for everything.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## Non-goals (important)

- **Audit log only.** Nothing may derive, reconcile, or correct actual stock from this table. It is never a source of truth for inventory. The only readers are this plugin's two admin views.
- **No backfill.** History starts at activation (`rsh_tracking_started`). Views state the tracking-start date.
- **Simple products only.** No variation/grouped/bundle handling — records against the product ID given and moves on.
- **Observation only.** Never mutates stock.
- Disposability: **no file outside this plugin may reference an `RSH_*` symbol or the table.** The one sanctioned external touchpoint is `do_action( 'rsh_record_stock_change', $args )`.

## File layout

```
realm-stock-history.php          # header, constants, requires, (de)activation, bootstrap
includes/
├── class-rsh-core.php           # singleton: activation, cron schedule + purge, schema self-heal, logger
├── class-rsh-db.php             # table install/upgrade, record() (single insert path), read queries, purge
├── class-rsh-listeners.php      # WC capture hooks + the rsh_record_stock_change action
└── class-rsh-admin.php          # metabox, central page, shared renderer, AJAX, assets
assets/
├── rsh-admin.js                 # panel loader (vanilla, no deps) — toggle + auto-load + pagination
└── rsh-admin.css
uninstall.php                    # drops the table + options
```

Classes are `require`d from the main file and wired by `RSH_Core::instance()` (instantiated at load, not on a late hook, because listeners must run on every request incl. WP-Cron and the front-end order path).

## DB table

`{prefix}rsh_stock_history`

| Column         | Type                 | Notes                                       |
|----------------|----------------------|---------------------------------------------|
| `id`           | BIGINT UNSIGNED PK   |                                             |
| `product_id`   | BIGINT UNSIGNED      | KEY `product_created(product_id, created_at)` |
| `qty_change`   | INT                  | signed; negative = out                      |
| `stock_before` | INT NULL             | NULL when unknown (e.g. stock mgmt just on) |
| `stock_after`  | INT NULL             |                                             |
| `change_type`  | VARCHAR(20)          | closed set (below)                          |
| `order_id`     | BIGINT UNSIGNED NULL | KEY `order_id`                              |
| `user_id`      | BIGINT UNSIGNED NULL |                                             |
| `note`         | VARCHAR(255) NULL    |                                             |
| `created_at`   | DATETIME             | `current_time('mysql')` (site-local); KEY `created_at` |

- Schema versioned via option **`rsh_db_version`** (`'1.0'`), `dbDelta()`, self-healed by `RSH_DB::maybe_upgrade()` on `admin_init`. Mirrors the theme's cost-price DB-version pattern.
- Option **`rsh_tracking_started`** (`Y-m-d`) set once at activation, never overwritten.
- No transient caching (indexed, admin-only, per-product queries). No CPTs/taxonomies/ACF/Meta Box/post/order meta introduced.

## Change types (closed set)

`sale`, `restock`, `manual`, `import`, `scanner`, `marketing`, `other`. Stored as the raw slug; unknown values coerce to `'other'` in `RSH_DB::record()`. Each has a label + colour badge in `RSH_Admin`; `marketing` is deliberately loud. **Note:** the barcode scanner adjusts stock via WC CRUD (not a direct `_stock` write), so it records as `manual` — the `scanner` type is reserved for future direct-write callers of the public action.

## Capture split (the core rule)

Two mutually-exclusive paths, exactly one row per logical change:

1. **WooCommerce-driven** — captured by this plugin's own listeners:
   - **Order lines** (`sale`/`marketing`/`restock`) via `woocommerce_reduce_order_item_stock` (args `$item, $change{from,to}, $order`) and `woocommerce_restore_order_item_stock` (`$item, $new_stock, $old_stock, $order`). Marketing classified by order meta `trm_is_marketing_order` / `_rss_marketing_order` (HPOS-safe `$order->get_meta()`).
   - **Everything else through WC** (`manual`/`import`, plus barcode-scanner CRUD & programmatic `wc_update_product_stock`) via `woocommerce_product_set_stock`, paired with `woocommerce_product_before_set_stock` to snapshot `stock_before` from the persisted `_stock` (before the write lands). Only fires when stock actually changed, so unchanged saves produce no row.
2. **Direct writes bypassing WC** — captured only when that code fires the public action (below).

**De-duplication:** order reductions call `wc_update_product_stock()`, which *also* fires `woocommerce_product_set_stock`. The product-level recorder is suppressed for the duration of an order stock op via an in-flight flag (`RSH_Listeners::$in_order_stock`), **set** in the `woocommerce_can_(reduce|restore)_order_stock` filters (fire once, before the item loop; only armed when the op is going ahead) and **cleared** in `woocommerce_(reduce|restore)_order_stock` (after the loop). Instance property → cannot leak across requests. Order lines are recorded solely by path 1a; everything else solely by path 1b.

## Public action — `rsh_record_stock_change`

`do_action( 'rsh_record_stock_change', $args )`. Fail-soft (no-op when the plugin is inactive; never throws/warns). Full argument contract is documented in the `RSH_Listeners::on_public_record()` docblock. Summary:

- `product_id` (int, **required**)
- `qty_change` (int, signed) — required unless both `stock_before` & `stock_after` given (then derived)
- `stock_before` / `stock_after` (int|null, optional) — pass `stock_before => null` explicitly to force a NULL "before"
- `change_type` (optional, defaults `'other'`, unknown → `'other'`)
- `order_id` (int|null, optional), `user_id` (int|null, optional — omitted defaults to current user when non-zero), `note` (≤255 chars)

Derivation: given two of qty/before/after, derive the third; all-three-inconsistent → before/after win, qty recomputed; only one of before/after and no qty → **rejected + logged** (would corrupt in/out totals). `RSH_DB::record()` returns insert ID / `false`.

## Admin

- **Metabox** "Stock History" on `product` (normal/high): a single `Show Stock History` `<button>` (`aria-expanded`/`aria-controls`) + AJAX-loaded panel.
- **Central page** WooCommerce → Stock History (`rsh-stock-history`, `manage_woocommerce`): GET search by SKU (exact → straight to product) or name (partial → capped list); selecting one auto-loads the same panel.
- **Shared renderer** `RSH_Admin::render_history_html()` — single-sourced summary (`Stock In / Out / Net / Current / of which marketing` + `Tracking started` + retention notice) and paginated table (Date/Time · Type · Change · Before · After · Order · User · Note; 25/page, newest first). Order links resolved HPOS-safely via `wc_get_order()->get_edit_order_url()`.
- **AJAX** `rsh_fetch_stock_history` (admin only, no `nopriv`): nonce `rsh_stock_history` + `manage_woocommerce` + `current_user_can('edit_post', $product_id)`. One endpoint serves both views.
- Assets enqueued only on the product editor + the Stock History page, versioned with `RSH_VERSION`.

## Cron / retention

- Daily WP-Cron `rsh_purge_stock_history` → `RSH_DB::purge()` deletes rows older than **12 months** (hardcoded), **batched** (5,000/statement, ≤10 iterations/run, remainder to next run). Never unbounded.
- Scheduled at activation and self-healed on `admin_init` (`maybe_schedule_cron`); unscheduled on deactivation. Daily (not monthly) is deliberate — no custom schedule needed, cheap, self-heals within 24h. Retention window is stated in the UI on both views.

## Conventions

- `rsh_` / `RSH_` prefix for all classes, options, transients, hooks, AJAX actions, the table.
- HPOS-safe: all order/order-item access via CRUD; no `get_post_meta()` on orders.
- Logger: `RSH_Core::log()` (WP_DEBUG-guarded `error_log`) — deliberately **not** the theme's `TRM_Core::write_log()` (no theme dependency).
- Uninstall drops the table + options (disposable audit log); deactivation preserves data, only clears the cron.
