<?php
/*
Plugin Name: The Realm GW Excel Uploader
Plugin URI:  https://jamesborg.me
Description: Upload GW Excle Data in the website
Version:     1.0
Author:      James Borg
Author URI:  https://jamesborg.me
*/

if (! defined('ABSPATH')) {
    die('Access denied');
}

define('GWEU_NAME',                 'GW Excel Data Uploader');
define('GWEU_REQUIRED_PHP_VERSION', '8.0');
define('GWEU_REQUIRED_WP_VERSION',  '6.0');

if (gweu_verify_requirements_met()) {
    require('includes/gw-excel-uploader-core.php');
    require('includes/classes/gweu-pages.php');
    require('includes/classes/gweu-upload-handler.php');

    if (class_exists('Realm_Gw_Excel_Uploader_Core')) {
        $GLOBALS['op'] = Realm_Gw_Excel_Uploader_Core::get_instance();
        register_activation_hook(__FILE__, [$GLOBALS['op'], 'activate']);
        register_deactivation_hook(__FILE__, [$GLOBALS['op'], 'deactivate']);
    }
}

function gweu_verify_requirements_met()
{
    global $wp_version;

    if (version_compare(PHP_VERSION, GWEU_REQUIRED_PHP_VERSION, '<')) {
        return false;
    }

    if (version_compare($wp_version, GWEU_REQUIRED_WP_VERSION, '<')) {
        return false;
    }

    return true;
}
