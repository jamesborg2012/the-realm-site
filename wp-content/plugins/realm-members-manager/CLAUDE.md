# Realm Members Manager

Custom plugin that owns the **member registration, member data, discounts, pricing tiers, and the checkout integration** for paid members of The Realm Malta.

Header (from [bootstrap.php](bootstrap.php)): `v1.1`, author James Borg. Asserts PHP ≥ 8.0 and WordPress ≥ 6.0.

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
    ├── wc-hooks-handler.php                # RMM_WC_Hooks_Handler (checkout + member discount)
    └── rmm-account-handler.php             # RMM_Account_Handler (My Account "Membership" tab)
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
        └── partials/
            ├── checkout/members-number-form.php
            └── account/membership.php
```

No autoloader; `realm-members-manager-core.php` `require`s each class. No custom DB tables — all member data lives in WP user meta. **Member discounts are applied directly to the cart (item 23); WC coupons are no longer used** (see below).

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
| `rmm_membership_store_coupon`     | coupon post ID (int)                                | **Legacy** — no longer written (coupons retired, item 23)   |
| `rmm_membership_online_coupon`    | coupon post ID (int)                                | **Legacy** — no longer written                              |
| `billing_*`                       | standard WC                                         | Checkout + admin create flow                                |

The theme also writes `realm_membership_number` (legacy duplicate). When changing membership-number logic, update both keys.

Order meta stamped on member orders (by `RMM_WC_Hooks_Handler`): `_rmm_member_discount_applied = 'yes'` and `_rmm_member_number`.

## Member discount — direct cart discount (item 23)

**Coupons were retired.** Each member gets a straight per-line discount applied by `RMM_WC_Hooks_Handler` on `woocommerce_before_calculate_totals`: every cart line's price is set to `base × (1 − pct)`, where `pct` is `rmm_member_online_only_discount` for products in the `online-only` `product_brand` term and `rmm_member_store_discount` for everything else. The base price is read fresh from `_price` each pass so repeated calculations never compound. **Per-line pricing (not a negative fee)** because the store is tax-inclusive with mixed VAT rates (Standard 18% / Reduced 5% / Zero 0%) — WooCommerce taxes the discounted inclusive price directly, keeping VAT exact; a fee mis-reconstructs it. Mirrors how `realm-sales-system` discounts backend orders.

- **Who gets it:** an active, unexpired member — resolved from the logged-in user (auto-applied) or, for guests, the `member_user_id` recorded in the WC session by the member-number form.
- **Cart/checkout display:** the pre-discount prices show on the lines + a **"Member Discount −€X"** row in the totals; the mini-cart shows the real discounted figures instead.
- **Orders & emails:** on `woocommerce_checkout_create_order_line_item` the original price is stored as the line **subtotal** (total stays the charged amount), and a **"Member Discount"** row is injected into `woocommerce_get_order_item_totals` — so the My Account order view and emails show original prices + the saving + discounted total.

**Existing coupons are left untouched** but nothing creates new ones. The old two-coupons-per-member helpers are retained as no-ops (`RMM_Admin_Ajax_Handler::create_member_coupons()`), and the theme's `storedisc`/`onlineonly` message rewrite still exists but is dormant for members.

## Shortcode

`[realm_membership_form]` → renders [assets/views/public/forms/new-member-form.php](assets/views/public/forms/new-member-form.php).

Note: the theme's Account Creation block (`parts/blocks/block-account-creation.php`) is a **separate** flow — it talks to the theme's `realm_register_customer` AJAX, not to this plugin's `register_new_member`. Both exist; pick deliberately when wiring new pages.

## AJAX actions

| Action                          | Auth          | Handler                                |
|---------------------------------|---------------|----------------------------------------|
| `load_member_data`              | logged-in     | `RMM_Admin_Ajax_Handler` (modal load)  |
| `create_new_member`             | logged-in     | `RMM_Admin_Ajax_Handler` (admin add)   |
| `update_member_details`         | logged-in     | `RMM_Admin_Ajax_Handler` (edit)        |
| `apply_membership_number`       | both          | `RMM_Ajax_Handler` (validates + records member in WC session; discount applied by the pricing hook) |
| `register_new_member`           | both          | `RMM_Ajax_Handler` (public form)       |

The My Account "Membership" tab uses **nonce-protected form posts** (not AJAX) handled by `RMM_Account_Handler::handle_form_submission()` on `template_redirect`.

## WooCommerce integrations (`RMM_WC_Hooks_Handler`)

- `woocommerce_before_checkout_form` — outputs [members-number-form.php](assets/views/public/partials/checkout/members-number-form.php). Shows an "applied" confirmation for an active logged-in member, else the member-number input for guests.
- `woocommerce_before_calculate_totals` — the per-line member discount (see **Member discount** above).
- `woocommerce_cart_item_price` / `_item_subtotal` / `woocommerce_cart_subtotal` + `woocommerce_cart_totals_before_order_total` / `woocommerce_review_order_before_order_total` — cart/checkout discount display (original prices + "Member Discount" row); suppressed inside the mini-cart via a `woocommerce_before/after_mini_cart` flag.
- `woocommerce_checkout_create_order_line_item` + `woocommerce_get_order_item_totals` — records the saving on the order so it shows in the My Account order view + emails.
- `woocommerce_checkout_get_value` (filter) — prefills billing fields when a member number matches an existing user.
- `woocommerce_checkout_create_order` — assigns the order's customer to the matched member user ID (so the order ends up under the right account).

## My Account "Membership" tab (`RMM_Account_Handler`)

Registers a `membership` My Account endpoint + menu item (before Logout), rendered from [account/membership.php](assets/views/public/partials/account/membership.php). Active members see their card; pending (`review`/`new`) see "under review"; expired members see a renew prompt; non-members get **Become a Member** (→ `rmm_membership_status = 'review'`) and an **"Already have a membership number?"** linking form (conflict-checked against other accounts, then stores the number on both `rmm_`/`realm_` keys + sets `review`). Rewrite rules are flushed once via the `rmm_membership_endpoint_flushed` option.

## Settings (Settings API)

Options registered by `RMM_Settings_Handler`:

| Option                              | Default | Used by                                                          |
|-------------------------------------|---------|------------------------------------------------------------------|
| `rmm_3_months_membership`           | –       | Theme account-creation block (price display)                     |
| `rmm_6_months_membership`           | –       | Same                                                             |
| `rmm_9_months_membership`           | –       | Same                                                             |
| `rmm_12_months_membership`          | –       | Same                                                             |
| `rmm_member_store_discount`         | 18      | Member % off all products except `online-only` (direct cart discount) |
| `rmm_member_online_only_discount`   | 8       | Member % off `online-only`-brand products                        |
| `rmm_guest_discount`                | –       | Guest discount logic                                             |

The theme picks the active tier as `rmm_{N}_months_membership` where `N` is months-remaining-this-year rounded to the nearest 3 (1 or 2 → 12).

## Cross-component dependencies

- Theme reads `rmm_membership_number` for duplicate-checks on registration.
- Theme writes `rmm_membership_status = 'review'` after a fresh account opts in for membership (same status the My Account "Membership" tab sets).
- `realm-gw-order-tracker` uses the same `product_brand: online-only` taxonomy term that gates the store/online-only discount split.
- The `online-only` brand term itself is seeded by a **custom client-side CSV importer** (not in this repo).

## Conventions

- Prefix everything new with `rmm_` for options/meta, and `RMM_` for classes.
- All admin AJAX handlers live in `RMM_Admin_Ajax_Handler`; frontend in `RMM_Ajax_Handler`. Don't mix.
- Views are rendered via core helpers in `Realm_Members_Manager_Core`; pass variables explicitly.
- Member discounts are applied directly (per-line pricing), not via coupons. The theme's `storedisc`/`onlineonly` coupon-message rewrite still exists for any legacy coupons but isn't part of the live member flow.
