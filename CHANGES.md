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

- [ ] **2. Make phone optional at registration**
- [ ] **3. Make address optional at checkout**
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

- [x] **1. Member number fix** — added editable membership number to the admin edit modal in `realm-members-manager`. Field is disabled once a number is set (to protect coupon code logic) and required when empty; server validates non-empty + uniqueness against `rmm_membership_number`. Assigning a number to a previously-numberless member now also creates the STOREDISC + ONLINEONLY coupons (coupon-creation logic extracted into a shared private helper so create + edit flows share it). Edit modal also gained: editable First / Last Name (syncs to `first_name`, `last_name`, `billing_first_name`, `billing_last_name`); a native date picker for expiry bounded to today−1y → end of next year, with stored values normalised to `Y-m-d` on render; and automatic sync of both member coupons' `date_expires` whenever the membership expiry is changed.

## Dropped

_(none)_
