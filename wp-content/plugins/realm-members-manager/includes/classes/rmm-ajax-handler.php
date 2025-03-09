<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_Ajax_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('wp_ajax_apply_membership_number', [$this, 'apply_membership_discounts']);
        add_action('wp_ajax_nopriv_apply_membership_number', [$this, 'apply_membership_discounts']);
    }

    public function apply_membership_discounts()
    {
        $membership_number = $_POST['member_number'] ?? '';

        if (empty($membership_number)) {
            //TODO
            wp_send_json_error([]);
        }

        global $woocommerce;
        $coupon_code = "realm_member";

        $this->write_log("TESTING");

        $woocommerce->cart->add_discount(sanitize_text_field($coupon_code));

        wp_send_json_success([]);
    }
}
