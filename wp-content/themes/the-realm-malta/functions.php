<?php

require_once('classes/trm-core.php');
require_once('classes/trm-marketing.php');
require_once('classes/wc-hooks.php');
require_once('classes/metabox-hooks.php');
require_once('classes/acf-hooks.php');

new TRM_Marketing_Handler();
new TRM_WC_Hooks();
new TRM_MB_Hooks();
new TRM_ACF_Hooks();

function trm_load_parent_stylesheets()
{
    $version = time();
    wp_enqueue_style('site-layout', get_stylesheet_directory_uri() . '/assets/css/layout.css', [], $version);
}

add_action('wp_enqueue_scripts', 'trm_load_parent_stylesheets');

add_action('admin_enqueue_scripts', 'trm_load_admin_scripts');

function trm_load_admin_scripts()
{
    $version = time();
    wp_enqueue_style('admin-styles', get_stylesheet_directory_uri() . '/assets/css/admin/admin.css', [], $version);
}

add_action('wp_enqueue_scripts', 'trm_load_child_theme_scripts_styles');
add_action('wp_enqueue_scripts', 'trm_load_child_theme_external_scripts_styles');

function trm_load_child_theme_scripts_styles()
{
    wp_enqueue_script('trm-wc-product-cat-carousel', get_stylesheet_directory_uri() . '/assets/js/wc-product-cat-carousel.js', array('jquery', 'slick-js'), time());
}

function trm_load_child_theme_external_scripts_styles()
{
    // Slick carousel assets
    wp_enqueue_style('slick-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.css', array(), '1.9.0');
    wp_enqueue_style('slick-theme-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick-theme.min.css', array(), '1.9.0');
    wp_enqueue_script('slick-js', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.js', array('jquery'), '1.9.0', true);
}