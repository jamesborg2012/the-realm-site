<?php

require_once('classes/trm-core.php');
require_once('classes/trm-marketing.php');
require_once('classes/wc-hooks.php');
require_once('classes/metabox-hooks.php');
require_once('classes/acf-hooks.php');
require_once('classes/ajax-hooks.php');
require_once('classes/cost-price/trm-cost-price.php');
require_once('classes/profit-analytics/trm-profit-analytics.php');

new TRM_Marketing_Handler();
new TRM_WC_Hooks();
new TRM_MB_Hooks();
new TRM_ACF_Hooks();
new TRM_AJAX_Hooks();
new TRM_Cost_Price();
new TRM_Profit_Analytics();
new TRM_Core();

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
    
    // Enqueue selectWoo (WooCommerce's select2 wrapper) for searchable dropdowns
    if (function_exists('WC')) {
        wp_enqueue_style('select2');
        wp_enqueue_script('selectWoo');
    }
    
    // AJAX script for account creation (with selectWoo dependency if available)
    $ajax_dependencies = array('jquery');
    if (function_exists('WC') && wp_script_is('selectWoo', 'registered')) {
        $ajax_dependencies[] = 'selectWoo';
    }
    
    wp_enqueue_script('trm-ajax', get_stylesheet_directory_uri() . '/assets/js/ajax.js', $ajax_dependencies, time(), true);
    wp_localize_script('trm-ajax', 'trmAjax', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('realm_register_customer'),
        'membershipNonce' => wp_create_nonce('realm_apply_membership'),
        'messages' => array(
            'duplicate' => 'The account you are registering already exists.',
            'error' => 'Please wait and try again later.',
            'success' => 'Your account is registered and will be finalised soon after review.',
            'membershipSuccess' => 'Thank you for applying to be a member! You will be contacted shortly to finalise payment and join this amazing community!'
        )
    ));
}

function trm_load_child_theme_external_scripts_styles()
{
    // Slick carousel assets
    wp_enqueue_style('slick-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.css', array(), '1.9.0');
    wp_enqueue_style('slick-theme-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick-theme.min.css', array(), '1.9.0');
    wp_enqueue_script('slick-js', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.9.0/slick.min.js', array('jquery'), '1.9.0', true);
}