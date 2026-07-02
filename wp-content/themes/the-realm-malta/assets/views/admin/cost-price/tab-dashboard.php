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
$today          = current_time('Y-m-d');
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

                // The current row = first in the (effective_date DESC) history whose
                // effective date is on or before today. Used to badge it in the panel.
                $current_row_id = 0;
                foreach ($history_rows as $hr) {
                    if ($hr->effective_date <= $today) {
                        $current_row_id = (int) $hr->id;
                        break;
                    }
                }
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
                    <details class="trm-history-details"
                             data-product-id="<?php echo esc_attr($product_id); ?>"
                             data-sku="<?php echo esc_attr($product->sku); ?>">
                        <summary><?php echo esc_html($history_count); ?> <?php echo $history_count === 1 ? 'record' : 'records'; ?> &mdash; manage / backdate</summary>
                        <table class="trm-history-table">
                            <thead>
                                <tr>
                                    <th class="trm-hist-col-eff">Effective From</th>
                                    <th class="trm-hist-col-price">Cost Price</th>
                                    <th class="trm-hist-col-rec">Recorded</th>
                                    <th class="trm-hist-col-act"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_rows as $hist_row) :
                                    $rid        = (int) $hist_row->id;
                                    $is_current = ($rid === $current_row_id);
                                    $eff_val    = esc_attr($hist_row->effective_date);
                                    $price_val  = esc_attr(number_format((float) $hist_row->cost_price, 2, '.', ''));
                                ?>
                                <tr class="trm-hist-row<?php echo $is_current ? ' is-current' : ''; ?>" data-row-id="<?php echo esc_attr($rid); ?>">
                                    <td class="trm-hist-eff">
                                        <span class="trm-hist-eff-disp"><?php echo esc_html(date_i18n('d M Y', strtotime($hist_row->effective_date))); ?></span>
                                        <input type="date" class="trm-hist-eff-input" value="<?php echo $eff_val; ?>" max="<?php echo esc_attr($today); ?>" hidden>
                                        <span class="trm-hist-current-badge">Current</span>
                                    </td>
                                    <td class="trm-hist-price">
                                        &euro;<span class="trm-hist-price-disp"><?php echo esc_html(number_format((float) $hist_row->cost_price, 2)); ?></span>
                                        <input type="number" step="0.01" min="0" inputmode="decimal" class="trm-hist-price-input small-text" value="<?php echo $price_val; ?>" hidden>
                                    </td>
                                    <td class="trm-hist-recorded">
                                        <?php echo esc_html(date_i18n('d M Y H:i', strtotime($hist_row->uploaded_at))); ?>
                                        <span class="trm-hist-source"><?php echo esc_html($hist_row->source); ?></span>
                                    </td>
                                    <td class="trm-hist-actions">
                                        <button type="button" class="button-link trm-hist-edit">Edit</button>
                                        <button type="button" class="button-link trm-hist-delete">Delete</button>
                                        <button type="button" class="button button-small button-primary trm-hist-save" hidden>Save</button>
                                        <button type="button" class="button button-small trm-hist-cancel" hidden>Cancel</button>
                                        <span class="spinner trm-hist-spinner"></span>
                                        <span class="trm-hist-error" hidden></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="trm-add-dated">
                            <strong>Add a cost price for a past date</strong>
                            <span class="trm-add-fields">
                                <label>&euro;
                                    <input type="number" step="0.01" min="0" inputmode="decimal" class="trm-add-price small-text" placeholder="0.00">
                                </label>
                                <label>Valid from
                                    <input type="date" class="trm-add-eff" max="<?php echo esc_attr($today); ?>">
                                </label>
                                <button type="button" class="button button-small trm-add-save">Add</button>
                                <span class="spinner trm-add-spinner"></span>
                            </span>
                            <span class="trm-add-error" hidden></span>
                        </div>
                    </details>
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
