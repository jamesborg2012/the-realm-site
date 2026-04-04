<?php
/**
 * Profit Analytics — Admin Page
 *
 * @var string  $date_from   'Y-m-d' (validated, defaulted to past 7 days)
 * @var string  $date_to     'Y-m-d' (validated, defaulted to today)
 * @var bool    $is_filtered True when the user submitted a date filter
 * @var array[] $rows        Aggregated profit rows from TRM_Profit_Analytics::aggregate_profit_data()
 *   Each row: product_id (int), product_title (string), sku (string),
 *             cost_price (float|null), cp_set_on (string|null),
 *             period_start (string), period_end (string),
 *             units_sold (int), revenue (float),
 *             total_cost (float|null), profit (float|null), margin (float|null)
 */

$has_rows       = !empty($rows);
$has_missing_cp = false;
$total_revenue  = 0.0;
$total_cost     = 0.0;
$total_profit   = 0.0;
$total_units    = 0;

foreach ($rows as $row) {
    $total_revenue += $row['revenue'];
    $total_units   += $row['units_sold'];
    if ($row['total_cost'] !== null) {
        $total_cost   += $row['total_cost'];
        $total_profit += $row['profit'];
    } else {
        $has_missing_cp = true;
    }
}

$overall_margin = ($total_revenue > 0) ? ($total_profit / $total_revenue * 100) : null;

$fmt_date = fn(string $dt): string => date_i18n('d M Y', strtotime($dt));
$page_url = admin_url('admin.php?page=trm-profit-analytics');
?>

<div class="wrap trm-pa-wrap">

    <h1>Profit Analytics</h1>

    <!-- Date Range Filter -->
    <div class="trm-pa-filter-bar">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="trm-profit-analytics">
            <label for="trm-pa-date-from" class="trm-pa-filter-label">From</label>
            <input
                type="date"
                id="trm-pa-date-from"
                name="date_from"
                value="<?php echo esc_attr($date_from); ?>"
                class="trm-pa-date-input"
            >
            <label for="trm-pa-date-to" class="trm-pa-filter-label">To</label>
            <input
                type="date"
                id="trm-pa-date-to"
                name="date_to"
                value="<?php echo esc_attr($date_to); ?>"
                class="trm-pa-date-input"
            >
            <button type="submit" class="button button-primary">Apply</button>
            <?php if ($is_filtered) : ?>
                <a href="<?php echo esc_url($page_url); ?>" class="button trm-pa-reset">Reset</a>
            <?php endif; ?>
        </form>
        <p class="trm-pa-range-note">
            Showing orders from
            <strong><?php echo esc_html($fmt_date($date_from . ' 00:00:00')); ?></strong>
            to
            <strong><?php echo esc_html($fmt_date($date_to . ' 00:00:00')); ?></strong>.
            Statuses included: <em>completed</em>, <em>processing</em>.
        </p>
    </div>

    <?php if ($has_missing_cp) : ?>
        <div class="notice notice-warning inline trm-pa-notice">
            <p>Some products have no cost price recorded. Revenue is shown for all products, but cost, profit, and margin are only calculated for products with a known cost price.</p>
        </div>
    <?php endif; ?>

    <?php if ($has_rows) : ?>

        <!-- Summary Stats -->
        <div class="trm-pa-summary">
            <div class="trm-pa-stat">
                <span class="trm-pa-stat-value">&euro;<?php echo esc_html(number_format($total_revenue, 2)); ?></span>
                <span class="trm-pa-stat-label">Revenue</span>
            </div>
            <div class="trm-pa-stat">
                <span class="trm-pa-stat-value">
                    &euro;<?php echo esc_html(number_format($total_cost, 2)); ?>
                    <?php if ($has_missing_cp) : ?><sup title="Partial — excludes products with no cost price">*</sup><?php endif; ?>
                </span>
                <span class="trm-pa-stat-label">Total Cost<?php echo $has_missing_cp ? ' <sup>*</sup>' : ''; ?></span>
            </div>
            <div class="trm-pa-stat trm-pa-stat--profit">
                <span class="trm-pa-stat-value">
                    &euro;<?php echo esc_html(number_format($total_profit, 2)); ?>
                    <?php if ($has_missing_cp) : ?><sup title="Partial — excludes products with no cost price">*</sup><?php endif; ?>
                </span>
                <span class="trm-pa-stat-label">Profit<?php echo $has_missing_cp ? ' <sup>*</sup>' : ''; ?></span>
            </div>
            <?php if ($overall_margin !== null) : ?>
                <div class="trm-pa-stat">
                    <span class="trm-pa-stat-value">
                        <?php echo esc_html(number_format($overall_margin, 1)); ?>%
                        <?php if ($has_missing_cp) : ?><sup title="Partial">*</sup><?php endif; ?>
                    </span>
                    <span class="trm-pa-stat-label">Avg Margin</span>
                </div>
            <?php endif; ?>
            <div class="trm-pa-stat">
                <span class="trm-pa-stat-value"><?php echo esc_html(number_format($total_units)); ?></span>
                <span class="trm-pa-stat-label">Units Sold</span>
            </div>
        </div>

        <!-- Results Table -->
        <table class="wp-list-table widefat fixed striped trm-pa-table">
            <thead>
                <tr>
                    <th scope="col" class="column-product">Product</th>
                    <th scope="col" class="column-sku">SKU</th>
                    <th scope="col" class="column-cost-price">Cost Price</th>
                    <th scope="col" class="column-period">Active Period</th>
                    <th scope="col" class="column-units">Units</th>
                    <th scope="col" class="column-revenue">Revenue</th>
                    <th scope="col" class="column-total-cost">Total Cost</th>
                    <th scope="col" class="column-profit">Profit</th>
                    <th scope="col" class="column-margin">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) :
                    $profit_class = '';
                    if ($row['profit'] !== null) {
                        $profit_class = $row['profit'] >= 0 ? 'trm-pa-positive' : 'trm-pa-negative';
                    }
                    $edit_link = get_edit_post_link($row['product_id']);
                ?>
                <tr>
                    <td class="column-product">
                        <a href="<?php echo esc_url($edit_link); ?>">
                            <?php echo esc_html($row['product_title']); ?>
                        </a>
                    </td>
                    <td class="column-sku">
                        <?php if ($row['sku'] !== '') : ?>
                            <code><?php echo esc_html($row['sku']); ?></code>
                        <?php else : ?>
                            <span class="trm-pa-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-cost-price">
                        <?php if ($row['cost_price'] !== null) : ?>
                            <strong>&euro;<?php echo esc_html(number_format($row['cost_price'], 2)); ?></strong>
                        <?php else : ?>
                            <span class="trm-pa-na">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-period">
                        <?php if ($row['period_start'] !== null) : ?>
                            <span class="trm-pa-period">
                                <?php echo esc_html($fmt_date($row['period_start'])); ?>
                                &rarr;
                                <?php echo esc_html($fmt_date($row['period_end'])); ?>
                            </span>
                        <?php else : ?>
                            <span class="trm-pa-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-units"><?php echo esc_html($row['units_sold']); ?></td>
                    <td class="column-revenue">&euro;<?php echo esc_html(number_format($row['revenue'], 2)); ?></td>
                    <td class="column-total-cost">
                        <?php if ($row['total_cost'] !== null) : ?>
                            &euro;<?php echo esc_html(number_format($row['total_cost'], 2)); ?>
                        <?php else : ?>
                            <span class="trm-pa-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-profit <?php echo esc_attr($profit_class); ?>">
                        <?php if ($row['profit'] !== null) : ?>
                            &euro;<?php echo esc_html(number_format($row['profit'], 2)); ?>
                        <?php else : ?>
                            <span class="trm-pa-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-margin">
                        <?php if ($row['margin'] !== null) : ?>
                            <?php echo esc_html(number_format($row['margin'], 1)); ?>%
                        <?php else : ?>
                            <span class="trm-pa-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col">Cost Price</th>
                    <th scope="col">Active Period</th>
                    <th scope="col">Units</th>
                    <th scope="col">Revenue</th>
                    <th scope="col">Total Cost</th>
                    <th scope="col">Profit</th>
                    <th scope="col">Margin</th>
                </tr>
            </tfoot>
        </table>

    <?php else : ?>

        <div class="trm-pa-empty-state">
            <p>No orders found between <strong><?php echo esc_html($fmt_date($date_from . ' 00:00:00')); ?></strong> and <strong><?php echo esc_html($fmt_date($date_to . ' 00:00:00')); ?></strong> with a completed or processing status.</p>
        </div>

    <?php endif; ?>

</div>
