/**
 * Inline cost-price editing on the Cost Prices dashboard.
 *
 * Each editable cell is a `.trm-cp-edit` wrapper holding a display span and a
 * hidden form span. Saving posts to the `trm_cost_price_inline_update` AJAX
 * action and updates the value + "Last Updated" cell in place on success.
 */
(function ($) {
    'use strict';

    function getRow($wrap) {
        return $wrap.closest('tr');
    }

    function toEdit($wrap) {
        $wrap.find('.trm-cp-display').attr('hidden', true);
        $wrap.find('.trm-cp-error').attr('hidden', true).text('');
        var $form = $wrap.find('.trm-cp-form').removeAttr('hidden');
        var $input = $form.find('.trm-cp-input');
        // Reset the field to the current displayed value before focusing.
        $input.val(parseFloat($wrap.find('.trm-cp-value').text().replace(',', '.')).toFixed(2));
        $input.trigger('focus').trigger('select');
    }

    function toDisplay($wrap) {
        $wrap.find('.trm-cp-form').attr('hidden', true);
        $wrap.find('.trm-cp-error').attr('hidden', true).text('');
        $wrap.find('.trm-cp-display').removeAttr('hidden');
    }

    function showError($wrap, message) {
        $wrap.find('.trm-cp-error').text(message).removeAttr('hidden');
    }

    function save($wrap) {
        var $form = $wrap.find('.trm-cp-form');
        var $spinner = $form.find('.trm-cp-spinner');
        var price = $.trim($form.find('.trm-cp-input').val());
        var productId = $wrap.data('product-id');

        if (price === '') {
            showError($wrap, 'Please enter a cost price.');
            return;
        }

        $spinner.addClass('is-active');
        $form.find('button').prop('disabled', true);

        $.post(window.trmCostPrice.ajaxUrl, {
            action: 'trm_cost_price_inline_update',
            nonce: window.trmCostPrice.nonce,
            product_id: productId,
            cost_price: price
        }).done(function (response) {
            if (response && response.success) {
                $wrap.find('.trm-cp-value').text(response.data.cost_price);
                getRow($wrap).find('.trm-cp-updated').text(response.data.updated_at);
                toDisplay($wrap);
            } else {
                showError($wrap, (response && response.data && response.data.message) || 'Could not save.');
            }
        }).fail(function () {
            showError($wrap, 'Request failed. Please try again.');
        }).always(function () {
            $spinner.removeClass('is-active');
            $form.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.trm-cp-edit-btn', function () {
        toEdit($(this).closest('.trm-cp-edit'));
    });

    $(document).on('click', '.trm-cp-cancel', function () {
        toDisplay($(this).closest('.trm-cp-edit'));
    });

    $(document).on('click', '.trm-cp-save', function () {
        save($(this).closest('.trm-cp-edit'));
    });

    // Enter saves, Escape cancels while editing.
    $(document).on('keydown', '.trm-cp-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            save($(this).closest('.trm-cp-edit'));
        } else if (e.key === 'Escape') {
            e.preventDefault();
            toDisplay($(this).closest('.trm-cp-edit'));
        }
    });
})(jQuery);
