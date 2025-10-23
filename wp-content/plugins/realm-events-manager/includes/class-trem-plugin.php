<?php
if (! defined('ABSPATH')) exit;

/**
 * Main plugin bootstrap class (Controller)
 */
class TREM_Plugin
{

    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Load components
        $this->load_dependencies();

        // Enqueue plugin assets
        add_action('init', ['TREM_Assets', 'init']);

        // Hooks
        add_action('init', ['TREM_Post_Type_Event', 'register']);
        add_action('init', ['TREM_Taxonomies', 'register']);
        add_action('rwmb_meta_boxes', ['TREM_Meta_Event', 'register_meta_box']);

        // Shortcode
        add_action('init', ['TREM_Shortcode_Calendar', 'init']);
    }

    private function load_dependencies()
    {
        // Nothing needed here because autoload handles includes
    }
}
