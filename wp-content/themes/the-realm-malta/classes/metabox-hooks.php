<?php

class TRM_MB_Hooks extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        add_filter('rwmb_meta_boxes', [$this, 'register_meta_boxes']);
    }

    public function register_meta_boxes($meta_boxes)
    {
        $meta_boxes[] = [
            'title' => 'Games Workshop Info',
            'post_types' => 'product',
            'fields' => [
                [
                    'name' => 'Previous Barcodes',
                    'id' => 'trm_gw_old_barcodes',
                    'type' => 'text_list',
                    'clone' => true,
                    'options' => [
                        '' => ''
                    ]
                ]
            ],
        ];

        return $meta_boxes;
    }
}
