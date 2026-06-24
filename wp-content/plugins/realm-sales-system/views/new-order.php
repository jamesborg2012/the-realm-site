<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap rss-wrap rss-new-order">
    <h1><?php esc_html_e('New Order', 'rss'); ?></h1>

    <div id="rss-feedback" class="rss-feedback" role="alert" hidden></div>

    <!-- 1. Member section -->
    <div class="rss-card">
        <h2><?php esc_html_e('Member', 'rss'); ?></h2>

        <div class="rss-member-lookup">
            <label for="rss-member-number"><?php esc_html_e('Member Number', 'rss'); ?></label>
            <input type="text" id="rss-member-number" class="regular-text" autocomplete="off" />
            <button type="button" class="button button-secondary" id="rss-verify-member"><?php esc_html_e('Verify', 'rss'); ?></button>
            <button type="button" class="button-link" id="rss-clear-member" hidden><?php esc_html_e('Clear', 'rss'); ?></button>
        </div>

        <div id="rss-member-status" class="rss-member-status" hidden></div>

        <div class="rss-customer-fields">
            <p class="rss-field">
                <label for="rss-first-name"><?php esc_html_e('Name', 'rss'); ?> <span class="rss-required">*</span></label>
                <input type="text" id="rss-first-name" class="regular-text" />
            </p>
            <p class="rss-field">
                <label for="rss-last-name"><?php esc_html_e('Surname', 'rss'); ?> <span class="rss-required">*</span></label>
                <input type="text" id="rss-last-name" class="regular-text" />
            </p>
            <p class="rss-field">
                <label for="rss-email"><?php esc_html_e('Email', 'rss'); ?> <span class="rss-required">*</span></label>
                <input type="email" id="rss-email" class="regular-text" />
            </p>
        </div>

        <input type="hidden" id="rss-user-id" value="0" />
    </div>

    <!-- 2. Scanner section -->
    <div class="rss-card">
        <h2><?php esc_html_e('Add Products', 'rss'); ?></h2>

        <div id="rss-scanner">
            <video id="rss-video" autoplay playsinline></video>
            <canvas id="rss-canvas" class="rss-hidden"></canvas>
            <div class="rss-scanner-controls">
                <button type="button" class="button" id="rss-start-scan"><?php esc_html_e('Start Scanner', 'rss'); ?></button>
                <button type="button" class="button" id="rss-stop-scan" disabled><?php esc_html_e('Stop', 'rss'); ?></button>
            </div>
            <div id="rss-scan-result" class="rss-scan-result"></div>
        </div>

        <!-- Manual search (camera-free fallback): by name, SKU or barcode -->
        <div class="rss-search">
            <label for="rss-search-input"><?php esc_html_e('Or search for a product', 'rss'); ?></label>
            <div class="rss-search-row">
                <input type="text" id="rss-search-input" class="regular-text"
                       placeholder="<?php esc_attr_e('Name, SKU or barcode…', 'rss'); ?>" autocomplete="off" />
                <button type="button" class="button button-secondary" id="rss-search-btn"><?php esc_html_e('Search', 'rss'); ?></button>
            </div>
            <div id="rss-search-results" class="rss-search-results" aria-live="polite"></div>
        </div>
    </div>

    <!-- 3. Cart table -->
    <div class="rss-card">
        <h2><?php esc_html_e('Order Items', 'rss'); ?></h2>

        <table class="widefat striped rss-cart-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Product', 'rss'); ?></th>
                    <th class="rss-num"><?php esc_html_e('Unit Price (ex VAT)', 'rss'); ?></th>
                    <th class="rss-num"><?php esc_html_e('Qty', 'rss'); ?></th>
                    <th class="rss-num"><?php esc_html_e('Discount', 'rss'); ?></th>
                    <th class="rss-num"><?php esc_html_e('Subtotal (ex VAT)', 'rss'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="rss-cart-body">
                <tr class="rss-cart-empty">
                    <td colspan="6"><?php esc_html_e('No products added yet.', 'rss'); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3"></th>
                    <th class="rss-num"><?php esc_html_e('Total Discount (ex VAT)', 'rss'); ?></th>
                    <th class="rss-num" id="rss-total-discount">—</th>
                    <th></th>
                </tr>
                <tr>
                    <th colspan="3"></th>
                    <th class="rss-num"><?php esc_html_e('Total to Pay (incl. VAT)', 'rss'); ?></th>
                    <th class="rss-num" id="rss-total-pay">—</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- 4. Order details + place -->
    <div class="rss-card">
        <h2><?php esc_html_e('Order Details', 'rss'); ?></h2>
        <textarea id="rss-order-notes" rows="3" class="large-text" placeholder="<?php esc_attr_e('Any final order details…', 'rss'); ?>"></textarea>

        <p>
            <button type="button" class="button button-primary button-hero" id="rss-place-order"><?php esc_html_e('Place Order', 'rss'); ?></button>
        </p>
    </div>
</div>

<!-- Scan confirm modal -->
<div id="rss-modal" class="rss-modal" hidden>
    <div class="rss-modal__backdrop"></div>
    <div class="rss-modal__box" role="dialog" aria-modal="true">
        <h2><?php esc_html_e('Confirm Product', 'rss'); ?></h2>
        <p class="rss-modal__name"><strong id="rss-modal-name"></strong></p>
        <p class="rss-modal__meta">
            <span><?php esc_html_e('SKU:', 'rss'); ?> <span id="rss-modal-sku"></span></span><br />
            <span><?php esc_html_e('Price:', 'rss'); ?> <span id="rss-modal-price"></span></span>
        </p>
        <p class="rss-field">
            <label for="rss-modal-qty"><?php esc_html_e('Quantity', 'rss'); ?></label>
            <input type="number" id="rss-modal-qty" min="1" step="1" value="1" />
        </p>
        <div class="rss-modal__actions">
            <button type="button" class="button button-primary" id="rss-modal-add"><?php esc_html_e('Add to Cart', 'rss'); ?></button>
            <button type="button" class="button" id="rss-modal-cancel"><?php esc_html_e('Cancel', 'rss'); ?></button>
        </div>
    </div>
</div>
