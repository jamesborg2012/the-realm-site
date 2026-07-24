<?php

/**
 * Shared render template for the theme's product-carousel ACF blocks (item 33) —
 * `acf/new-releases-carousel` and `acf/pre-orders-carousel`.
 *
 * Included by TRM_Carousel_Block::render_block(), which prepares these locals:
 *   - string $title         Optional heading (already trimmed; may be '').
 *   - int[]  $ids           Capped, display-ordered product IDs (may be empty).
 *   - string $block_id      Per-instance wrapper id (sanitised).
 *   - string $base_class    Shared block class, e.g. 'trm-carousel'.
 *   - string $variant       Variant slug, e.g. 'new-releases' / 'pre-orders' (CSS modifier).
 *   - string $region_label  Accessible region label (aria fallback when no title).
 *   - string $empty_message Empty-state text.
 *   - string $loop_name     wc_setup_loop() name for this variant.
 *
 * Reuses WooCommerce's standard loop item (`content-product`) so member-discount / sale price
 * formatting and the "Order Now" backorder label flow through untouched. The <ul.products> is the
 * Slick track (slides must be its direct children); assets/js/trm-carousel.js initialises it.
 */

defined('ABSPATH') || exit;

$has_title    = ($title !== '');
$title_id     = $block_id . '-title';
$region_attrs = $has_title
    ? ' aria-labelledby="' . esc_attr($title_id) . '"'
    : ' aria-label="' . esc_attr($region_label) . '"';
?>
<section id="<?php echo esc_attr($block_id); ?>" class="<?php echo esc_attr($base_class . ' ' . $base_class . '--' . $variant); ?>"<?php echo $region_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above ?>>

    <?php if ($has_title) : ?>
        <h2 id="<?php echo esc_attr($title_id); ?>" class="<?php echo esc_attr($base_class . '__title'); ?>"><?php echo esc_html($title); ?></h2>
    <?php endif; ?>

    <?php if (empty($ids)) : ?>

        <p class="<?php echo esc_attr($base_class . '__empty'); ?>"><?php echo esc_html($empty_message); ?></p>

    <?php else : ?>

        <?php
        wc_setup_loop([
            'name'         => $loop_name,
            'is_shortcode' => false,
            'is_paginated' => false,
            'total'        => count($ids),
            'columns'      => 4,
        ]);

        global $post, $product;
        ?>
        <ul class="products columns-4 <?php echo esc_attr($base_class . '__track'); ?>">
            <?php
            foreach ($ids as $trm_pid) :
                $post = get_post($trm_pid); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard custom product loop
                if (!$post) {
                    continue;
                }
                setup_postdata($post);

                // the_post action does not fire on setup_postdata(), so set the product global that
                // content-product.php reads. Skip anything not visible in the catalogue.
                $product = wc_get_product($trm_pid);
                if (!$product instanceof WC_Product || !$product->is_visible()) {
                    continue;
                }

                wc_get_template_part('content', 'product');
            endforeach;

            wp_reset_postdata();
            wc_reset_loop();
            ?>
        </ul>

    <?php endif; ?>

</section>
