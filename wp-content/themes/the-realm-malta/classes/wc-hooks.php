<?php

class TRM_WC_Hooks extends TRM_Core
{
    public function __construct()
    {
        $this->remove_wc_hooks();
        $this->register_hook_callbacks();
    }

    public function remove_wc_hooks()
    {
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    }

    public function register_hook_callbacks()
    {
        add_action('woocommerce_checkout_order_processed', [$this, 'check_marketing_order'], 99, 3);
        add_filter('storefront_credit_link', '__return_false');

        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_custom_product_data_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_product_data_fields']);
    }

    public function check_marketing_order($order_id, $posted_args, $order)
    {
        $order_user = $order->get_user_id();
        $wp_user = get_user_by('ID', $order_user);

        if ($wp_user) {
            $user_role = $wp_user->roles;
            $user_role = reset($user_role);

            if ($user_role == 'marketing') {
                $order->update_meta_data('trm_is_marketing_order', 'yes');
                $order->save();
            }
        }
    }

    public function add_custom_product_data_fields()
    {
        woocommerce_wp_text_input(
            array(
                'id' => '_product_code',
                'placeholder' => 'GW Product Code',
                'label' => __('GW Product Code', 'woocommerce'),
                'desc_tip' => 'true'
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_ssc_code',
                'placeholder' => 'GW SSC Code',
                'label' => __('GW SSC Code', 'woocommerce'),
                'desc_tip' => 'true'
            )
        );
    }

    public function save_custom_product_data_fields($post_id)
    {
        $custom_field_keys = [
            '_product_code',
            '_ssc_code'
        ];

        foreach ($custom_field_keys as $custom_field_key) {
            if (isset($_POST[$custom_field_key])) {
                update_post_meta($post_id, $custom_field_key, $_POST[$custom_field_key]);
            }
        }
    }
}
