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

        // Hooks
        add_action('init', ['TREM_Post_Type_Event', 'register']);
        add_action('init', ['TREM_Taxonomies', 'register']);

        // Event Details fields (ACF — replaces the old Meta Box panel)
        TREM_Event_Fields::init();

        // Calendar: AJAX endpoints + "Events Calendar" ACF block
        add_action('init', ['TREM_Calendar', 'init']);
        TREM_Block_Calendar::init();

        // "Upcoming Events" agenda ACF block
        TREM_Block_Upcoming_Events::init();
    }

    private function load_dependencies()
    {
        // Nothing needed here because autoload handles includes
    }
}
