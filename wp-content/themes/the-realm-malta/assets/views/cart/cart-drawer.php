<?php

/**
 * Off-canvas cart drawer.
 *
 * Rendered by TRM_Cart::render_drawer() via TRM_Core::render_template('cart/cart-drawer').
 *
 * The body wraps WooCommerce's mini-cart in `.widget_shopping_cart_content` — the selector WC's
 * AJAX add-to-cart refreshes by default — so the contents stay live. The wrapper
 * (.trm-cart-drawer__body) is kept OUTSIDE that div so it survives the fragment swap.
 */

if (!function_exists('woocommerce_mini_cart')) {
    return;
}
?>
<div class="trm-cart-drawer" id="trm-cart-drawer" aria-hidden="true">
    <div class="trm-cart-drawer__overlay" data-trm-cart-close tabindex="-1"></div>

    <aside class="trm-cart-drawer__panel" role="dialog" aria-modal="true"
        aria-label="<?php esc_attr_e('Shopping cart', 'the-realm-malta'); ?>">

        <div class="trm-cart-drawer__header">
            <span class="trm-cart-drawer__title"><?php esc_html_e('Shopping cart', 'the-realm-malta'); ?></span>
            <button type="button" class="trm-cart-drawer__close" data-trm-cart-close>
                <span class="trm-cart-drawer__close-icon" aria-hidden="true">&times;</span>
                <span class="trm-cart-drawer__close-label"><?php esc_html_e('Close', 'the-realm-malta'); ?></span>
            </button>
        </div>

        <div class="trm-cart-drawer__body">
            <div class="widget_shopping_cart_content">
                <?php woocommerce_mini_cart(); ?>
            </div>
        </div>
    </aside>
</div>
