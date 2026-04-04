<?php

class TRM_Cost_Price_Upload extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks(): void
    {
        add_action('admin_post_trm_cost_price_upload', [$this, 'handle_upload']);
    }

    public function handle_upload(): void
    {
        // Verify nonce
        check_admin_referer('trm_cost_price_upload');

        // Verify capability
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'the-realm-malta'));
        }

        $redirect_base = admin_url('admin.php?page=trm-cost-price&tab=upload');

        // Validate file presence
        if (empty($_FILES['trm_cost_price_csv']['tmp_name'])) {
            wp_redirect(add_query_arg('upload_error', 'no_file', $redirect_base));
            exit;
        }

        $file     = $_FILES['trm_cost_price_csv'];
        $tmp_path = $file['tmp_name'];

        // Validate file type
        $file_type = wp_check_filetype(basename($file['name']), ['csv' => 'text/csv']);
        if (empty($file_type['ext'])) {
            wp_redirect(add_query_arg('upload_error', 'invalid_type', $redirect_base));
            exit;
        }

        // Validate upload error code
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(add_query_arg('upload_error', 'upload_failed', $redirect_base));
            exit;
        }

        // Parse and validate the CSV
        $parsed = TRM_Cost_Price_CSV::parse_upload($tmp_path);

        // Filter out rows where cost price has not changed since the last upload
        $unchanged = 0;
        if (!empty($parsed['rows'])) {
            $product_ids    = array_column($parsed['rows'], 'product_id');
            $current_prices = TRM_Cost_Price_DB::get_current_prices_by_product_ids($product_ids);

            $changed_rows = [];
            foreach ($parsed['rows'] as $row) {
                $pid     = $row['product_id'];
                $current = $current_prices[$pid] ?? null;
                if ($current !== null && abs((float) $current - (float) $row['cost_price']) < 0.001) {
                    $unchanged++;
                    continue;
                }
                $changed_rows[] = $row;
            }
            $parsed['rows'] = $changed_rows;
        }

        // Insert valid, changed rows
        $imported = 0;
        if (!empty($parsed['rows'])) {
            $imported = TRM_Cost_Price_DB::insert_rows($parsed['rows'], get_current_user_id());
        }

        $skipped = count($parsed['errors']);

        // Store any row-level errors in a transient so the view can display them
        $transient_key = 'trm_cp_upload_errors_' . get_current_user_id();
        if (!empty($parsed['errors'])) {
            set_transient($transient_key, $parsed['errors'], 60);
        } else {
            delete_transient($transient_key);
        }

        $redirect = add_query_arg(
            [
                'imported'  => $imported,
                'skipped'   => $skipped,
                'unchanged' => $unchanged,
            ],
            $redirect_base
        );

        wp_redirect($redirect);
        exit;
    }
}
