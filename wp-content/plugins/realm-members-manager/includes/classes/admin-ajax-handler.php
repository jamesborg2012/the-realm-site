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
        add_action('wp_ajax_update_member_details', [$this, 'update_member_details']);
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

        update_user_meta($user_id, 'billing_phone', $member_data['member_phone']);
        update_user_meta($user_id, 'rmm_membership_status', $member_status);
        update_user_meta($user_id, 'rmm_membership_expire', $expiry_date);
        update_user_meta($user_id, 'rmm_membership_number', $member_number);

        $this->create_member_coupons($user_id, $member_number, $coupon_expiry_date);

        wp_send_json_success([
            'status' => 'member_created'
        ]);
    }

    /**
     * Creates the two member coupons (store + online-only) for the given member.
     */
    private function create_member_coupons($user_id, $member_number, $coupon_expiry_date)
    {
        $online_only_brand = get_term_by('slug', 'online-only', 'product_brand');

        if (!$online_only_brand || is_wp_error($online_only_brand)) {
            return;
        }

        $store_discount = get_option('rmm_member_store_discount', 18);
        $online_discount = get_option('rmm_member_online_discount', 8);

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
        /**
     * Updates member details from admin modal
     */
    public function update_member_details()
    {
        $form_data = isset($_POST['form_data']) ? $_POST['form_data'] : '';

        if (empty($form_data)) {
            wp_send_json_error([
                'message' => 'No data received.'
            ]);
        }

        parse_str($form_data, $data);

        $user_id = isset($data['user_id']) ? intval($data['user_id']) : 0;
        $status = isset($data['rmm_membership_status']) ? sanitize_text_field($data['rmm_membership_status']) : '';
        $expires_raw = isset($data['rmm_membership_expires']) ? $data['rmm_membership_expires'] : '';
        $first_name = isset($data['rmm_member_first_name']) ? sanitize_text_field($data['rmm_member_first_name']) : '';
        $last_name = isset($data['rmm_member_last_name']) ? sanitize_text_field($data['rmm_member_last_name']) : '';

        if ($user_id <= 0) {
            wp_send_json_error([
                'message' => 'Invalid user id.'
            ]);
        }

        if ($first_name === '' || $last_name === '') {
            wp_send_json_error([
                'message' => 'First name and last name are required.'
            ]);
        }

        if (!in_array($status, ['active', 'deactive', 'new'])) {
            // Keep it lenient but sanitize unknown status
            $status = sanitize_text_field($status);
        }

        // Normalize date to Y-m-d if provided; accept formats like d/m/Y or Y-m-d
        $expires = '';
        if (!empty($expires_raw)) {
            $normalized = str_replace('/', '-', $expires_raw);
            $ts = strtotime($normalized);
            if ($ts !== false) {
                $expires = date('Y-m-d', $ts);
            }
        }

        // Membership number: only writable when the user has none yet — once set,
        // it's wired into coupon codes and other logic, so we never allow edits.
        $current_member_number = get_user_meta($user_id, 'rmm_membership_number', true);

        if (empty($current_member_number)) {
            $new_member_number = isset($data['rmm_membership_number']) ? sanitize_text_field($data['rmm_membership_number']) : '';

            if ($new_member_number === '') {
                wp_send_json_error([
                    'message' => 'Member number is required.'
                ]);
            }

            $duplicate = get_users([
                'meta_key' => 'rmm_membership_number',
                'meta_value' => $new_member_number,
                'exclude' => [$user_id],
                'number' => 1,
                'fields' => 'ID',
            ]);

            if (!empty($duplicate)) {
                wp_send_json_error([
                    'message' => 'Member number is already in use.'
                ]);
            }

            update_user_meta($user_id, 'rmm_membership_number', $new_member_number);

            $coupon_expiry_date = !empty($expires) ? $expires : date('Y-m-d', strtotime('+1 year'));
            $this->create_member_coupons($user_id, $new_member_number, $coupon_expiry_date);
        }

        // Update meta
        update_user_meta($user_id, 'rmm_membership_status', $status);
        if (!empty($expires)) {
            // The plugin elsewhere uses rmm_membership_expire (singular); keep both in sync
            update_user_meta($user_id, 'rmm_membership_expire', $expires);
            update_user_meta($user_id, 'rmm_membership_expires', $expires);

            // Mirror onto the member's existing coupons so they expire alongside the membership.
            $this->sync_member_coupon_expiry($user_id, $expires);
        }

        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ]);
        update_user_meta($user_id, 'billing_first_name', $first_name);
        update_user_meta($user_id, 'billing_last_name', $last_name);

        wp_send_json_success([
            'status' => 'updated'
        ]);
    }

    /**
     * Updates the expiry on both member coupons (store + online-only) if they exist.
     */
    private function sync_member_coupon_expiry($user_id, $expires)
    {
        foreach (['rmm_membership_store_coupon', 'rmm_membership_online_coupon'] as $meta_key) {
            $coupon_id = (int) get_user_meta($user_id, $meta_key, true);
            if ($coupon_id <= 0) {
                continue;
            }

            $coupon = new WC_Coupon($coupon_id);
            if (!$coupon->get_id()) {
                continue;
            }

            $coupon->set_date_expires($expires);
            $coupon->save();
        }
    }
}
