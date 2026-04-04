<?php
/**
 * Cost Price admin page wrapper.
 *
 * Variables available (extracted by TRM_Core::render_template):
 * @var string $active_tab
 * @var array  $products_data
 * @var int    $total_products
 * @var array  $history_map
 * @var int    $current_page
 * @var int    $per_page
 * @var int    $imported      -1 means no upload was just performed
 * @var int    $skipped
 * @var string $upload_error
 * @var array  $upload_errors
 */
?>
<div class="wrap trm-cost-price-wrap">

    <h1 class="wp-heading-inline">Product Cost Prices</h1>

    <hr class="wp-header-end">

    <nav class="nav-tab-wrapper wp-clearfix">
        <a href="<?php echo esc_url(admin_url('admin.php?page=trm-cost-price&tab=dashboard')); ?>"
           class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
            Dashboard
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=trm-cost-price&tab=upload')); ?>"
           class="nav-tab <?php echo $active_tab === 'upload' ? 'nav-tab-active' : ''; ?>">
            Import CSV
        </a>
    </nav>

    <div class="trm-cost-price-tab-content">
        <?php if ($active_tab === 'dashboard') : ?>
            <?php include __DIR__ . '/tab-dashboard.php'; ?>
        <?php else : ?>
            <?php include __DIR__ . '/tab-upload.php'; ?>
        <?php endif; ?>
    </div>

</div>
