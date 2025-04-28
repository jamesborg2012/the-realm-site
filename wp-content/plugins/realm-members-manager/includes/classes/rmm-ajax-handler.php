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

    /**
     * Applies membership discounts
     */
    public function apply_membership_discounts()
    {
        WC()->session->set('member_number', '');

        $membership_number = $_POST['member_number'] ?? '';

        if (empty($membership_number)) {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'No member number provided!'
            ]);
        }

        global $woocommerce;

        $woocommerce->cart->remove_coupons();

        WC()->session->set('member_number', $membership_number);

        $result = get_users([
            'role' => 'customer',
            'meta_key' => 'rmm_membership_number',
            'meta_value' => $membership_number
        ]);

        if (empty($result)) {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'Number provided does not belong to existing member!'
            ]);
        }

        $user = reset($result);
        $is_active = get_user_meta($user->ID, 'rmm_membership_status', true) == 'active';
        $expire = get_user_meta($user->ID, 'rmm_membership_expire', true);

        if (!$is_active || ($is_active && strtotime($expire) < strtotime('now'))) {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'Customer membership expired or customer is no longer a member!'
            ]);
        }

        $items = $woocommerce->cart->get_cart();
        $has_shop_items = false;
        $has_online_only = false;

        foreach ($items as $values) {
            if ($has_shop_items && $has_online_only) {
                break;
            }

            $product = $values['data'];
            $brands = get_the_terms($product->get_id(), 'product_brand');

            if ($brands === false) {
                $has_shop_items = true;
                continue;
            }

            $brand = reset($brands);
            if ($brand->slug == 'online-only') {
                $has_online_only = true;
                continue;
            }
        }

        if ($has_shop_items) {
            $woocommerce->cart->add_discount(sanitize_text_field($membership_number . "storedisc"));
        }

        if ($has_online_only) {
            $woocommerce->cart->add_discount(sanitize_text_field($membership_number . "onlineonly"));
        }

        wp_send_json_success([
            'status' => 'success'
        ]);
    }
}
