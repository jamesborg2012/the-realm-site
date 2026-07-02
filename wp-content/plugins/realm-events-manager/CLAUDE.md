# Realm Events Manager

Custom plugin for the in-store gaming events calendar.

Header (from [realm-events-manager.php](realm-events-manager.php)): `v1.0.0`, author James Borg.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
realm-events-manager.php
includes/
├── class-trem-plugin.php                       # TREM_Plugin (singleton bootstrap)
├── class-trem-calendar.php                     # TREM_Calendar (rendering + AJAX)
├── class-trem-block-calendar.php               # TREM_Block_Calendar (Events Calendar ACF block + assets)
├── class-trem-block-upcoming-events.php        # TREM_Block_Upcoming_Events (Upcoming Events ACF block + fields)
├── models/
│   ├── class-trem-post-type-event.php          # event CPT
│   └── class-trem-event-fields.php             # TREM_Event_Fields (ACF "Event Details" + storage-format filters)
├── taxonomies/
│   └── class-trem-taxonomies.php               # event_category + game_system
└── views/
    ├── calendar-view.php
    ├── single-event-view.php
    └── upcoming-events-view.php
assets/js/trem-calendar.js
assets/css/trem-calendar.css
assets/css/trem-upcoming-events.css
```

All classes live under the `TREM_` prefix.

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
| `event_banner`          | image (`return_format: id`) | `event_banner` |

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

## Conventions

- Use the `TREM_` prefix for new classes, `trem_` for meta/AJAX/option keys.
- Models go in `includes/models/`, taxonomies in `includes/taxonomies/`, views in `includes/views/`.
- Front-end UI is an ACF block; AJAX actions follow the `trem_*` pattern with paired nopriv handlers.

## Single event page (front-end)

The single `event` view lives in the **child theme**, not the plugin: [wp-content/themes/the-realm-malta/single-event.php](../../themes/the-realm-malta/single-event.php). It mirrors Storefront's `single.php`, reads the ACF fields via `get_field()` (banner, date, time, location, participants, register link), renders `event_category` + `game_system` terms, and shows a "Register" button (new tab, `rel="noopener noreferrer external nofollow"`) when `event_register_link` is set. Styling: `assets/scss/single-event.scss` (compiled into `layout.css`). The AJAX modal ([includes/views/single-event-view.php](includes/views/single-event-view.php)) is separate and still uses `get_post_meta`.

## Cross-component notes

This plugin is largely self-contained — no direct dependency on other realm-* plugins or theme classes. The crossovers: events display on theme templates (`single-event.php` + `assets/scss/` in the child theme), and the ACF fields depend on ACF Pro (also used by the theme's Account Creation block).
