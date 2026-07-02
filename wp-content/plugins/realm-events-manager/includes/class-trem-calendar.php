<?php
if (! defined('ABSPATH')) exit;

/**
 * Calendar rendering + AJAX.
 *
 * The calendar is placed on a page via the "Events Calendar" ACF block
 * (see TREM_Block_Calendar). This class owns the shared markup rendering
 * and the AJAX endpoints used for month navigation and event detail modals.
 */
class TREM_Calendar
{

    public static function init()
    {
        // AJAX: month navigation
        add_action('wp_ajax_trem_load_calendar', [__CLASS__, 'ajax_load_calendar']);
        add_action('wp_ajax_nopriv_trem_load_calendar', [__CLASS__, 'ajax_load_calendar']);

        // AJAX: single event details
        add_action('wp_ajax_trem_load_event', [__CLASS__, 'ajax_load_event']);
        add_action('wp_ajax_nopriv_trem_load_event', [__CLASS__, 'ajax_load_event']);
    }

    /**
     * Render the calendar markup for a given month/year.
     * Shared by the ACF block render callback and the AJAX nav handler.
     */
    public static function render_calendar_view($month, $year)
    {
        $events = self::get_events_for_month($month, $year);

        $events_by_day = [];
        foreach ($events as $event) {
            $date = get_post_meta($event->ID, 'event_date', true);
            if (! $date) continue;
            $day = (int) date('j', strtotime($date));
            $events_by_day[$day][] = $event;
        }

        include TREM_PATH . 'includes/views/calendar-view.php';
    }

    /**
     * AJAX callback for month navigation.
     */
    public static function ajax_load_calendar()
    {
        $month = isset($_POST['month']) ? intval($_POST['month']) : date('m');
        $year  = isset($_POST['year'])  ? intval($_POST['year'])  : date('Y');

        // Security check: limit navigation
        $current_year  = date('Y');
        $current_month = date('n');

        $diff_months = (($year - $current_year) * 12) + ($month - $current_month);

        if ($diff_months < 0 || $diff_months > 12) {
            wp_send_json_error(['message' => 'Invalid month range']);
        }

        ob_start();
        self::render_calendar_view($month, $year);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX callback: Load single event details.
     */
    public static function ajax_load_event()
    {
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if (! $event_id || get_post_type($event_id) !== 'event') {
            wp_send_json_error(['message' => 'Invalid event ID']);
        }

        ob_start();
        include TREM_PATH . 'includes/views/single-event-view.php';
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Query events within the given month.
     */
    private static function get_events_for_month($month, $year)
    {
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date   = date('Y-m-t', strtotime($start_date));

        $query = new WP_Query([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'event_date',
                    'value'   => [$start_date, $end_date],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'event_date',
            'order'   => 'ASC',
        ]);

        return $query->posts;
    }
}
