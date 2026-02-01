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
        
        // Membership application actions
        add_action('wp_ajax_realm_apply_membership', array($this, 'handle_membership_application'));
        add_action('wp_ajax_nopriv_realm_apply_membership', array($this, 'handle_membership_application'));
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
        
        // Handle passwords carefully - do NOT sanitize to preserve special characters
        $password = isset($_POST['password']) ? wp_unslash($_POST['password']) : '';
        $password_confirm = isset($_POST['password_confirm']) ? wp_unslash($_POST['password_confirm']) : '';
        
        // Validate required fields
        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone_prefix) || empty($mobile_number) || empty($password) || empty($password_confirm)) {
            wp_send_json_error(array('code' => 'validation'));
        }
        
        // Validate passwords match
        if ($password !== $password_confirm) {
            wp_send_json_error(array('code' => 'validation', 'message' => 'Passwords do not match.'));
        }

        // Validate password strength requirements
        $password_errors = array();
        if (strlen($password) < 8) {
            $password_errors[] = 'at least 8 characters';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $password_errors[] = 'at least one number';
        }
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\\\|,.<>\/?`~]/', $password)) {
            $password_errors[] = 'at least one special character';
        }
        if (!empty($password_errors)) {
            wp_send_json_error(array('code' => 'validation', 'message' => 'Password must contain ' . implode(', ', $password_errors) . '.'));
        }
        
        // Validate email format
        if (!is_email($email)) {
            wp_send_json_error(array('code' => 'validation'));
        }
        
        // Validate phone prefix format (must start with + and contain only digits and hyphens)
        if (!preg_match('/^\+[\d\-]+$/', $phone_prefix)) {
            wp_send_json_error(array('code' => 'validation', 'message' => 'Invalid phone prefix format.'));
        }
        
        // Validate mobile number does NOT start with "+"
        if (strpos(trim($mobile_number), '+') === 0) {
            wp_send_json_error(array('code' => 'mobile_plus_sign', 'message' => 'Please enter your mobile number without the + sign. Use the Phone Prefix dropdown instead.'));
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
        
        // Create user with customer role using user-provided password
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
        
        // Send new user notification to admin only (user already has their password)
        // Using 'admin' instead of 'both' to avoid sending password reset email to user
        wp_new_user_notification($user_id, null, 'admin');
        
        // Generate a short-lived token for membership application (prevents abuse)
        $membership_token = wp_generate_password(32, false);
        set_transient('realm_membership_token_' . $user_id, $membership_token, 3600); // 1 hour expiry
        
        // Return success with user_id and membership token
        wp_send_json_success(array(
            'user_id' => (int) $user_id,
            'membership_token' => $membership_token,
            'membership_number' => $membership_number
        ));
    }
    
    /**
     * Handle membership application request
     * Sets user meta rmm_membership_status to 'review'
     */
    public function handle_membership_application()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'realm_apply_membership')) {
            wp_send_json_error(array('code' => 'server_error'), 403);
        }
        
        // Sanitize and validate user_id
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        
        if ($user_id <= 0) {
            wp_send_json_error(array('code' => 'validation'), 400);
        }
        
        // Verify user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error(array('code' => 'validation'), 400);
        }
        
        // Verify membership token (security check to prevent abuse)
        $membership_token = isset($_POST['membership_token']) ? sanitize_text_field(wp_unslash($_POST['membership_token'])) : '';
        $stored_token = get_transient('realm_membership_token_' . $user_id);
        
        if (empty($membership_token) || $membership_token !== $stored_token) {
            $this->write_log('Membership token mismatch for user_id: ' . $user_id);
            wp_send_json_error(array('code' => 'server_error'), 403);
        }
        
        // Delete the token (one-time use)
        delete_transient('realm_membership_token_' . $user_id);
        
        // Set membership status to review
        $result = update_user_meta($user_id, 'rmm_membership_status', 'review');
        
        if ($result === false && get_user_meta($user_id, 'rmm_membership_status', true) !== 'review') {
            // Update failed and value is not already 'review'
            $this->write_log('Failed to update membership status for user_id: ' . $user_id);
            wp_send_json_error(array('code' => 'server_error'), 500);
        }
        
        // Return success
        wp_send_json_success();
    }
}

