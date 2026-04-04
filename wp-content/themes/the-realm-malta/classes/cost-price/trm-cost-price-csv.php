<?php

class TRM_Cost_Price_CSV
{
    /**
     * Parse a CSV file from a temp path and return validated rows + error messages.
     *
     * Expected CSV format (header row is optional):
     *   sku,cost_price
     *   GW-001,12.50
     *   GW-002,8.00
     *
     * @param  string $tmp_path Path to the uploaded temp file
     * @return array {
     *     rows:   array of ['product_id' => int, 'sku' => string, 'cost_price' => float]
     *     errors: array of human-readable error strings
     * }
     */
    public static function parse_upload(string $tmp_path): array
    {
        $rows   = [];
        $errors = [];

        if (!is_readable($tmp_path)) {
            return [
                'rows'   => [],
                'errors' => ['Could not read the uploaded file.'],
            ];
        }

        $handle = fopen($tmp_path, 'r');
        if ($handle === false) {
            return [
                'rows'   => [],
                'errors' => ['Could not open the uploaded file.'],
            ];
        }

        $line_number    = 0;
        $header_skipped = false;

        while (($cols = fgetcsv($handle)) !== false) {
            $line_number++;

            // Skip completely blank lines
            if (empty(array_filter(array_map('trim', $cols)))) {
                continue;
            }

            // Auto-detect and skip header row on the first non-blank line
            if (!$header_skipped && self::is_header_row($cols)) {
                $header_skipped = true;
                continue;
            }

            $header_skipped = true; // ensure we don't re-check after first data row

            if (count($cols) < 2) {
                $errors[] = "Row {$line_number}: expected at least 2 columns (sku, cost_price), got " . count($cols) . '.';
                continue;
            }

            $sku        = trim($cols[0]);
            $price_raw  = trim($cols[1]);

            if ($sku === '') {
                $errors[] = "Row {$line_number}: SKU is empty.";
                continue;
            }

            if (!is_numeric($price_raw) || (float) $price_raw < 0) {
                $errors[] = "Row {$line_number}: cost_price \"{$price_raw}\" is not a valid non-negative number.";
                continue;
            }

            $cost_price = (float) $price_raw;
            $product_id = self::resolve_product_id($sku);

            if ($product_id === 0) {
                $errors[] = "Row {$line_number}: SKU \"{$sku}\" was not found in WooCommerce.";
                continue;
            }

            // Duplicate SKUs in the same file: last row wins
            $rows[$sku] = [
                'product_id' => $product_id,
                'sku'        => $sku,
                'cost_price' => $cost_price,
            ];
        }

        fclose($handle);

        return [
            'rows'   => array_values($rows),
            'errors' => $errors,
        ];
    }

    /**
     * Detect a header row by checking whether the first column is non-numeric.
     */
    private static function is_header_row(array $cols): bool
    {
        return !is_numeric(trim($cols[0]));
    }

    /**
     * Look up a WooCommerce product ID by SKU.
     * Returns 0 if the SKU does not exist.
     */
    private static function resolve_product_id(string $sku): int
    {
        $product_id = wc_get_product_id_by_sku($sku);
        return (int) $product_id;
    }
}
