<?php

namespace GW\OrderTracker\Services;

use WC_Order_Query;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

class OrderQuery
{

    /**
     * Fetch paginated orders for registered customers (non-guests).
     * Default sort: newest first (DESC).
     */
    public static function get_orders(
        int $page = 1,
        int $per_page = 10,
        string $order = 'DESC',
        string $from_date = '',
        string $to_date = ''
    ): array {
        $marketing_users = get_users(['role' => 'marketing', 'fields' => 'ID']);
        $marketing_ids   = !empty($marketing_users) ? array_map('intval', $marketing_users) : [0];

        $page      = max(1, $page);
        $per_page  = max(1, $per_page);
        $offset    = ($page - 1) * $per_page;

        $args = [
            'limit'    => $per_page,
            'offset'   => $offset,
            'type'     => 'shop_order',
            'orderby'  => 'date',
            'order'    => $from_date || $to_date ? 'ASC' : $order,
            'return'   => 'objects',
            'status'   => array_keys(wc_get_order_statuses()),
        ];

        // Add date range to args
        if ($from_date) {
            $args['date_after'] = gmdate('Y-m-d 00:00:00', strtotime($from_date));
        }
        if ($to_date) {
            $args['date_before'] = gmdate('Y-m-d 23:59:59', strtotime($to_date));
        }

        $orders = wc_get_orders($args);

        // Filter out orders placed by users with the "marketing" role
        $orders = array_filter($orders, function ($order) {
            $user_id = $order->get_user_id();

            if (!$user_id) {
                return true;
            }

            $user = get_userdata($user_id);
            if (!$user || empty($user->roles)) {
                return true;
            }

            return !in_array('marketing', (array) $user->roles, true);
        });

        // Repeat same logic for count
        $count_args = $args;
        $count_args['limit']  = -1;
        $count_args['offset'] = 0;
        $count_args['return'] = 'ids';
        $query = new \WC_Order_Query($count_args);
        $all_ids = $query->get_orders();

        $filtered_count = 0;
        if (!empty($all_ids)) {
            foreach ($all_ids as $order_id) {
                $wc_order = wc_get_order($order_id);
                $customer_id = $wc_order->get_customer_id();

                if (!$customer_id) {
                    $filtered_count++;
                    continue;
                }

                if (!in_array($customer_id, $marketing_ids, true)) {
                    $filtered_count++;
                    continue;
                }
            }
        }

        return [
            'orders'      => array_values($orders),
            'total'       => $filtered_count,
            'per_page'    => $per_page,
            'page'        => $page,
            'total_pages' => (int) ceil($filtered_count / $per_page),
        ];
    }

    /**
     * Sum of quantities for all line items in an order.
     */
    public static function total_item_qty(\WC_Order $order): int
    {
        $qty = 0;
        foreach ($order->get_items() as $item) {
            $qty += (int) $item->get_quantity();
        }
        return $qty;
    }

    /**
     * Basic customer details (name + email).
     */
    public static function customer_info(\WC_Order $order): array
    {
        $name  = trim($order->get_formatted_billing_full_name());
        $email = $order->get_billing_email();

        if (!$name && $order->get_user_id()) {
            $user = get_userdata($order->get_user_id());
            if ($user instanceof WP_User) {
                $name = $user->display_name ?: $user->user_login;
                $email = $email ?: $user->user_email;
            }
        }

        return [
            'name'  => $name ?: __('(No Name)', 'gwot'),
            'email' => $email ?: __('(No Email)', 'gwot'),
        ];
    }
}
