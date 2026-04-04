<?php
/**
 * Upload tab — CSV import form and result notices.
 *
 * Variables available from parent template:
 * @var int    $imported     -1 = no upload performed this request; >= 0 = upload was processed
 * @var int    $skipped
 * @var int    $unchanged
 * @var string $upload_error
 * @var array  $upload_errors
 */
?>

<?php if ($upload_error !== '') : ?>
    <?php
    $error_messages = [
        'no_file'       => 'No file was selected. Please choose a CSV file to upload.',
        'invalid_type'  => 'Invalid file type. Please upload a .csv file.',
        'upload_failed' => 'The file upload failed. Please try again.',
    ];
    $message = $error_messages[$upload_error] ?? 'An unexpected error occurred. Please try again.';
    ?>
    <div class="notice notice-error">
        <p><?php echo esc_html($message); ?></p>
    </div>
<?php elseif ($imported >= 0) : ?>
    <div class="notice notice-success">
        <p>
            Import complete &mdash;
            <strong><?php echo esc_html($imported); ?></strong> <?php echo $imported === 1 ? 'product' : 'products'; ?> updated.
            <?php if ($unchanged > 0) : ?>
                <strong><?php echo esc_html($unchanged); ?></strong> <?php echo $unchanged === 1 ? 'product' : 'products'; ?> unchanged (same price, not re-recorded).
            <?php endif; ?>
            <?php if ($skipped > 0) : ?>
                <strong><?php echo esc_html($skipped); ?></strong> <?php echo $skipped === 1 ? 'row' : 'rows'; ?> skipped due to errors.
            <?php endif; ?>
        </p>
    </div>
    <?php if (!empty($upload_errors)) : ?>
        <div class="notice notice-warning">
            <p><strong>Skipped rows:</strong></p>
            <ul class="trm-upload-error-list">
                <?php foreach ($upload_errors as $err) : ?>
                    <li><?php echo esc_html($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="trm-upload-section">
    <h2>Import Cost Prices via CSV</h2>

    <p>Upload a CSV file to set or update cost prices for multiple products at once. Use the product SKU to identify each product.</p>

    <h3>CSV Format</h3>
    <p>The file should have two columns. The header row is optional.</p>
    <table class="trm-csv-example widefat striped" style="max-width:300px;">
        <thead>
            <tr>
                <th>sku</th>
                <th>cost_price</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>GW-001</td><td>12.50</td></tr>
            <tr><td>GW-002</td><td>8.00</td></tr>
            <tr><td>GW-003</td><td>22.99</td></tr>
        </tbody>
    </table>

    <ul class="trm-upload-notes">
        <li>Each upload creates a new dated record — previous cost prices are kept in full.</li>
        <li>If the same SKU appears more than once in the file, the last row wins.</li>
        <li>SKUs that do not match any WooCommerce product will be skipped and reported.</li>
        <li>Cost prices must be a number greater than or equal to zero.</li>
    </ul>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="trm-upload-form">
        <input type="hidden" name="action" value="trm_cost_price_upload">
        <?php wp_nonce_field('trm_cost_price_upload'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="trm_cost_price_csv">CSV File</label></th>
                <td>
                    <input type="file" name="trm_cost_price_csv" id="trm_cost_price_csv" accept=".csv,text/csv" required>
                    <p class="description">Accepted format: <code>.csv</code></p>
                </td>
            </tr>
        </table>

        <?php submit_button('Upload and Import', 'primary', 'submit', false); ?>
    </form>
</div>
