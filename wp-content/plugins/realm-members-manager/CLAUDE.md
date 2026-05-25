# Realm Members Manager

Custom plugin that owns the **member registration, member data, discounts, pricing tiers, and the checkout integration** for paid members of The Realm Malta.

Header (from [bootstrap.php](bootstrap.php)): `v1.0`, author James Borg. Asserts PHP ≥ 8.0 and WordPress ≥ 6.0.

Root-level context lives in the [project CLAUDE.md](../../../CLAUDE.md); this file is the in-plugin map.

## File layout

```
bootstrap.php
includes/
├── realm-members-manager-core.php          # Realm_Members_Manager_Core (singleton)
└── classes/
    ├── members-manager.php                 # Members_Manager (admin dashboard)
    ├── admin-ajax-handler.php              # RMM_Admin_Ajax_Handler (admin AJAX)
    ├── rmm-ajax-handler.php                # RMM_Ajax_Handler (frontend AJAX)
    ├── rmm-shortcodes-handler.php          # RMM_Shortcodes_Handler
    ├── rmm-settings-handler.php            # RMM_Settings_Handler (Settings API)
    ├── rmm-upload-handler.php              # RMM_Upload_Handler (bulk CSV import)
    └── wc-hooks-handler.php                # RMM_WC_Hooks_Handler (checkout integration)
assets/
├── js/admin-scripts.js
├── js/rmm-scripts.js
└── views/
    ├── admin/
    │   ├── members-manager-landing-page.php
    │   ├── bulk-import-members-page.php
    │   ├── manage-membership-pricing-page.php
    │   ├── manage-member-discount-page.php
    │   └── partials/
    │       ├── member-manage-modal-content.php
    │       └── user-members-meta.php
    └── public/
        ├── forms/new-member-form.php
        └── partials/checkout/members-number-form.php
```

No autoloader; `realm-members-manager-core.php` `require`s each class. No custom DB tables — all member data lives in WP user meta and WC coupons.

## Admin menus

| Slug                                  | Capability         | Page                         |
|---------------------------------------|--------------------|------------------------------|
| `realm-members`                       | `manage_woocommerce` | Members list / manage modal  |
| `rmm-import-members`                  | `manage_woocommerce` | Bulk CSV import              |
| `rmm-manage-membership-pricing`       | `manage_options`     | Pricing tiers (3/6/9/12 mo)  |
| `rmm-manage-member-discount`          | `manage_options`     | Discount % settings          |

## User meta keys (canonical)

| Meta key                          | Values / format                                     | Written by                                                  |
|-----------------------------------|-----------------------------------------------------|-------------------------------------------------------------|
| `rmm_membership_status`           | `new` / `active` / `not_active` / `review`          | Admin AJAX + theme `realm_apply_membership` (`'review'`)    |
| `rmm_membership_number`           | unique string                                       | Admin AJAX + bulk import + theme registration               |
| `rmm_membership_expire`           | `Y-m-d`                                             | Admin AJAX + bulk import                                    |
| `rmm_membership_expires`          | `Y-m-d` (legacy/duplicate key still in places)      | Same                                                        |
| `rmm_membership_store_coupon`     | coupon post ID (int)                                | Bulk import + `create_new_member`                            |
| `rmm_membership_online_coupon`    | coupon post ID (int)                                | Same                                                        |
| `billing_*`                       | standard WC                                         | Checkout + admin create flow                                |

The theme also writes `realm_membership_number` (legacy duplicate). When changing membership-number logic, update both keys.

## Coupons (the convention)

Bulk import (and `create_new_member`) creates **two coupons per member**:

- **Store coupon** — code contains `storedisc` — excludes products in the `online-only` `product_brand` term.
- **Online-only coupon** — code contains `onlineonly` — limited to that brand.

The theme's `TRM_WC_Hooks::update_coupon_message_member()` rewrites the apply/remove notice for any coupon whose code matches those substrings → "Member Discount Applied!/Removed!". **Don't rename those substrings without updating the theme.**

Discount percentages come from the options below.

## Shortcode

`[realm_membership_form]` → renders [assets/views/public/forms/new-member-form.php](assets/views/public/forms/new-member-form.php).

Note: the theme's Account Creation block (`parts/blocks/block-account-creation.php`) is a **separate** flow — it talks to the theme's `realm_register_customer` AJAX, not to this plugin's `register_new_member`. Both exist; pick deliberately when wiring new pages.

## AJAX actions

| Action                          | Auth          | Handler                                |
|---------------------------------|---------------|----------------------------------------|
| `load_member_data`              | logged-in     | `RMM_Admin_Ajax_Handler` (modal load)  |
| `create_new_member`             | logged-in     | `RMM_Admin_Ajax_Handler` (admin add)   |
| `update_member_details`         | logged-in     | `RMM_Admin_Ajax_Handler` (edit)        |
| `apply_membership_number`       | both          | `RMM_Ajax_Handler` (checkout discount) |
| `register_new_member`           | both          | `RMM_Ajax_Handler` (public form)       |

## WooCommerce integrations (`RMM_WC_Hooks_Handler`)

- `woocommerce_before_checkout_form` — outputs [members-number-form.php](assets/views/public/partials/checkout/members-number-form.php). The member-number input lets a logged-out customer apply their member discount before paying.
- `woocommerce_checkout_get_value` (filter) — prefills billing fields when a member number matches an existing user.
- `woocommerce_checkout_create_order` — assigns the order's customer to the matched member user ID (so the order ends up under the right account).

## Settings (Settings API)

Options registered by `RMM_Settings_Handler`:

| Option                              | Default | Used by                                                          |
|-------------------------------------|---------|------------------------------------------------------------------|
| `rmm_3_months_membership`           | –       | Theme account-creation block (price display)                     |
| `rmm_6_months_membership`           | –       | Same                                                             |
| `rmm_9_months_membership`           | –       | Same                                                             |
| `rmm_12_months_membership`          | –       | Same                                                             |
| `rmm_member_store_discount`         | 18      | Coupon amount for member store coupons                           |
| `rmm_member_online_only_discount`   | 8       | Coupon amount for member online-only coupons                     |
| `rmm_guest_discount`                | –       | Guest discount logic                                             |

The theme picks the active tier as `rmm_{N}_months_membership` where `N` is months-remaining-this-year rounded to the nearest 3 (1 or 2 → 12).

## Cross-component dependencies

- Theme reads `rmm_membership_number` for duplicate-checks on registration.
- Theme writes `rmm_membership_status = 'review'` after a fresh account opts in for membership.
- `realm-gw-order-tracker` uses the same `product_brand: online-only` taxonomy term that gates the coupon split.
- The `online-only` brand term itself is seeded by a **custom client-side CSV importer** (not in this repo).

## Conventions

- Prefix everything new with `rmm_` for options/meta, and `RMM_` for classes.
- All admin AJAX handlers live in `RMM_Admin_Ajax_Handler`; frontend in `RMM_Ajax_Handler`. Don't mix.
- Views are rendered via core helpers in `Realm_Members_Manager_Core`; pass variables explicitly.
- Don't touch coupon code substrings (`storedisc`, `onlineonly`) without updating `TRM_WC_Hooks` in the theme.
