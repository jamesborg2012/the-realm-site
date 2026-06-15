<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap for the Sales System module: admin menu, asset enqueue, page render.
 */
class RSS_Core
{
    const CAP            = 'manage_woocommerce';
    const MENU_SLUG      = 'rss-sales-system';
    const NEW_ORDER_SLUG = 'rss-new-order';
    const NONCE_ACTION   = 'rss_sales_system';

    private static $instance = null;

    /** Page hook suffix of the New Order screen, captured from add_submenu_page(). */
    private $new_order_hook = '';

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
    }

    public function enqueue_assets($hook): void
    {
        // Only on the New Order screen (hook suffix captured from add_submenu_page).
        if ($hook !== $this->new_order_hook) {
            return;
        }

        $version = '0.1.0';

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
        require RSS_PLUGIN_DIR . 'views/new-order.php';
    }
}
