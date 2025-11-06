<?php
if (! defined('ABSPATH')) exit;

class TREM_Meta_Event
{

    /**
     * Register event meta fields for REST + Members compatibility.
     */
    public static function register_meta_box()
    {
        $meta_boxes[] = [
            'id'         => 'trem_event_details',
            'title'      => __('Event Details', 'the-realm-events-manager'),
            'post_types' => ['event'],
            'context'    => 'normal',
            'priority'   => 'high',
            'autosave'   => true,

            'fields' => [
                [
                    'name'  => __('Date of Event', 'the-realm-events-manager'),
                    'id'    => 'event_date',
                    'type'  => 'date',
                    'js_options' => [
                        'dateFormat' => 'yy-mm-dd',
                    ],
                ],
                [
                    'name'  => __('Start Time of Event', 'the-realm-events-manager'),
                    'id'    => 'event_start_time',
                    'type'  => 'time',
                ],
                [
                    'name'  => __('Number of Participants', 'the-realm-events-manager'),
                    'id'    => 'event_participants',
                    'type'  => 'number',
                    'min'   => 1,
                    'step'  => 1,
                ],
                [
                    'name'  => __('Location', 'the-realm-events-manager'),
                    'id'    => 'event_location',
                    'type'  => 'text',
                    'placeholder' => __('e.g. Valletta Gaming Hub', 'the-realm-events-manager'),
                ],
                [
                    'name'  => __('External Link to Register', 'the-realm-events-manager'),
                    'id'    => 'event_register_link',
                    'type'  => 'url',
                    'placeholder' => __('https://example.com/register', 'the-realm-events-manager'),
                ],
            ],
        ];

        return $meta_boxes;
    }
}
