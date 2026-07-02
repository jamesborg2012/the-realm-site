<?php

class TRM_Cost_Price_DB
{
    const DB_VERSION = '1.2';

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

        // effective_date is the date the cost price was *valid from* (distinct from
        // uploaded_at, which is when the row was recorded). Nullable so dbDelta can add
        // it to existing installs without a strict-mode default; every insert sets it and
        // the backfill below populates legacy rows.
        $sql = "CREATE TABLE {$table_name} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id     BIGINT UNSIGNED NOT NULL,
            sku            VARCHAR(191)    NOT NULL,
            cost_price     DECIMAL(10, 2)  NOT NULL,
            effective_date DATE            NULL,
            uploaded_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by    BIGINT UNSIGNED NOT NULL,
            source         VARCHAR(20)     NOT NULL DEFAULT 'upload',
            PRIMARY KEY  (id),
            KEY idx_product_id     (product_id),
            KEY idx_sku            (sku),
            KEY idx_uploaded_at    (uploaded_at),
            KEY idx_effective_date (effective_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Backfill legacy rows: before this version the "valid from" date was implicit in
        // uploaded_at, so seed effective_date from it. Idempotent.
        $wpdb->query(
            "UPDATE {$table_name} SET effective_date = DATE(uploaded_at) WHERE effective_date IS NULL"
        );
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
                    'product_id'     => $row['product_id'],
                    'sku'            => $row['sku'],
                    'cost_price'     => $row['cost_price'],
                    'effective_date' => current_time('Y-m-d'),
                    'uploaded_at'    => current_time('mysql'),
                    'uploaded_by'    => $uploaded_by,
                    'source'         => 'upload',
                ],
                ['%d', '%s', '%f', '%s', '%s', '%d', '%s']
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
        $today      = current_time('Y-m-d');
        $latest     = self::get_current_price($product_id);

        // Collapse a prior inline edit from within the last hour into this one. Only a
        // today-effective inline row qualifies — a backdated ('manual') correction is
        // never overwritten by a "set current price" edit.
        if (
            $latest
            && $latest->source === 'inline'
            && $latest->effective_date === $today
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
                'product_id'     => $product_id,
                'sku'            => $sku,
                'cost_price'     => $cost_price,
                'effective_date' => $today,
                'uploaded_at'    => $now,
                'uploaded_by'    => $user_id,
                'source'         => 'inline',
            ],
            ['%d', '%s', '%f', '%s', '%s', '%d', '%s']
        );

        return self::get_current_price($product_id);
    }

    /**
     * Insert a cost price valid from a specific (typically past) date — the backdated
     * correction path. Append-only; tagged source='manual' so it is never collapsed by
     * the inline "set current price" edit.
     *
     * @return object|null The inserted row, or null on failure. (The product's *current*
     *                     price may be a different, later-dated row — fetch it separately.)
     */
    public static function insert_dated_price(
        int    $product_id,
        string $sku,
        float  $cost_price,
        string $effective_date,
        int    $user_id
    ): ?object {
        global $wpdb;

        $result = $wpdb->insert(
            self::table_name(),
            [
                'product_id'     => $product_id,
                'sku'            => $sku,
                'cost_price'     => $cost_price,
                'effective_date' => $effective_date,
                'uploaded_at'    => current_time('mysql'),
                'uploaded_by'    => $user_id,
                'source'         => 'manual',
            ],
            ['%d', '%s', '%f', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            return null;
        }

        return self::get_row((int) $wpdb->insert_id);
    }

    /**
     * Fetch a single history row by id.
     */
    public static function get_row(int $id): ?object
    {
        global $wpdb;

        $table_name = self::table_name();
        $row        = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id)
        );

        return $row ?: null;
    }

    /**
     * Update the cost price and/or effective date of an existing history row.
     * uploaded_at (the audit "recorded on" timestamp) is intentionally left untouched.
     *
     * @return bool True if a row was updated.
     */
    public static function update_row(int $id, float $cost_price, string $effective_date): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            self::table_name(),
            [
                'cost_price'     => $cost_price,
                'effective_date' => $effective_date,
            ],
            ['id' => $id],
            ['%f', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a single history row.
     *
     * @return bool True if a row was deleted.
     */
    public static function delete_row(int $id): bool
    {
        global $wpdb;

        return (bool) $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
    }

    /**
     * Get the cost price currently in effect for a product: the row with the latest
     * effective_date on or before today, breaking ties on id (last recorded wins).
     *
     * Note: this is NOT "the newest row" — a backdated correction has a higher id but an
     * earlier effective_date, so it must not be treated as current.
     */
    public static function get_current_price(int $product_id): ?object
    {
        global $wpdb;

        $table_name = self::table_name();
        $row        = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name}
                 WHERE product_id = %d AND effective_date <= CURDATE()
                 ORDER BY effective_date DESC, id DESC
                 LIMIT 1",
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
                "SELECT * FROM {$table_name} WHERE product_id = %d ORDER BY effective_date DESC, id DESC",
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

        // "Current" = the row per product with the greatest effective_date on/before today
        // (id as tie-break). Expressed as a greatest-n-per-group anti-join so it works
        // without window functions (MariaDB / MySQL 5.7 compatible).
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, cur.sku, cur.cost_price,
                        cur.effective_date, cur.uploaded_at, cur.uploaded_by
                FROM {$wpdb->posts} p
                INNER JOIN {$table_name} cur
                        ON cur.product_id = p.ID
                       AND cur.effective_date <= CURDATE()
                LEFT  JOIN {$table_name} newer
                        ON newer.product_id = cur.product_id
                       AND newer.effective_date <= CURDATE()
                       AND (newer.effective_date > cur.effective_date
                            OR (newer.effective_date = cur.effective_date AND newer.id > cur.id))
                WHERE p.post_type = 'product'
                  AND p.post_status = 'publish'
                  AND newer.id IS NULL
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
            "SELECT COUNT(DISTINCT product_id) FROM {$table_name} WHERE effective_date <= CURDATE()"
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
                "SELECT cur.product_id, cur.cost_price
                 FROM {$table_name} cur
                 LEFT JOIN {$table_name} newer
                        ON newer.product_id = cur.product_id
                       AND newer.effective_date <= CURDATE()
                       AND (newer.effective_date > cur.effective_date
                            OR (newer.effective_date = cur.effective_date AND newer.id > cur.id))
                 WHERE cur.product_id IN ({$placeholders})
                   AND cur.effective_date <= CURDATE()
                   AND newer.id IS NULL",
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
                "SELECT p.ID, p.post_title, latest.sku, latest.cost_price,
                        latest.effective_date, latest.uploaded_at, latest.uploaded_by
                 FROM {$wpdb->posts} p
                 INNER JOIN (
                     SELECT product_id, sku, cost_price, effective_date, uploaded_at, uploaded_by
                     FROM {$table_name}
                     WHERE product_id = %d
                       AND effective_date <= CURDATE()
                     ORDER BY effective_date DESC, id DESC
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
                 ORDER BY product_id ASC, effective_date DESC, id DESC",
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
