<?php

/**
 * Pre-Order handling.
 *
 * "Pre-order" is defined entirely by the Coming Soon category (configured under
 * WC → Settings → General and owned by TRM_Coming_Soon). A product is a pre-order while it carries
 * that category (or a descendant of it); once TRM_Coming_Soon's daily cron strips the category on
 * the release date, the product becomes a normal product and none of the rules below apply to it
 * any more — exactly the "limit no longer considered after release" requirement.
 *
 * Five concerns:
 *   1. A per-product Pre-Order Limit field (`_trm_preorder_limit`) on the Inventory tab — the max
 *      total quantity that may ever be pre-ordered. Empty = no limit. A read-only "pre-orders placed"
 *      figure is shown alongside it.
 *   2. Cart isolation: a cart may hold pre-order items OR normal items, never both. Enforced on
 *      add-to-cart and re-checked on the cart/checkout pages (catches quantity edits / stale carts).
 *   3. Limit enforcement: add-to-cart and cart validation block exceeding
 *      (limit − already-ordered − already-in-cart).
 *   4. A checkout notice (after the totals) telling the customer payment is required before the
 *      pre-order is confirmed. Editable under WC → Settings → General.
 *   5. Order-count tracking: `_trm_preorder_ordered_count` product meta records the live pre-ordered
 *      quantity. Incremented when an order is placed, decremented when it is cancelled / refunded /
 *      failed. The reversal is driven by per-line-item meta (`_trm_preorder_counted_qty`) recorded at
 *      increment time, so the decrement is exact even if the product has since left the category.
 */
class TRM_Preorder extends TRM_Core
{
    /** Per-product max total pre-order quantity. Empty meta / missing = unlimited. */
    const META_LIMIT = '_trm_preorder_limit';

    /** Per-product live count of pre-ordered quantity across active (non-cancelled) orders. */
    const META_ORDERED_COUNT = '_trm_preorder_ordered_count';

    /** Order-item meta recording which (parent) product id the line is counted against. */
    const ITEM_META_PRODUCT_ID = '_trm_preorder_counted_product_id';

    /** Order-item meta recording how much of this line is currently applied to the product count. */
    const ITEM_META_APPLIED_QTY = '_trm_preorder_applied_qty';

    /** Editable checkout notice text. */
    const OPTION_PAYMENT_MESSAGE = 'trm_preorder_payment_message';

    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        // Admin: per-product limit field + read-only placed count on the Inventory tab.
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);

        // Admin: editable checkout payment notice under WC → Settings → General.
        add_filter('woocommerce_general_settings', [$this, 'add_preorder_setting']);

        // Cart isolation + limit enforcement.
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 5);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_items']);

        // Checkout: payment-required notice after the order total.
        add_action('woocommerce_review_order_after_order_total', [$this, 'render_checkout_notice']);

        // Order-count tracking. Every relevant event funnels into reconcile_order(), which recomputes
        // each pre-order line's contribution to the product count and applies only the delta — so it's
        // fully idempotent however many of these fire for a single order.
        //   - woocommerce_checkout_order_processed: the storefront path (order is fully populated).
        //   - woocommerce_order_status_changed: admin-created orders and every later transition
        //     (cancelled / refunded / failed stop counting; restoring to an active status re-counts).
        //   - woocommerce_order_refunded: partial AND full refunds — a partial refund doesn't change
        //     the order status, so this is the only signal for it; reduces the count per line by the
        //     refunded quantity.
        //   - woocommerce_refund_deleted: a refund being deleted restores the quantity.
        // woocommerce_new_order is deliberately NOT used: it can fire mid-construction before line items
        // are attached, which would count an empty order and undercount.
        add_action('woocommerce_checkout_order_processed', [$this, 'sync_order_count'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'sync_order_count'], 20, 1);
        add_action('woocommerce_order_refunded', [$this, 'sync_order_count'], 20, 1);
        add_action('woocommerce_refund_deleted', [$this, 'on_refund_deleted'], 20, 2);
    }

    /* ---------------------------------------------------------------------
     * Pre-order detection
     * ------------------------------------------------------------------- */

    /**
     * The Coming Soon category id plus all of its descendants — the full set of term ids that mark a
     * product as a pre-order. Empty when no Coming Soon category is configured (feature disabled).
     *
     * @return int[]
     */
    private static function preorder_term_ids()
    {
        if (!class_exists('TRM_Coming_Soon')) {
            return [];
        }

        $cat_id = TRM_Coming_Soon::get_category_id();
        if (!$cat_id) {
            return [];
        }

        $ids = [$cat_id];

        $children = get_term_children($cat_id, 'product_cat');
        if (!is_wp_error($children)) {
            foreach ($children as $child) {
                $ids[] = (int) $child;
            }
        }

        return $ids;
    }

    /**
     * Whether a product is currently a pre-order (i.e. sits in the Coming Soon category tree).
     * Variations inherit the category from their parent.
     *
     * @param WC_Product|mixed $product
     * @return bool
     */
    public static function is_preorder_product($product)
    {
        if (!$product instanceof WC_Product) {
            return false;
        }

        $term_ids = self::preorder_term_ids();
        if (empty($term_ids)) {
            return false;
        }

        return has_term($term_ids, 'product_cat', self::category_post_id($product));
    }

    /**
     * The post id that carries the product_cat terms / limit / count meta for a product — the parent
     * for variations, the product itself otherwise.
     *
     * @param WC_Product $product
     * @return int
     */
    private static function category_post_id($product)
    {
        $parent_id = $product->get_parent_id();

        return $parent_id ? (int) $parent_id : (int) $product->get_id();
    }

    /* ---------------------------------------------------------------------
     * Limit / count accessors
     * ------------------------------------------------------------------- */

    /**
     * The configured pre-order limit for a product, or null when there is no limit.
     *
     * @param int $product_id Parent product id.
     * @return int|null
     */
    public static function get_limit($product_id)
    {
        $raw = get_post_meta($product_id, self::META_LIMIT, true);

        if ($raw === '' || $raw === null) {
            return null;
        }

        return max(0, (int) $raw);
    }

    /**
     * Live count of quantity already pre-ordered for a product across active orders.
     *
     * @param int $product_id Parent product id.
     * @return int
     */
    public static function get_ordered_count($product_id)
    {
        return max(0, (int) get_post_meta($product_id, self::META_ORDERED_COUNT, true));
    }

    /* ---------------------------------------------------------------------
     * Admin: product fields
     * ------------------------------------------------------------------- */

    /**
     * Render the Pre-Order Limit input plus a read-only "pre-orders placed" figure on the Inventory tab.
     *
     * Hook: woocommerce_product_options_inventory_product_data
     *
     * @return void
     */
    public function render_product_fields()
    {
        global $thepostid, $post;

        $post_id = $thepostid ?: ($post ? $post->ID : 0);

        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id'                => self::META_LIMIT,
            'label'             => __('Pre-Order Limit', 'the-realm-malta'),
            'desc_tip'          => true,
            'description'       => __('Maximum total quantity that may be pre-ordered for this product while it is in the Coming Soon category. Leave empty for no limit. Has no effect once the product leaves the Coming Soon category.', 'the-realm-malta'),
            'type'              => 'number',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '1',
            ],
        ]);

        // Read-only informational figure. Submitted in $_POST but deliberately ignored on save — the
        // count is maintained automatically from order placement / cancellation.
        woocommerce_wp_text_input([
            'id'                => 'trm_preorder_ordered_count_display',
            'label'             => __('Pre-Orders Placed', 'the-realm-malta'),
            'desc_tip'          => true,
            'description'       => __('Total quantity pre-ordered so far across active orders. Updated automatically; reduced when an order is cancelled, refunded or fails.', 'the-realm-malta'),
            'value'             => $post_id ? self::get_ordered_count($post_id) : 0,
            'custom_attributes' => [
                'readonly' => 'readonly',
            ],
        ]);

        echo '</div>';
    }

    /**
     * Persist the Pre-Order Limit. Empty clears the meta (= no limit). The placed-count display field
     * is intentionally not saved here.
     *
     * Hook: woocommerce_process_product_meta
     *
     * @param int $post_id
     * @return void
     */
    public function save_product_fields($post_id)
    {
        if (!isset($_POST[self::META_LIMIT])) {
            return;
        }

        $raw = wc_clean(wp_unslash($_POST[self::META_LIMIT]));

        if ($raw === '') {
            delete_post_meta($post_id, self::META_LIMIT);
            return;
        }

        update_post_meta($post_id, self::META_LIMIT, max(0, (int) $raw));
    }

    /* ---------------------------------------------------------------------
     * Admin: checkout notice setting
     * ------------------------------------------------------------------- */

    /**
     * The checkout payment notice text. Editable under WC → Settings → General; falls back to a
     * sensible default when empty.
     *
     * @return string
     */
    private function get_payment_message()
    {
        $message = get_option(self::OPTION_PAYMENT_MESSAGE, '');
        $message = is_string($message) ? trim($message) : '';

        if ($message === '') {
            $message = __('Payment is required before your pre-order can be confirmed.', 'the-realm-malta');
        }

        return $message;
    }

    /**
     * Add a "The Realm — Pre-Orders" section to WC → Settings → General with the editable checkout
     * notice. Stored in option `trm_preorder_payment_message`.
     *
     * Hook: woocommerce_general_settings
     *
     * @param array $settings
     * @return array
     */
    public function add_preorder_setting($settings)
    {
        $section = [
            [
                'title' => __('The Realm — Pre-Orders', 'the-realm-malta'),
                'type'  => 'title',
                'desc'  => __('Shown at checkout when the cart contains pre-order (Coming Soon) products. The pre-order limit is set per product on the Inventory tab.', 'the-realm-malta'),
                'id'    => 'trm_preorder_options',
            ],
            [
                'title'    => __('Checkout Payment Notice', 'the-realm-malta'),
                'desc'     => __('Leave empty to use the default: "Payment is required before your pre-order can be confirmed."', 'the-realm-malta'),
                'id'       => self::OPTION_PAYMENT_MESSAGE,
                'type'     => 'textarea',
                'css'      => 'min-width:400px; min-height:80px;',
                'default'  => '',
                'desc_tip' => false,
            ],
            [
                'type' => 'sectionend',
                'id'   => 'trm_preorder_options',
            ],
        ];

        return array_merge($settings, $section);
    }

    /* ---------------------------------------------------------------------
     * Cart rules
     * ------------------------------------------------------------------- */

    /**
     * Block adding an item that would either mix pre-order with normal products or exceed the
     * product's pre-order limit.
     *
     * Hook: woocommerce_add_to_cart_validation (priority 10)
     *
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @param int $variation_id
     * @param array $variations
     * @return bool
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = [])
    {
        if (!$passed) {
            return $passed;
        }

        if (!class_exists('TRM_Coming_Soon') || !TRM_Coming_Soon::get_category_id()) {
            return $passed;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return $passed;
        }

        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return $passed;
        }

        $adding_preorder = self::is_preorder_product($product);
        $target_pid      = self::category_post_id($product);

        // Inspect the current cart: does it hold pre-order and/or normal items, and how much of THIS
        // product is already in it (matched by parent id, so variations of the same product count).
        $cart_has_preorder = false;
        $cart_has_normal   = false;
        $in_cart_qty       = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_product = isset($cart_item['data']) ? $cart_item['data'] : null;

            if (self::is_preorder_product($cart_product)) {
                $cart_has_preorder = true;
                if (self::category_post_id($cart_product) === $target_pid) {
                    $in_cart_qty += (int) $cart_item['quantity'];
                }
            } else {
                $cart_has_normal = true;
            }
        }

        // Isolation rule.
        if ($adding_preorder && $cart_has_normal) {
            wc_add_notice($this->get_mix_notice(true), 'error');
            return false;
        }

        if (!$adding_preorder && $cart_has_preorder) {
            wc_add_notice($this->get_mix_notice(false), 'error');
            return false;
        }

        // Limit rule (pre-order items only).
        if ($adding_preorder) {
            $limit = self::get_limit($target_pid);

            if ($limit !== null) {
                $already_ordered = self::get_ordered_count($target_pid);
                $available_to_add = $limit - $already_ordered - $in_cart_qty;

                if ($quantity > $available_to_add) {
                    wc_add_notice($this->get_limit_notice($product->get_name(), $available_to_add, $in_cart_qty), 'error');
                    return false;
                }
            }
        }

        return $passed;
    }

    /**
     * Re-validate the whole cart on the cart / checkout pages. Catches quantity edits and stale carts
     * that bypass add-to-cart validation.
     *
     * Hook: woocommerce_check_cart_items
     *
     * @return void
     */
    public function validate_cart_items()
    {
        if (!class_exists('TRM_Coming_Soon') || !TRM_Coming_Soon::get_category_id()) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $has_preorder = false;
        $has_normal   = false;
        $qty_by_pid   = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_product = isset($cart_item['data']) ? $cart_item['data'] : null;

            if (self::is_preorder_product($cart_product)) {
                $has_preorder = true;
                $pid = self::category_post_id($cart_product);
                $qty_by_pid[$pid] = ($qty_by_pid[$pid] ?? 0) + (int) $cart_item['quantity'];
            } else {
                $has_normal = true;
            }
        }

        // Mixing is the headline problem — report it and stop (limit messaging would be noise).
        if ($has_preorder && $has_normal) {
            wc_add_notice($this->get_mix_notice(true), 'error');
            return;
        }

        foreach ($qty_by_pid as $pid => $qty) {
            $limit = self::get_limit($pid);
            if ($limit === null) {
                continue;
            }

            $already_ordered = self::get_ordered_count($pid);

            if ($qty + $already_ordered > $limit) {
                $allowed = max(0, $limit - $already_ordered);
                wc_add_notice(
                    sprintf(
                        /* translators: 1: max quantity still available, 2: product name */
                        __('You can pre-order at most %1$d of "%2$s". Please reduce the quantity in your cart.', 'the-realm-malta'),
                        $allowed,
                        get_the_title($pid)
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * The cart-isolation warning. $adding_preorder distinguishes the two directions.
     *
     * @param bool $adding_preorder True when the blocked action was adding a pre-order item.
     * @return string
     */
    private function get_mix_notice($adding_preorder)
    {
        if ($adding_preorder) {
            return __('Pre-order products must be purchased on their own. Please check out or empty your current cart before adding a pre-order item.', 'the-realm-malta');
        }

        return __('Your cart contains pre-order products, which must be purchased on their own. Please check out or empty your pre-order before adding other items.', 'the-realm-malta');
    }

    /**
     * The limit warning shown on add-to-cart.
     *
     * @param string $name Product name.
     * @param int $available_to_add Quantity that could still be added (may be <= 0).
     * @param int $in_cart_qty Quantity of this product already in the cart.
     * @return string
     */
    private function get_limit_notice($name, $available_to_add, $in_cart_qty)
    {
        if ($available_to_add <= 0) {
            if ($in_cart_qty > 0) {
                return sprintf(
                    /* translators: %s: product name */
                    __('You already have the maximum number of "%s" available to pre-order in your cart.', 'the-realm-malta'),
                    $name
                );
            }

            return sprintf(
                /* translators: %s: product name */
                __('"%s" has reached its pre-order limit and is no longer available to pre-order.', 'the-realm-malta'),
                $name
            );
        }

        return sprintf(
            /* translators: 1: quantity still available, 2: product name */
            __('You can pre-order at most %1$d more of "%2$s".', 'the-realm-malta'),
            $available_to_add,
            $name
        );
    }

    /* ---------------------------------------------------------------------
     * Checkout notice
     * ------------------------------------------------------------------- */

    /**
     * Render the payment-required notice directly beneath the order total at checkout, but only when
     * the cart holds a pre-order item. Output as a totals-table row (the hook fires inside <tfoot>).
     *
     * Hook: woocommerce_review_order_after_order_total
     *
     * @return void
     */
    public function render_checkout_notice()
    {
        if (!class_exists('TRM_Coming_Soon') || !TRM_Coming_Soon::get_category_id()) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $has_preorder = false;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (self::is_preorder_product(isset($cart_item['data']) ? $cart_item['data'] : null)) {
                $has_preorder = true;
                break;
            }
        }

        if (!$has_preorder) {
            return;
        }

        echo '<tr class="trm-preorder-row"><td colspan="2">';
        echo '<div class="trm-preorder-notice">' . wpautop(wp_kses_post($this->get_payment_message())) . '</div>';
        echo '</td></tr>';
    }

    /* ---------------------------------------------------------------------
     * Order-count tracking
     * ------------------------------------------------------------------- */

    /**
     * Statuses whose orders count toward a product's pre-order total. An order counts from the moment
     * it is placed and stops counting only when it is cancelled, refunded, failed or trashed.
     *
     * @param string $status WC status, with or without the "wc-" prefix.
     * @return bool
     */
    private function status_counts($status)
    {
        $status = str_replace('wc-', '', (string) $status);

        return !in_array($status, ['cancelled', 'refunded', 'failed', 'trash'], true);
    }

    /**
     * Reconcile a single order's contribution to product pre-order counts.
     *
     * Hooks: woocommerce_checkout_order_processed, woocommerce_order_status_changed,
     *        woocommerce_order_refunded
     *
     * @param int $order_id
     * @return void
     */
    public function sync_order_count($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $this->reconcile_order($order);
        }
    }

    /**
     * woocommerce_refund_deleted passes ($refund_id, $order_id) — reconcile the parent order so the
     * quantity restored by the deletion is added back to the count.
     *
     * Hook: woocommerce_refund_deleted
     *
     * @param int $refund_id
     * @param int $order_id
     * @return void
     */
    public function on_refund_deleted($refund_id, $order_id)
    {
        $this->sync_order_count($order_id);
    }

    /**
     * Recompute every pre-order line item's contribution to its product's count and apply only the
     * difference from what is currently recorded as applied. This single routine handles placement,
     * cancellation / refund / failure (and restoring from them), and partial refunds — and is
     * idempotent, so the overlapping hooks above can't double-count.
     *
     * The target contribution for a line is:
     *   - 0 when the order is in a non-counting status (cancelled / refunded / failed / trash);
     *   - otherwise (ordered quantity − refunded quantity), clamped at zero. A partial refund leaves
     *     the status counting but raises the refunded quantity, so the contribution drops accordingly.
     *
     * Which lines are tracked is remembered on the item itself (ITEM_META_PRODUCT_ID), set the first
     * time a line is seen as a pre-order line. After that we never re-check the category, so the
     * reversal stays correct even once the product has left the Coming Soon category on release.
     *
     * @param WC_Order $order
     * @return void
     */
    private function reconcile_order($order)
    {
        $counts = $this->status_counts($order->get_status());

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $pid           = (int) $item->get_meta(self::ITEM_META_PRODUCT_ID);
            $newly_tracked = false;

            // Untracked line: only start tracking it if it is currently a pre-order product.
            if (!$pid) {
                $product = $item->get_product();
                if (!self::is_preorder_product($product)) {
                    continue;
                }
                $pid = self::category_post_id($product);
                $item->update_meta_data(self::ITEM_META_PRODUCT_ID, $pid);
                $newly_tracked = true;
            }

            if ($counts) {
                $ordered  = (int) $item->get_quantity();
                $refunded = abs((int) $order->get_qty_refunded_for_item($item->get_id()));
                $target   = max(0, $ordered - $refunded);
            } else {
                $target = 0;
            }

            $applied = (int) $item->get_meta(self::ITEM_META_APPLIED_QTY);
            $delta   = $target - $applied;

            if ($delta !== 0) {
                $this->adjust_product_count($pid, $delta);
                $item->update_meta_data(self::ITEM_META_APPLIED_QTY, $target);
                $item->save();
            } elseif ($newly_tracked) {
                // Persist the product-id we just attached even when there's no count change.
                $item->save();
            }
        }
    }

    /**
     * Apply a signed delta to a product's pre-order count, clamped at zero.
     *
     * @param int $product_id
     * @param int $delta
     * @return void
     */
    private function adjust_product_count($product_id, $delta)
    {
        $new = max(0, self::get_ordered_count($product_id) + $delta);
        update_post_meta($product_id, self::META_ORDERED_COUNT, $new);
    }
}
