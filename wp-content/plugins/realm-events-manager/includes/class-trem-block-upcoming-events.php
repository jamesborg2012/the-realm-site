<?php
if (! defined('ABSPATH')) exit;

/**
 * Registers the "Upcoming Events" ACF block.
 *
 * A Google-Calendar-style agenda widget that lists the next N upcoming events,
 * optionally filtered by event category and/or game system. All options are
 * chosen by the client per-instance in the block editor (ACF fields registered
 * below). Can be dropped anywhere the block editor is available.
 */
class TREM_Block_Upcoming_Events
{

    const BLOCK_NAME = 'upcoming-events';

    public static function init()
    {
        add_action('acf/init', [__CLASS__, 'register_block']);
        add_action('acf/init', [__CLASS__, 'register_fields']);
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
            'name'            => self::BLOCK_NAME,
            'title'           => __('Upcoming Events', 'the-realm-events-manager'),
            'description'     => __('Agenda-style list of the next upcoming events, with optional category / game system filters.', 'the-realm-events-manager'),
            'category'        => 'trm-blocks',
            'icon'            => 'list-view',
            'keywords'        => ['events', 'upcoming', 'agenda', 'realm'],
            'mode'            => 'preview',
            'supports'        => ['anchor' => true, 'jsx' => true],
            'render_callback' => [__CLASS__, 'render_block'],
            'enqueue_assets'  => [__CLASS__, 'enqueue_assets'],
        ]);
    }

    /**
     * Register the block's ACF fields in PHP (self-contained, no acf-json sync).
     */
    public static function register_fields()
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => 'group_trem_upcoming_events',
            'title'    => __('Upcoming Events Settings', 'the-realm-events-manager'),
            'fields'   => [
                [
                    'key'           => 'field_trem_ue_heading',
                    'label'         => __('Heading', 'the-realm-events-manager'),
                    'name'          => 'heading',
                    'type'          => 'text',
                    'instructions'  => __('Optional heading shown above the list. Leave blank for none.', 'the-realm-events-manager'),
                    'placeholder'   => __('Upcoming Events', 'the-realm-events-manager'),
                ],
                [
                    'key'           => 'field_trem_ue_number',
                    'label'         => __('Number of events', 'the-realm-events-manager'),
                    'name'          => 'number_of_events',
                    'type'          => 'number',
                    'instructions'  => __('How many upcoming events to display.', 'the-realm-events-manager'),
                    'default_value' => 5,
                    'min'           => 1,
                    'max'           => 50,
                    'step'          => 1,
                    'required'      => 1,
                ],
                [
                    'key'           => 'field_trem_ue_categories',
                    'label'         => __('Filter by category', 'the-realm-events-manager'),
                    'name'          => 'filter_categories',
                    'type'          => 'taxonomy',
                    'instructions'  => __('Leave empty to show events from all categories.', 'the-realm-events-manager'),
                    'taxonomy'      => 'event_category',
                    'field_type'    => 'multi_select',
                    'add_term'      => 0,
                    'save_terms'    => 0,
                    'load_terms'    => 0,
                    'return_format' => 'id',
                    'allow_null'    => 1,
                    'multiple'      => 0,
                ],
                [
                    'key'           => 'field_trem_ue_game_systems',
                    'label'         => __('Filter by game system', 'the-realm-events-manager'),
                    'name'          => 'filter_game_systems',
                    'type'          => 'taxonomy',
                    'instructions'  => __('Leave empty to show events from all game systems.', 'the-realm-events-manager'),
                    'taxonomy'      => 'game_system',
                    'field_type'    => 'multi_select',
                    'add_term'      => 0,
                    'save_terms'    => 0,
                    'load_terms'    => 0,
                    'return_format' => 'id',
                    'allow_null'    => 1,
                    'multiple'      => 0,
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'block',
                        'operator' => '==',
                        'value'    => 'acf/' . self::BLOCK_NAME,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Block render callback — outputs the upcoming events agenda list.
     */
    public static function render_block($block, $content = '', $is_preview = false, $post_id = 0)
    {
        $heading = get_field('heading');
        $number  = (int) get_field('number_of_events');
        if ($number < 1) {
            $number = 5;
        }

        $categories   = get_field('filter_categories');
        $game_systems = get_field('filter_game_systems');

        $events = self::get_upcoming_events($number, $categories, $game_systems);

        include TREM_PATH . 'includes/views/upcoming-events-view.php';
    }

    /**
     * Query the next $number upcoming events (event_date today or later),
     * optionally narrowed by category and/or game system term IDs.
     *
     * @return WP_Post[]
     */
    private static function get_upcoming_events($number, $categories = [], $game_systems = [])
    {
        $today = current_time('Y-m-d');

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $number,
            'meta_key'       => 'event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => 'event_date',
                    'value'   => $today,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ];

        $tax_query = [];
        if (! empty($categories)) {
            $tax_query[] = [
                'taxonomy' => 'event_category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', (array) $categories),
            ];
        }
        if (! empty($game_systems)) {
            $tax_query[] = [
                'taxonomy' => 'game_system',
                'field'    => 'term_id',
                'terms'    => array_map('intval', (array) $game_systems),
            ];
        }
        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }
        if (! empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $query = new WP_Query($args);

        return $query->posts;
    }

    /**
     * Enqueue the agenda widget CSS wherever the block is present
     * (frontend + editor preview).
     */
    public static function enqueue_assets()
    {
        wp_enqueue_style(
            'trem-upcoming-events',
            TREM_URL . 'assets/css/trem-upcoming-events.css',
            [],
            '1.0.0'
        );
    }
}
