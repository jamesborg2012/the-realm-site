<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_WC_Hooks_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('woocommerce_before_checkout_form', [$this, 'display_member_number_form'], 40);
    }

    public function display_member_number_form()
    {
        echo $this->render_template(
            'public/partials/checkout/members-number-form'
        );
    }
}
