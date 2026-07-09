<?php

/**
 * Plugin Name: The Realm Events Manager
 * Description: Custom post type and taxonomies for managing gaming events.
 * Version: 1.1.0
 * Author: James Borg
 * Text Domain: the-realm-events-manager
 */

if (! defined('ABSPATH')) exit;

// Define constants
define('TREM_PATH', plugin_dir_path(__FILE__));
define('TREM_URL', plugin_dir_url(__FILE__));

// Basic autoloader for all TREM_ classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'TREM_') === 0) {
        $class_name = strtolower(str_replace('_', '-', $class));
        $paths = [
            TREM_PATH . 'includes/',
            TREM_PATH . 'includes/models/',
            TREM_PATH . 'includes/taxonomies/',
        ];
        foreach ($paths as $path) {
            $file = $path . 'class-' . $class_name . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ✅ Explicitly require the main class to guarantee it exists
require_once TREM_PATH . 'includes/class-trem-plugin.php';

// Create the registrations table on activation (the autoloader resolves the
// class; admin_init re-runs the versioned check on every load thereafter).
register_activation_hook(__FILE__, ['TREM_Event_Registrations', 'maybe_install_db']);

// Boot the plugin immediately (not deferred to plugins_loaded)
TREM_Plugin::get_instance();
