<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap for the Sales System module: admin menu, asset enqueue, page render.
 */
class RSS_Core
{
    const CAP                  = 'manage_woocommerce';
    const MENU_SLUG            = 'rss-sales-system';
    const NEW_ORDER_SLUG       = 'rss-new-order';
    const MARKETING_ORDER_SLUG = 'rss-marketing-order';
    const NONCE_ACTION         = 'rss_sales_system';

    private static $instance = null;

    /** Page hook suffix of the New Order screen, captured from add_submenu_page(). */
    private $new_order_hook = '';

    /** Page hook suffix of the New Marketing Order screen. */
    private $marketing_order_hook = '';

    public static function instance(): RSS_Core
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        new RSS_Ajax();

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Sales System', 'rss'),
            __('Sales System', 'rss'),
            self::CAP,
            self::MENU_SLUG,
            [$this, 'render_landing'],
            'dashicons-cart',
            56
        );

        // Rename the auto-created first submenu (mirrors the parent) to "Home".
        add_submenu_page(
            self::MENU_SLUG,
            __('Sales System', 'rss'),
            __('Home', 'rss'),
            self::CAP,
            self::MENU_SLUG,
            [$this, 'render_landing']
        );

        $this->new_order_hook = add_submenu_page(
            self::MENU_SLUG,
            __('New Order', 'rss'),
            __('New Order', 'rss'),
            self::CAP,
            self::NEW_ORDER_SLUG,
            [$this, 'render_new_order']
        );

        $this->marketing_order_hook = add_submenu_page(
            self::MENU_SLUG,
            __('New Marketing Order', 'rss'),
            __('New Marketing Order', 'rss'),
            self::CAP,
            self::MARKETING_ORDER_SLUG,
            [$this, 'render_marketing_order']
        );
    }

    public function enqueue_assets($hook): void
    {
        // Only on the order-placing screens (hook suffixes captured from
        // add_submenu_page). Both the standard and marketing order pages share
        // the same scanner/cart JS, parametrised by a `mode` flag below.
        $is_marketing = ($hook === $this->marketing_order_hook);
        if ($hook !== $this->new_order_hook && !$is_marketing) {
            return;
        }

        $version = '0.2.0';

        wp_enqueue_script(
            'quagga',
            'https://unpkg.com/quagga@0.12.1/dist/quagga.min.js',
            [],
            '0.12.1',
            true
        );

        wp_enqueue_script(
            'rss-new-order',
            RSS_PLUGIN_URL . 'assets/new-order.js',
            ['jquery', 'quagga'],
            $version,
            true
        );

        wp_localize_script('rss-new-order', 'rssData', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_ACTION),
            'currencySym'  => get_woocommerce_currency_symbol(),
            'decimals'     => wc_get_price_decimals(),
            // 'marketing' hides the member section + per-line discounts and posts
            // to the marketing place-order endpoint; 'order' is the standard flow.
            'mode'         => $is_marketing ? 'marketing' : 'order',
            'i18n'         => [
                'missingFields' => __('Please fill in the Name, Surname and Email fields.', 'rss'),
                'emptyCart'     => __('Please add at least one product before placing the order.', 'rss'),
                'guestConfirm'  => __('No member number is applied. This order will be placed for a guest customer with no discounts. Continue?', 'rss'),
                'notFound'      => __('Product not found for that barcode.', 'rss'),
                'networkError'  => __('Network error. Please try again.', 'rss'),
                'placing'       => __('Placing order…', 'rss'),
            ],
        ]);

        wp_enqueue_style(
            'rss-new-order',
            RSS_PLUGIN_URL . 'assets/new-order.css',
            [],
            $version
        );
    }

    public function render_landing(): void
    {
        require RSS_PLUGIN_DIR . 'views/landing.php';
    }

    public function render_new_order(): void
    {
        $rss_is_marketing = false;
        require RSS_PLUGIN_DIR . 'views/new-order.php';
    }

    public function render_marketing_order(): void
    {
        $rss_is_marketing = true;
        require RSS_PLUGIN_DIR . 'views/new-order.php';
    }
}
