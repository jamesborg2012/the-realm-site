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
        $input.val((parseFloat($wrap.find('.trm-cp-value').text().replace(',', '.')) || 0).toFixed(2));
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

    // ── Historical (dated) cost prices ──────────────────────────────────────────
    //
    // A per-product <details> panel lists every cost-price record by effective date,
    // lets each be edited/deleted, and lets a backdated price be added. After any
    // change the server returns the product's recomputed *current* price, which we use
    // to refresh the main cell and re-badge the current row.

    function detailsOf($el) {
        return $el.closest('.trm-history-details');
    }

    // Reflect a returned `current` payload: re-badge the current history row and refresh
    // the product's main "Current Cost Price" + "Last Updated" cells.
    function applyCurrent($details, current) {
        var $table = $details.find('.trm-history-table');
        $table.find('.trm-hist-row').removeClass('is-current');
        if (current && current.id) {
            $table.find('.trm-hist-row[data-row-id="' + current.id + '"]').addClass('is-current');
        }

        var $prodRow = $details.closest('tr.trm-product-row');
        $prodRow.find('.trm-cp-edit .trm-cp-value').text((current && current.cost_price) || '—');
        $prodRow.find('.trm-cp-updated').text((current && current.updated_at) || '—');
    }

    function refreshSummary($details) {
        var n = $details.find('.trm-hist-row').length;
        $details.children('summary').text(n + ' ' + (n === 1 ? 'record' : 'records') + ' — manage / backdate');
    }

    function priceToNumber(str) {
        return parseFloat(String(str).replace(/,/g, ''));
    }

    // Build a history <tr> for a freshly-added dated price (mirrors the PHP markup).
    function buildHistRow(row, maxDate) {
        var priceDot = priceToNumber(row.cost_price).toFixed(2);
        var $tr = $('<tr class="trm-hist-row"></tr>').attr('data-row-id', row.id);
        $tr.html(
            '<td class="trm-hist-eff">' +
                '<span class="trm-hist-eff-disp"></span> ' +
                '<input type="date" class="trm-hist-eff-input" hidden>' +
                '<span class="trm-hist-current-badge">Current</span>' +
            '</td>' +
            '<td class="trm-hist-price">' +
                '€<span class="trm-hist-price-disp"></span> ' +
                '<input type="number" step="0.01" min="0" inputmode="decimal" class="trm-hist-price-input small-text" hidden>' +
            '</td>' +
            '<td class="trm-hist-recorded"> <span class="trm-hist-source"></span></td>' +
            '<td class="trm-hist-actions">' +
                '<button type="button" class="button-link trm-hist-edit">Edit</button> ' +
                '<button type="button" class="button-link trm-hist-delete">Delete</button> ' +
                '<button type="button" class="button button-small button-primary trm-hist-save" hidden>Save</button> ' +
                '<button type="button" class="button button-small trm-hist-cancel" hidden>Cancel</button>' +
                '<span class="spinner trm-hist-spinner"></span>' +
                '<span class="trm-hist-error" hidden></span>' +
            '</td>'
        );
        $tr.find('.trm-hist-eff-disp').text(row.effective_disp);
        $tr.find('.trm-hist-eff-input').val(row.effective_date).attr('max', maxDate);
        $tr.find('.trm-hist-price-disp').text(row.cost_price);
        $tr.find('.trm-hist-price-input').val(priceDot);
        $tr.find('.trm-hist-recorded').prepend(document.createTextNode(row.recorded_disp + ' '));
        $tr.find('.trm-hist-source').text(row.source);
        return $tr;
    }

    // Insert a row into the tbody keeping effective_date DESC order.
    function insertSorted($tbody, $newRow, effDate) {
        var placed = false;
        $tbody.find('.trm-hist-row').each(function () {
            var d = $(this).find('.trm-hist-eff-input').val();
            if (effDate >= d) {
                $(this).before($newRow);
                placed = true;
                return false;
            }
        });
        if (!placed) {
            $tbody.append($newRow);
        }
    }

    function histError($el, message) {
        $el.find('.trm-hist-error, .trm-add-error').first().text(message).removeAttr('hidden');
    }

    // --- Edit an existing history row ---

    function histToEdit($row) {
        $row.find('.trm-hist-eff-disp, .trm-hist-price-disp').attr('hidden', true);
        $row.find('.trm-hist-eff-input, .trm-hist-price-input').removeAttr('hidden');
        $row.find('.trm-hist-edit, .trm-hist-delete').attr('hidden', true);
        $row.find('.trm-hist-save, .trm-hist-cancel').removeAttr('hidden');
        $row.find('.trm-hist-error').attr('hidden', true).text('');
        $row.find('.trm-hist-price-input').trigger('focus').trigger('select');
    }

    function histToDisplay($row) {
        $row.find('.trm-hist-eff-input, .trm-hist-price-input').attr('hidden', true);
        $row.find('.trm-hist-eff-disp, .trm-hist-price-disp').removeAttr('hidden');
        $row.find('.trm-hist-save, .trm-hist-cancel').attr('hidden', true);
        $row.find('.trm-hist-edit, .trm-hist-delete').removeAttr('hidden');
        $row.find('.trm-hist-error').attr('hidden', true).text('');
    }

    function histSave($row) {
        var $details = detailsOf($row);
        var price = $.trim($row.find('.trm-hist-price-input').val());
        var eff = $row.find('.trm-hist-eff-input').val();

        if (price === '') { histError($row, 'Please enter a cost price.'); return; }
        if (eff === '') { histError($row, 'Please choose a date.'); return; }

        var $spinner = $row.find('.trm-hist-spinner').addClass('is-active');
        $row.find('button').prop('disabled', true);

        $.post(window.trmCostPrice.ajaxUrl, {
            action: 'trm_cost_price_update_row',
            nonce: window.trmCostPrice.nonce,
            row_id: $row.data('row-id'),
            cost_price: price,
            effective_date: eff
        }).done(function (response) {
            if (response && response.success) {
                var row = response.data.row;
                $row.find('.trm-hist-price-disp').text(row.cost_price);
                $row.find('.trm-hist-eff-disp').text(row.effective_disp);
                histToDisplay($row);
                applyCurrent($details, response.data.current);
            } else {
                histError($row, (response && response.data && response.data.message) || 'Could not save.');
            }
        }).fail(function () {
            histError($row, 'Request failed. Please try again.');
        }).always(function () {
            $spinner.removeClass('is-active');
            $row.find('button').prop('disabled', false);
        });
    }

    function histDelete($row) {
        if (!window.confirm('Delete this cost-price record? This cannot be undone.')) {
            return;
        }
        var $details = detailsOf($row);
        var $spinner = $row.find('.trm-hist-spinner').addClass('is-active');
        $row.find('button').prop('disabled', true);

        $.post(window.trmCostPrice.ajaxUrl, {
            action: 'trm_cost_price_delete_row',
            nonce: window.trmCostPrice.nonce,
            row_id: $row.data('row-id')
        }).done(function (response) {
            if (response && response.success) {
                $row.remove();
                applyCurrent($details, response.data.current);
                refreshSummary($details);
            } else {
                histError($row, (response && response.data && response.data.message) || 'Could not delete.');
                $spinner.removeClass('is-active');
                $row.find('button').prop('disabled', false);
            }
        }).fail(function () {
            histError($row, 'Request failed. Please try again.');
            $spinner.removeClass('is-active');
            $row.find('button').prop('disabled', false);
        });
    }

    function histAdd($details) {
        var $wrap = $details.find('.trm-add-dated');
        var $price = $wrap.find('.trm-add-price');
        var $eff = $wrap.find('.trm-add-eff');
        var price = $.trim($price.val());
        var eff = $eff.val();

        $wrap.find('.trm-add-error').attr('hidden', true).text('');

        if (price === '') { histError($wrap, 'Please enter a cost price.'); return; }
        if (eff === '') { histError($wrap, 'Please choose a date.'); return; }

        var $spinner = $wrap.find('.trm-add-spinner').addClass('is-active');
        $wrap.find('button').prop('disabled', true);

        $.post(window.trmCostPrice.ajaxUrl, {
            action: 'trm_cost_price_add_dated',
            nonce: window.trmCostPrice.nonce,
            product_id: $details.data('product-id'),
            cost_price: price,
            effective_date: eff
        }).done(function (response) {
            if (response && response.success) {
                var $newRow = buildHistRow(response.data.row, $eff.attr('max'));
                insertSorted($details.find('.trm-history-table tbody'), $newRow, response.data.row.effective_date);
                applyCurrent($details, response.data.current);
                refreshSummary($details);
                $price.val('');
                $eff.val('');
            } else {
                histError($wrap, (response && response.data && response.data.message) || 'Could not add.');
            }
        }).fail(function () {
            histError($wrap, 'Request failed. Please try again.');
        }).always(function () {
            $spinner.removeClass('is-active');
            $wrap.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.trm-hist-edit', function () { histToEdit($(this).closest('.trm-hist-row')); });
    $(document).on('click', '.trm-hist-cancel', function () { histToDisplay($(this).closest('.trm-hist-row')); });
    $(document).on('click', '.trm-hist-save', function () { histSave($(this).closest('.trm-hist-row')); });
    $(document).on('click', '.trm-hist-delete', function () { histDelete($(this).closest('.trm-hist-row')); });
    $(document).on('click', '.trm-add-save', function () { histAdd(detailsOf($(this))); });

    // Enter/Escape shortcuts inside the history editor + add form.
    $(document).on('keydown', '.trm-hist-price-input, .trm-hist-eff-input', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); histSave($(this).closest('.trm-hist-row')); }
        else if (e.key === 'Escape') { e.preventDefault(); histToDisplay($(this).closest('.trm-hist-row')); }
    });
    $(document).on('keydown', '.trm-add-price, .trm-add-eff', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); histAdd(detailsOf($(this))); }
    });
})(jQuery);
