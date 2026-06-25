<?php

/**
 * TRM_Cart — off-canvas cart drawer.
 *
 * Replaces Storefront's hover-dropdown header cart (storefront_header_cart) with:
 *   - a red pill trigger in the lower header bar (cart icon + count badge + subtotal), and
 *   - a right-hand off-canvas drawer (rendered in the footer) holding WooCommerce's mini-cart.
 *
 * Live updates: WooCommerce's AJAX add-to-cart always returns a `div.widget_shopping_cart_content`
 * fragment, so the drawer body refreshes itself for free. We additionally register fragments for
 * the trigger's count badge and subtotal so the button stays in sync.
 */
class TRM_Cart extends TRM_Core
{
    public function __construct()
    {
        // after_setup_theme @ 11: Storefront registers storefront_header_cart (priority 60) when
        // its functions.php loads, which runs after this child theme — so remove it there.
        add_action('after_setup_theme', [$this, 'register_hook_callbacks'], 11);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_drawer']);
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'cart_fragments']);
    }

    public function register_hook_callbacks()
    {
        if (!function_exists('WC')) {
            return;
        }
        remove_action('storefront_header', 'storefront_header_cart', 60);
        add_action('storefront_header', [$this, 'render_cart_trigger'], 60);
    }

    /**
     * Enqueue the drawer open/close script. Styling ships in layout.css (cart.scss).
     *
     * @return void
     */
    public function enqueue_assets()
    {
        if (!function_exists('WC')) {
            return;
        }
        // Depend on wc-cart-fragments so it's always present — that's the script that refreshes
        // the drawer's mini-cart and the trigger badge/total after AJAX add-to-cart.
        wp_enqueue_script(
            'trm-cart',
            get_stylesheet_directory_uri() . '/assets/js/trm-cart.js',
            ['wc-cart-fragments'],
            time(),
            true
        );
    }

    /**
     * Render the cart trigger pill in the lower header bar.
     *
     * Hook: storefront_header (priority 60)
     *
     * @return void
     */
    public function render_cart_trigger()
    {
        if (!function_exists('WC')) {
            return;
        }

        printf(
            '<div class="trm-cart">'
                . '<button type="button" class="trm-cart-trigger" data-trm-cart-trigger aria-controls="trm-cart-drawer" aria-expanded="false" aria-label="%1$s">'
                . '<span class="trm-cart-trigger__icon" aria-hidden="true">%2$s%3$s</span>'
                . '%4$s'
                . '</button>'
                . '</div>',
            esc_attr__('View your shopping cart', 'the-realm-malta'),
            $this->cart_icon_svg(),
            $this->count_html(),
            $this->total_html()
        );
    }

    /**
     * Output the off-canvas cart drawer at the end of the page.
     *
     * Hook: wp_footer
     *
     * @return void
     */
    public function render_drawer()
    {
        if (!function_exists('WC')) {
            return;
        }
        echo $this->render_template('cart/cart-drawer');
    }

    /**
     * Keep the trigger's count badge and subtotal in sync with AJAX cart updates.
     *
     * @param array $fragments
     * @return array
     */
    public function cart_fragments($fragments)
    {
        $fragments['span.trm-cart-trigger__count'] = $this->count_html();
        $fragments['span.trm-cart-trigger__total'] = $this->total_html();
        return $fragments;
    }

    private function count_html()
    {
        $count = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;
        return '<span class="trm-cart-trigger__count">' . esc_html($count) . '</span>';
    }

    private function total_html()
    {
        $total = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_subtotal() : wc_price(0);
        return '<span class="trm-cart-trigger__total">' . wp_kses_post($total) . '</span>';
    }

    private function cart_icon_svg()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
    }
}
