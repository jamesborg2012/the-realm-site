<?php

class TRM_AJAX_Hooks extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }
    
    public function register_hook_callbacks()
    {
        // Register AJAX actions for both logged-in and logged-out users
        add_action('wp_ajax_realm_register_customer', array($this, 'handle_customer_registration'));
        add_action('wp_ajax_nopriv_realm_register_customer', array($this, 'handle_customer_registration'));
    }
    
    /**
     * Handle AJAX customer registration request
     */
    public function handle_customer_registration()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'realm_register_customer')) {
            wp_send_json_error(array('code' => 'server_error'), 403);
        }
        
        // Sanitize inputs
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone_prefix = isset($_POST['phone_prefix']) ? sanitize_text_field(wp_unslash($_POST['phone_prefix'])) : '';
        $mobile_number = isset($_POST['mobile_number']) ? sanitize_text_field(wp_unslash($_POST['mobile_number'])) : '';
        $is_realm_member = isset($_POST['is_realm_member']) && $_POST['is_realm_member'] === '1';
        $membership_number = isset($_POST['membership_number']) ? sanitize_text_field(wp_unslash($_POST['membership_number'])) : '';
        
        // Validate required fields
        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone_prefix) || empty($mobile_number)) {
            wp_send_json_error(array('code' => 'validation'));
        }
        
        // Validate email format
        if (!is_email($email)) {
            wp_send_json_error(array('code' => 'validation'));
        }
        
        // Validate membership number if realm member
        if ($is_realm_member && empty($membership_number)) {
            wp_send_json_error(array('code' => 'validation'));
        }
        
        // Normalize and combine phone
        $billing_phone = trim($phone_prefix) . ' ' . trim($mobile_number);
        $normalized_phone = preg_replace('/[^0-9]/', '', $billing_phone);
        
        // Check for duplicates
        
        // 1. Email duplicate check
        if (email_exists($email)) {
            wp_send_json_error(array('code' => 'duplicate'));
        }
        
        // 2. Phone duplicate check
        $phone_users = get_users(array(
            'meta_key' => 'billing_phone',
            'meta_value' => $billing_phone,
            'number' => 1,
            'fields' => 'ID'
        ));
        
        // If exact match not found, check normalized phone
        if (empty($phone_users)) {
            $all_users_with_phone = get_users(array(
                'meta_key' => 'billing_phone',
                'fields' => array('ID'),
                'number' => -1
            ));
            
            foreach ($all_users_with_phone as $user) {
                $existing_phone = get_user_meta($user->ID, 'billing_phone', true);
                $existing_normalized = preg_replace('/[^0-9]/', '', $existing_phone);
                
                if ($existing_normalized === $normalized_phone) {
                    wp_send_json_error(array('code' => 'duplicate'));
                }
            }
        } else {
            wp_send_json_error(array('code' => 'duplicate'));
        }
        
        // 3. Membership number duplicate check (only if provided)
        if ($is_realm_member && !empty($membership_number)) {
            $membership_users = get_users(array(
                'meta_key' => 'rmm_membership_number',
                'meta_value' => $membership_number,
                'number' => 1,
                'fields' => 'ID'
            ));
            
            if (!empty($membership_users)) {
                wp_send_json_error(array('code' => 'duplicate'));
            }
        }
        
        // Generate unique username from email
        $username_base = sanitize_user(current(explode('@', $email)), true);
        $username = $username_base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $username_base . $counter;
            $counter++;
        }
        
        // Generate secure password
        $password = wp_generate_password(16, true);
        
        // Create user with customer role
        $user_data = array(
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role' => 'customer'
        );
        
        $user_id = wp_insert_user($user_data);
        
        if (is_wp_error($user_id)) {
            $this->write_log('User creation failed: ' . $user_id->get_error_message());
            wp_send_json_error(array('code' => 'server_error'));
        }
        
        // Set WooCommerce billing meta
        update_user_meta($user_id, 'billing_first_name', $first_name);
        update_user_meta($user_id, 'billing_last_name', $last_name);
        update_user_meta($user_id, 'billing_email', $email);
        update_user_meta($user_id, 'billing_phone', $billing_phone);
        
        // Set membership number if provided
        if ($is_realm_member && !empty($membership_number)) {
            update_user_meta($user_id, 'rmm_membership_number', $membership_number);
        }
        
        // Set additional realm meta (maintain backward compatibility with existing meta keys)
        update_user_meta($user_id, 'realm_phone_prefix', $phone_prefix);
        update_user_meta($user_id, 'realm_mobile_number', $mobile_number);
        if ($is_realm_member && !empty($membership_number)) {
            update_user_meta($user_id, 'realm_membership_number', $membership_number);
        }
        
        // Send new user notification
        wp_new_user_notification($user_id, null, 'both');
        
        // Return success
        wp_send_json_success(array('user_id' => $user_id));
    }
}

