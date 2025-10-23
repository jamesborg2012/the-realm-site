<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_Shortcodes_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_shortcode('realm_membership_form', [$this, 'render_realm_membership_form']);
    }

    public function render_realm_membership_form()
    {
        return $this->render_template(
            'public/forms/new-member-form'
        );
    }
}
