<?php

/** @var \WC_Order $order */
/** @var array $cust */
/** @var int $qty_total */
/** @var float|string $total */
?>
<div class="gwot-card" data-order-id="<?php echo esc_attr($order_id); ?>">
    <div class="gwot-card__header">
        <div class="gwot-card__title">
            <?php echo esc_html(sprintf(__('Order #%d', 'gwot'), $order_id)); ?>
        </div>
        <div class="gwot-card__meta">
            <span class="gwot-meta"><strong><?php esc_html_e('Customer:', 'gwot'); ?></strong> <?php echo esc_html($cust['name']); ?></span>
            <span class="gwot-meta"><strong><?php esc_html_e('Email:', 'gwot'); ?></strong> <?php echo esc_html($cust['email']); ?></span>
            <span class="gwot-meta"><strong><?php esc_html_e('Items:', 'gwot'); ?></strong> <?php echo esc_html($qty_total); ?></span>
            <span class="gwot-meta"><strong><?php esc_html_e('Total:', 'gwot'); ?></strong> <?php echo wp_kses_post(wc_price($total)); ?></span>
        </div>
        <button class="button button-primary gwot-expand" type="button" data-order-id="<?php echo esc_attr($order_id); ?>">
            <?php esc_html_e('Expand', 'gwot'); ?>
        </button>
    </div>

    <div class="gwot-card__body" hidden>
        <div class="gwot-card__body" hidden>
            <div class="gwot-items gwot-items--loading">
                <table class="gwot-items-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'gwot'); ?></th>
                            <th><?php esc_html_e('Barcode', 'gwot'); ?></th>
                            <th><?php esc_html_e('Quantity', 'gwot'); ?></th>
                            <th><?php esc_html_e('Action', 'gwot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="4"><?php esc_html_e('Loading items…', 'gwot'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="gwot-modal" class="gwot-modal" hidden>
    <div class="gwot-modal__overlay"></div>
    <div class="gwot-modal__content">
        <h2><?php esc_html_e('Edit Item', 'gwot'); ?></h2>
        <form id="gwot-modal-form">
            <input type="hidden" id="gwot-item-id" value="">

            <div class="gwot-form-group">
                <label><?php esc_html_e('Product Name', 'gwot'); ?></label>
                <input type="text" id="gwot-item-name" readonly>
            </div>

            <div class="gwot-form-group">
                <label><?php esc_html_e('Quantity Ordered', 'gwot'); ?></label>
                <input type="number" id="gwot-item-qty" readonly>
            </div>

            <div class="gwot-form-group">
                <label><?php esc_html_e('GW Quantity Ordered', 'gwot'); ?></label>
                <input type="number" id="gwot-item-gwordered" min="0">
            </div>

            <div class="gwot-form-group">
                <label><?php esc_html_e('Received Quantity', 'gwot'); ?></label>
                <input type="number" id="gwot-item-received" min="0">
            </div>

            <div class="gwot-form-group">
                <label><?php esc_html_e('Delivered Quantity', 'gwot'); ?></label>
                <input type="number" id="gwot-item-delivered" min="0">
            </div>

            <div class="gwot-modal__actions">
                <button type="button" class="button button-secondary" id="gwot-modal-cancel">
                    <?php esc_html_e('Cancel', 'gwot'); ?>
                </button>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save Changes', 'gwot'); ?>
                </button>
            </div>
        </form>
    </div>
</div>