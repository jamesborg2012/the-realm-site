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
        add_action('wp_ajax_rss_search_products', [$this, 'search_products']);
        add_action('wp_ajax_rss_place_order', [$this, 'place_order']);
        add_action('wp_ajax_rss_place_marketing_order', [$this, 'place_marketing_order']);
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
     * Whether the request is coming from the New Marketing Order screen (the
     * shared view/JS pass `mode = 'marketing'`). Marketing orders are priced at
     * cost with zero VAT rather than at the live retail price.
     */
    private function is_marketing_request(): bool
    {
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : '';

        return $mode === 'marketing';
    }

    /**
     * Whether the theme's cost-price system is available. Marketing orders depend
     * on it (a plugin -> theme call that inverts the usual direction), so every
     * caller guards on this before pricing a marketing line.
     */
    private function cost_price_available(): bool
    {
        return class_exists('TRM_Cost_Price_DB')
            && method_exists('TRM_Cost_Price_DB', 'get_current_price');
    }

    /**
     * Resolve a product's *current* cost price via the theme's cost-price system.
     *
     * Returns the recorded cost as a float, or null when no cost-price row exists
     * for the product (or the system is unavailable). A stored 0.00 is a valid,
     * chargeable price and is returned as 0.0 — only a missing row yields null, so
     * callers must test with `=== null`, never a truthy check.
     *
     * The effective product id follows the rest of the system: for a variation,
     * its own (variation) id; otherwise the product id.
     *
     * @return float|null
     */
    private function resolve_cost_price(int $product_id, int $variation_id = 0): ?float
    {
        if (!$this->cost_price_available()) {
            return null;
        }

        $effective_id = $variation_id > 0 ? $variation_id : $product_id;
        $row          = TRM_Cost_Price_DB::get_current_price($effective_id);

        // No row at all -> the product has no cost price. Distinct from a row whose
        // cost_price is 0.00, which is a real (free) cost and returns 0.0 below.
        if ($row === null) {
            return null;
        }

        return (float) $row->cost_price;
    }

    /**
     * The variation id to key cost prices on when the resolved product is a
     * variation (0 for simple/parent products).
     */
    private function cost_variation_id(WC_Product $product): int
    {
        return $product->is_type('variation') ? (int) $product->get_id() : 0;
    }

    /**
     * Resolve a scanned barcode to a product, capturing the live price. In
     * marketing mode the returned price is the product's cost price (zero-VAT),
     * not the retail price, and a product with no cost price is rejected.
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

        // Marketing orders price every line at cost with zero VAT, so the lookup
        // returns the cost price as the product's price (ex == incl == net cost).
        if ($this->is_marketing_request()) {
            if (!$this->cost_price_available()) {
                wp_send_json_error(['message' => __('The cost-price system is unavailable, so marketing orders cannot be priced.', 'rss')]);
            }

            $cost = $this->resolve_cost_price((int) $product->get_id(), $this->cost_variation_id($product));

            if ($cost === null) {
                wp_send_json_error([
                    'message' => sprintf(__('Cannot add %s — no cost price recorded.', 'rss'), $product->get_name()),
                ]);
            }

            wp_send_json_success([
                'product_id' => (int) $product->get_id(),
                'name'       => $product->get_name(),
                'sku'        => $product->get_sku(),
                'price'      => $cost,
                'price_excl' => $cost,
                'price_incl' => $cost,
                'price_html' => wc_price($cost),
            ]);
        }

        // Price is read straight from the product so the line captures the latest price.
        // Both bases are sent: the inclusive price is the shelf price shown in the
        // modal, while the ex-VAT price is what the cart math (and the order) use, so
        // the cart's discount/subtotal figures match what the order ends up recording.
        $price      = (float) $product->get_price();
        $price_excl = (float) wc_get_price_excluding_tax($product);
        $price_incl = (float) wc_get_price_including_tax($product);

        wp_send_json_success([
            'product_id' => (int) $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => $price,
            'price_excl' => $price_excl,
            'price_incl' => $price_incl,
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
     * Search products by name, SKU or barcode for manual cart entry (the
     * fallback when no camera is available). Returns only purchasable, in-stock
     * products and excludes the `online-only` product brand — in-store sales
     * only deal with items that can actually be handed over the counter.
     */
    public function search_products(): void
    {
        $this->guard();

        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            wp_send_json_error(['message' => __('Enter at least 2 characters to search.', 'rss')]);
        }

        $is_marketing = $this->is_marketing_request();

        if ($is_marketing && !$this->cost_price_available()) {
            wp_send_json_error(['message' => __('The cost-price system is unavailable, so marketing orders cannot be priced.', 'rss')]);
        }

        global $wpdb;

        $ids = [];

        // 1. Exact barcode match first (reuses the scanner resolver), so a typed
        //    or pasted barcode behaves the same as a scan.
        $barcode_id = $this->resolve_product_id($term);
        if ($barcode_id) {
            $ids[] = $barcode_id;
        }

        // 2. Title or SKU LIKE. Online-only products are excluded at the SQL
        //    level so they never consume a result slot.
        $like = '%' . $wpdb->esc_like($term) . '%';
        $found = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND (p.post_title LIKE %s OR sku.meta_value LIKE %s)
                   AND p.ID NOT IN (
                       SELECT tr.object_id
                       FROM {$wpdb->term_relationships} tr
                       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                       INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                       WHERE tt.taxonomy = 'product_brand' AND t.slug = 'online-only'
                   )
                 ORDER BY p.post_title ASC
                 LIMIT 100",
                $like,
                $like
            )
        );

        foreach ($found as $id) {
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique(array_filter($ids)));

        // 3. Resolve, filter (purchasable + in stock + not online-only) and cap
        //    at a sensible number of results.
        $limit   = 25;
        $results = [];

        foreach ($ids as $id) {
            if (count($results) >= $limit) {
                break;
            }

            $product = wc_get_product($id);

            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                continue;
            }

            // Guard the barcode hit too (the SQL list is already filtered).
            if (has_term('online-only', 'product_brand', $id)) {
                continue;
            }

            $entry = [
                'product_id'   => (int) $product->get_id(),
                'name'         => $product->get_name(),
                'sku'          => $product->get_sku(),
                'price'        => (float) $product->get_price(),
                'price_excl'   => (float) wc_get_price_excluding_tax($product),
                'price_incl'   => (float) wc_get_price_including_tax($product),
                'price_html'   => $product->get_price_html(),
                'stock_status' => $product->get_stock_status(),
                'stock_qty'    => $product->get_stock_quantity(),
            ];

            // Marketing mode: price at cost (zero-VAT). A product with no cost
            // price is flagged so the client can show it but refuse to add it.
            if ($is_marketing) {
                $cost = $this->resolve_cost_price((int) $product->get_id(), $this->cost_variation_id($product));

                if ($cost === null) {
                    $entry['cost_missing'] = true;
                    $entry['price']        = null;
                    $entry['price_excl']   = null;
                    $entry['price_incl']   = null;
                    $entry['price_html']   = esc_html__('No cost price', 'rss');
                } else {
                    $entry['price']      = $cost;
                    $entry['price_excl'] = $cost;
                    $entry['price_incl'] = $cost;
                    $entry['price_html'] = wc_price($cost);
                }
            }

            $results[] = $entry;
        }

        if (empty($results)) {
            wp_send_json_error(['message' => __('No matching in-stock products found.', 'rss')]);
        }

        wp_send_json_success(['products' => $results]);
    }

    /**
     * Compute one order line's ex-VAT subtotal and discounted total, mirroring
     * the cart's lineMath() in new-order.js so the order matches the screen.
     *
     * A percentage discount is applied to the ex-VAT base. A fixed-amount
     * discount is money off the **inclusive** (shelf) price — what the customer
     * saves — and is backed out to ex-VAT via the product's own incl/excl ratio.
     * Subtotal and total are rounded independently, exactly as WC stores them.
     *
     * @return array{0:float,1:float} [ex-VAT subtotal, ex-VAT discounted total].
     */
    private function line_amounts(WC_Product $product, int $qty, string $type, float $value): array
    {
        $dec      = wc_get_price_decimals();
        $ex_unit  = (float) wc_get_price_excluding_tax($product);
        $inc_unit = (float) wc_get_price_including_tax($product);
        $ex_sub   = $ex_unit * $qty;
        $inc_sub  = $inc_unit * $qty;
        $factor   = $ex_sub > 0 ? $inc_sub / $ex_sub : 1;

        if ($type === 'amount') {
            $amt          = max(0.0, min($value, $inc_sub));
            $ex_total_raw = ($inc_sub - $amt) / $factor;
        } else {
            $pct          = max(0.0, min($value, 100.0));
            $ex_total_raw = $ex_sub * (1 - $pct / 100);
        }

        return [round($ex_sub, $dec), round($ex_total_raw, $dec)];
    }

    /**
     * Add the posted cart rows to an order as line items, re-resolving each
     * product and recomputing its figures server-side (never trusting client
     * prices). When $allow_discounts is false (marketing orders) every line is
     * charged at full price regardless of any discount the client sent.
     *
     * When $is_marketing is true the line is instead priced at the product's
     * current cost price with **zero VAT** (see below); a line whose product has
     * no cost price aborts the whole order.
     *
     * Dies with a JSON error if a product is no longer purchasable.
     */
    private function add_items_to_order(WC_Order $order, array $items, bool $allow_discounts, bool $is_marketing = false): void
    {
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

            // Marketing line: charged at the recorded cost price with zero VAT.
            // The cost price is re-resolved server-side (never trusting the client)
            // and used verbatim as the net line total — no VAT added, none backed
            // out. A missing cost price aborts the order (place_marketing_order
            // pre-validates so this is a belt-and-braces guard).
            if ($is_marketing) {
                $cost = $this->resolve_cost_price((int) $product->get_id(), $this->cost_variation_id($product));

                if ($cost === null) {
                    wp_send_json_error([
                        'message' => sprintf(__('Cannot place this marketing order — no cost price recorded for %s.', 'rss'), $product->get_name()),
                    ]);
                }

                $line_total = round($cost * $qty, wc_get_price_decimals());

                $item = new WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_quantity($qty);
                $item->set_subtotal($line_total);
                $item->set_total($line_total);
                // Zero VAT: empty the line's tax arrays (total_tax / subtotal_tax = 0).
                // The caller then runs calculate_totals(false) so WooCommerce sums the
                // stored line totals WITHOUT recomputing tax from the store rates — the
                // net cost price is recorded verbatim and the order tax total stays 0.
                $item->set_taxes([]);
                $order->add_item($item);
                continue;
            }

            // Per-line discount (a % or a fixed inclusive amount), recomputed
            // server-side with the same maths as the cart so the order matches
            // exactly what staff saw. calculate_totals() rebuilds the tax lines.
            if ($allow_discounts) {
                $disc_type  = (isset($row['disc_type']) && $row['disc_type'] === 'amount') ? 'amount' : 'percent';
                $disc_value = isset($row['disc_value']) ? (float) $row['disc_value'] : 0.0;
            } else {
                $disc_type  = 'percent';
                $disc_value = 0.0;
            }

            list($subtotal, $total) = $this->line_amounts($product, $qty, $disc_type, $disc_value);

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity($qty);
            $item->set_subtotal($subtotal);
            $item->set_total($total);
            $order->add_item($item);
        }
    }

    /**
     * Resolve the store marketing account an internal marketing order is
     * attributed to: the `realm.marketing` user if it exists, otherwise the
     * first user carrying the `marketing` role. Null when neither is found.
     */
    private function resolve_marketing_user(): ?WP_User
    {
        $user = get_user_by('login', 'realm.marketing');
        if ($user instanceof WP_User) {
            return $user;
        }

        $users = get_users(['role' => 'marketing', 'number' => 1]);
        if (!empty($users)) {
            return reset($users);
        }

        return null;
    }

    /**
     * Create a marketing (internal) WooCommerce order: always attributed to the
     * store marketing account, never carrying a member number or discounts.
     */
    public function place_marketing_order(): void
    {
        $this->guard();

        if (!function_exists('wc_create_order')) {
            wp_send_json_error(['message' => __('WooCommerce is not available.', 'rss')]);
        }

        $marketing_user = $this->resolve_marketing_user();
        if ($marketing_user === null) {
            wp_send_json_error([
                'message' => __('No marketing account found. Create a user named "realm.marketing" (or any user with the "marketing" role) before placing marketing orders.', 'rss'),
            ]);
        }

        $notes     = isset($_POST['order_notes']) ? sanitize_textarea_field(wp_unslash($_POST['order_notes'])) : '';
        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items     = json_decode($items_raw, true);

        if (!is_array($items) || empty($items)) {
            wp_send_json_error(['message' => __('Add at least one product before placing the order.', 'rss')]);
        }

        // Pre-flight: marketing orders are priced at cost with zero VAT, so every
        // line must resolve to a purchasable product WITH a recorded cost price.
        // Validate up front — before wc_create_order() — so a missing cost price
        // (or an unavailable cost-price system) creates nothing at all.
        if (!$this->cost_price_available()) {
            wp_send_json_error(['message' => __('The cost-price system is unavailable, so marketing orders cannot be priced. No order was created.', 'rss')]);
        }

        foreach ($items as $row) {
            $pid = isset($row['product_id']) ? absint($row['product_id']) : 0;
            $qty = isset($row['qty']) ? max(1, absint($row['qty'])) : 0;

            if (!$pid || !$qty) {
                continue;
            }

            $product = wc_get_product($pid);
            if (!$product || !$product->is_purchasable()) {
                wp_send_json_error(['message' => sprintf(__('A product (ID %d) is no longer available for sale.', 'rss'), $pid)]);
            }

            if ($this->resolve_cost_price((int) $product->get_id(), $this->cost_variation_id($product)) === null) {
                wp_send_json_error([
                    'message' => sprintf(__('Cannot place this marketing order — no cost price recorded for %s.', 'rss'), $product->get_name()),
                ]);
            }
        }

        $order = wc_create_order();

        if (is_wp_error($order)) {
            wp_send_json_error(['message' => __('Could not create the order.', 'rss')]);
        }

        $order->set_customer_id($marketing_user->ID);

        // Billing identity comes from the marketing account itself (no customer
        // fields on the marketing page), falling back to its display name.
        $first = get_user_meta($marketing_user->ID, 'billing_first_name', true) ?: get_user_meta($marketing_user->ID, 'first_name', true);
        $last  = get_user_meta($marketing_user->ID, 'billing_last_name', true) ?: get_user_meta($marketing_user->ID, 'last_name', true);
        if ($first === '' && $last === '') {
            $first = $marketing_user->display_name;
        }
        $order->set_billing_first_name($first);
        $order->set_billing_last_name($last);
        $order->set_billing_email($marketing_user->user_email);

        $this->add_items_to_order($order, $items, false, true);

        if (empty($order->get_items())) {
            wp_send_json_error(['message' => __('No valid products to add to the order.', 'rss')]);
        }

        if ($notes !== '') {
            $order->set_customer_note($notes);
        }

        $order->set_created_via('rss-sales-system');
        $order->set_payment_method('rss_in_store');
        $order->set_payment_method_title(__('In-Store Sale (Marketing)', 'rss'));
        $order->update_meta_data('_rss_sales_system_order', 'yes');
        $order->update_meta_data('_rss_marketing_order', 'yes');
        // Stay inline with the rest of the marketing logic: the theme flags
        // marketing orders with this meta on checkout, but programmatic orders
        // skip that hook, so we set it here. (Order-tracker exclusion is by the
        // customer's marketing role, which is already satisfied above.)
        $order->update_meta_data('trm_is_marketing_order', 'yes');

        // Zero VAT on marketing orders. Each line's taxes were emptied in
        // add_items_to_order (total_tax = 0); calculate_totals(false) makes
        // WooCommerce sum the stored line totals WITHOUT recomputing tax from the
        // store rates. On this tax-inclusive store calculate_totals(true) would
        // re-derive VAT from each product's tax class and silently alter the
        // charge — so we deliberately pass false. Result: order tax total = 0 and
        // order total = sum(cost_price x qty), matching the on-screen Total to Pay.
        $order->calculate_totals(false);
        $order->set_date_paid(time());
        $order->save();

        $order->update_status('completed', __('Marketing order placed via Sales System (internal purchase).', 'rss'));

        wp_send_json_success([
            'order_id'   => $order->get_id(),
            'edit_url'   => $order->get_edit_order_url(),
            'total_html' => wc_price($order->get_total()),
            'message'    => sprintf(__('Marketing order #%d placed successfully.', 'rss'), $order->get_id()),
        ]);
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

        // --- Member attribution -----------------------------------------
        // Discounts are now set per line on the client (a % or a fixed amount,
        // defaulting to the member's standard discount). The member is resolved
        // only to attribute the order to their account — even if their discount
        // has lapsed, the order is still theirs.
        if ($member_number !== '') {
            $member = $this->resolve_member($member_number);
            if ($member !== null) {
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

        $this->add_items_to_order($order, $items, true);

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
