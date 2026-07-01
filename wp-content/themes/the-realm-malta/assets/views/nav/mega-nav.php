<?php

/**
 * Off-canvas category drawer (3 levels: main → child → grandchild).
 *
 * Rendered by TRM_Mega_Nav::render_drawer() via TRM_Core::render_template('nav/mega-nav', ...).
 *
 * Panes are flat: every sub-category list (level 2 and level 3) is a `.trm-nav-sub` keyed by its
 * own `cat-{id}`.
 *
 * Every category is a real link to its archive. Items that ALSO have children render a split row:
 *   - a `.trm-nav-*__link` <a> (navigates to the category archive), and
 *   - a `.trm-nav-*__toggle` <button data-trm-nav-open="cat-{id}"> (opens the child pane).
 * The row wrapper carries `data-trm-nav-hover="cat-{id}"` so desktop hover can open the pane while
 * the label click still navigates. Leaf items are a single link carrying `data-trm-nav-clear="{level}"`
 * so desktop hover can collapse deeper columns.
 *
 *   Desktop: level 1 / 2 / 3 sit side by side as columns (fly-out on hover).
 *   Mobile : each level slides over the previous one, with a Back button; the label link navigates
 *            and the chevron button opens the sub-pane.
 *
 * @var array $tree  [['id','name','url','children'=>[['id','name','url','children'=>[...]], ...]], ...]
 */

if (empty($tree) || !is_array($tree)) {
    return;
}

/** Render one sub-list <li> — a split link+toggle (has children) or a plain link (leaf). */
$render_sub_item = static function (array $item, int $leaf_clear_level) {
    if (!empty($item['children'])) {
        printf(
            '<li class="trm-nav-sub__item" data-trm-nav-hover="cat-%1$d">'
                . '<a class="trm-nav-sub__link" href="%2$s">%3$s</a>'
                . '<button type="button" class="trm-nav-sub__toggle" data-trm-nav-open="cat-%1$d"'
                . ' aria-haspopup="true" aria-expanded="false" aria-label="%4$s">'
                . '<span class="trm-nav-sub__chevron" aria-hidden="true">&rsaquo;</span>'
                . '</button></li>',
            (int) $item['id'],
            esc_url($item['url']),
            esc_html($item['name']),
            esc_attr(sprintf(__('Show subcategories of %s', 'the-realm-malta'), $item['name']))
        );
    } else {
        printf(
            '<li><a class="trm-nav-sub__link" data-trm-nav-clear="%1$d" href="%2$s">%3$s</a></li>',
            (int) $leaf_clear_level,
            esc_url($item['url']),
            esc_html($item['name'])
        );
    }
};

/** Render one sub pane (the children of $parent at the given level). */
$render_pane = static function (array $parent, int $level) use ($render_sub_item) {
    // Leaves in this pane collapse the column immediately to their right (this pane's level + 1).
    // For the deepest pane that's a level with no column, so the clear becomes a harmless no-op —
    // crucially, a leaf never clears its OWN column (which would close the pane on hover).
    $leaf_clear_level = $level + 1;
    ?>
    <div class="trm-nav-sub" data-trm-nav-pane="cat-<?php echo (int) $parent['id']; ?>"
        data-trm-nav-level="<?php echo (int) $level; ?>" aria-hidden="true">
        <button type="button" class="trm-nav-sub__back" data-trm-nav-back>
            <span aria-hidden="true">&lsaquo;</span> <?php esc_html_e('Back', 'the-realm-malta'); ?>
        </button>
        <div class="trm-nav-sub__head">
            <h2 class="trm-nav-sub__title">
                <a class="trm-nav-sub__title-link" href="<?php echo esc_url($parent['url']); ?>">
                    <?php echo esc_html($parent['name']); ?>
                </a>
            </h2>
        </div>
        <ul class="trm-nav-sub__list">
            <?php foreach ($parent['children'] as $child) {
                $render_sub_item($child, $leaf_clear_level);
            } ?>
        </ul>
    </div>
    <?php
};
?>
<div class="trm-nav-drawer" id="trm-nav-drawer" aria-hidden="true">
    <div class="trm-nav-drawer__overlay" data-trm-nav-close tabindex="-1"></div>

    <aside class="trm-nav-drawer__panel" role="dialog" aria-modal="true"
        aria-label="<?php esc_attr_e('Product categories', 'the-realm-malta'); ?>">

        <div class="trm-nav-drawer__header">
            <span class="trm-nav-drawer__title"><?php esc_html_e('All Categories', 'the-realm-malta'); ?></span>
            <button type="button" class="trm-nav-drawer__close" data-trm-nav-close
                aria-label="<?php esc_attr_e('Close menu', 'the-realm-malta'); ?>">&times;</button>
        </div>

        <div class="trm-nav-drawer__body">
            <!-- Level 1: main categories -->
            <ul class="trm-nav-main">
                <?php foreach ($tree as $main) : ?>
                    <?php if (!empty($main['children'])) : ?>
                        <li class="trm-nav-main__item" data-trm-nav-hover="cat-<?php echo (int) $main['id']; ?>">
                            <a class="trm-nav-main__link" href="<?php echo esc_url($main['url']); ?>">
                                <span class="trm-nav-main__label"><?php echo esc_html($main['name']); ?></span>
                            </a>
                            <button type="button" class="trm-nav-main__toggle"
                                data-trm-nav-open="cat-<?php echo (int) $main['id']; ?>"
                                aria-haspopup="true" aria-expanded="false"
                                aria-label="<?php echo esc_attr(sprintf(__('Show subcategories of %s', 'the-realm-malta'), $main['name'])); ?>">
                                <span class="trm-nav-main__chevron" aria-hidden="true">&rsaquo;</span>
                            </button>
                        </li>
                    <?php else : ?>
                        <li class="trm-nav-main__item">
                            <a class="trm-nav-main__link" data-trm-nav-clear="2"
                                href="<?php echo esc_url($main['url']); ?>">
                                <span class="trm-nav-main__label"><?php echo esc_html($main['name']); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <!-- Level 2: child panes -->
            <?php foreach ($tree as $main) : ?>
                <?php if (empty($main['children'])) {
                    continue;
                } ?>
                <?php $render_pane($main, 2); ?>
            <?php endforeach; ?>

            <!-- Level 3: grandchild panes -->
            <?php foreach ($tree as $main) : ?>
                <?php foreach ($main['children'] as $child) : ?>
                    <?php if (empty($child['children'])) {
                        continue;
                    } ?>
                    <?php $render_pane($child, 3); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </aside>
</div>
