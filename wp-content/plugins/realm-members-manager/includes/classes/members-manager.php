<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class Members_Manager extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_members_menu']);

        add_action('show_user_profile', array($this, 'add_members_meta_fields'));
        add_action('edit_user_profile', array($this, 'add_members_meta_fields'));

        add_action('personal_options_update', array($this, 'save_members_meta_fields'));
        add_action('edit_user_profile_update', array($this, 'save_members_meta_fields'));
    }

    /**
     * Register custom menu options
     */
    public function register_members_menu()
    {
        add_menu_page(
            'The Realm Members',
            'The Realm Members',
            'manage_woocommerce',
            'realm-members',
            [$this, 'render_realm_members_page']
        );
    }

    /**
     * Render the operators data manager settings page
     */
    public function render_realm_members_page()
    {
        $site_users = get_users([
            'role' => 'customer',
            'orderby' => 'user_registered',
            'order' => 'ASC'
        ]);

        $users_data = [];
        /**
         * @var WP_User $site_user
         */
        foreach ($site_users as $site_user) {
            $member_number = get_user_meta($site_user->ID, 'rmm_member_number', true);
            $expires_at = get_user_meta($site_user->ID, 'rmm_membership_expires', true);

            $users_data[] = [
                'id' => $site_user->ID,
                'member_number' => $member_number == '' ? 'N/A' : $member_number,
                'name' => $site_user->first_name . ' ' . $site_user->last_name,
                'email' => $site_user->user_email,
                'phone' => get_user_meta($site_user->ID, 'billing_phone', true),
                'is_member' => get_user_meta($site_user->ID, 'rmm_membership_status', true) == 'active' ? 'Member' : 'Not a Member',
                'expires_at' => $expires_at == '' ? 'N/A' : $expires_at,
            ];
        }

        echo $this->render_template(
            'admin/members-manager-landing-page',
            [
                'users_data' => $users_data
            ]
        );
    }

    public function add_members_meta_fields($user)
    {
        echo $this->render_template(
            'admin/partials/user-members-meta',
            [
                'user_membership_status' => get_user_meta($user->ID, 'rmm_membership_status', true)
            ]
        );
    }

    public function save_members_meta_fields($user_id)
    {
        $membership_status = $_POST['rmm_membership_status'] ?? 'not_active';
        update_user_meta($user_id, 'rmm_membership_status', $membership_status);
    }
}
