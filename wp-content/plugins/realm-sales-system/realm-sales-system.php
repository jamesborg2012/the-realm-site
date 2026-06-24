<?php

/**
 * Plugin Name: Realm Sales System
 * Description: Backend order-placing module ("Sales System") for in-store sales — member lookup, camera barcode scanning, member discounts, and one-click WooCommerce order creation.
 * Version: 0.1.5
 * Author: James Borg
 *
 * Hard dependency: Realm Barcode Scanner (barcode -> product resolution).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSS_PLUGIN_FILE', __FILE__);
define('RSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RSS_DEPENDENCY_BASENAME', 'realm-barcode-scanner/realm-barcode-scanner.php');

/**
 * Whether the hard dependency (Realm Barcode Scanner) is active.
 */
function rss_dependency_met(): bool
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active(RSS_DEPENDENCY_BASENAME) || class_exists('RealmBarcodeScanner');
}

/**
 * Block activation outright when the dependency is missing.
 */
register_activation_hook(__FILE__, function () {
    if (!rss_dependency_met()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Realm Sales System requires the "Realm Barcode Scanner" plugin to be installed and active. Please activate it first.', 'rss'),
            esc_html__('Plugin dependency missing', 'rss'),
            ['back_link' => true]
        );
    }
});

/**
 * Runtime guard: if the dependency is deactivated after the fact, self-deactivate
 * with a notice rather than running in a broken state.
 */
add_action('plugins_loaded', function () {
    if (!rss_dependency_met()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Realm Sales System has been deactivated because the required "Realm Barcode Scanner" plugin is not active.', 'rss')
                . '</p></div>';
        });

        add_action('admin_init', function () {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            deactivate_plugins(plugin_basename(RSS_PLUGIN_FILE));
        });

        return;
    }

    require_once RSS_PLUGIN_DIR . 'includes/class-rss-ajax.php';
    require_once RSS_PLUGIN_DIR . 'includes/class-rss-core.php';

    RSS_Core::instance();
});
