<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_Settings_Handler extends Realm_Members_Manager_Core
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'rmm_register_settings']);
    }

    public function rmm_register_settings()
    {
        register_setting('rmm_membership_pricing_setting_group', 'rmm_12_months_membership');
        register_setting('rmm_membership_pricing_setting_group', 'rmm_9_months_membership');
        register_setting('rmm_membership_pricing_setting_group', 'rmm_6_months_membership');
        register_setting('rmm_membership_pricing_setting_group', 'rmm_3_months_membership');

        add_settings_section(
            'rmm_membership_pricing_section',
            'Membership Pricings',
            null,
            'rmm-membership-pricing-settings'
        );

        add_settings_field(
            'rmm_12_months_membership',
            '12 Months Membership',
            [$this, 'rmm_text_field_callback'],
            'rmm-membership-pricing-settings',
            'rmm_membership_pricing_section',
            ['id' => 'rmm_12_months_membership']
        );

        add_settings_field(
            'rmm_9_months_membership',
            '9 Months Membership',
            [$this, 'rmm_text_field_callback'],
            'rmm-membership-pricing-settings',
            'rmm_membership_pricing_section',
            ['id' => 'rmm_9_months_membership']
        );

        add_settings_field(
            'rmm_6_months_membership',
            '6 Months Membership',
            [$this, 'rmm_text_field_callback'],
            'rmm-membership-pricing-settings',
            'rmm_membership_pricing_section',
            ['id' => 'rmm_6_months_membership']
        );

        add_settings_field(
            'rmm_3_months_membership',
            '3 Months Membership',
            [$this, 'rmm_text_field_callback'],
            'rmm-membership-pricing-settings',
            'rmm_membership_pricing_section',
            ['id' => 'rmm_3_months_membership']
        );

        /// Member Discount Settings
        register_setting('rmm_member_discount_setting_group', 'rmm_guest_discount');
        register_setting('rmm_member_discount_setting_group', 'rmm_member_store_discount');
        register_setting('rmm_member_discount_setting_group', 'rmm_member_online_only_discount');

        add_settings_section(
            'rmm_member_discount_section',
            'Member Discounts',
            null,
            'rmm-member-discount-settings'
        );

        add_settings_field(
            'rmm_guest_discount',
            'Guest Discount (%)',
            [$this, 'rmm_text_field_callback'],
            'rmm-member-discount-settings',
            'rmm_member_discount_section',
            ['id' => 'rmm_guest_discount']
        );

        add_settings_field(
            'rmm_member_store_discount',
            'Member Store Discount (%)',
            [$this, 'rmm_text_field_callback'],
            'rmm-member-discount-settings',
            'rmm_member_discount_section',
            ['id' => 'rmm_member_store_discount']
        );

        add_settings_field(
            'rmm_member_online_only_discount',
            'Member Online Only Discount (%)',
            [$this, 'rmm_text_field_callback'],
            'rmm-member-discount-settings',
            'rmm_member_discount_section',
            ['id' => 'rmm_member_online_only_discount']
        );
    }

    public function rmm_text_field_callback($args)
    {
        $option = get_option($args['id']);
        echo '<input type="number" name="' . esc_attr($args['id']) . '" value="' . esc_attr($option) . '">';
    }
}
