# Realm Events Manager

Custom plugin for the in-store gaming events calendar.

Header (from [realm-events-manager.php](realm-events-manager.php)): `v1.0.0`, author James Borg.

See the [project CLAUDE.md](../../../CLAUDE.md) for site-wide context.

## File layout

```
realm-events-manager.php
includes/
├── class-trem-plugin.php                       # TREM_Plugin (singleton bootstrap)
├── class-trem-assets.php                       # TREM_Assets (front-end enqueue)
├── class-trem-shortcode-calendar.php           # TREM_Shortcode_Calendar (+ AJAX)
├── models/
│   ├── class-trem-post-type-event.php          # event CPT
│   └── class-trem-meta-event.php               # event meta box ("Event Details")
├── taxonomies/
│   └── class-trem-taxonomies.php               # event_category + game_system
└── views/
    ├── calendar-view.php
    └── single-event-view.php
assets/js/trem-calendar.js
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

## Meta box

`trem_event_details` ("Event Details") on the event editor — five fields:

| Field key                | Type   |
|--------------------------|--------|
| `event_date`             | date   |
| `event_start_time`       | time   |
| `event_participants`     | number |
| `event_location`         | text   |
| `event_register_link`    | URL    |

## Shortcode

`[realm_events_calendar month="" year=""]` — renders an interactive month-view calendar. Defaults to the current month/year.

Implementation in [class-trem-shortcode-calendar.php](includes/class-trem-shortcode-calendar.php). View: [includes/views/calendar-view.php](includes/views/calendar-view.php).

## AJAX (both `nopriv`-enabled)

| Action                | Returns                                                |
|-----------------------|--------------------------------------------------------|
| `trem_load_calendar`  | Calendar HTML for a given month/year (nav)             |
| `trem_load_event`     | Single-event detail HTML for a modal                   |

## Assets

`TREM_Assets` enqueues conditionally — only on pages where the shortcode appears:

- `trem-calendar.css` (CSS file shipped with the plugin)
- `trem-calendar-js` (depends on jQuery) — localised as `tremCalendar` with `ajaxUrl` etc.

## Conventions

- Use the `TREM_` prefix for new classes, `trem_` for meta/AJAX/option keys.
- Models go in `includes/models/`, taxonomies in `includes/taxonomies/`, views in `includes/views/`.
- New shortcodes follow the `trem_*` AJAX action pattern with paired nopriv handlers.

## Cross-component notes

This plugin is largely self-contained — no direct dependency on other realm-* plugins or theme classes. The only crossover is that events display on theme templates, so styling is in the theme (`assets/scss/`) when needed.
