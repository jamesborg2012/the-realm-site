<?php

namespace GW\OrderTracker\Ajax;

use GW\OrderTracker\Services\OrderQuery;

if (!defined('ABSPATH')) {
    exit;
}

class Orders
{

    public static function init(): void
    {
        add_action('wp_ajax_gwot_fetch_orders', [__CLASS__, 'fetch_orders']);
        add_action('wp_ajax_gwot_fetch_order_items', [__CLASS__, 'fetch_order_items']);
        add_action('wp_ajax_gwot_export_csv', [__CLASS__, 'export_csv']);

        add_action('wp_ajax_gwot_get_item_meta', [__CLASS__, 'get_item_meta']);
        add_action('wp_ajax_gwot_update_item_meta', [__CLASS__, 'update_item_meta']);
    }

    protected static function can_access(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function fetch_orders(): void
    {
        check_ajax_referer('gwot_nonce', 'nonce');

        if (!self::can_access()) {
            wp_send_json_error(['message' => __('Unauthorized', 'gwot')], 403);
        }

        $page    = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        $perPage = isset($_POST['perPage']) ? (int) $_POST['perPage'] : 10;

        // Interpret “latest first” as DESC; change to 'ASC' if you truly want oldest->newest.
        $orderDir = 'DESC';

        $from_date = isset($_POST['fromDate']) ? sanitize_text_field($_POST['fromDate']) : '';
        $to_date   = isset($_POST['toDate']) ? sanitize_text_field($_POST['toDate']) : '';

        // Validate dates
        $from_date = $from_date && strtotime($from_date) ? $from_date : '';
        $to_date   = $to_date && strtotime($to_date) ? $to_date : '';

        if ($to_date && strtotime($to_date) > time()) {
            // Cap at today
            $to_date = date('Y-m-d');
        }

        // Pass to the service layer
        $result = OrderQuery::get_orders($page, $perPage, $orderDir, $from_date, $to_date);

        ob_start();
        foreach ($result['orders'] as $order) {
            $order_id  = $order->get_id();
            $cust      = OrderQuery::customer_info($order);
            $qty_total = OrderQuery::total_item_qty($order);
            $total     = $order->get_total(); // Includes discounts

            include GWOT_PATH . 'views/order-card.php';
        }
        $cards_html = ob_get_clean();

        ob_start();
        $pagination = [
            'page'        => $result['page'],
            'total_pages' => $result['total_pages'],
        ];
        include GWOT_PATH . 'views/pagination.php';
        $pagination_html = ob_get_clean();

        wp_send_json_success([
            'cards'      => $cards_html,
            'pagination' => $pagination_html,
            'page'       => $result['page'],
            'totalPages' => $result['total_pages'],
        ]);
    }

    public static function fetch_order_items(): void
    {
        check_ajax_referer('gwot_nonce', 'nonce');

        if (!self::can_access()) {
            wp_send_json_error(['message' => __('Unauthorized', 'gwot')], 403);
        }

        $order_id = isset($_POST['orderId']) ? (int) $_POST['orderId'] : 0;
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'gwot')], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'gwot')], 404);
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'item_id'         => $item->get_id(),
                'title'           => $item->get_name(),
                'sku'             => $product ? $product->get_sku() : '',
                'qty'             => (int) $item->get_quantity(),
                'gw_ordered_qty'  => (int) wc_get_order_item_meta($item->get_id(), '_gw_ordered_qty', true),
                'gw_received_qty' => (int) wc_get_order_item_meta($item->get_id(), '_gw_received_qty', true),
                'gw_delivered_qty' => (int) wc_get_order_item_meta($item->get_id(), '_gw_delivered_qty', true),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    public static function export_csv(): void
    {
        check_ajax_referer('gwot_nonce', 'nonce');

        if (!self::can_access()) {
            wp_send_json_error(['message' => __('Unauthorized', 'gwot')], 403);
        }

        $from_date = isset($_POST['fromDate']) ? sanitize_text_field($_POST['fromDate']) : '';
        $to_date   = isset($_POST['toDate']) ? sanitize_text_field($_POST['toDate']) : '';

        if ($to_date && strtotime($to_date) > time()) {
            $to_date = date('Y-m-d');
        }

        // Always sort ascending for exports
        $result = \GW\OrderTracker\Services\OrderQuery::get_orders(1, 9999, 'ASC', $from_date, $to_date);
        $orders = $result['orders'] ?? [];

        if (empty($orders)) {
            wp_send_json_error(['message' => __('No orders found for this date range.', 'gwot')]);
        }

        // Aggregate product quantities
        $aggregated = [];

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $name = $item->get_name();
                $qty  = (int) $item->get_quantity();

                if (!isset($aggregated[$name])) {
                    $aggregated[$name] = 0;
                }
                $aggregated[$name] += $qty;
            }
        }

        // Build CSV
        $csv_lines = ["Name of Product,Quantity Ordered"];
        foreach ($aggregated as $name => $qty) {
            $csv_lines[] = '"' . str_replace('"', '""', $name) . '",' . $qty;
        }

        $csv_content = "\xEF\xBB\xBF" . implode("\n", $csv_lines);

        // Return as plain text for browser download
        wp_send_json_success([
            'csv' => $csv_content,
            'filename' => sprintf('gw_order_%s_%s.csv', $from_date ?: 'start', $to_date ?: 'today'),
        ]);
    }

    public static function get_item_meta(): void
    {
        check_ajax_referer('gwot_nonce', 'nonce');
        if (!self::can_access()) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $item = new \WC_Order_Item_Product($item_id);
        if (!$item->get_id()) {
            wp_send_json_error(['message' => 'Invalid item ID'], 400);
        }

        wp_send_json_success([
            'item_id' => $item_id,
            'title'   => $item->get_name(),
            'qty'     => (int) $item->get_quantity(),
            'gw_ordered_qty'  => (int) wc_get_order_item_meta($item_id, '_gw_ordered_qty', true),
            'gw_received_qty' => (int) wc_get_order_item_meta($item_id, '_gw_received_qty', true),
            'gw_delivered_qty' => (int) wc_get_order_item_meta($item_id, '_gw_delivered_qty', true),
        ]);
    }

    public static function update_item_meta(): void
    {
        check_ajax_referer('gwot_nonce', 'nonce');
        if (!self::can_access()) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $gw_ordered  = isset($_POST['gw_ordered_qty']) ? (int) $_POST['gw_ordered_qty'] : 0;
        $gw_received = isset($_POST['gw_received_qty']) ? (int) $_POST['gw_received_qty'] : 0;
        $gw_delivered = isset($_POST['gw_delivered_qty']) ? (int) $_POST['gw_delivered_qty'] : 0;

        if (!$item_id) {
            wp_send_json_error(['message' => 'Invalid item ID'], 400);
        }

        wc_update_order_item_meta($item_id, '_gw_ordered_qty', $gw_ordered);
        wc_update_order_item_meta($item_id, '_gw_received_qty', $gw_received);
        wc_update_order_item_meta($item_id, '_gw_delivered_qty', $gw_delivered);

        wp_send_json_success(['message' => 'Item updated']);
    }
}
