<?php
if (! defined('ABSPATH')) exit;

/**
 * Registers the "Events Calendar" ACF block.
 *
 * Replaces the old [realm_events_calendar] shortcode: drop the block onto a
 * page in the editor to render the interactive month-view calendar. Rendering
 * and AJAX navigation are handled by TREM_Calendar.
 */
class TREM_Block_Calendar
{

    public static function init()
    {
        add_action('acf/init', [__CLASS__, 'register_block']);
    }

    /**
     * Register the ACF block type.
     */
    public static function register_block()
    {
        if (! function_exists('acf_register_block_type')) {
            return;
        }

        acf_register_block_type([
            'name'            => 'events-calendar',
            'title'           => __('Events Calendar', 'the-realm-events-manager'),
            'description'     => __('Interactive month-view calendar of gaming events.', 'the-realm-events-manager'),
            'category'        => 'trm-blocks',
            'icon'            => 'calendar-alt',
            'keywords'        => ['events', 'calendar', 'realm'],
            'mode'            => 'preview',
            'supports'        => ['anchor' => true, 'multiple' => false],
            'render_callback' => [__CLASS__, 'render_block'],
            'enqueue_assets'  => [__CLASS__, 'enqueue_assets'],
        ]);
    }

    /**
     * Block render callback — outputs the current month's calendar.
     */
    public static function render_block($block, $content = '', $is_preview = false, $post_id = 0)
    {
        $month = (int) date('m');
        $year  = (int) date('Y');

        TREM_Calendar::render_calendar_view($month, $year);
    }

    /**
     * Enqueue calendar CSS/JS. Runs on the frontend and in the editor preview
     * wherever the block is present.
     */
    public static function enqueue_assets()
    {
        wp_enqueue_style(
            'trem-calendar',
            TREM_URL . 'assets/css/trem-calendar.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'trem-calendar-js',
            TREM_URL . 'assets/js/trem-calendar.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('trem-calendar-js', 'tremCalendar', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'currentMonth' => date('m'),
            'currentYear'  => date('Y'),
        ]);
    }
}
