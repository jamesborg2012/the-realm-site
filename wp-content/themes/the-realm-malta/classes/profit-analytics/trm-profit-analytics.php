<?php

require_once __DIR__ . '/trm-profit-analytics-db.php';

class TRM_Profit_Analytics extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks(): void
    {
        add_action('admin_menu',            [$this, 'register_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function register_submenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Profit Analytics',
            'Profit Analytics',
            'manage_woocommerce',
            'trm-profit-analytics',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_trm-profit-analytics') {
            return;
        }

        wp_enqueue_style(
            'trm-profit-analytics',
            get_stylesheet_directory_uri() . '/assets/css/admin/profit-analytics.css',
            [],
            '1.0'
        );
    }

    public function render_admin_page(): void
    {
        $default_from = date('Y-m-d', strtotime('-6 days'));
        $default_to   = date('Y-m-d');

        $raw_from = sanitize_text_field(wp_unslash($_GET['date_from'] ?? ''));
        $raw_to   = sanitize_text_field(wp_unslash($_GET['date_to']   ?? ''));

        $is_filtered = ($raw_from !== '' || $raw_to !== '');

        $date_from = ($raw_from !== '' && $this->is_valid_date($raw_from)) ? $raw_from : $default_from;
        $date_to   = ($raw_to   !== '' && $this->is_valid_date($raw_to))   ? $raw_to   : $default_to;

        // Swap if inverted
        if ($date_from > $date_to) {
            [$date_from, $date_to] = [$date_to, $date_from];
        }

        $line_items  = TRM_Profit_Analytics_DB::get_order_line_items(
            $date_from . ' 00:00:00',
            $date_to   . ' 23:59:59'
        );

        $product_ids = array_values(
            array_unique(array_map(fn($i) => (int) $i->product_id, $line_items))
        );

        $cp_history = empty($product_ids)
            ? []
            : TRM_Profit_Analytics_DB::get_cost_price_history_for_products($product_ids);

        $rows = $this->aggregate_profit_data($line_items, $cp_history, $date_from, $date_to);

        echo $this->render_template(
            'admin/profit-analytics/profit-analytics-page',
            [
                'date_from'   => $date_from,
                'date_to'     => $date_to,
                'is_filtered' => $is_filtered,
                'rows'        => $rows,
            ]
        );
    }

    // ── Aggregation ────────────────────────────────────────────────────────────

    /**
     * Aggregate order line items into per-(product, cost-price-period) rows with profit figures.
     *
     * @param  object[] $line_items  From TRM_Profit_Analytics_DB::get_order_line_items()
     * @param  array    $cp_history  From TRM_Profit_Analytics_DB::get_cost_price_history_for_products()
     * @param  string   $date_from   'Y-m-d'
     * @param  string   $date_to     'Y-m-d'
     * @return array[]  Sorted by product_title ASC, then period_start ASC within same product.
     *                  Each row: product_id, product_title, sku, cost_price (float|null),
     *                  cp_set_on (datetime|null), period_start (datetime), period_end (datetime),
     *                  units_sold (int), revenue (float), total_cost (float|null),
     *                  profit (float|null), margin (float|null)
     */
    private function aggregate_profit_data(
        array  $line_items,
        array  $cp_history,
        string $date_from,
        string $date_to
    ): array {
        if (empty($line_items)) {
            return [];
        }

        $aggregated = [];

        foreach ($line_items as $item) {
            $pid      = (int) $item->product_id;
            $order_ts = strtotime($item->order_date);

            $active_cp = $this->find_active_cost_price($cp_history[$pid] ?? [], $order_ts);

            $cp_key  = $active_cp ? (int) $active_cp->id : 0;
            $agg_key = $pid . '_' . $cp_key;

            if (!isset($aggregated[$agg_key])) {
                $aggregated[$agg_key] = [
                    'product_id'    => $pid,
                    'product_title' => $item->post_title,
                    'sku'           => $item->sku,
                    'cost_price'    => $active_cp ? (float) $active_cp->cost_price : null,
                    'cp_set_on'     => $active_cp ? $active_cp->uploaded_at        : null,
                    'period_start'  => null,
                    'period_end'    => null,
                    'units_sold'    => 0,
                    'revenue'       => 0.0,
                ];
            }

            $aggregated[$agg_key]['units_sold'] += (int) $item->quantity;
            $aggregated[$agg_key]['revenue']    += (float) $item->line_total;
        }

        $this->fill_periods($aggregated, $cp_history, $date_from, $date_to);

        foreach ($aggregated as &$row) {
            if ($row['cost_price'] !== null) {
                $total_cost = $row['cost_price'] * $row['units_sold'];
                $profit     = $row['revenue'] - $total_cost;
                $margin     = $row['revenue'] > 0 ? ($profit / $row['revenue'] * 100) : 0.0;
            } else {
                $total_cost = null;
                $profit     = null;
                $margin     = null;
            }
            $row['total_cost'] = $total_cost;
            $row['profit']     = $profit;
            $row['margin']     = $margin;
        }
        unset($row);

        uasort($aggregated, function (array $a, array $b): int {
            $cmp = strcmp($a['product_title'], $b['product_title']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) $a['period_start'], (string) $b['period_start']);
        });

        return array_values($aggregated);
    }

    /**
     * Return the latest cost price row active at or before $order_ts, or null if none.
     * $history must be sorted ASC by id (oldest first).
     */
    private function find_active_cost_price(array $history, int $order_ts): ?object
    {
        $active = null;
        foreach ($history as $cp) {
            if (strtotime($cp->uploaded_at) <= $order_ts) {
                $active = $cp;
            } else {
                break;
            }
        }
        return $active;
    }

    /**
     * For each aggregated row, populate period_start and period_end:
     *
     *   period_start = max(cp.uploaded_at, date_from 00:00:00)
     *   period_end   = start of next CP change within the range − 1 second,
     *                  or date_to 23:59:59 for the last (or only) period.
     */
    private function fill_periods(
        array  &$aggregated,
        array  $cp_history,
        string $date_from,
        string $date_to
    ): void {
        $range_start = $date_from . ' 00:00:00';
        $range_end   = $date_to   . ' 23:59:59';

        // All CP change timestamps per product (ASC), for calculating period boundaries.
        $all_cp_dates_by_product = [];
        foreach ($cp_history as $pid => $cps) {
            $all_cp_dates_by_product[$pid] = array_column($cps, 'uploaded_at');
        }

        foreach ($aggregated as $key => &$row) {
            $pid       = $row['product_id'];
            $cp_set_on = $row['cp_set_on'];

            // period_start = max(when CP was set, start of date range)
            $period_start = ($cp_set_on !== null && $cp_set_on > $range_start)
                ? $cp_set_on
                : $range_start;

            // period_end = start of the next CP change within the range - 1s, or range_end
            $period_end = $range_end;
            if ($cp_set_on !== null) {
                foreach ($all_cp_dates_by_product[$pid] ?? [] as $cp_date) {
                    if ($cp_date > $cp_set_on && $cp_date <= $range_end) {
                        $period_end = date('Y-m-d H:i:s', strtotime($cp_date) - 1);
                        break;
                    }
                }
            }

            $row['period_start'] = $period_start;
            $row['period_end']   = $period_end;
        }
        unset($row);
    }

    private function is_valid_date(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
