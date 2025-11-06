<?php
/*
Plugin Name: The Realm Members Manager
Plugin URI:  https://jamesborg.me
Description: Manage members of the Realm
Version:     1.0
Author:      James Borg
Author URI:  https://jamesborg.me
*/

if (! defined('ABSPATH')) {
    die('Access denied');
}

define('RMM_NAME',                 'Realm Members Manager');
define('RMM_REQUIRED_PHP_VERSION', '8.0');
define('RMM_REQUIRED_WP_VERSION',  '6.0');

if (verify_requirements_met()) {
    require('includes/realm-members-manager-core.php');
    require('includes/classes/members-manager.php');
    require('includes/classes/admin-ajax-handler.php');
    require('includes/classes/rmm-ajax-handler.php');
    require('includes/classes/wc-hooks-handler.php');
    require('includes/classes/rmm-upload-handler.php');
    require('includes/classes/rmm-shortcodes-handler.php');
    require('includes/classes/rmm-settings-handler.php');

    if (class_exists('Realm_Members_Manager_Core')) {
        $GLOBALS['op'] = Realm_Members_Manager_Core::get_instance();
        register_activation_hook(__FILE__, [$GLOBALS['op'], 'activate']);
        register_deactivation_hook(__FILE__, [$GLOBALS['op'], 'deactivate']);
    }
}

function verify_requirements_met()
{
    global $wp_version;

    if (version_compare(PHP_VERSION, RMM_REQUIRED_PHP_VERSION, '<')) {
        return false;
    }

    if (version_compare($wp_version, RMM_REQUIRED_WP_VERSION, '<')) {
        return false;
    }

    return true;
}
