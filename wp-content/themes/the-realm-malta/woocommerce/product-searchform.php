<?php
/**
 * Product search form — overridden to wrap the WC widget form with a live-search dropdown container.
 *
 * Mirrors WooCommerce templates/product-searchform.php @ 7.0.1 (kept the same form fields so existing
 * CSS targeting .woocommerce-product-search / .search-field continues to work). The wrapping div plus
 * the .trm-live-search__panel container are wired up by assets/js/live-search.js. The submit button's
 * text label is kept for screen readers; the visible affordance is the inlined SVG magnifier.
 *
 * @see TRM_WC_Hooks::handle_live_search() — AJAX endpoint that populates the panel.
 */

if (!defined('ABSPATH')) {
    exit;
}

$field_id = isset($index) ? absint($index) : 0;
?>
<div class="trm-live-search" data-trm-live-search>
    <form role="search" method="get" class="woocommerce-product-search trm-live-search__form" action="<?php echo esc_url(home_url('/')); ?>">
        <label class="screen-reader-text" for="woocommerce-product-search-field-<?php echo $field_id; ?>"><?php esc_html_e('Search for:', 'woocommerce'); ?></label>
        <input
            type="search"
            id="woocommerce-product-search-field-<?php echo $field_id; ?>"
            class="search-field trm-live-search__input"
            placeholder="<?php echo esc_attr__('Search products&hellip;', 'woocommerce'); ?>"
            value="<?php echo get_search_query(); ?>"
            name="s"
            autocomplete="off"
            aria-autocomplete="list"
            aria-controls="trm-live-search-panel-<?php echo $field_id; ?>"
            aria-expanded="false"
        />
        <button
            type="button"
            class="trm-live-search__clear"
            aria-label="<?php esc_attr_e('Clear search', 'the-realm-malta'); ?>"
            hidden
        >
            <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="14" height="14">
                <path d="M4 4l12 12M16 4L4 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
        </button>
        <button
            type="submit"
            value="<?php echo esc_attr_x('Search', 'submit button', 'woocommerce'); ?>"
            class="<?php echo esc_attr(wc_wp_theme_get_element_class_name('button')); ?> trm-live-search__submit"
            aria-label="<?php esc_attr_e('Search', 'the-realm-malta'); ?>"
        >
            <span class="screen-reader-text"><?php echo esc_html_x('Search', 'submit button', 'woocommerce'); ?></span>
            <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="18" height="18">
                <circle cx="9" cy="9" r="6.25" fill="none" stroke="currentColor" stroke-width="1.75" />
                <path d="M14 14l4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" />
            </svg>
        </button>
        <input type="hidden" name="post_type" value="product" />
    </form>
    <div
        id="trm-live-search-panel-<?php echo $field_id; ?>"
        class="trm-live-search__panel"
        role="listbox"
        aria-label="<?php esc_attr_e('Search suggestions', 'the-realm-malta'); ?>"
        hidden
    ></div>
</div>
