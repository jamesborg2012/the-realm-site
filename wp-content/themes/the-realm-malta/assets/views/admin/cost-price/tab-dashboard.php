<?php
/**
 * Dashboard tab — paginated table of all products with cost prices.
 *
 * Variables available from parent template:
 * @var array  $products_data      Each item: {ID, post_title, sku, cost_price, uploaded_at, uploaded_by}
 * @var int    $total_products
 * @var array  $history_map        Associative: product_id => array of row objects (newest first)
 * @var int    $current_page
 * @var int    $per_page
 * @var string $sku_search     Current SKU search term (empty string = no search)
 * @var bool   $sku_not_found  True when SKU was searched but yielded no result
 */

$total_pages    = $total_products > 0 ? (int) ceil($total_products / $per_page) : 1;
$is_search_mode = $sku_search !== '';
$base_page_url  = admin_url('admin.php?page=trm-cost-price&tab=dashboard');
?>

<div class="trm-dashboard-header">
    <h2>
        Cost Price Overview
        <?php if (!$is_search_mode) : ?>
            <span class="trm-badge"><?php echo esc_html($total_products); ?> <?php echo $total_products === 1 ? 'product' : 'products'; ?></span>
        <?php endif; ?>
    </h2>
    <a href="<?php echo esc_url(admin_url('admin.php?page=trm-cost-price&tab=upload')); ?>" class="button button-primary">
        Import CSV
    </a>
</div>

<!-- SKU Search -->
<div class="trm-sku-search-wrap">
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="trm-sku-form">
        <input type="hidden" name="page" value="trm-cost-price">
        <input type="hidden" name="tab" value="dashboard">
        <div class="trm-sku-input-group">
            <label for="trm-sku-input" class="screen-reader-text">Search by SKU / Barcode</label>
            <input
                type="text"
                id="trm-sku-input"
                name="sku"
                value="<?php echo esc_attr($sku_search); ?>"
                placeholder="Search by SKU / barcode&hellip;"
                class="regular-text"
                <?php echo $is_search_mode ? 'autofocus' : ''; ?>
            >
            <button type="submit" class="button">Search</button>
            <?php if ($is_search_mode) : ?>
                <a href="<?php echo esc_url($base_page_url); ?>" class="button trm-clear-search">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($sku_not_found) : ?>
    <div class="notice notice-warning inline trm-search-notice">
        <p>No product found for SKU / barcode <strong><?php echo esc_html($sku_search); ?></strong>. The SKU may not exist or may not have a cost price set yet.</p>
    </div>
<?php elseif ($is_search_mode && !empty($products_data)) : ?>
    <div class="notice notice-info inline trm-search-notice">
        <p>Showing result for SKU / barcode <strong><?php echo esc_html($sku_search); ?></strong>. <a href="<?php echo esc_url($base_page_url); ?>">View all products</a>.</p>
    </div>
<?php endif; ?>

<?php if (empty($products_data) && !$sku_not_found) : ?>
    <div class="trm-empty-state">
        <p>No cost prices have been set yet. <a href="<?php echo esc_url(admin_url('admin.php?page=trm-cost-price&tab=upload')); ?>">Import a CSV</a> to get started.</p>
    </div>
<?php elseif (!empty($products_data)) : ?>

    <?php if (!$is_search_mode && $total_pages > 1) : ?>
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_products); ?> items</span>
                <span class="pagination-links">
                    <?php if ($current_page > 1) : ?>
                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1, $base_page_url)); ?>">&laquo;</a>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $base_page_url)); ?>">&lsaquo;</a>
                    <?php else : ?>
                        <span class="first-page button disabled">&laquo;</span>
                        <span class="prev-page button disabled">&lsaquo;</span>
                    <?php endif; ?>
                    <span class="paging-input">
                        <?php echo esc_html($current_page); ?> of <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                    </span>
                    <?php if ($current_page < $total_pages) : ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $base_page_url)); ?>">&rsaquo;</a>
                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_page_url)); ?>">&raquo;</a>
                    <?php else : ?>
                        <span class="next-page button disabled">&rsaquo;</span>
                        <span class="last-page button disabled">&raquo;</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped trm-cost-price-table">
        <thead>
            <tr>
                <th scope="col" class="column-product">Product</th>
                <th scope="col" class="column-sku">SKU</th>
                <th scope="col" class="column-cost-price">Current Cost Price</th>
                <th scope="col" class="column-updated">Last Updated</th>
                <th scope="col" class="column-history">Price History</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products_data as $product) :
                $product_id    = (int) $product->ID;
                $history_rows  = $history_map[$product_id] ?? [];
                $history_count = count($history_rows);
                $edit_link     = get_edit_post_link($product_id);
            ?>
            <tr class="trm-product-row">
                <td class="column-product">
                    <a href="<?php echo esc_url($edit_link); ?>">
                        <?php echo esc_html($product->post_title); ?>
                    </a>
                </td>
                <td class="column-sku">
                    <code><?php echo esc_html($product->sku); ?></code>
                </td>
                <td class="column-cost-price">
                    <div class="trm-cp-edit" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <span class="trm-cp-display">
                            <strong>&euro;<span class="trm-cp-value"><?php echo esc_html(number_format((float) $product->cost_price, 2)); ?></span></strong>
                            <button type="button" class="button-link trm-cp-edit-btn" title="Edit cost price">Edit</button>
                        </span>
                        <span class="trm-cp-form" hidden>
                            <span class="trm-cp-euro">&euro;</span>
                            <input type="number" step="0.01" min="0" inputmode="decimal" class="trm-cp-input small-text"
                                   value="<?php echo esc_attr(number_format((float) $product->cost_price, 2, '.', '')); ?>">
                            <button type="button" class="button button-small button-primary trm-cp-save">Save</button>
                            <button type="button" class="button button-small trm-cp-cancel">Cancel</button>
                            <span class="spinner trm-cp-spinner"></span>
                        </span>
                        <span class="trm-cp-error" hidden></span>
                    </div>
                </td>
                <td class="column-updated">
                    <span class="trm-cp-updated"><?php echo esc_html(date_i18n('d M Y H:i', strtotime($product->uploaded_at))); ?></span>
                </td>
                <td class="column-history">
                    <?php if ($history_count <= 1) : ?>
                        <span class="trm-no-history">No previous records</span>
                    <?php else : ?>
                        <details class="trm-history-details">
                            <summary><?php echo esc_html($history_count - 1); ?> previous <?php echo ($history_count - 1) === 1 ? 'record' : 'records'; ?></summary>
                            <table class="trm-history-table">
                                <thead>
                                    <tr>
                                        <th>Cost Price</th>
                                        <th>Date Set</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $past_rows = array_slice($history_rows, 1);
                                    foreach ($past_rows as $hist_row) :
                                    ?>
                                    <tr>
                                        <td>&euro;<?php echo esc_html(number_format((float) $hist_row->cost_price, 2)); ?></td>
                                        <td><?php echo esc_html(date_i18n('d M Y H:i', strtotime($hist_row->uploaded_at))); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">SKU</th>
                <th scope="col">Current Cost Price</th>
                <th scope="col">Last Updated</th>
                <th scope="col">Price History</th>
            </tr>
        </tfoot>
    </table>

    <?php if (!$is_search_mode && $total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_products); ?> items</span>
                <span class="pagination-links">
                    <?php if ($current_page > 1) : ?>
                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1, $base_page_url)); ?>">&laquo;</a>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $base_page_url)); ?>">&lsaquo;</a>
                    <?php else : ?>
                        <span class="first-page button disabled">&laquo;</span>
                        <span class="prev-page button disabled">&lsaquo;</span>
                    <?php endif; ?>
                    <span class="paging-input">
                        <?php echo esc_html($current_page); ?> of <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                    </span>
                    <?php if ($current_page < $total_pages) : ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $base_page_url)); ?>">&rsaquo;</a>
                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_page_url)); ?>">&raquo;</a>
                    <?php else : ?>
                        <span class="next-page button disabled">&rsaquo;</span>
                        <span class="last-page button disabled">&raquo;</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>
