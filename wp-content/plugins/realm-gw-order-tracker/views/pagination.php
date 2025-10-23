<?php

/** @var array $pagination */
$page        = (int) ($pagination['page'] ?? 1);
$total_pages = (int) ($pagination['total_pages'] ?? 1);

if ($total_pages > 1): ?>
    <nav class="gwot-pagination" aria-label="<?php esc_attr_e('Orders pagination', 'gwot'); ?>">
        <button class="button gwot-page" data-page="<?php echo max(1, $page - 1); ?>" <?php disabled($page <= 1); ?>>
            <?php esc_html_e('« Prev', 'gwot'); ?>
        </button>

        <span class="gwot-pagination__status">
            <?php echo esc_html(sprintf(__('Page %d of %d', 'gwot'), $page, $total_pages)); ?>
        </span>

        <button class="button gwot-page" data-page="<?php echo min($total_pages, $page + 1); ?>" <?php disabled($page >= $total_pages); ?>>
            <?php esc_html_e('Next »', 'gwot'); ?>
        </button>
    </nav>
<?php endif; ?>