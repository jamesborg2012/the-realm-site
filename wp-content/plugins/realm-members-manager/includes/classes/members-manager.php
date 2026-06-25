<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class Members_Manager extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_members_menu']);

        add_action('admin_post_rmm_export_members', [$this, 'export_members_csv']);

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

        add_submenu_page(
            'realm-members',
            'Bulk Import Members',
            'Bulk Import Members',
            'manage_woocommerce',
            'rmm-import-members',
            [$this, 'render_import_members_page']
        );

        add_submenu_page(
            'realm-members',
            'Manage Membership Pricing',
            'Manage Membership Pricing',
            'manage_options',
            'rmm-manage-membership-pricing',
            [$this, 'render_manage_membership_pricing_page']
        );

        add_submenu_page(
            'realm-members',
            'Manage Member Discount',
            'Manage Member Discount',
            'manage_options',
            'rmm-manage-member-discount',
            [$this, 'render_manage_member_discount_page']
        );
    }

    /**
     * Render the operators data manager settings page
     */
    public function render_realm_members_page()
    {
        echo $this->render_template(
            'admin/members-manager-landing-page',
            [
                'users_data' => $this->get_members_data()
            ]
        );
    }

    /**
     * Gathers all customer/member records used by the members page and the CSV export.
     *
     * @return array
     */
    private function get_members_data(): array
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
            $member_number = get_user_meta($site_user->ID, 'rmm_membership_number', true);
            $expires_at = get_user_meta($site_user->ID, 'rmm_membership_expire', true);
            $member_status = get_user_meta($site_user->ID, 'rmm_membership_status', true);

            $is_member_flag = false;

            if ($member_number != '' && $member_status == 'active' && strtotime($expires_at) > strtotime('now')) {
                $is_member_flag = true;
            }

            if($member_status == '') {
                $member_status = 'not_active';
            }

            $current_status = 'Not a Member';
            if($member_status == 'active') {
                $current_status = 'Member';
            } elseif ($member_status == 'review') {
                $current_status = 'Under Review';
            }

            $users_data[] = [
                'id' => $site_user->ID,
                'member_number' => $member_number == '' ? 'N/A' : $member_number,
                'name' => $site_user->first_name . ' ' . $site_user->last_name,
                'email' => $site_user->user_email,
                'phone' => get_user_meta($site_user->ID, 'billing_phone', true),
                'is_member' => $current_status,
                'member_status' => $member_status,
                'expires_at' => $expires_at == '' ? 'N/A' : date('d/m/Y', strtotime($expires_at)),
                'is_member_flag' => $is_member_flag
            ];
        }

        return $users_data;
    }

    /**
     * Streams a CSV of all members as a file download.
     */
    public function export_members_csv()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to export members.'));
        }

        check_admin_referer('rmm_export_members');

        $users_data = $this->get_members_data();

        $filename = 'realm-members-' . date('Y-m-d') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM so Excel reads accented characters correctly.
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Name & Surname',
            'Email',
            'Mobile Number',
            'Member Number',
            'Status',
            'Membership Expires'
        ]);

        foreach ($users_data as $user) {
            fputcsv($output, [
                trim($user['name']),
                $user['email'],
                $user['phone'],
                $user['member_number'] === 'N/A' ? '' : $user['member_number'],
                $user['is_member'],
                $user['expires_at'] === 'N/A' ? '' : $user['expires_at']
            ]);
        }

        fclose($output);
        exit;
    }

    public function add_members_meta_fields($user)
    {
        echo $this->render_template(
            'admin/partials/user-members-meta',
            [
                'rmm_membership_status' => get_user_meta($user->ID, 'rmm_membership_status', true),
                'rmm_membership_number' => get_user_meta($user->ID, 'rmm_membership_number', true),
                'rmm_membership_expire' => get_user_meta($user->ID, 'rmm_membership_expire', true)
            ]
        );
    }

    public function save_members_meta_fields($user_id)
    {
        $membership_status = $_POST['rmm_membership_status'] ?? 'not_active';
        $membership_number = $_POST['rmm_membership_number'] ?? '';
        $membership_expire = $_POST['rmm_membership_expire'] ?? '';

        update_user_meta($user_id, 'rmm_membership_status', $membership_status);
        update_user_meta($user_id, 'rmm_membership_number', $membership_number);
        update_user_meta($user_id, 'rmm_membership_expire', $membership_expire);
    }

    public function render_import_members_page()
    {
        echo $this->render_template(
            'admin/bulk-import-members-page'
        );
    }

    public function render_manage_membership_pricing_page()
    {
        echo $this->render_template(
            'admin/manage-membership-pricing-page'
        );
    }

    public function render_manage_member_discount_page()
    {
        echo $this->render_template(
            'admin/manage-member-discount-page'
        );
    }
}
