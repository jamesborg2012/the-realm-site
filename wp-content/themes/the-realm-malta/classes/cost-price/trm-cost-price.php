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
            '1.0'
        );
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
