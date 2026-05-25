# Realm GW Excel Uploader

Custom admin tool to **batch-update Games Workshop product metadata via CSV** — SKUs, barcodes, product codes, SSC codes. Owned by the same maintainer as the rest of the realm-* code.

Header (from [bootstrap.php](bootstrap.php)): `v1.0`, author James Borg. Asserts PHP ≥ 8.0 and WordPress ≥ 6.0.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
bootstrap.php
includes/
├── gw-excel-uploader-core.php                    # Realm_Gw_Excel_Uploader_Core (singleton)
└── classes/
    ├── gweu-pages.php                            # GWEU_Pages (admin menu + render)
    └── gweu-upload-handler.php                   # Realm_GWEU_Upload_Handler (CSV parser)
assets/views/admin/gw-excel-uploader-page.php     # the upload form view
```

No autoloader — classes loaded via `require` in the core file.

## Admin menu

`gw-excel-uploader` (cap `manage_options`).

## Form submission

**Plain form POST** (no AJAX). The view's `<form>` submits to the admin page itself; the handler reads `$_FILES`. Nonce + cap check inside the handler.

## What it writes

For each row in the CSV, resolves the WC product by SKU and updates / appends to:

| Meta key                          | Type                  | Source                                   |
|-----------------------------------|-----------------------|------------------------------------------|
| `_product_code`                   | single string         | Theme's GW Product Code field             |
| `_ssc_code`                       | single string         | Theme's GW SSC Code field                 |
| `trm_gw_old_barcodes`             | Meta Box text_list    | Appends previous barcodes (clone field)   |
| `trm_gw_old_product_codes`        | Meta Box text_list    | Appends previous product codes            |
| `trm_gw_old_ssc_codes`            | Meta Box text_list    | Appends previous SSC codes                |

The `trm_gw_old_*` fields are defined by the theme's `TRM_MB_Hooks` (Meta Box panel "Games Workshop Info"). The pattern is: replace the canonical field, archive the previous value into the cloneable history list.

## No DB tables

Uses standard `postmeta` only.

## Cross-component dependencies

- Depends on the theme's `TRM_MB_Hooks` Meta Box registration. If that panel changes its field IDs, this plugin's writes will land on dead keys.
- The canonical `_product_code` / `_ssc_code` fields are also written by `TRM_WC_Hooks::handle_custom_product_import()` during native WC CSV imports — both code paths can produce the same meta. Keep semantics consistent.

## Conventions

- Use the `gweu_` / `GWEU_` prefix for new functions/classes/options.
- Views live in `assets/views/admin/`.
- Keep this plugin synchronous + admin-only — there's no AJAX surface to maintain.
