<?php

class TRM_Cost_Price_DB
{
    const DB_VERSION = '1.1';

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'trm_product_cost_price';
    }

    public static function install(): void
    {
        global $wpdb;

        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id   BIGINT UNSIGNED NOT NULL,
            sku          VARCHAR(191)    NOT NULL,
            cost_price   DECIMAL(10, 2)  NOT NULL,
            uploaded_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by  BIGINT UNSIGNED NOT NULL,
            source       VARCHAR(20)     NOT NULL DEFAULT 'upload',
            PRIMARY KEY  (id),
            KEY idx_product_id  (product_id),
            KEY idx_sku         (sku),
            KEY idx_uploaded_at (uploaded_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Insert multiple cost price rows.
     *
     * @param array $rows        Each element: ['product_id' => int, 'sku' => string, 'cost_price' => float]
     * @param int   $uploaded_by WP user ID performing the upload
     * @return int Number of rows successfully inserted
     */
    public static function insert_rows(array $rows, int $uploaded_by): int
    {
        global $wpdb;

        $table_name = self::table_name();
        $inserted   = 0;

        foreach ($rows as $row) {
            $result = $wpdb->insert(
                $table_name,
                [
                    'product_id'  => $row['product_id'],
                    'sku'         => $row['sku'],
                    'cost_price'  => $row['cost_price'],
                    'uploaded_at' => current_time('mysql'),
                    'uploaded_by' => $uploaded_by,
                    'source'      => 'upload',
                ],
                ['%d', '%s', '%f', '%s', '%d', '%s']
            );

            if ($result !== false) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Record an inline (dashboard) cost-price edit.
     *
     * Append-only like uploads, with one exception: consecutive inline edits made
     * within the last hour are collapsed into a single record (the prior inline row
     * is updated in place) so that quick corrections don't pollute the price history.
     * A CSV-uploaded record is never overwritten — it always gets a fresh inline row.
     *
     * @return object|null The resulting current row for the product.
     */
    public static function upsert_inline_price(int $product_id, string $sku, float $cost_price, int $user_id): ?object
    {
        global $wpdb;

        $table_name = self::table_name();
        $now        = current_time('mysql');
        $latest     = self::get_current_price($product_id);

        // Collapse a prior inline edit from within the last hour into this one.
        if (
            $latest
            && $latest->source === 'inline'
            && (strtotime($now) - strtotime($latest->uploaded_at)) < HOUR_IN_SECONDS
        ) {
            $wpdb->update(
                $table_name,
                [
                    'cost_price'  => $cost_price,
                    'uploaded_at' => $now,
                    'uploaded_by' => $user_id,
                ],
                ['id' => (int) $latest->id],
                ['%f', '%s', '%d'],
                ['%d']
            );

            return self::get_current_price($product_id);
        }

        $wpdb->insert(
            $table_name,
            [
                'product_id'  => $product_id,
                'sku'         => $sku,
                'cost_price'  => $cost_price,
                'uploaded_at' => $now,
                'uploaded_by' => $user_id,
                'source'      => 'inline',
            ],
            ['%d', '%s', '%f', '%s', '%d', '%s']
        );

        return self::get_current_price($product_id);
    }

    /**
     * Get the most recent cost price record for a product.
     */
    public static function get_current_price(int $product_id): ?object
    {
        global $wpdb;

        $table_name = self::table_name();
        $row        = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE product_id = %d ORDER BY id DESC LIMIT 1",
                $product_id
            )
        );

        return $row ?: null;
    }

    /**
     * Get the full history of cost prices for a product, newest first.
     */
    public static function get_history(int $product_id): array
    {
        global $wpdb;

        $table_name = self::table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE product_id = %d ORDER BY id DESC",
                $product_id
            )
        ) ?: [];
    }

    /**
     * Get the current (latest) cost price for every tracked product, joined with product title.
     * Returns paginated results sorted by product title.
     */
    public static function get_all_current_prices(int $page, int $per_page): array
    {
        global $wpdb;

        $table_name = self::table_name();
        $offset     = ($page - 1) * $per_page;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, latest.sku, latest.cost_price, latest.uploaded_at, latest.uploaded_by
                FROM {$wpdb->posts} p
                INNER JOIN (
                    SELECT product_id, sku, cost_price, uploaded_at, uploaded_by
                    FROM {$table_name}
                    WHERE id IN (
                        SELECT MAX(id)
                        FROM {$table_name}
                        GROUP BY product_id
                    )
                ) AS latest ON latest.product_id = p.ID
                WHERE p.post_type = 'product'
                  AND p.post_status = 'publish'
                ORDER BY p.post_title ASC
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        ) ?: [];
    }

    /**
     * Count distinct products that have at least one cost price record.
     */
    public static function get_total_product_count(): int
    {
        global $wpdb;

        $table_name = self::table_name();

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT product_id) FROM {$table_name}"
        );
    }

    /**
     * Get the most recent cost price for each of the given product IDs.
     * Returns an associative array: product_id => cost_price (as float).
     *
     * @param int[] $product_ids
     * @return array
     */
    public static function get_current_prices_by_product_ids(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;

        $table_name   = self::table_name();
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, cost_price
                 FROM {$table_name}
                 WHERE id IN (
                     SELECT MAX(id)
                     FROM {$table_name}
                     WHERE product_id IN ({$placeholders})
                     GROUP BY product_id
                 )",
                ...$product_ids
            )
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->product_id] = (float) $row->cost_price;
        }

        return $map;
    }

    /**
     * Look up a product ID from a barcode using the barcode scanner's lookup table.
     * Returns 0 if the barcode is not found or the table does not exist.
     */
    public static function get_product_id_by_barcode(string $barcode): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'barcode_lookup';

        // Guard: table may not exist if the plugin is inactive
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT product_id FROM {$table_name} WHERE barcode = %s LIMIT 1",
                $barcode
            )
        );

        return (int) ($result ?? 0);
    }

    /**
     * Get the current cost price row for a single product, joined with its WP post title.
     * Returns null if no cost price has been set for this product.
     */
    public static function get_current_price_with_product(int $product_id): ?object
    {
        global $wpdb;

        $table_name = self::table_name();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, latest.sku, latest.cost_price, latest.uploaded_at, latest.uploaded_by
                 FROM {$wpdb->posts} p
                 INNER JOIN (
                     SELECT product_id, sku, cost_price, uploaded_at, uploaded_by
                     FROM {$table_name}
                     WHERE product_id = %d
                     ORDER BY id DESC
                     LIMIT 1
                 ) AS latest ON latest.product_id = p.ID
                 WHERE p.post_type = 'product'",
                $product_id
            )
        ) ?: null;
    }

    /**
     * Get all history rows for a product keyed by product_id, used by the dashboard view.
     * Returns all rows for the given product IDs in a single query, grouped by product_id.
     *
     * @param int[] $product_ids
     * @return array Associative array: product_id => array of row objects (newest first)
     */
    public static function get_history_for_products(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;

        $table_name  = self::table_name();
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name}
                 WHERE product_id IN ({$placeholders})
                 ORDER BY product_id ASC, id DESC",
                ...$product_ids
            )
        ) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->product_id][] = $row;
        }

        return $grouped;
    }
}
