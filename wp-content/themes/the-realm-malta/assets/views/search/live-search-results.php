<?php
/**
 * Live-search dropdown panel contents.
 *
 * Rendered by TRM_WC_Hooks::handle_live_search() and injected into the
 * .trm-live-search__panel element by assets/js/live-search.js.
 *
 * Variables (via extract()):
 *
 * @var WC_Product[] $products    Matched products (already filtered by visibility + price gate).
 * @var string       $term        The (sanitised) raw search term.
 * @var string       $search_url  Full URL for the "View all results" link (mirrors the form submit).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($products)) {
    ?>
    <div class="trm-live-search__empty">
        <p><?php
            /* translators: %s: search term */
            printf(esc_html__('No products found for "%s".', 'the-realm-malta'), esc_html($term));
        ?></p>
    </div>
    <?php
    return;
}

// Build a regex once that we'll use to wrap matches in <mark>. Word-aware: matches as a substring,
// case-insensitive, multibyte-safe via the /u modifier.
$highlight_pattern = '/(' . preg_quote($term, '/') . ')/iu';
?>
<ul class="trm-live-search__list">
    <?php foreach ($products as $i => $product):
        /** @var WC_Product $product */
        $permalink     = $product->get_permalink();
        $title         = $product->get_name();
        $sku           = $product->get_sku();
        $price_html    = $product->get_price_html();
        $thumbnail_id  = $product->get_image_id();
        $thumbnail_src = $thumbnail_id
            ? wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail')
            : wc_placeholder_img_src('woocommerce_thumbnail');

        // Highlight matches in the title. KSES-allowed <mark> tag, no other HTML expected.
        $highlighted_title = preg_replace(
            $highlight_pattern,
            '<mark>$1</mark>',
            esc_html($title)
        );
    ?>
        <li class="trm-live-search__item" role="option" data-index="<?php echo (int) $i; ?>">
            <a class="trm-live-search__link" href="<?php echo esc_url($permalink); ?>">
                <span class="trm-live-search__thumb">
                    <?php if ($thumbnail_src): ?>
                        <img src="<?php echo esc_url($thumbnail_src); ?>" alt="" loading="lazy" />
                    <?php endif; ?>
                </span>
                <span class="trm-live-search__body">
                    <span class="trm-live-search__title"><?php echo wp_kses($highlighted_title, ['mark' => []]); ?></span>
                    <?php if ($sku): ?>
                        <span class="trm-live-search__sku"><?php echo esc_html__('SKU:', 'the-realm-malta'); ?> <?php echo esc_html($sku); ?></span>
                    <?php endif; ?>
                    <?php if ($price_html): ?>
                        <span class="trm-live-search__price"><?php echo wp_kses_post($price_html); ?></span>
                    <?php endif; ?>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<a class="trm-live-search__view-all" href="<?php echo esc_url($search_url); ?>">
    <?php
    /* translators: %s: search term */
    printf(esc_html__('View all results for "%s"', 'the-realm-malta'), esc_html($term));
    ?>
</a>
