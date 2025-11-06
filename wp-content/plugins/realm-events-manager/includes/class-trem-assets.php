<?php
if (! defined('ABSPATH')) exit;

/**
 * Handles plugin CSS/JS enqueuing.
 */
class TREM_Assets
{

    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_public_assets']);
    }

    /**
     * Enqueue front-end assets (CSS/JS)
     */
    public static function enqueue_public_assets()
    {
        // Only enqueue if shortcode is present on the page
        global $post;
        if (! is_a($post, 'WP_Post')) return;

        if (has_shortcode($post->post_content, 'realm_events_calendar')) {
            wp_enqueue_style(
                'trem-calendar',
                TREM_URL . 'assets/css/trem-calendar.css',
                [],
                '1.0.0'
            );
        }
    }
}
