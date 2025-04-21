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

        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'handle_custom_product_import'], 99, 2);
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

    /**
     * Handles custom fields in the CSV that can be imported as per data requirments
     */
    public function handle_custom_product_import(WC_Product $object, $data)
    {
        $custom_fields = [
            'product_code' => '_product_code',
            'ssc_code' => '_ssc_code'
        ];

        if (!empty($data['meta_data'])) {
            foreach ($data['meta_data'] as $meta_data) {

                //Custom product category setting
                if ($meta_data['key'] == 'category' && !empty($meta_data['value'])) {
                    $term_ids = [];

                    $content = html_entity_decode($meta_data['value']);
                    $content = str_replace(['; ', ' ;'], ';', $content);

                    $term_rows = explode(';', $content);

                    foreach ($term_rows as $term_row) {
                        $parent = null;
                        $_terms = array_map('trim', explode('>', $term_row));

                        foreach ($_terms as $_term) {
                            $term = wp_insert_term($_term, 'product_cat', array('parent' => intval($parent)));

                            if (is_wp_error($term)) {
                                if ($term->get_error_code() === 'term_exists') {
                                    // When term exists, error data should contain existing term id.
                                    $term_id = $term->get_error_data();
                                } else {
                                    break; // We cannot continue on any other error.
                                }
                            } else {
                                // New term.
                                $term_id = $term['term_id'];
                            }

                            //If the term has not already been added do so now
                            if (!in_array($term_id, $term_ids)) {
                                $term_ids[] = $term_id;
                            }

                            //Always set the term as the next parent
                            $parent = $term_id;
                        }
                    }

                    //Setting the custom product categories
                    $object->set_category_ids($term_ids);
                    continue;
                }


                $meta_key = $custom_fields[$meta_data['key']] ?? '';

                //Setting custom meta data
                if (!empty($meta_key)) {
                    $object->update_meta_data($meta_key, $meta_data['value']);
                }
            }
        }

        return $object;
    }
}
