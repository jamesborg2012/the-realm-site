<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX endpoints for the Sales System New Order page.
 *
 * All endpoints are admin-only (no nopriv) and require the `manage_woocommerce`
 * capability plus the shared nonce.
 */
class RSS_Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_rss_verify_member', [$this, 'verify_member']);
        add_action('wp_ajax_rss_lookup_barcode', [$this, 'lookup_barcode']);
        add_action('wp_ajax_rss_place_order', [$this, 'place_order']);
    }

    /**
     * Gatekeeper: verify nonce + capability or die with a JSON error.
     */
    private function guard(): void
    {
        if (!check_ajax_referer(RSS_Core::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'rss')]);
        }

        if (!current_user_can(RSS_Core::CAP)) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'rss')]);
        }
    }

    /**
     * Resolve a membership number to a member payload.
     *
     * @return array|null Null when no member matches the number.
     */
    private function resolve_member(string $number): ?array
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }

        $users = get_users([
            'meta_key'   => 'rmm_membership_number',
            'meta_value' => $number,
            'number'     => 1,
        ]);

        if (empty($users)) {
            return null;
        }

        $user   = reset($users);
        $status = get_user_meta($user->ID, 'rmm_membership_status', true);
        $expire = get_user_meta($user->ID, 'rmm_membership_expire', true);

        $is_active = ($status === 'active');
        $is_expired = !empty($expire) && strtotime($expire) < strtotime('today');
        $discount_applies = $is_active && !$is_expired;

        $first = get_user_meta($user->ID, 'billing_first_name', true) ?: get_user_meta($user->ID, 'first_name', true);
        $last  = get_user_meta($user->ID, 'billing_last_name', true) ?: get_user_meta($user->ID, 'last_name', true);

        return [
            'user_id'          => (int) $user->ID,
            'first_name'       => $first,
            'last_name'        => $last,
            'email'            => $user->user_email,
            'is_active'        => $is_active,
            'is_expired'       => $is_expired,
            'discount_applies' => $discount_applies,
            'discount_pct'     => $discount_applies ? (float) get_option('rmm_member_store_discount', 18) : 0.0,
        ];
    }

    /**
     * Verify a member number and return their details + applicable discount.
     */
    public function verify_member(): void
    {
        $this->guard();

        $number = isset($_POST['member_number']) ? sanitize_text_field(wp_unslash($_POST['member_number'])) : '';

        if ($number === '') {
            wp_send_json_error(['message' => __('Please enter a member number.', 'rss')]);
        }

        $member = $this->resolve_member($number);

        if ($member === null) {
            wp_send_json_error(['message' => __('No member found for that number.', 'rss')]);
        }

        $message = '';
        if ($member['is_expired']) {
            $message = __('This membership has expired — no discount will be applied.', 'rss');
        } elseif (!$member['is_active']) {
            $message = __('This membership is not active — no discount will be applied.', 'rss');
        }

        wp_send_json_success([
            'user_id'          => $member['user_id'],
            'first_name'       => $member['first_name'],
            'last_name'        => $member['last_name'],
            'email'            => $member['email'],
            'discount_applies' => $member['discount_applies'],
            'discount_pct'     => $member['discount_pct'],
            'message'          => $message,
        ]);
    }

    /**
     * Resolve a scanned barcode to a product, capturing the live price.
     */
    public function lookup_barcode(): void
    {
        $this->guard();

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';

        if ($code === '') {
            wp_send_json_error(['message' => __('No barcode received.', 'rss')]);
        }

        $product_id = $this->resolve_product_id($code);

        if (!$product_id) {
            wp_send_json_error(['message' => __('Product not found for that barcode.', 'rss')]);
        }

        $product = wc_get_product($product_id);

        if (!$product || !$product->is_purchasable()) {
            wp_send_json_error(['message' => __('Product is not available for sale.', 'rss')]);
        }

        // Price is read straight from the product so the line captures the latest price.
        $price = (float) $product->get_price();

        wp_send_json_success([
            'product_id' => (int) $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => $price,
            'price_html' => $product->get_price_html(),
        ]);
    }

    /**
     * Barcode -> product id, leaning on the Realm Barcode Scanner resolver
     * (hard dependency) and falling back to a plain SKU lookup.
     */
    private function resolve_product_id(string $code): int
    {
        if (class_exists('RealmBarcodeScanner')) {
            $id = RealmBarcodeScanner::resolve_product_fast($code);
            if (!$id) {
                $id = RealmBarcodeScanner::resolve_product_slow_from_meta($code);
            }
            if ($id) {
                return (int) $id;
            }
        }

        $id = wc_get_product_id_by_sku($code);

        return $id ? (int) $id : 0;
    }

    /**
     * Create the WooCommerce order from the page inputs.
     */
    public function place_order(): void
    {
        $this->guard();

        if (!function_exists('wc_create_order')) {
            wp_send_json_error(['message' => __('WooCommerce is not available.', 'rss')]);
        }

        $first = isset($_POST['customer_first']) ? sanitize_text_field(wp_unslash($_POST['customer_first'])) : '';
        $last  = isset($_POST['customer_last']) ? sanitize_text_field(wp_unslash($_POST['customer_last'])) : '';
        $email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $member_number = isset($_POST['member_number']) ? sanitize_text_field(wp_unslash($_POST['member_number'])) : '';
        $notes = isset($_POST['order_notes']) ? sanitize_textarea_field(wp_unslash($_POST['order_notes'])) : '';

        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items = json_decode($items_raw, true);

        // --- Validation -------------------------------------------------
        if ($first === '' || $last === '' || $email === '') {
            wp_send_json_error(['message' => __('Name, Surname and Email are required.', 'rss')]);
        }

        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'rss')]);
        }

        if (!is_array($items) || empty($items)) {
            wp_send_json_error(['message' => __('Add at least one product before placing the order.', 'rss')]);
        }

        // --- Discount: store discount only, members only ----------------
        $discount_pct = 0.0;
        if ($member_number !== '') {
            $member = $this->resolve_member($member_number);
            if ($member !== null && $member['discount_applies']) {
                $discount_pct = $member['discount_pct'];
                // Trust the server-resolved user id over the posted hidden field.
                $user_id = $member['user_id'];
            }
        }

        // --- Build the order --------------------------------------------
        $order = wc_create_order();

        if (is_wp_error($order)) {
            wp_send_json_error(['message' => __('Could not create the order.', 'rss')]);
        }

        if ($user_id > 0) {
            $order->set_customer_id($user_id);
        }

        $order->set_billing_first_name($first);
        $order->set_billing_last_name($last);
        $order->set_billing_email($email);

        foreach ($items as $row) {
            $product_id = isset($row['product_id']) ? absint($row['product_id']) : 0;
            $qty        = isset($row['qty']) ? max(1, absint($row['qty'])) : 0;

            if (!$product_id || !$qty) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                wp_send_json_error([
                    'message' => sprintf(__('A product (ID %d) is no longer available for sale.', 'rss'), $product_id),
                ]);
            }

            // Net (ex-tax) line amount; calculate_totals() rebuilds the tax lines.
            $net = (float) wc_get_price_excluding_tax($product, ['qty' => $qty]);

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity($qty);
            $item->set_subtotal($net);
            $item->set_total($net * (1 - $discount_pct / 100));
            $order->add_item($item);
        }

        if (empty($order->get_items())) {
            wp_send_json_error(['message' => __('No valid products to add to the order.', 'rss')]);
        }

        if ($notes !== '') {
            $order->set_customer_note($notes);
        }

        $order->set_created_via('rss-sales-system');
        $order->set_payment_method('rss_in_store');
        $order->set_payment_method_title(__('In-Store Sale', 'rss'));
        $order->update_meta_data('_rss_sales_system_order', 'yes');
        if ($member_number !== '') {
            $order->update_meta_data('_rss_member_number', $member_number);
        }

        // Recompute taxes/totals (VAT base-country fallback handled by WC for
        // address-less orders, consistent with the item-12 fix).
        $order->calculate_totals(true);
        $order->set_date_paid(time());
        $order->save();

        // Transition to completed + paid; WC reduces stock once on this transition.
        $order->update_status('completed', __('Order placed via Sales System (in-store sale).', 'rss'));

        wp_send_json_success([
            'order_id'   => $order->get_id(),
            'edit_url'   => $order->get_edit_order_url(),
            'total_html' => wc_price($order->get_total()),
            'message'    => sprintf(__('Order #%d placed successfully.', 'rss'), $order->get_id()),
        ]);
    }
}
