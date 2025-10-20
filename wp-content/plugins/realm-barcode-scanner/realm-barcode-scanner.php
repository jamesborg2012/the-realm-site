<?php

/**
 * Plugin Name: Realm Barcode Scanner (Stock Adjust)
 * Description: Scan barcodes with phone camera to update WooCommerce stock.
 * Version: 0.1.0
 * Author: James Borg
 */

if (!defined('ABSPATH')) exit;

class RealmBarcodeScanner
{
    const TABLE_SLUG   = 'barcode_lookup';
    const NONCE_ACTION = 'rbs_nonce';

    /** Create the lookup table */
    public static function activate()
    {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // barcode is UNIQUE so we never store duplicates. product_id indexed for quick reverse reports.
        $sql = "
        CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            barcode VARCHAR(191) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_barcode (barcode),
            KEY idx_product (product_id)
        ) {$charset_collate};
        ";

        dbDelta($sql);
    }

    public function __construct()
    {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_routes']);

        add_action('wp_enqueue_scripts', [$this, 'rbs_frontend_scripts']);

        add_shortcode('rbs_add_to_cart_scanner', [$this, 'render_cart_barcode_scanner']);

        add_action('wp_ajax_rbs_add_to_cart', [$this, 'rbs_ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_rbs_add_to_cart', [$this, 'rbs_ajax_add_to_cart']);
    }

    public function menu()
    {
        add_submenu_page(
            'woocommerce',
            'Scan & Adjust Stock',
            'Scan & Adjust Stock',
            'manage_options',
            'rbs-scan',
            [$this, 'render_page'],
            58
        );
    }

    public function enqueue($hook)
    {
        if ($hook !== 'woocommerce_page_rbs-scan') return;
        // Quagga (fallback) – load only here
        wp_enqueue_script('quagga', 'https://unpkg.com/quagga@0.12.1/dist/quagga.min.js', [], '0.12.1', true);
        // Our script
        wp_enqueue_script('rbs', plugin_dir_url(__FILE__) . 'assets/rbs.js', ['quagga'], '0.1.0', true);
        wp_localize_script('rbs', 'rbs', [
            'restLookupUrl' => esc_url_raw(rest_url('rbs/v1/lookup')),
            'restAdjustUrl' => esc_url_raw(rest_url('rbs/v1/adjust')),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
        ]);
        wp_enqueue_style('rbs', plugin_dir_url(__FILE__) . 'assets/rbs.css', [], '0.1.0');
    }

    public function rbs_frontend_scripts()
    {
        if (is_singular() && has_shortcode(get_post()->post_content, 'rbs_add_to_cart_scanner')) {
            wp_enqueue_script(
                'quagga',
                'https://unpkg.com/quagga@0.12.1/dist/quagga.min.js',
                [],
                '0.12.1',
                true
            );

            wp_enqueue_script('rbs-cart', plugin_dir_url(__FILE__) . 'assets/rbs-cart.js', ['quagga'], '1.0.0', true);
            wp_localize_script('rbs-cart', 'rbsCart', [
                'lookupUrl'     => esc_url_raw(rest_url('rbs/v1/lookup-public')),
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('rbs_public'),
                'addSuccess'    => __('✅ Added to cart!', 'rbs'),
                'notFound'      => __('❌ Product not found', 'rbs'),
            ]);


            wp_enqueue_style('rbs-stock', plugin_dir_url(__FILE__) . 'assets/rbs.css', [], '1.0.0');
        }
    }

    public function render_page()
    {
?>
        <div class="wrap rbs-wrap">
            <h1>Scan & Adjust Stock</h1>
            <p class="rbs-note">Scan product barcodes and set the updated product stock</p>
            <div id="rbs-scanner">
                <video id="rbs-video" autoplay playsinline></video>
                <canvas id="rbs-canvas" class="hidden"></canvas>
                <button id="rbs-start">Start Camera</button>
                <button id="rbs-stop" disabled>Stop</button>
            </div>

            <div id="rbs-result"></div>
            <div id="rbs-log"></div>

            <div id="rbs-product-panel" class="hidden">
                <div class="rbs-product-line">
                    <strong id="rbs-product-name"></strong>
                    <span> (SKU: <span id="rbs-product-sku"></span>)</span>
                </div>
                <div class="rbs-stock-line">
                    Current stock: <strong id="rbs-product-stock"></strong>
                </div>

                <div class="rbs-controls">
                    <label>
                        Mode:
                        <select id="rbs-mode">
                            <option value="inc">Increase by</option>
                            <option value="set">Set to</option>
                        </select>
                    </label>
                    <label>
                        Qty:
                        <input id="rbs-qty" type="number" step="1" value="1" />
                    </label>
                </div>

                <div class="rbs-actions">
                    <button id="rbs-save" class="button button-primary">Save</button>
                    <button id="rbs-cancel" class="button">Cancel</button>
                </div>
            </div>

        </div>
    <?php
    }

    public function register_routes()
    {
        register_rest_route('rbs/v1', '/lookup', [
            'methods'  => 'POST',
            'callback' => [$this, 'rest_lookup_stock'],
        ]);

        register_rest_route('rbs/v1', '/adjust', [
            'methods'  => 'POST',
            'callback' => [$this, 'rest_adjust_stock'],
        ]);

        register_rest_route('rbs/v1', '/lookup-public', [
            'methods'  => 'POST',
            'callback' => [$this, 'rest_lookup_stock_public'],
        ]);
    }

    public function rest_lookup_stock(\WP_REST_Request $req)
    {
        global $wpdb;

        // check_ajax_referer('rbs_nonce', $req->get_param('nonce'));

        $code  = sanitize_text_field($req->get_param('code'));

        if (!$code) {
            return new WP_Error('bad_input', 'Missing code', ['status' => 400]);
        }

        $product_id = self::resolve_product_fast($code);

        if (!$product_id) {
            // Slow path: try legacy meta lookup
            $product_id = self::resolve_product_slow_from_meta($code);

            if (!$product_id) {
                return new WP_Error('not_found', 'Product not found', ['status' => 404]);
            }
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('not_found', 'Invalid product', ['status' => 404]);
        }

        self::upsert_barcode($code, (int) $product_id);
        $stock_qty = (int) $product->get_stock_quantity();

        return [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'sku'        => $code,
            'stock_qty'  => $stock_qty,
        ];
    }

    public function rest_lookup_stock_public(\WP_REST_Request $req)
    {
        global $wpdb;

        // check_ajax_referer('rbs_nonce', $req->get_param('nonce'));

        $code  = sanitize_text_field($req->get_param('code'));

        if (!$code) {
            return new WP_Error('bad_input', 'Missing code', ['status' => 400]);
        }

        $product_id = self::resolve_product_fast($code);

        if (!$product_id) {
            // Slow path: try legacy meta lookup
            $product_id = self::resolve_product_slow_from_meta($code);

            if (!$product_id) {
                return new WP_Error('not_found', 'Product not found', ['status' => 404]);
            }
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('not_found', 'Invalid product', ['status' => 404]);
        }

        return [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'price_html' => $product->get_price_html(),
        ];
    }

    public function rest_adjust_stock(\WP_REST_Request $req)
    {
        $product_id = absint($req->get_param('product_id'));
        $mode       = sanitize_text_field($req->get_param('mode')); // 'inc' | 'set'
        $qty        = max(0, intval($req->get_param('qty')));

        if (!$product_id || !in_array($mode, ['inc', 'set'], true)) {
            return new WP_Error('bad_input', 'Invalid input', ['status' => 400]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found', ['status' => 404]);
        }

        $current = (int) $product->get_stock_quantity();
        $new     = $mode === 'inc' ? $current + $qty : $qty;

        $product->set_stock_quantity($new);
        $product->save();

        return [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'old_qty'    => $current,
            'new_qty'    => $new,
        ];
    }

    function rbs_ajax_add_to_cart()
    {
        check_ajax_referer('rbs_public', 'nonce'); // Security check

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity   = max(1, intval($_POST['quantity'] ?? 1));

        if (!$product_id || !function_exists('WC')) {
            wp_send_json_error(['message' => 'Invalid product.']);
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            wp_send_json_error(['message' => 'Product not purchasable.']);
        }

        // ✅ WooCommerce handles session & persistence here automatically
        $added = WC()->cart->add_to_cart($product_id, $quantity);

        if (!$added) {
            wp_send_json_error(['message' => 'Failed to add to cart.']);
        }

        // Optionally trigger fragment refresh for mini-cart
        do_action('woocommerce_ajax_added_to_cart', $product_id);

        wp_send_json_success([
            'message'    => sprintf(__('✅ %s (%d×) added to cart', 'rbs'), $product->get_name(), $quantity),
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'subtotal'   => wc_price(WC()->cart->get_cart_contents_total()),
        ]);
    }

    /**
     * FAST PATH: check the lookup table via raw SQL only.
     * Returns product_id|false.
     */
    public static function resolve_product_fast(string $barcode)
    {
        global $wpdb;
        $table = self::table_name();

        // Raw SQL, no WP object loading, very fast
        $product_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT product_id FROM {$table} WHERE barcode = %s LIMIT 1",
                $barcode
            )
        );

        return $product_id ? (int) $product_id : false;
    }

    /**
     * SLOW PATH: First checks the normal SKU field then the custom old barcodes meta field
     * Returns product_id|false.
     */
    public static function resolve_product_slow_from_meta(string $barcode)
    {
        global $wpdb;

        $product_id = wc_get_product_id_by_sku($barcode);

        if ($product_id) {
            return $product_id;
        }

        // If products only, join posts with post_type = 'product'.
        $product_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = %s
                     AND meta_value LIKE %s
                     LIMIT 1
                    ",
                'trm_gw_old_barcodes',
                '%' . $wpdb->esc_like($barcode) . '%'
            )
        );

        return $product_id ? (int) $product_id : false;
    }

    /**
     * Insert or update (idempotent) — prevents duplicates by UNIQUE(barcode).
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to refresh product_id/last_seen.
     */
    public static function upsert_barcode(string $barcode, int $product_id): void
    {
        global $wpdb;
        $table = self::table_name();

        // Prefer ON DUPLICATE KEY UPDATE for atomicity; $wpdb->replace() also works.
        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO {$table} (barcode, product_id, created_at, last_seen)
                VALUES (%s, %d, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    last_seen  = NOW()
                ",
                $barcode,
                $product_id
            )
        );
    }

    /** Helper: fully-qualified table name */
    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public function render_cart_barcode_scanner()
    {
        ob_start(); ?>
        <div id="rbs-cart-wrap">
            <h3>Scan Product to Add to Cart</h3>

            <video id="rbs-cart-video" autoplay playsinline></video>
            <canvas id="rbs-cart-canvas" class="hidden"></canvas>

            <div class="rbs-controls">
                <button id="rbs-cart-start">Start Scanner</button>
                <button id="rbs-cart-stop" disabled>Stop</button>
            </div>

            <div id="rbs-cart-result" style="margin-top:10px;font-weight:bold;"></div>

            <div id="rbs-cart-panel" class="hidden">
                <div><strong id="rbs-prod-name"></strong> (<span id="rbs-prod-sku"></span>)</div>
                <div>Price: <span id="rbs-prod-price"></span></div>

                <div class="rbs-form">
                    <label>Quantity:
                        <input type="number" id="rbs-cart-qty" value="1" min="1" step="1">
                    </label>
                </div>

                <button id="rbs-cart-add" class="button button-primary">Add to Cart</button>
                <button id="rbs-cart-cancel" class="button">Cancel</button>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}

new RealmBarcodeScanner();
