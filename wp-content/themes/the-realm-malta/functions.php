<?php

require_once('classes/trm-core.php');
require_once('classes/wc-hooks.php');
require_once('classes/metabox-hooks.php');

new TRM_Core();
new TRM_WC_Hooks();
new TRM_MB_Hooks();

function wpse_load_parent_stylesheets()
{
    $version = time();
    wp_enqueue_style('site-layout', get_stylesheet_directory_uri() . '/assets/css/layout.css', [], $version);
}
add_action('wp_enqueue_scripts', 'wpse_load_parent_stylesheets');
