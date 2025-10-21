<?php

namespace GW\OrderTracker;

if (!defined('ABSPATH')) {
    exit;
}

class Core
{
    public static function init(): void
    {
        self::includes();
        Admin\Menu::init();
        Admin\Page::init();
        Ajax\Orders::init();
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    protected static function includes(): void
    {
        require_once GWOT_PATH . 'src/Admin/Menu.php';
        require_once GWOT_PATH . 'src/Admin/Page.php';
        require_once GWOT_PATH . 'src/Services/OrderQuery.php';
        require_once GWOT_PATH . 'src/Ajax/Orders.php';
    }

    public static function enqueue_admin_assets($hook_suffix): void
    {
        // Only load on our page
        if ($hook_suffix !== 'toplevel_page_gw-order-tracker') {
            return;
        }

        wp_enqueue_style(
            'gwot-admin',
            GWOT_URL . 'assets/css/admin.css',
            [],
            GWOT_VER
        );

        wp_enqueue_script(
            'gwot-admin',
            GWOT_URL . 'assets/js/admin.js',
            ['jquery'],
            GWOT_VER,
            true
        );

        wp_localize_script('gwot-admin', 'GWOT', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('gwot_nonce'),
            'perPage' => 10,
        ]);
    }
}
