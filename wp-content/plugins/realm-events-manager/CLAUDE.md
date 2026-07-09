# Realm Events Manager

Custom plugin for the in-store gaming events calendar.

Header (from [realm-events-manager.php](realm-events-manager.php)): `v1.1.0`, author James Borg.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
realm-events-manager.php
includes/
├── class-trem-plugin.php                       # TREM_Plugin (singleton bootstrap)
├── class-trem-calendar.php                     # TREM_Calendar (rendering + AJAX)
├── class-trem-block-calendar.php               # TREM_Block_Calendar (Events Calendar ACF block + assets)
├── class-trem-block-upcoming-events.php        # TREM_Block_Upcoming_Events (Upcoming Events ACF block + fields)
├── class-trem-registration-ajax.php            # TREM_Registration_Ajax (front-end registration submit)
├── class-trem-registrations-admin.php          # TREM_Registrations_Admin (event-editor meta box + edit/delete AJAX)
├── models/
│   ├── class-trem-post-type-event.php          # event CPT
│   ├── class-trem-event-fields.php             # TREM_Event_Fields (ACF "Event Details" + storage-format filters)
│   └── class-trem-event-registrations.php      # TREM_Event_Registrations (registrations table + seat-count data layer)
├── taxonomies/
│   └── class-trem-taxonomies.php               # event_category + game_system
└── views/
    ├── calendar-view.php
    ├── single-event-view.php
    └── upcoming-events-view.php
assets/js/trem-calendar.js
assets/js/trem-registrations-admin.js
assets/css/trem-calendar.css
assets/css/trem-upcoming-events.css
assets/css/trem-registrations-admin.css
```

All classes live under the `TREM_` prefix. They are loaded by a
`spl_autoload_register` autoloader in [realm-events-manager.php](realm-events-manager.php)
(maps `TREM_Foo_Bar` → `class-trem-foo-bar.php` under `includes/`,
`includes/models/`, `includes/taxonomies/`) — **not** manual `require`s. A new
class just needs the matching filename in one of those dirs plus an init call
from `TREM_Plugin::__construct()`.

## Custom post type

`event` (slug `events`)
- Supports: `title`, `editor`, `thumbnail`.
- `show_in_rest: true` → block editor available for this CPT (note: blocks are disabled site-wide via Classic Editor, so the REST flag mostly enables WP_REST endpoints).

## Taxonomies (both hierarchical, REST-enabled)

- `event_category` (slug `event-category`)
- `game_system` (slug `game-system`)

Both attached to the `event` CPT.

## Event Details fields (ACF)

Registered by `TREM_Event_Fields` (`includes/models/class-trem-event-fields.php`) via `acf_add_local_field_group()` on `acf/init` — group `group_trem_event_details`, located to `post_type == event`. **Replaces the old Meta Box panel** (`TREM_Meta_Event`, removed).

| Field name              | ACF type      | Meta key (DB)          |
|-------------------------|---------------|------------------------|
| `event_date`            | date_picker   | `event_date`           |
| `event_start_time`      | time_picker   | `event_start_time`     |
| `event_participants`    | number        | `event_participants`   |
| `event_location`        | text          | `event_location`       |
| `event_register_link`   | url           | `event_register_link`  |
| `event_enable_website_registration` | true_false (`ui:1`) | `event_enable_website_registration` |
| `event_seats_taken`     | number (`disabled:1`, derived) | _(never stored)_ |
| `event_banner`          | image (`return_format: id`) | `event_banner` |

**On-site registration fields (item 29).** `event_enable_website_registration` (key `field_trem_event_enable_website_registration`) is a `true_false` toggle following the `event_show_timer` pattern; when on, `event_register_link` is ignored and the front-end/admin registration flow activates. `event_seats_taken` (key `field_trem_event_seats_taken`) is a **display-only** number field (`disabled => 1`, conditional-logic-gated on the toggle) whose value is **derived live** via `acf/load_value` → `TREM_Event_Registrations::seats_taken()` and **never stored** — the field is disabled so the browser never POSTs it and there is deliberately no `acf/update_value`.

**Storage-format compatibility (important).** ACF's pickers natively store `Ymd` / `H:i:s`, but existing data and every consumer (`get_post_meta`, the `meta_query type=DATE` in the calendar + upcoming-events queries, `orderby meta_value`, the AJAX modal) expect the legacy Meta Box formats **`event_date` = `Y-m-d`** and **`event_start_time` = `H:i`**. `TREM_Event_Fields` keeps the DB in those formats via `acf/update_value` + `acf/load_value` filters on the date/time field keys, and sets `return_format` to match. Result: `get_field()` and `get_post_meta()` both return the legacy format, so no data migration was needed and all existing consumers keep working. **Don't change these formats without auditing those consumers.**

## ACF block — "Events Calendar"

Registered by `TREM_Block_Calendar` (`class-trem-block-calendar.php`) via `acf_register_block_type` on `acf/init`:

- Name: `events-calendar` (full block name `acf/events-calendar`)
- Category: `trm-blocks` (same category as the theme's Account Creation block)
- Icon: `calendar-alt`, `mode: preview`, `supports.multiple: false`
- `render_callback` → `TREM_Block_Calendar::render_block()` → `TREM_Calendar::render_calendar_view()` for the current month/year.
- `enqueue_assets` → enqueues `trem-calendar.css` + `trem-calendar-js` (localised as `tremCalendar`) wherever the block is placed, including the editor preview.

Drop the block onto a page in the block editor to render the calendar. **Replaces the old `[realm_events_calendar]` shortcode** (removed). Rendering/query logic lives in `TREM_Calendar` (`class-trem-calendar.php`). View: [includes/views/calendar-view.php](includes/views/calendar-view.php).

Note: the block editor is disabled site-wide (Classic Editor + Disable Gutenberg), so the block is only insertable where Gutenberg is enabled for the page.

## ACF block — "Upcoming Events"

Registered by `TREM_Block_Upcoming_Events` (`class-trem-block-upcoming-events.php`) via `acf_register_block_type` on `acf/init`. A Google-Calendar-style agenda widget listing the next N upcoming events (event_date today or later, ordered ascending). Can be placed anywhere the block editor is available.

- Name: `upcoming-events` (full block name `acf/upcoming-events`), category `trm-blocks`, icon `list-view`, `mode: preview`.
- **ACF fields** registered in PHP via `acf_add_local_field_group()` (group `group_trem_upcoming_events`, located to `block == acf/upcoming-events`) — **not** acf-json:
  - `heading` (text, optional) — heading shown above the list.
  - `number_of_events` (number, default 5, required) — how many events to show.
  - `filter_categories` (taxonomy `event_category`, multi-select, `return_format: id`) — optional.
  - `filter_game_systems` (taxonomy `game_system`, multi-select, `return_format: id`) — optional.
- Query: `WP_Query` on `event`, `meta_query` `event_date >= current_time('Y-m-d')` (DATE compare), `orderby meta_value ASC`. When both taxonomy filters are set they combine with **`relation: AND`** (an event must match a selected category *and* a selected game system). Each filter is optional; empty = no restriction.
- `render_callback` → `TREM_Block_Upcoming_Events::render_block()` → [includes/views/upcoming-events-view.php](includes/views/upcoming-events-view.php).
- `enqueue_assets` → `trem-upcoming-events.css` (no JS; the list is static).

## AJAX (both `nopriv`-enabled)

Registered by `TREM_Calendar::init()`:

| Action                | Returns                                                |
|-----------------------|--------------------------------------------------------|
| `trem_load_calendar`  | Calendar HTML for a given month/year (nav)             |
| `trem_load_event`     | Single-event detail HTML for a modal                   |

Registration AJAX (item 29):

| Action                              | Auth              | Purpose                                                        |
|-------------------------------------|-------------------|----------------------------------------------------------------|
| `trem_register_for_event` (+nopriv) | nonce `trem_register_for_event` | Front-end seat booking — validate → dup-email guard → last-seat re-count → insert → emails. `TREM_Registration_Ajax`. |
| `trem_admin_update_registration`    | nonce `trem_admin_registrations` + `current_user_can('edit_post', $event_id)` | Inline-edit a registration row. `TREM_Registrations_Admin`. |
| `trem_admin_delete_registration`    | same as above     | Delete one registration row (frees a seat via the live count). |

## On-site event registration (item 29)

Opt-in per event: when `event_enable_website_registration` is on, shoppers book seats through a modal on the event page instead of the external link, and the client manages the roster from the event editor. When off, nothing here runs — the external `event_register_link` button behaves exactly as before.

- **Table** `{$wpdb->prefix}trem_event_registrations` — one row per registration, owned by `TREM_Event_Registrations`:
  `id` PK, `event_post_id` (indexed), `first_name`, `last_name`, `phone` (nullable), `email`, `created_at DATETIME`. Indexes: `KEY event_post_id (event_post_id)` (seat count/gate) and `KEY event_email (event_post_id, email)` (duplicate-email guard). `created_at` is stored in **site-local** time (`current_time('mysql')`) so the admin "Registered On" column renders without conversion.
- **DB versioning:** new `trem_db_version` option (this is the plugin's first DB table). `TREM_Event_Registrations::maybe_install_db()` runs on `register_activation_hook` and `admin_init`, `dbDelta`-ing the table when the code `DB_VERSION` (`1.0.0`) is newer — same self-heal pattern as the theme's cost-price table.
- **Count-derived seats — one source of truth.** There is **no stored seat counter**. `TREM_Event_Registrations::seats_taken($event_id)` is always `SELECT COUNT(*) WHERE event_post_id = %d`; `seat_limit()` reads the `event_participants` meta; `seats_available_remaining()` = `max(0, limit − taken)` (0 when the limit is unset/0 → no registration possible → "fully booked"). The front end, the ACF "Seats Taken" display, the fully-booked gate, and the server-side re-check all call these — so deleting a registration frees a seat automatically with nothing to decrement.
- **Front-end submit** (`TREM_Registration_Ajax::handle`, `trem_register_for_event` +nopriv): nonce → sanitise/validate (shared `TREM_Event_Registrations::validate_fields()`) → **duplicate-email guard** (case-insensitive per event) → **last-seat re-count** (final gate before insert) → `$wpdb->insert()` (parameterised) → two `wp_mail()`s (admin + registrant, event title from `get_the_title()`). Email failure is logged (WP_DEBUG only) and never rolls back the booking.
- **Admin manager** (`TREM_Registrations_Admin`): a meta box on the `event` editor (`context => 'normal'`, `low` priority, under the ACF UI), rendered only when registration is enabled. Table columns First/Surname/Phone/Email/Registered On + inline Edit / Delete over AJAX. Both handlers check `current_user_can('edit_post', $event_id)` (per-event, resolved from the row). Assets (`assets/js/trem-registrations-admin.js`, `assets/css/trem-registrations-admin.css`) enqueue only on the `event` post edit screen.
- **Theme side:** the modal + fully-booked state live in the child theme's [single-event.php](../../themes/the-realm-malta/single-event.php); the front-end JS is the theme's `assets/js/trm-event-registration.js` and styling is `assets/scss/event-registration.scss` (compiled into `layout.css`). The theme guards `class_exists('TREM_Event_Registrations')` and degrades to the external-link button if the plugin is unavailable.

## Conventions

- Use the `TREM_` prefix for new classes, `trem_` for meta/AJAX/option keys.
- Models go in `includes/models/`, taxonomies in `includes/taxonomies/`, views in `includes/views/`.
- Front-end UI is an ACF block; AJAX actions follow the `trem_*` pattern with paired nopriv handlers.

## Single event page (front-end)

The single `event` view lives in the **child theme**, not the plugin: [wp-content/themes/the-realm-malta/single-event.php](../../themes/the-realm-malta/single-event.php). It mirrors Storefront's `single.php`, reads the ACF fields via `get_field()` (banner, date, time, location, participants, register link), renders `event_category` + `game_system` terms, and shows a "Register" button (new tab, `rel="noopener noreferrer external nofollow"`) when `event_register_link` is set. **When `event_enable_website_registration` is on** it instead renders the on-site registration modal (or an "Event is Fully Booked" `<h3>` when no seats remain), ignoring the external link — see the registration section above. Styling: `assets/scss/single-event.scss` + `assets/scss/event-registration.scss` (both compiled into `layout.css`). The AJAX modal ([includes/views/single-event-view.php](includes/views/single-event-view.php)) is separate and still uses `get_post_meta`.

## Cross-component notes

This plugin is largely self-contained — no direct dependency on other realm-* plugins or theme classes. The crossovers: events display on theme templates (`single-event.php` + `assets/scss/` in the child theme), and the ACF fields depend on ACF Pro (also used by the theme's Account Creation block).
