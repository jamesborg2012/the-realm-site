<?php
if (! defined('ABSPATH')) exit;

class TREM_Taxonomies
{

    public static function register()
    {
        // Category
        register_taxonomy('event_category', 'event', [
            'labels' => [
                'name' => __('Categories', 'the-realm-events-manager'),
                'singular_name' => __('Category', 'the-realm-events-manager'),
            ],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'event-category'],
        ]);

        // Game System
        register_taxonomy('game_system', 'event', [
            'labels' => [
                'name' => __('Game Systems', 'the-realm-events-manager'),
                'singular_name' => __('Game System', 'the-realm-events-manager'),
            ],
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'game-system'],
        ]);
    }
}
