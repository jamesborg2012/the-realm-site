# Changes

Backlog of client-requested changes for The Realm Malta.

## Workflow

- One item = one session = direct commits to master (no PR / branch).
- At the start of each session, Claude reads this file and **asks for a brief** on the chosen item before implementing — items below are names only.
- Items move: `Backlog` → `In Progress` (with brief) → `Done` (with commit SHA + 1-line outcome) or `Dropped` (with reason).
- Cross-cutting decisions that should bind future work go to Claude memory, not here.

See [CLAUDE.md](CLAUDE.md) for the architecture map.

---

## Backlog

- [ ] **5. Client mobile number and email address in header**
- [ ] **6. Pre-Order handling**
- [ ] **7. Difference between On Order & In Stock in single product pages**
- [ ] **8. Completed vs Processing handling**
- [ ] **9. Revolut number at checkout for cash payment method**
- [ ] **10. CRON to remove coming soon category when expired**
- [ ] **11. Menu improvements**

## In Progress

_(none)_

## Done

- [x] **4. Search improvements** — replaced the plain header search with a competitor-style live-suggest dropdown and restyled the input itself as a pill with a right-side icon button. Header search override: `woocommerce/product-searchform.php` now wraps the WC widget form in `.trm-live-search` with a `.trm-live-search__panel` listbox; the form is a pill (`border-radius: 999px`, 1px border, focus ring on `:focus-within`); the text "Search" submit became a transparent 32×32 circular button with an inlined SVG magnifier (`screen-reader-text` for a11y); a sibling X clear button (also SVG, `hidden` by default) sits between the input and the submit. Storefront's default left-side magnifier — injected via a `::before` on `.woocommerce-product-search` — is killed with `content: none !important` on the form. AJAX endpoint `TRM_WC_Hooks::handle_live_search` (action `trm_live_search` + `nopriv`, nonce `trm_live_search`) builds a single `WP_Query` for `post_type=product` and uses a one-shot `posts_search` filter to OR-in `_sku LIKE %term%`; the filter is gated on a custom `trm_live_search` query var so it can't leak to unrelated queries, and is removed immediately after the query runs. Results respect the same `_price` visibility gate as `exclude_products_without_price` plus `product_visibility` `exclude-from-search` / `exclude-from-catalog`; ordered by title ASC; capped at `LIVE_SEARCH_LIMIT = 6`. View `assets/views/search/live-search-results.php` renders each card as thumbnail + title (matched substring wrapped in `<mark>` via a multibyte-safe regex, allowed through `wp_kses`) + SKU + `$product->get_price_html()` (so sale strikethrough / member discount formatting is preserved); empty state ("No products found for X"); and a "View all results for X" footer link pointing at `?s=&post_type=product`. JS `assets/js/live-search.js` (jQuery) handles 250 ms debounce, min 2 chars (mirrored as `LIVE_SEARCH_MIN_LEN`), in-flight XHR abort, stale-response discard (compares response `term` to current input), focus/outside-click/ESC dismissal, ArrowUp/Down + Enter keyboard nav, clear-button toggle on input + click-to-clear (aborts XHR, empties panel, refocuses), and arrow-key scroll-into-view targeting the result list. SCSS `assets/scss/live-search.scss` (added to `styles.scss` via `@use`) sizes the panel viewport so ~3 cards are visible before the list scrolls — scrolling moved off `.trm-live-search__panel` and onto `.trm-live-search__list` so the View-all footer stays sticky to the bottom of the panel; panel itself uses `overflow: hidden` to clip the rounded corners cleanly. Localised config (`trmLiveSearch`) lives in `functions.php` alongside the existing `trmAjax` block.

- [x] **3. Make address optional at checkout** — billing address is no longer required at checkout / My Account billing-address edit. `TRM_WC_Hooks` adds two filters: `woocommerce_billing_fields` → `make_billing_address_optional` (controls PHP-side render + server validation, flips `required` to `false` on `billing_country`, `billing_address_1`, `billing_address_2`, `billing_city`, `billing_state`, `billing_postcode`); and `woocommerce_get_country_locale_default` → `make_locale_address_optional` (flips the same fields in the default locale dataset, because `address-i18n.js` re-applies the required asterisk client-side on country change / page load — without this, the PHP filter is visually overwritten for address_1 / city / postcode). Identity fields (first/last name, email) are deliberately untouched. The billing-field filter doesn't touch the shared `woocommerce_default_address_fields` schema, but the locale filter does affect the JS selectors for both `#billing_*` and `#shipping_*` — when shipping is eventually enabled, restore shipping field requirement via a `woocommerce_shipping_fields` filter + JS adjustment (or whatever fits the shipping plugin/config at the time). `update_user_billing_meta_on_order` already skipped empty values, so no change there.
- [x] **2. Make phone optional at registration** — phone is no longer required anywhere a customer signs up or is edited. Theme Account Creation block dropped the `required` attribute and `*` markers on Phone Prefix / Mobile Number; `TRM_AJAX_Hooks::handle_customer_registration` only validates prefix format, the no-leading-`+` rule, and the dup-phone check when a mobile is actually supplied, and only writes `billing_phone` / `realm_phone_prefix` / `realm_mobile_number` user meta in that case (so empty submissions don't store a stray prefix). Realm Members Manager: admin create modal phone label tagged `(optional)` and `create_new_member` only writes `billing_phone` when set; the admin **edit** modal now has an editable, optional Phone Number input wired into `update_member_details` (empty = delete the meta, populated = update it); the public `register_new_member` shortcode form placeholder reads `(optional)` and the handler skips the `billing_phone` write when blank. WC checkout: `TRM_WC_Hooks` adds `woocommerce_billing_fields` + `woocommerce_default_address_fields` filters that flip `billing_phone`/`phone` `required` to `false`, so the checkout + My Account address pages no longer block on a missing phone. `update_user_billing_meta_on_order` already skipped empty values — left untouched.
- [x] **1. Member number fix** — added editable membership number to the admin edit modal in `realm-members-manager`. Field is disabled once a number is set (to protect coupon code logic) and required when empty; server validates non-empty + uniqueness against `rmm_membership_number`. Assigning a number to a previously-numberless member now also creates the STOREDISC + ONLINEONLY coupons (coupon-creation logic extracted into a shared private helper so create + edit flows share it). Edit modal also gained: editable First / Last Name (syncs to `first_name`, `last_name`, `billing_first_name`, `billing_last_name`); a native date picker for expiry bounded to today−1y → end of next year, with stored values normalised to `Y-m-d` on render; and automatic sync of both member coupons' `date_expires` whenever the membership expiry is changed.

## Dropped

_(none)_
