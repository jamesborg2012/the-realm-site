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

        add_action('wp_ajax_register_new_member', [$this, 'register_new_member']);
        add_action('wp_ajax_norpiv_register_new_member', [$this, 'register_new_member']);
    }

    /**
     * Applies membership discounts
     */
    public function apply_membership_discounts()
    {
        $store_discount = get_option('rmm_member_store_discount', 18);
        $online_discount = get_option('rmm_member_online_discount', 8);

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
            $current_store_coupon = new WC_Coupon($membership_number . 'storedisc');
            $amount = $current_store_coupon->get_amount();

            if ($amount != $store_discount) {
                $current_store_coupon->set_amount($store_discount);
                $current_store_coupon->save();
            }

            $woocommerce->cart->add_discount(sanitize_text_field($membership_number . "storedisc"));
        }

        if ($has_online_only) {
            $current_online_coupon = new WC_Coupon($membership_number . 'onlineonly');
            $amount = $current_online_coupon->get_amount();

            if ($amount != $online_discount) {
                $current_online_coupon->set_amount($online_discount);
                $current_online_coupon->save();
            }

            $woocommerce->cart->add_discount(sanitize_text_field($membership_number . "onlineonly"));
        }

        wp_send_json_success([
            'status' => 'success'
        ]);
    }

    public function register_new_member()
    {
        $form_data = $_POST['form_data'] ?? [];

        if (empty($form_data)) {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'Membership form submission incomplete!'
            ]);
        }

        parse_str($form_data, $form_data);

        $existing_user = get_user_by('email', $form_data['email']);

        if ($existing_user !== false) {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'Membership cannot be registered for this email'
            ]);
        }

        $user_id = wc_create_new_customer($form_data['email'], '', wp_generate_password(), [
            'display_name' => $form_data['fname'] . ' ' . $form_data['lname']
        ]);

        if (is_a($user_id, 'WP_Error')) {
            $this->write_log($user_id);

            wp_send_json_error([
                'status' => 'error',
                'message' => 'Membership registration failed!'
            ]);
        }

        update_user_meta($user_id, 'billing_first_name', $form_data['fname']);
        update_user_meta($user_id, 'billing_last_name', $form_data['lname']);
        update_user_meta($user_id, 'billing_phone', $form_data['phone']);
        update_user_meta($user_id, 'billing_email', $form_data['email']);
        update_user_meta($user_id, 'rmm_membership_status', 'new');

        //TODO: Once we have PayPal we can create a payment intent to process the membership payment

        wp_send_json_success([
            'status' => 'success'
        ]);
    }
}
