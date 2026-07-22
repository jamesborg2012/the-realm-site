<?php

/**
 * Render template for the `acf/new-releases-carousel` block.
 *
 * Included by TRM_New_Releases::render_block(), which prepares these locals:
 *   - string $title    Optional heading (already trimmed; may be '').
 *   - int[]  $ids       Capped, display-ordered product IDs (may be empty).
 *   - string $block_id  Per-instance wrapper id (sanitised).
 *
 * Reuses WooCommerce's standard loop item (`content-product`) so member-discount / sale price
 * formatting and the "Order Now" backorder label flow through untouched. The <ul.products> is the
 * Slick track (slides must be its direct children); assets/js/trm-new-releases.js initialises it.
 */

defined('ABSPATH') || exit;

$has_title    = ($title !== '');
$title_id     = $block_id . '-title';
$region_attrs = $has_title
    ? ' aria-labelledby="' . esc_attr($title_id) . '"'
    : ' aria-label="' . esc_attr__('New Releases', 'the-realm-malta') . '"';
?>
<section id="<?php echo esc_attr($block_id); ?>" class="trm-new-releases"<?php echo $region_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above ?>>

    <?php if ($has_title) : ?>
        <h2 id="<?php echo esc_attr($title_id); ?>" class="trm-new-releases__title"><?php echo esc_html($title); ?></h2>
    <?php endif; ?>

    <?php if (empty($ids)) : ?>

        <p class="trm-new-releases__empty"><?php echo esc_html__('No products released this week.', 'the-realm-malta'); ?></p>

    <?php else : ?>

        <?php
        wc_setup_loop([
            'name'         => 'trm-new-releases',
            'is_shortcode' => false,
            'is_paginated' => false,
            'total'        => count($ids),
            'columns'      => 4,
        ]);

        global $post, $product;
        ?>
        <ul class="products columns-4 trm-new-releases__track">
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
