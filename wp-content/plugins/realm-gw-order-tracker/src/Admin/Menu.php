<?php

namespace GW\OrderTracker\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Menu
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            __('GW Order Tracker', 'gwot'),
            __('GW Order Tracker', 'gwot'),
            'manage_woocommerce',               // Shop Manager + Admin
            'gw-order-tracker',
            [Page::class, 'render'],
            'dashicons-clipboard',
            58                                   // Place near WooCommerce
        );
    }
}
