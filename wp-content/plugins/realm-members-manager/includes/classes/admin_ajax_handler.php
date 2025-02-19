<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class Admin_Ajax_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('wp_ajax_load_member_data', [$this, 'load_member_data']);
    }

    public function load_member_data()
    {
        $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : 0;

        //TODO
        if ($user_id == 0) {
            wp_send_json_error([]);
        }

        $content = $this->render_template(
            'admin/partials/member-manage-modal-content',
            [
                'user_id' => $user_id
            ]
        );

        wp_send_json_success([
            'content' => $content
        ]);
    }
}
