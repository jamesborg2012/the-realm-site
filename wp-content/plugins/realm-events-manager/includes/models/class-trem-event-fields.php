<?php
if (! defined('ABSPATH')) exit;

/**
 * Event Details ACF fields (replaces the old Meta Box panel TREM_Meta_Event).
 *
 * Registered in PHP via acf_add_local_field_group() so the plugin stays
 * self-contained (no acf-json sync).
 *
 * IMPORTANT — storage-format compatibility:
 * Existing events (and the calendar / upcoming-events queries + AJAX modal)
 * rely on `event_date` being stored as `Y-m-d` and `event_start_time` as `H:i`,
 * matching what the old Meta Box wrote. ACF's date/time pickers natively store
 * `Ymd` / `H:i:s`, so the load/update_value filters below translate between
 * ACF's internal format and the legacy storage format. This keeps every
 * existing consumer (get_post_meta, meta_query type=DATE, orderby meta_value)
 * working with zero data migration.
 */
class TREM_Event_Fields
{

    const KEY_DATE = 'field_trem_event_date';
    const KEY_TIME = 'field_trem_event_time';

    public static function init()
    {
        add_action('acf/init', [__CLASS__, 'register_fields']);

        // Keep DB storage in the legacy Meta Box format (Y-m-d / H:i).
        add_filter('acf/update_value/key=' . self::KEY_DATE, [__CLASS__, 'update_date'], 10, 3);
        add_filter('acf/load_value/key=' . self::KEY_DATE, [__CLASS__, 'load_date'], 10, 3);
        add_filter('acf/update_value/key=' . self::KEY_TIME, [__CLASS__, 'update_time'], 10, 3);
        add_filter('acf/load_value/key=' . self::KEY_TIME, [__CLASS__, 'load_time'], 10, 3);
    }

    /**
     * Register the "Event Details" field group on the event post type.
     */
    public static function register_fields()
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_trem_event_details',
            'title'  => __('Event Details', 'the-realm-events-manager'),
            'fields' => [
                [
                    'key'           => self::KEY_DATE,
                    'label'         => __('Date of Event', 'the-realm-events-manager'),
                    'name'          => 'event_date',
                    'type'          => 'date_picker',
                    'display_format' => 'd/m/Y',
                    'return_format' => 'Y-m-d',
                    'first_day'     => 1,
                    'required'      => 1,
                ],
                [
                    'key'           => self::KEY_TIME,
                    'label'         => __('Start Time of Event', 'the-realm-events-manager'),
                    'name'          => 'event_start_time',
                    'type'          => 'time_picker',
                    'display_format' => 'H:i',
                    'return_format' => 'H:i',
                ],
                [
                    'key'           => 'field_trem_event_participants',
                    'label'         => __('Number of Participants', 'the-realm-events-manager'),
                    'name'          => 'event_participants',
                    'type'          => 'number',
                    'min'           => 1,
                    'step'          => 1,
                ],
                [
                    'key'           => 'field_trem_event_location',
                    'label'         => __('Location', 'the-realm-events-manager'),
                    'name'          => 'event_location',
                    'type'          => 'text',
                    'placeholder'   => __('e.g. Valletta Gaming Hub', 'the-realm-events-manager'),
                ],
                [
                    'key'           => 'field_trem_event_register_link',
                    'label'         => __('External Link to Register', 'the-realm-events-manager'),
                    'name'          => 'event_register_link',
                    'type'          => 'url',
                    'placeholder'   => __('https://example.com/register', 'the-realm-events-manager'),
                ],
                [
                    'key'           => 'field_trem_event_banner',
                    'label'         => __('Banner Image', 'the-realm-events-manager'),
                    'name'          => 'event_banner',
                    'type'          => 'image',
                    'instructions'  => __('Wide banner shown at the top of the event page.', 'the-realm-events-manager'),
                    'return_format' => 'id',
                    'preview_size'  => 'medium',
                    'library'       => 'all',
                    'mime_types'    => 'jpg,jpeg,png,webp',
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'event',
                    ],
                ],
            ],
            'menu_order'    => 0,
            'position'      => 'normal',
            'style'         => 'default',
            'label_placement' => 'top',
            'active'        => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Date: DB stores Y-m-d; ACF picker works in Ymd internally.          */
    /* ------------------------------------------------------------------ */

    public static function update_date($value, $post_id, $field)
    {
        return self::reformat_date($value, 'Y-m-d');
    }

    public static function load_date($value, $post_id, $field)
    {
        return self::reformat_date($value, 'Ymd');
    }

    /**
     * Parse a date from either Y-m-d or Ymd and reformat, leaving anything
     * unrecognised untouched.
     */
    private static function reformat_date($value, $out_format)
    {
        if (empty($value) || ! is_string($value)) {
            return $value;
        }
        foreach (['Y-m-d', 'Ymd'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $value);
            if ($d && $d->format($fmt) === $value) {
                return $d->format($out_format);
            }
        }
        return $value;
    }

    /* ------------------------------------------------------------------ */
    /* Time: DB stores H:i; ACF picker works in H:i:s internally.          */
    /* ------------------------------------------------------------------ */

    public static function update_time($value, $post_id, $field)
    {
        return self::reformat_time($value, false);
    }

    public static function load_time($value, $post_id, $field)
    {
        return self::reformat_time($value, true);
    }

    /**
     * Normalise a HH:MM[:SS] time string. $with_seconds toggles H:i:s vs H:i.
     */
    private static function reformat_time($value, $with_seconds)
    {
        if (empty($value) || ! is_string($value)) {
            return $value;
        }
        if (preg_match('/^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $s = isset($m[3]) ? (int) $m[3] : 0;
            return $with_seconds
                ? sprintf('%02d:%02d:%02d', $h, $i, $s)
                : sprintf('%02d:%02d', $h, $i);
        }
        return $value;
    }
}
