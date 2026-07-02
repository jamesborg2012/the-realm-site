<?php

require_once __DIR__ . '/trm-cost-price-db.php';
require_once __DIR__ . '/trm-cost-price-csv.php';
require_once __DIR__ . '/trm-cost-price-upload.php';

class TRM_Cost_Price extends TRM_Core
{
    const PER_PAGE = 50;

    public function __construct()
    {
        new TRM_Cost_Price_Upload();
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks(): void
    {
        add_action('after_switch_theme',    [$this, 'maybe_install_db']);
        add_action('admin_init',            [$this, 'maybe_install_db']);
        add_action('admin_menu',            [$this, 'register_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Inline cost-price editing on the dashboard.
        add_action('wp_ajax_trm_cost_price_inline_update', [$this, 'ajax_inline_update']);

        // Backdated / historical cost-price management on the dashboard.
        add_action('wp_ajax_trm_cost_price_add_dated',   [$this, 'ajax_add_dated']);
        add_action('wp_ajax_trm_cost_price_update_row',  [$this, 'ajax_update_row']);
        add_action('wp_ajax_trm_cost_price_delete_row',  [$this, 'ajax_delete_row']);

        // Expose the current cost price in the WooCommerce product CSV export.
        add_filter('woocommerce_product_export_column_names',                  [$this, 'add_export_column']);
        add_filter('woocommerce_product_export_product_default_columns',       [$this, 'add_export_column']);
        add_filter('woocommerce_product_export_product_column_trm_cost_price', [$this, 'export_column_value'], 10, 3);
    }

    public function maybe_install_db(): void
    {
        $installed = get_option('trm_cost_price_db_version', '0');

        if (version_compare($installed, TRM_Cost_Price_DB::DB_VERSION, '>=')) {
            return;
        }

        TRM_Cost_Price_DB::install();
        update_option('trm_cost_price_db_version', TRM_Cost_Price_DB::DB_VERSION);
    }

    public function register_submenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Product Cost Prices',
            'Cost Prices',
            'manage_woocommerce',
            'trm-cost-price',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_trm-cost-price') {
            return;
        }

        wp_enqueue_style(
            'trm-cost-price-admin',
            get_stylesheet_directory_uri() . '/assets/css/admin/cost-price.css',
            [],
            '1.1'
        );

        wp_enqueue_script(
            'trm-cost-price-admin',
            get_stylesheet_directory_uri() . '/assets/js/admin/cost-price.js',
            ['jquery'],
            '1.1',
            true
        );

        wp_localize_script('trm-cost-price-admin', 'trmCostPrice', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('trm_cost_price_inline'),
        ]);
    }

    /**
     * AJAX: persist an inline cost-price edit from the dashboard.
     */
    public function ajax_inline_update(): void
    {
        check_ajax_referer('trm_cost_price_inline', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'You do not have permission to do this.'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $raw_price  = sanitize_text_field(wp_unslash($_POST['cost_price'] ?? ''));

        if ($product_id <= 0) {
            wp_send_json_error(['message' => 'Invalid product.']);
        }

        // Accept either a dot or comma decimal separator.
        $normalised = str_replace(',', '.', $raw_price);
        if ($normalised === '' || !is_numeric($normalised) || (float) $normalised < 0) {
            wp_send_json_error(['message' => 'Please enter a valid cost price.']);
        }
        $cost_price = round((float) $normalised, 2);

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found.']);
        }

        $sku = $product->get_sku();
        if ($sku === '') {
            $existing = TRM_Cost_Price_DB::get_current_price($product_id);
            $sku      = $existing->sku ?? '';
        }

        $row = TRM_Cost_Price_DB::upsert_inline_price($product_id, $sku, $cost_price, get_current_user_id());

        if (!$row) {
            wp_send_json_error(['message' => 'Could not save the cost price. Please try again.']);
        }

        wp_send_json_success([
            'cost_price' => number_format((float) $row->cost_price, 2),
            'updated_at' => date_i18n('d M Y H:i', strtotime($row->uploaded_at)),
        ]);
    }

    /**
     * AJAX: add a cost price valid from a specific (typically past) date.
     */
    public function ajax_add_dated(): void
    {
        $this->verify_ajax_request();

        $product_id = absint($_POST['product_id'] ?? 0);
        $cost_price = $this->parse_price($_POST['cost_price'] ?? '');
        $effective  = $this->parse_effective_date($_POST['effective_date'] ?? '');

        if ($product_id <= 0) {
            wp_send_json_error(['message' => 'Invalid product.']);
        }
        if ($cost_price === null) {
            wp_send_json_error(['message' => 'Please enter a valid cost price.']);
        }
        if ($effective === null) {
            wp_send_json_error(['message' => 'Please enter a valid date (not in the future).']);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found.']);
        }

        $sku = $product->get_sku();
        if ($sku === '') {
            $existing = TRM_Cost_Price_DB::get_current_price($product_id);
            $sku      = $existing->sku ?? '';
        }

        $inserted = TRM_Cost_Price_DB::insert_dated_price(
            $product_id,
            $sku,
            $cost_price,
            $effective,
            get_current_user_id()
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'Could not save the cost price. Please try again.']);
        }

        wp_send_json_success([
            'row'     => $this->format_history_row($inserted),
            'current' => $this->format_current($product_id),
        ]);
    }

    /**
     * AJAX: edit the price and/or effective date of an existing history row.
     */
    public function ajax_update_row(): void
    {
        $this->verify_ajax_request();

        $row_id     = absint($_POST['row_id'] ?? 0);
        $cost_price = $this->parse_price($_POST['cost_price'] ?? '');
        $effective  = $this->parse_effective_date($_POST['effective_date'] ?? '');

        $row = $row_id > 0 ? TRM_Cost_Price_DB::get_row($row_id) : null;
        if (!$row) {
            wp_send_json_error(['message' => 'Record not found.']);
        }
        if ($cost_price === null) {
            wp_send_json_error(['message' => 'Please enter a valid cost price.']);
        }
        if ($effective === null) {
            wp_send_json_error(['message' => 'Please enter a valid date (not in the future).']);
        }

        if (!TRM_Cost_Price_DB::update_row($row_id, $cost_price, $effective)) {
            wp_send_json_error(['message' => 'Could not update the record. Please try again.']);
        }

        $updated = TRM_Cost_Price_DB::get_row($row_id);

        wp_send_json_success([
            'row'     => $this->format_history_row($updated),
            'current' => $this->format_current((int) $row->product_id),
        ]);
    }

    /**
     * AJAX: delete a single history row.
     */
    public function ajax_delete_row(): void
    {
        $this->verify_ajax_request();

        $row_id = absint($_POST['row_id'] ?? 0);
        $row    = $row_id > 0 ? TRM_Cost_Price_DB::get_row($row_id) : null;
        if (!$row) {
            wp_send_json_error(['message' => 'Record not found.']);
        }

        if (!TRM_Cost_Price_DB::delete_row($row_id)) {
            wp_send_json_error(['message' => 'Could not delete the record. Please try again.']);
        }

        wp_send_json_success([
            'current' => $this->format_current((int) $row->product_id),
        ]);
    }

    /**
     * Shared nonce + capability gate for the dashboard AJAX actions.
     */
    private function verify_ajax_request(): void
    {
        check_ajax_referer('trm_cost_price_inline', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'You do not have permission to do this.'], 403);
        }
    }

    /**
     * Normalise a posted price to a rounded float, or null if invalid.
     * Accepts a dot or comma decimal separator.
     */
    private function parse_price($raw): ?float
    {
        $raw        = sanitize_text_field(wp_unslash($raw));
        $normalised = str_replace(',', '.', $raw);

        if ($normalised === '' || !is_numeric($normalised) || (float) $normalised < 0) {
            return null;
        }

        return round((float) $normalised, 2);
    }

    /**
     * Validate a posted Y-m-d effective date, or null if invalid / in the future.
     */
    private function parse_effective_date($raw): ?string
    {
        $raw = sanitize_text_field(wp_unslash($raw));
        $d   = DateTime::createFromFormat('Y-m-d', $raw);

        if ($d === false || $d->format('Y-m-d') !== $raw) {
            return null;
        }
        if ($raw > current_time('Y-m-d')) {
            return null;
        }

        return $raw;
    }

    /**
     * Shape a cost-price row for the history table in the JS response.
     */
    private function format_history_row(object $row): array
    {
        return [
            'id'             => (int) $row->id,
            'cost_price'     => number_format((float) $row->cost_price, 2),
            'effective_date' => $row->effective_date,
            'effective_disp' => date_i18n('d M Y', strtotime($row->effective_date)),
            'recorded_disp'  => date_i18n('d M Y H:i', strtotime($row->uploaded_at)),
            'source'         => $row->source,
        ];
    }

    /**
     * Shape the product's current cost price (post-change) for the dashboard cell.
     * Returns null values when the product no longer has any effective cost price.
     */
    private function format_current(int $product_id): array
    {
        $row = TRM_Cost_Price_DB::get_current_price($product_id);

        return [
            'id'         => $row ? (int) $row->id : 0,
            'cost_price' => $row ? number_format((float) $row->cost_price, 2) : '',
            'updated_at' => $row ? date_i18n('d M Y H:i', strtotime($row->uploaded_at)) : '',
        ];
    }

    /**
     * Add a "Cost Price" column to the WooCommerce product CSV exporter
     * (both the available column list and the default selection).
     */
    public function add_export_column(array $columns): array
    {
        $columns['trm_cost_price'] = __('Cost Price', 'the-realm-malta');
        return $columns;
    }

    /**
     * Provide the current cost price for a product/variation in the export.
     * Current value only — no history.
     */
    public function export_column_value($value, $product, $column_id)
    {
        $row = TRM_Cost_Price_DB::get_current_price((int) $product->get_id());
        return $row ? wc_format_decimal($row->cost_price, 2) : '';
    }

    public function render_admin_page(): void
    {
        $active_tab = $this->get_current_tab();
        $page       = max(1, absint($_GET['paged'] ?? 1));

        $products_data    = [];
        $total_products   = 0;
        $history_map      = [];
        $sku_search    = '';
        $sku_not_found = false;

        if ($active_tab === 'dashboard') {
            $sku_search = sanitize_text_field(wp_unslash($_GET['sku'] ?? ''));

            if ($sku_search !== '') {
                // SKU search mode — show only the matching product
                $product_id = (int) wc_get_product_id_by_sku($sku_search);

                if ($product_id > 0) {
                    $row = TRM_Cost_Price_DB::get_current_price_with_product($product_id);
                    if ($row) {
                        $products_data  = [$row];
                        $total_products = 1;
                        $history_map    = TRM_Cost_Price_DB::get_history_for_products([$product_id]);
                    } else {
                        $sku_not_found = true;
                    }
                } else {
                    $sku_not_found = true;
                }
            } else {
                $products_data  = TRM_Cost_Price_DB::get_all_current_prices($page, self::PER_PAGE);
                $total_products = TRM_Cost_Price_DB::get_total_product_count();

                $product_ids = array_map(fn($p) => (int) $p->ID, $products_data);
                $history_map = TRM_Cost_Price_DB::get_history_for_products($product_ids);
            }
        }

        // Retrieve any upload errors stored in the transient
        $transient_key  = 'trm_cp_upload_errors_' . get_current_user_id();
        $upload_errors  = get_transient($transient_key) ?: [];
        if (!empty($upload_errors)) {
            delete_transient($transient_key);
        }

        echo $this->render_template(
            'admin/cost-price/cost-price-page',
            [
                'active_tab'     => $active_tab,
                'products_data'     => $products_data,
                'total_products'    => $total_products,
                'history_map'       => $history_map,
                'current_page'      => $page,
                'per_page'          => self::PER_PAGE,
                'sku_search'    => $sku_search,
                'sku_not_found' => $sku_not_found,
                'imported'       => isset($_GET['imported']) ? absint($_GET['imported']) : -1,
                'skipped'        => absint($_GET['skipped'] ?? 0),
                'unchanged'      => absint($_GET['unchanged'] ?? 0),
                'upload_error'   => sanitize_key($_GET['upload_error'] ?? ''),
                'upload_errors'  => $upload_errors,
            ]
        );
    }

    private function get_current_tab(): string
    {
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        return in_array($tab, ['dashboard', 'upload'], true) ? $tab : 'dashboard';
    }
}
