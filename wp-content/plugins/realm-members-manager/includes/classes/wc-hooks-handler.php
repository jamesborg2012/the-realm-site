<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_WC_Hooks_Handler extends Realm_Members_Manager_Core
{
    /** True while the mini-cart (drawer) is rendering — there we show real discounted figures. */
    private $in_mini_cart = false;

    public function __construct()
    {
        add_action('woocommerce_before_checkout_form', [$this, 'display_member_number_form'], 40);

        // The pre-discount "original price" display only makes sense on the full cart/checkout,
        // where the "Member Discount" row reconciles it. In the mini-cart there's no such row, so
        // show the real (discounted) prices there instead.
        add_action('woocommerce_before_mini_cart', function () { $this->in_mini_cart = true; }, 0);
        add_action('woocommerce_after_mini_cart', function () { $this->in_mini_cart = false; }, 999);

        // Member discount is applied directly to each cart line (item 23) — replaces the old
        // two-coupons-per-member mechanism. Per-line pricing keeps VAT exact across mixed tax
        // classes and mirrors how the backend Sales System discounts orders.
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_member_line_discount'], 20);

        // Display: show the pre-discount prices on the lines + the subtotal, and surface the
        // saving as a single "Member Discount" row in the totals (the actual charged total stays
        // the discounted one). All gated on an active member so nothing changes for everyone else.
        add_filter('woocommerce_cart_item_price', [$this, 'show_original_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'show_original_item_subtotal'], 10, 3);
        add_filter('woocommerce_cart_subtotal', [$this, 'show_original_cart_subtotal'], 10, 3);
        add_action('woocommerce_cart_totals_before_order_total', [$this, 'render_cart_discount_row']);
        add_action('woocommerce_review_order_before_order_total', [$this, 'render_review_discount_row']);

        // Persist the saving onto the order so it shows on the My Account order view, in order
        // emails and in wp-admin: record each line's pre-discount price as its subtotal (its total
        // stays the discounted, charged amount). WooCommerce then renders the original price on the
        // line + a discount row natively — the same shape a coupon would produce, without a coupon.
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'record_original_subtotal_on_order_item'], 10, 4);
        add_filter('woocommerce_get_order_item_totals', [$this, 'add_member_discount_order_row'], 10, 3);
    }

    public function display_member_number_form()
    {
        $auto_applied  = $this->is_member_active(get_current_user_id());
        $session_value = WC()->session ? WC()->session->get('member_number') : '';

        echo $this->render_template(
            'public/partials/checkout/members-number-form',
            [
                'auto_applied'   => $auto_applied,
                'applied_number' => empty($session_value) ? '' : $session_value,
            ]
        );
    }

    /**
     * Reduces each cart line's price by the member's percentage: the online-only % for products in
     * the `online-only` product_brand, the store % for everything else. The base price is read
     * fresh from the product's `_price` meta each pass so repeated calculations never compound.
     */
    public function apply_member_line_discount($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!$cart instanceof WC_Cart) {
            return;
        }

        if (!$this->resolve_active_member_id()) {
            return;
        }

        $store_pct  = (float) get_option('rmm_member_store_discount', 18);
        $online_pct = (float) get_option('rmm_member_online_only_discount', 8);

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product instanceof WC_Product) {
                continue;
            }

            $base = $this->get_base_price($product);
            if ($base <= 0) {
                continue;
            }

            $pct = $this->is_online_only_product($cart_item['product_id']) ? $online_pct : $store_pct;
            if ($pct <= 0) {
                continue;
            }

            $product->set_price(round($base * (1 - $pct / 100), wc_get_price_decimals()));
        }
    }

    /**
     * Cart line unit price → show the pre-discount price when a member discount is active.
     */
    public function show_original_item_price($price_html, $cart_item, $cart_item_key)
    {
        if ($this->in_mini_cart || !$this->resolve_active_member_id() || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
            return $price_html;
        }

        $base = $this->get_base_price($cart_item['data']);
        if ($base <= 0) {
            return $price_html;
        }

        return wc_price(wc_get_price_to_display($cart_item['data'], ['price' => $base]));
    }

    /**
     * Cart line subtotal → show the pre-discount line subtotal when a member discount is active.
     */
    public function show_original_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if ($this->in_mini_cart || !$this->resolve_active_member_id() || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
            return $subtotal_html;
        }

        $base = $this->get_base_price($cart_item['data']);
        if ($base <= 0) {
            return $subtotal_html;
        }

        $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;

        return wc_price(wc_get_price_to_display($cart_item['data'], ['price' => $base, 'qty' => $qty]));
    }

    /**
     * Totals "Subtotal" row → show the pre-discount subtotal so it reconciles with the per-line
     * prices above and the "Member Discount" row below.
     */
    public function show_original_cart_subtotal($subtotal_html, $compound, $cart)
    {
        if ($this->in_mini_cart || !$this->resolve_active_member_id()) {
            return $subtotal_html;
        }

        $original = $this->original_display_subtotal($cart);
        if ($original <= 0) {
            return $subtotal_html;
        }

        return wc_price($original);
    }

    /**
     * "Member Discount" row on the cart totals table.
     */
    public function render_cart_discount_row()
    {
        $savings = $this->member_savings(WC()->cart);
        if ($savings <= 0) {
            return;
        }
        ?>
        <tr class="cart-discount rmm-member-discount">
            <th><?php esc_html_e('Member Discount', 'realm-members-manager'); ?></th>
            <td data-title="<?php esc_attr_e('Member Discount', 'realm-members-manager'); ?>">-<?php echo wc_price($savings); // phpcs:ignore ?></td>
        </tr>
        <?php
    }

    /**
     * "Member Discount" row on the checkout review-order totals table.
     */
    public function render_review_discount_row()
    {
        $savings = $this->member_savings(WC()->cart);
        if ($savings <= 0) {
            return;
        }
        ?>
        <tr class="cart-discount rmm-member-discount">
            <th><?php esc_html_e('Member Discount', 'realm-members-manager'); ?></th>
            <td>-<?php echo wc_price($savings); // phpcs:ignore ?></td>
        </tr>
        <?php
    }

    /**
     * On order creation, set each discounted line's subtotal to its pre-discount amount (its total
     * stays the charged, discounted amount). WooCommerce then shows the original line price + a
     * discount row on the order (My Account, emails, admin). The subtotal tax is scaled up from the
     * charged tax at the same rate, so VAT stays exact and location-independent.
     */
    public function record_original_subtotal_on_order_item($item, $cart_item_key, $values, $order)
    {
        if (!$this->resolve_active_member_id()) {
            return;
        }

        $product = isset($values['data']) ? $values['data'] : null;
        if (!$product instanceof WC_Product) {
            return;
        }

        $base = $this->get_base_price($product);
        if ($base <= 0) {
            return;
        }

        $qty = isset($values['quantity']) ? (int) $values['quantity'] : 1;

        $original_subtotal = (float) wc_get_price_excluding_tax($product, ['price' => $base, 'qty' => $qty]);
        $charged_total     = (float) $item->get_total();

        // Nothing to record if this line wasn't actually discounted.
        if ($charged_total <= 0 || $original_subtotal <= $charged_total) {
            return;
        }

        $scale = $original_subtotal / $charged_total;

        $taxes = $item->get_taxes();
        if (!empty($taxes['subtotal'])) {
            foreach ($taxes['subtotal'] as $rate_id => $amount) {
                $taxes['subtotal'][$rate_id] = (float) $amount * $scale;
            }
            $item->set_taxes($taxes);
        }

        $item->set_subtotal($original_subtotal);

        $order->update_meta_data('_rmm_member_discount_applied', 'yes');
        $number = get_user_meta($this->resolve_active_member_id(), 'rmm_membership_number', true);
        if (!empty($number)) {
            $order->update_meta_data('_rmm_member_number', $number);
        }
    }

    /**
     * Adds a "Member Discount" row to the order totals (My Account order view + emails) computed
     * from the gap between each line's original subtotal and its charged total. Injected directly
     * rather than via WooCommerce's coupon-owned discount_total, so it's exact and self-contained.
     */
    public function add_member_discount_order_row($total_rows, $order, $tax_display)
    {
        if (!$order instanceof WC_Order || $order->get_meta('_rmm_member_discount_applied') !== 'yes') {
            return $total_rows;
        }

        $ex_tax  = ($tax_display === 'excl');
        $savings = 0.0;

        foreach ($order->get_items() as $item) {
            $subtotal = $ex_tax
                ? (float) $item->get_subtotal()
                : (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
            $total = $ex_tax
                ? (float) $item->get_total()
                : (float) $item->get_total() + (float) $item->get_total_tax();

            $savings += ($subtotal - $total);
        }

        if ($savings <= 0) {
            return $total_rows;
        }

        // Insert the discount row immediately after the subtotal row.
        $rows = [];
        foreach ($total_rows as $key => $row) {
            $rows[$key] = $row;
            if ($key === 'cart_subtotal') {
                $rows['rmm_member_discount'] = [
                    'label' => __('Member Discount:', 'realm-members-manager'),
                    'value' => wc_price(-1 * $savings, ['currency' => $order->get_currency()]),
                ];
            }
        }

        return $rows;
    }

    /**
     * The pre-discount subtotal (in display context, so tax-inclusive when the store displays
     * inclusive prices).
     */
    private function original_display_subtotal($cart): float
    {
        if (!$cart instanceof WC_Cart) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product instanceof WC_Product) {
                continue;
            }
            $base = $this->get_base_price($product);
            if ($base <= 0) {
                continue;
            }
            $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $total += (float) wc_get_price_to_display($product, ['price' => $base, 'qty' => $qty]);
        }

        return $total;
    }

    /**
     * The member's total saving = pre-discount display subtotal minus the (already discounted)
     * displayed subtotal WooCommerce calculated.
     */
    private function member_savings($cart): float
    {
        if (!$cart instanceof WC_Cart || !$this->resolve_active_member_id()) {
            return 0.0;
        }

        $original   = $this->original_display_subtotal($cart);
        $discounted = (float) $cart->get_displayed_subtotal();

        $saving = $original - $discounted;

        return $saving > 0 ? round($saving, wc_get_price_decimals()) : 0.0;
    }

    /**
     * The product's current (pre-member-discount) active price, read from `_price` meta so
     * repeated total calculations don't compound the discount.
     */
    private function get_base_price($product): float
    {
        $price = get_post_meta($product->get_id(), '_price', true);
        if ($price === '' || $price === null) {
            $price = $product->get_price();
        }

        return (float) $price;
    }

    /**
     * Resolves the member whose discount should apply: an active, logged-in member first
     * (auto-applied), otherwise a guest who has applied a valid member number this session.
     */
    private function resolve_active_member_id(): int
    {
        $current_user_id = get_current_user_id();
        if ($current_user_id && $this->is_member_active($current_user_id)) {
            return $current_user_id;
        }

        $session_member_id = WC()->session ? (int) WC()->session->get('member_user_id') : 0;
        if ($session_member_id && $this->is_member_active($session_member_id)) {
            return $session_member_id;
        }

        return 0;
    }

    /**
     * True when the user is a member with active status and an unexpired membership.
     */
    private function is_member_active($user_id): bool
    {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return false;
        }

        if (get_user_meta($user_id, 'rmm_membership_status', true) !== 'active') {
            return false;
        }

        $expire = get_user_meta($user_id, 'rmm_membership_expire', true);
        if (empty($expire)) {
            return false;
        }

        return strtotime($expire) >= strtotime('today');
    }

    /**
     * True when the product (matched by its parent id so variations resolve correctly) is in the
     * `online-only` product_brand term.
     */
    private function is_online_only_product($product_id): bool
    {
        $terms = get_the_terms($product_id, 'product_brand');
        if (!is_array($terms)) {
            return false;
        }

        foreach ($terms as $term) {
            if ($term->slug === 'online-only') {
                return true;
            }
        }

        return false;
    }
}
