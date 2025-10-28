<?php
/**
 * Sub Category Filter Template
 *
 * This view receives the following variables:
 * - $sub_terms (WP_Term[]): array of immediate child terms of the current product_cat
 * - $current_term (WP_Term): the currently queried product_cat term
 *
 * You can customize the markup below to render a list of terms based on $sub_terms.
 * This file is intentionally minimal and safe; feel free to replace its contents.
 *
 * This is a custom template file based on how WooCommerce works
 */

defined('ABSPATH') || exit;

if (empty($sub_terms) || !is_array($sub_terms) || empty($current_term)) {
    return; // Nothing to render.
}

/**
 * @var WP_Term $category
 */

// Example minimal placeholder output (replace as needed):
?>
<div class="sub-category-sub-title">
    <h2>Explore the range for <?= $current_term->name ?></h2>
</div>
<div class="product-category-carousel">
    <div class="product-category-list">
        <?php foreach ($sub_terms as $category): ?>
            <div class="product-category-item">
                <a href="<?php echo esc_url(get_term_link($category, 'product_cat')); ?>">
                    <p class="product-cat-title"><?= $category->name ?></p>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>