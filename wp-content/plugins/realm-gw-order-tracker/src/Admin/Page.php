<?php

namespace GW\OrderTracker\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Page
{
    public static function init(): void
    {
        // No-op for now
    }

    public static function render(): void
    { ?>
        <div class="wrap gwot-wrap">
            <h1><?php esc_html_e('GW Order Tracker', 'gwot'); ?></h1>

            <div id="gwot-filters" class="gwot-filters">
                <label>
                    <?php esc_html_e('From:', 'gwot'); ?>
                    <input type="date" id="gwot-from-date" max="<?php echo esc_attr(date('Y-m-d')); ?>">
                </label>

                <label>
                    <?php esc_html_e('To:', 'gwot'); ?>
                    <input type="date" id="gwot-to-date" max="<?php echo esc_attr(date('Y-m-d')); ?>">
                </label>

                <button class="button button-secondary" id="gwot-filter-btn">
                    <?php esc_html_e('Filter Orders', 'gwot'); ?>
                </button>

                <button class="button button-primary" id="gwot-export-btn">
                    <?php esc_html_e('Export CSV', 'gwot'); ?>
                </button>
            </div>


            <div id="gwot-orders" class="gwot-orders" data-page="1" aria-live="polite">
                <!-- Initial list injected via AJAX -->
                <div class="gwot-loading"><?php esc_html_e('Loading orders…', 'gwot'); ?></div>
            </div>
        </div>
<?php }
}
