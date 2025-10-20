<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_Admin_Ajax_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('wp_ajax_load_member_data', [$this, 'load_member_data']);
        add_action('wp_ajax_create_new_member', [$this, 'create_new_member']);
    }

    /**
     * Loads user data
     */
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

    /**
     * Creates a new member
     */
    public function create_new_member()
    {
        $member_data = isset($_POST['member_data']) ? $_POST['member_data'] : [];

        $store_discount = get_option('rmm_member_store_discount', 18);
        $online_discount = get_option('rmm_member_online_discount', 8);

        //TODO
        if (empty($member_data)) {
            wp_send_json_error([
                'message' => 'User data provided is empty'
            ]);
        }

        $user_id = wp_insert_user([
            'user_email' => $member_data['member_email'],
            'user_login' => $member_data['member_email'],
            'first_name' => $member_data['member_name'],
            'last_name' => $member_data['member_surname'],
            'role' => 'customer',
            'user_pass' => wp_generate_password(),
        ]);

        if (is_wp_error($user_id)) {
            wp_send_json_error([
                'message' => 'Unable to create user. Please check inputted data. User may already exist'
            ]);
        }

        $member_number = $member_data['member_number'];

        $member_status = $member_data['member_status'] ?? 'not_active';
        $expiry_date = $member_data['member_expiry_date'] ?? '';

        if (empty($expiry_date)) {
            $expiry_date = date('Y-m-d', strtotime('+1 year'));
            $coupon_expiry_date = date('Y-m-d', strtotime('+1 year'));
        } else {
            $expiry_date = str_replace('/', '-', $expiry_date);
            $expiry_date = date('Y-m-d', strtotime($expiry_date));
            $coupon_expiry_date = date('Y-m-d', strtotime($expiry_date));
        }

        $online_only_brand = get_term_by('slug', 'online-only', 'product_brand');

        update_user_meta($user_id, 'billing_phone', $member_data['member_phone']);
        update_user_meta($user_id, 'rmm_membership_status', $member_status);
        update_user_meta($user_id, 'rmm_membership_expire', $expiry_date);
        update_user_meta($user_id, 'rmm_membership_number', $member_number);

        if ($online_only_brand && !is_wp_error($online_only_brand)) {
            $coupon = new WC_Coupon();

            $coupon->set_code($member_number . 'STOREDISC');
            $coupon->set_discount_type('percent');
            $coupon->set_amount($store_discount);
            $coupon->set_date_expires($coupon_expiry_date);
            $coupon->add_meta_data('exclude_product_brands', [$online_only_brand->term_id]);
            $coupon->save();

            update_user_meta($user_id, 'rmm_membership_store_coupon', $coupon->get_id());

            $coupon = new WC_Coupon();

            $coupon->set_code($member_number . 'ONLINEONLY');
            $coupon->set_discount_type('percent');
            $coupon->set_amount($online_discount);
            $coupon->set_date_expires($coupon_expiry_date);
            $coupon->add_meta_data('product_brands', [$online_only_brand->term_id]);
            $coupon->save();

            update_user_meta($user_id, 'rmm_membership_online_coupon', $coupon->get_id());
        }

        wp_send_json_success([
            'status' => 'member_created'
        ]);
    }
}
