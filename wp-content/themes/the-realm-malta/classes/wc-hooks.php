<?php

class TRM_WC_Hooks extends TRM_Core
{
    private const COMM_CODE_MAP = [
        'standard' => [
            '95030095',
            '32131000',
            '42021250',
            '49119100',
            '82031000',
            '82032000',
            '82051000',
            '95030099',
            '95049080',
            '96033010',
        ],
        'reduced-rate' => [
            '49019900',
            '49029000'
        ],
        'zero-rate' => [],
    ];

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
        // Update user's billing meta fields from checkout data when an order is placed
        add_action('woocommerce_checkout_order_processed', [$this, 'update_user_billing_meta_on_order'], 100, 3);
        add_filter('storefront_credit_link', '__return_false');

        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_custom_product_data_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_product_data_fields']);

        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'handle_custom_product_import'], 99, 2);

        add_filter('woocommerce_coupon_message', [$this, 'update_coupon_message_member'], 99, 3);

        add_filter('woocommerce_get_availability_text', [$this, 'set_custom_backorder_availability_text'], 99, 2);
        add_filter('woocommerce_get_availability_class', [$this, 'set_custom_backorder_availability_class'], 99, 2);

        add_filter('product_cat_class', [$this, 'set_custom_product_cat_class'], 99, 3);

        // Exclude products without a price from frontend queries (e.g., product archives and Query Loop)
        add_action('pre_get_posts', [$this, 'exclude_products_without_price'], 99);

        add_action('woocommerce_before_shop_loop', [$this, 'render_sub_category_filter'], 99);

        // Disable registration form on My Account page (custom registration is handled separately)
        add_filter('option_woocommerce_enable_myaccount_registration', [$this, 'disable_myaccount_registration']);

        // Phone is optional everywhere on the site — flip WC's checkout billing_phone field accordingly.
        add_filter('woocommerce_billing_fields', [$this, 'make_billing_phone_optional'], 99);
        add_filter('woocommerce_default_address_fields', [$this, 'make_address_phone_optional'], 99);

        // Billing address is optional at checkout. Two filters are needed:
        //   1. woocommerce_billing_fields — controls the PHP-side initial render + server validation.
        //   2. woocommerce_get_country_locale_default — controls the JS-side locale sync
        //      (assets/js/frontend/address-i18n.js re-applies required asterisks on country change /
        //      page load, reading from the default locale dataset; without this, JS overwrites the
        //      PHP filter result for address_1 / city / postcode / state / address_2).
        // When shipping is eventually enabled, the locale filter will also flip shipping fields to
        // optional (the JS selectors cover #shipping_* too). Restore shipping requirement then by
        // either adding a woocommerce_shipping_fields filter + custom JS, or by re-checking the
        // approach against whatever shipping plugin/config is introduced.
        add_filter('woocommerce_billing_fields', [$this, 'make_billing_address_optional'], 99);
        add_filter('woocommerce_get_country_locale_default', [$this, 'make_locale_address_optional'], 99);
    }

    /**
     * Mark the WC billing phone field as optional at checkout / My Account → Addresses.
     *
     * Hook: woocommerce_billing_fields (priority 99)
     *
     * @param array $fields
     * @return array
     */
    public function make_billing_phone_optional($fields)
    {
        if (isset($fields['billing_phone'])) {
            $fields['billing_phone']['required'] = false;
        }

        return $fields;
    }

    /**
     * Mirror the optional-phone change on the shared address field schema (covers shipping / address book).
     *
     * Hook: woocommerce_default_address_fields (priority 99)
     *
     * @param array $fields
     * @return array
     */
    public function make_address_phone_optional($fields)
    {
        if (isset($fields['phone'])) {
            $fields['phone']['required'] = false;
        }

        return $fields;
    }

    /**
     * Flip every billing address field to optional at checkout / My Account billing address.
     *
     * Identity fields (first/last name, email) are deliberately left as-is — the brief was only about
     * the address. Filtering `woocommerce_billing_fields` rather than `woocommerce_default_address_fields`
     * keeps the shipping address schema untouched, so when shipping is enabled later it remains required
     * by default.
     *
     * Hook: woocommerce_billing_fields (priority 99)
     *
     * @param array $fields
     * @return array
     */
    public function make_billing_address_optional($fields)
    {
        $optional_keys = [
            'billing_country',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
        ];

        foreach ($optional_keys as $key) {
            if (isset($fields[$key])) {
                $fields[$key]['required'] = false;
            }
        }

        return $fields;
    }

    /**
     * Flip the JS-side "default" locale entries so the address-i18n script doesn't re-add the required
     * asterisk after the PHP filter has marked these fields optional.
     *
     * WC ships a `locale` dataset to the browser; on country change / page load, address-i18n.js reads
     * each entry's `required` flag and re-syncs the DOM. The PHP `woocommerce_billing_fields` filter
     * controls the initial render and server-side validation but the JS will overwrite the displayed
     * state from this locale data — hence the need to match the change here.
     *
     * Only the keys present in `WC_Countries::get_country_locale_field_selectors()` are touched
     * (address_1, address_2, city, state, postcode); country isn't part of that JS sync.
     *
     * Hook: woocommerce_get_country_locale_default (priority 99)
     *
     * @param array $locale
     * @return array
     */
    public function make_locale_address_optional($locale)
    {
        $optional_keys = ['address_1', 'address_2', 'city', 'state', 'postcode'];

        foreach ($optional_keys as $key) {
            if (isset($locale[$key])) {
                $locale[$key]['required'] = false;
            }
        }

        return $locale;
    }

    /**
     * Checks if the order is for a marketing purchase
     */
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

    /**
     * Adds custom fields to the WC product data
     */
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

    /**
     * Saves custom fields created for WC Product Data
     */
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
            'ssc_code' => '_ssc_code',
            'commodity_code' => 'tax_class',
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

                //If commodity code - check with list and apply correct tax bracket - Default is 18%

                if ($meta_data['key'] == 'commodity_code' && !empty($meta_data['value'])) {
                    $tax_class = '';

                    // Check if 'commodity_code' has a value
                    if (!empty($meta_data['value'])) {
                        $ccode = $meta_data['value'];
                        if (isset($compared_comodities[$ccode])) {
                            $tax_class = $compared_comodities[$ccode];
                        } else {
                            //Not performance optimal but will have to do the loop once per commodity code
                            foreach (self::COMM_CODE_MAP as $tax => $codes) {
                                foreach ($codes as $code) {
                                    if (stripos($ccode, $code) !== false) {
                                        $tax_class = $tax;
                                        $compared_comodities[$ccode] = $tax;
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    $object->is_taxable(true);
                    $object->set_tax_class($tax_class);
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

    /**
     * Changes text of coupon message for member discounts
     */
    public function update_coupon_message_member($message, $message_code, WC_Coupon $coupon)
    {
        $coupon_data = $coupon->get_data();

        if (strpos($coupon_data['code'], 'storedisc') !== false || strpos($coupon_data['code'], 'onlineonly') !== false) {
            if ($message_code == 200) {
                $message = 'Member Discount Applied!';
            } elseif ($message_code == 201) {
                $message = 'Member Discount Removed!';
            }
        }

        return $message;
    }

    /**
     * Sets custom availability text for WooCommerce products based on stock and backorder status.
     *
     * @param string $availability The default availability text for the product.
     * @param WC_Product $product The WooCommerce product object being checked.
     * @return string The updated availability text.
     */
    public function set_custom_backorder_availability_text($availability, $product)
    {
        if ($product->managing_stock()) {
            if ($product->is_on_backorder()) {
                $availability = 'Product available on order';
            } else if ($product->is_in_stock()) {
                $availability = 'Product available in store';
            }
        }

        return $availability;
    }

    /**
     * Sets a custom CSS class for backorder availability based on the product stock status.
     *
     * @param string $class The current CSS class for the product.
     * @param WC_Product $product The WooCommerce product object.
     * @return string The updated CSS class based on the product's stock status.
     */
    public function set_custom_backorder_availability_class($class, $product)
    {
        if ($product->managing_stock()) {
            if ($product->is_on_backorder()) {
                $class = 'available-on-order';
            } else if ($product->is_in_stock()) {
                $class = "available-in-store";
            }
        }

        return $class;
    }

    public function set_custom_product_cat_class($classes, $class, $category)
    {
        if ($class == 'product-category-item') {
            foreach ($classes as $key => $value) {
                if ($value == 'first' || $value == 'last') {
                    unset($classes[$key]);
                }
            }
        }

        return $classes;
    }

    /**
     * Update the standard WooCommerce billing user meta fields with values submitted at checkout
     * when an order is placed.
     *
     * Hook: woocommerce_checkout_order_processed (priority 100)
     *
     * @param int $order_id
     * @param array $posted_args Raw posted checkout data
     * @param WC_Order $order
     * @return void
     */
    public function update_user_billing_meta_on_order($order_id, $posted_args, $order)
    {
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $user_id = (int)$order->get_user_id();
        if ($user_id <= 0) {
            // No associated user (guest checkout) – nothing to update
            return;
        }

        // Prefer data from the order object (already sanitized by WC); fallback to posted args
        $billing = array(
            'billing_first_name' => $order->get_billing_first_name() ?: ($posted_args['billing_first_name'] ?? ''),
            'billing_last_name' => $order->get_billing_last_name() ?: ($posted_args['billing_last_name'] ?? ''),
            'billing_company' => $order->get_billing_company() ?: ($posted_args['billing_company'] ?? ''),
            'billing_address_1' => $order->get_billing_address_1() ?: ($posted_args['billing_address_1'] ?? ''),
            'billing_address_2' => $order->get_billing_address_2() ?: ($posted_args['billing_address_2'] ?? ''),
            'billing_city' => $order->get_billing_city() ?: ($posted_args['billing_city'] ?? ''),
            'billing_state' => $order->get_billing_state() ?: ($posted_args['billing_state'] ?? ''),
            'billing_postcode' => $order->get_billing_postcode() ?: ($posted_args['billing_postcode'] ?? ''),
            'billing_country' => $order->get_billing_country() ?: ($posted_args['billing_country'] ?? ''),
            'billing_phone' => $order->get_billing_phone() ?: ($posted_args['billing_phone'] ?? ''),
            'billing_email' => $order->get_billing_email() ?: ($posted_args['billing_email'] ?? ''),
        );

        foreach ($billing as $meta_key => $value) {
            // Only update if a value was provided (avoid wiping existing meta with empty strings)
            if (isset($value) && $value !== '') {
                update_user_meta($user_id, $meta_key, wc_clean($value));
            }
        }
    }

    /**
     * Exclude products without a price from frontend product queries, including Query Loop.
     * Ensures only products with a non-empty _price meta appear.
     *
     * Hook: pre_get_posts (priority 99)
     *
     * @param WP_Query $q
     * @return void
     */
    public function exclude_products_without_price($q)
    {
        // Only affect frontend (not admin, AJAX, or REST) and product-related queries.
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Do not alter single product pages.
        if (method_exists($q, 'is_singular') && $q->is_singular('product')) {
            return;
        }

        $post_type = $q->get('post_type');
        $is_product_query = false;

        if ($post_type === 'product') {
            $is_product_query = true;
        } elseif (is_array($post_type) && in_array('product', $post_type, true)) {
            $is_product_query = true;
        } elseif ($q->get('wc_query') || $q->get('product_cat') || $q->get('product_tag')) {
            // Heuristics for product taxonomy/archive queries.
            $is_product_query = true;
        }

        if (!$is_product_query) {
            return;
        }

        $meta_query = (array)$q->get('meta_query');

        // Require _price meta to exist and not be an empty string.
        $meta_query[] = [
            'key' => '_price',
            'compare' => 'EXISTS',
        ];
        $meta_query[] = [
            'key' => '_price',
            'value' => '',
            'compare' => '!=',
        ];

        $q->set('meta_query', $meta_query);
    }

    function render_sub_category_filter()
    {
        $current_term = get_queried_object();

        if ($current_term && $current_term->taxonomy == 'product_cat') {
            /**
             * @var $current_term WP_Term
             */
            //Get all terms which have this term as parent
            $sub_terms = get_terms([
                'taxonomy' => 'product_cat',
                'parent' => $current_term->term_id,
                'hide_empty' => true,
                'fields' => 'all',
                'orderby' => 'name',
                'order' => 'ASC'
            ]);

            if (!is_wp_error($sub_terms) && !empty($sub_terms)) {
                // Load a theme view to render the subcategory list. The template receives $sub_terms.
                if (function_exists('wc_get_template')) {
                    wc_get_template('sub-category-filter.php', [
                        'sub_terms' => $sub_terms,
                        'current_term' => $current_term,
                    ]);
                }
            }
        }
    }

    /**
     * Disable the registration form on the WooCommerce My Account page.
     * Custom registration is handled via a separate registration page/form.
     *
     * Hook: option_woocommerce_enable_myaccount_registration
     *
     * @param string $value The option value ('yes' or 'no')
     * @return string Returns 'no' on the account page, original value elsewhere
     */
    public function disable_myaccount_registration($value)
    {
        if (is_account_page()) {
            return 'no';
        }

        return $value;
    }

}
