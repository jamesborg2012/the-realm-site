<?php

/**
 * Off-canvas category drawer (3 levels: main → child → grandchild).
 *
 * Rendered by TRM_Mega_Nav::render_drawer() via TRM_Core::render_template('nav/mega-nav', ...).
 *
 * Panes are flat: every sub-category list (level 2 and level 3) is a `.trm-nav-sub` keyed by its
 * own `cat-{id}`. Buttons reference their pane via `data-trm-nav-open`; childless items are plain
 * links carrying `data-trm-nav-clear="{level}"` so desktop hover can collapse deeper columns.
 *
 *   Desktop: level 1 / 2 / 3 sit side by side as columns (fly-out on hover).
 *   Mobile : each level slides over the previous one, with a Back button.
 *
 * @var array $tree  [['id','name','url','children'=>[['id','name','url','children'=>[...]], ...]], ...]
 */

if (empty($tree) || !is_array($tree)) {
    return;
}

/** Render one sub-list <li> — a button (has children) or a link (leaf). */
$render_sub_item = static function (array $item, int $leaf_clear_level) {
    if (!empty($item['children'])) {
        printf(
            '<li><button type="button" class="trm-nav-sub__btn" data-trm-nav-open="cat-%1$d" aria-haspopup="true" aria-expanded="false">'
                . '<span class="trm-nav-sub__btn-label">%2$s</span>'
                . '<span class="trm-nav-sub__chevron" aria-hidden="true">&rsaquo;</span>'
                . '</button></li>',
            (int) $item['id'],
            esc_html($item['name'])
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
$render_pane = static function (array $parent, int $level, int $leaf_clear_level) use ($render_sub_item) {
    ?>
    <div class="trm-nav-sub" data-trm-nav-pane="cat-<?php echo (int) $parent['id']; ?>"
        data-trm-nav-level="<?php echo (int) $level; ?>" aria-hidden="true">
        <button type="button" class="trm-nav-sub__back" data-trm-nav-back>
            <span aria-hidden="true">&lsaquo;</span> <?php esc_html_e('Back', 'the-realm-malta'); ?>
        </button>
        <div class="trm-nav-sub__head">
            <h2 class="trm-nav-sub__title"><?php echo esc_html($parent['name']); ?></h2>
            <a class="trm-nav-sub__viewall" href="<?php echo esc_url($parent['url']); ?>">
                <?php printf(esc_html__('View all %s', 'the-realm-malta'), esc_html($parent['name'])); ?>
            </a>
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
                    <li class="trm-nav-main__item">
                        <?php if (!empty($main['children'])) : ?>
                            <button type="button" class="trm-nav-main__btn"
                                data-trm-nav-open="cat-<?php echo (int) $main['id']; ?>"
                                aria-haspopup="true" aria-expanded="false">
                                <span class="trm-nav-main__label"><?php echo esc_html($main['name']); ?></span>
                                <span class="trm-nav-main__chevron" aria-hidden="true">&rsaquo;</span>
                            </button>
                        <?php else : ?>
                            <a class="trm-nav-main__btn trm-nav-main__btn--link" data-trm-nav-clear="2"
                                href="<?php echo esc_url($main['url']); ?>">
                                <span class="trm-nav-main__label"><?php echo esc_html($main['name']); ?></span>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Level 2: child panes -->
            <?php foreach ($tree as $main) : ?>
                <?php if (empty($main['children'])) {
                    continue;
                } ?>
                <?php $render_pane($main, 2, 3); ?>
            <?php endforeach; ?>

            <!-- Level 3: grandchild panes -->
            <?php foreach ($tree as $main) : ?>
                <?php foreach ($main['children'] as $child) : ?>
                    <?php if (empty($child['children'])) {
                        continue;
                    } ?>
                    <?php $render_pane($child, 3, 3); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </aside>
</div>
