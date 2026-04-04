<?php

class TRM_Profit_Analytics_DB
{
    /**
     * Get WooCommerce order line items within a date range.
     *
     * Uses the WooCommerce Analytics lookup tables (HPOS-compatible):
     *   wc_order_product_lookup  — one row per line item, with qty and net revenue pre-calculated
     *   wc_order_stats           — joined for status filtering
     *
     * For variable products, variation_id is used as the effective product_id when non-zero
     * (matching how cost prices are keyed via wc_get_product_id_by_sku). The post_title is
     * always sourced from the parent product record.
     *
     * Revenue used is product_net_revenue (after discounts, before tax/shipping).
     *
     * @param  string   $date_from  'Y-m-d H:i:s' inclusive lower bound on date_created
     * @param  string   $date_to    'Y-m-d H:i:s' inclusive upper bound on date_created
     * @return object[] Rows: product_id, post_title, sku, quantity, line_total, order_date
     */
    public static function get_order_line_items(string $date_from, string $date_to): array
    {
        global $wpdb;

        $table_lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $table_stats  = $wpdb->prefix . 'wc_order_stats';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    COALESCE(NULLIF(opl.variation_id, 0), opl.product_id) AS product_id,
                    COALESCE(p.post_title, CONCAT('#', opl.product_id))   AS post_title,
                    COALESCE(pm_sku.meta_value, '')                        AS sku,
                    opl.product_qty                                        AS quantity,
                    opl.product_net_revenue                                AS line_total,
                    opl.date_created                                       AS order_date

                FROM   {$table_lookup} opl

                INNER JOIN {$table_stats} os
                        ON os.order_id = opl.order_id
                       AND os.status  IN ('wc-completed', 'wc-processing')

                LEFT  JOIN {$wpdb->posts} p
                        ON p.ID = opl.product_id

                LEFT  JOIN {$wpdb->postmeta} pm_sku
                        ON pm_sku.post_id  = COALESCE(NULLIF(opl.variation_id, 0), opl.product_id)
                       AND pm_sku.meta_key = '_sku'

                WHERE  opl.date_created >= %s
                  AND  opl.date_created <= %s

                ORDER  BY opl.date_created ASC",
                $date_from,
                $date_to
            )
        );
        // phpcs:enable
    }

    /**
     * Get the full cost price history for a set of product IDs, ordered oldest-first (ASC by id).
     *
     * @param  int[]  $product_ids
     * @return array<int, object[]>  Keyed by product_id; each value is an array of cost price rows.
     */
    public static function get_cost_price_history_for_products(array $product_ids): array
    {
        global $wpdb;

        if (empty($product_ids)) {
            return [];
        }

        $table        = $wpdb->prefix . 'trm_product_cost_price';
        $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, cost_price, uploaded_at
                 FROM   {$table}
                 WHERE  product_id IN ({$placeholders})
                 ORDER  BY id ASC",
                ...$product_ids
            )
        );
        // phpcs:enable

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->product_id][] = $row;
        }

        return $map;
    }
}
