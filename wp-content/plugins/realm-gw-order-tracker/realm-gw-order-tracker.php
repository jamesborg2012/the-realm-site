<?php

/**
 * Plugin Name: The Realm GW Order Tracker
 * Description: Admin-only order tracker (cards + AJAX expand + pagination). Separate top-level menu.
 * Author: James Borg
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GWOT_PATH', plugin_dir_path(__FILE__));
define('GWOT_URL', plugin_dir_url(__FILE__));
define('GWOT_VER', '1.0.0');

require_once GWOT_PATH . 'src/Core.php';

add_action('plugins_loaded', static function () {
    // Ensure WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    \GW\OrderTracker\Core::init();
});
