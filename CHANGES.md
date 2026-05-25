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

- [ ] **4. Search improvements**
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

- [x] **3. Make address optional at checkout** — billing address is no longer required at checkout / My Account billing-address edit. `TRM_WC_Hooks` adds a new `woocommerce_billing_fields` filter (`make_billing_address_optional`, priority 99) that flips `required` to `false` on `billing_country`, `billing_address_1`, `billing_address_2`, `billing_city`, `billing_state`, `billing_postcode`. Identity fields (first/last name, email) are deliberately untouched. The filter is scoped to `woocommerce_billing_fields` only — the shared `woocommerce_default_address_fields` schema is intentionally not modified, so when shipping is eventually enabled the shipping address remains required by default. `update_user_billing_meta_on_order` already skipped empty values, so no change there.
- [x] **2. Make phone optional at registration** — phone is no longer required anywhere a customer signs up or is edited. Theme Account Creation block dropped the `required` attribute and `*` markers on Phone Prefix / Mobile Number; `TRM_AJAX_Hooks::handle_customer_registration` only validates prefix format, the no-leading-`+` rule, and the dup-phone check when a mobile is actually supplied, and only writes `billing_phone` / `realm_phone_prefix` / `realm_mobile_number` user meta in that case (so empty submissions don't store a stray prefix). Realm Members Manager: admin create modal phone label tagged `(optional)` and `create_new_member` only writes `billing_phone` when set; the admin **edit** modal now has an editable, optional Phone Number input wired into `update_member_details` (empty = delete the meta, populated = update it); the public `register_new_member` shortcode form placeholder reads `(optional)` and the handler skips the `billing_phone` write when blank. WC checkout: `TRM_WC_Hooks` adds `woocommerce_billing_fields` + `woocommerce_default_address_fields` filters that flip `billing_phone`/`phone` `required` to `false`, so the checkout + My Account address pages no longer block on a missing phone. `update_user_billing_meta_on_order` already skipped empty values — left untouched.
- [x] **1. Member number fix** — added editable membership number to the admin edit modal in `realm-members-manager`. Field is disabled once a number is set (to protect coupon code logic) and required when empty; server validates non-empty + uniqueness against `rmm_membership_number`. Assigning a number to a previously-numberless member now also creates the STOREDISC + ONLINEONLY coupons (coupon-creation logic extracted into a shared private helper so create + edit flows share it). Edit modal also gained: editable First / Last Name (syncs to `first_name`, `last_name`, `billing_first_name`, `billing_last_name`); a native date picker for expiry bounded to today−1y → end of next year, with stored values normalised to `Y-m-d` on render; and automatic sync of both member coupons' `date_expires` whenever the membership expiry is changed.

## Dropped

_(none)_
