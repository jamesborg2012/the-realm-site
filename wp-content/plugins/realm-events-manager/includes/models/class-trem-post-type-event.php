<?php
if (! defined('ABSPATH')) exit;

class TREM_Post_Type_Event
{

    public static function register()
    {
        $labels = [
            'name'               => __('Events', 'the-realm-events-manager'),
            'singular_name'      => __('Event', 'the-realm-events-manager'),
            'add_new_item'       => __('Add New Event', 'the-realm-events-manager'),
            'edit_item'          => __('Edit Event', 'the-realm-events-manager'),
            'new_item'           => __('New Event', 'the-realm-events-manager'),
            'view_item'          => __('View Event', 'the-realm-events-manager'),
            'search_items'       => __('Search Events', 'the-realm-events-manager'),
            'not_found'          => __('No events found', 'the-realm-events-manager'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'events'],
            'menu_icon'          => 'dashicons-calendar',
            'supports'           => ['title', 'editor', 'thumbnail'],
            'show_in_rest'       => true,
        ];

        register_post_type('event', $args);
    }
}
