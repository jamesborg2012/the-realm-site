;(function ($) {
  'use strict'

  const $container = $('#gwot-orders')

  $(document).on('click', '#gwot-filter-btn', function (e) {
    e.preventDefault()
    fetchOrders(1)
  })

  $(document).on('click', '#gwot-export-btn', function (e) {
    e.preventDefault()

    const fromDate = $('#gwot-from-date').val()
    const toDate = $('#gwot-to-date').val()

    // Simple front-end validation
    if (!fromDate || !toDate) {
      alert('Please select both From and To dates before exporting.')
      return
    }

    $.post(GWOT.ajaxUrl, {
      action: 'gwot_export_csv',
      nonce: GWOT.nonce,
      fromDate: fromDate,
      toDate: toDate
    })
      .done(res => {
        if (!res || !res.success) {
          alert(res?.data?.message || 'Failed to export CSV.')
          return
        }

        const csv = res.data.csv
        const filename = res.data.filename || 'gw_order.csv'
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })

        const link = document.createElement('a')
        const url = URL.createObjectURL(blob)
        link.setAttribute('href', url)
        link.setAttribute('download', filename)
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)
      })
      .fail(() => {
        alert('Error generating CSV export.')
      })
  })

  function fetchOrders (page = 1) {
    const fromDate = $('#gwot-from-date').val()
    const toDate = $('#gwot-to-date').val()

    $container.attr('data-page', page)
    $container.html('<div class="gwot-loading">Loading orders…</div>')

    $.post(GWOT.ajaxUrl, {
      action: 'gwot_fetch_orders',
      nonce: GWOT.nonce,
      page: page,
      perPage: GWOT.perPage,
      fromDate: fromDate,
      toDate: toDate
    })
      .done(res => {
        if (!res || !res.success) {
          $container.html('<div class="gwot-loading">Failed to load.</div>')
          return
        }
        $container.html(res.data.cards + res.data.pagination)
      })
      .fail(() => {
        $container.html('<div class="gwot-loading">Error loading orders.</div>')
      })
  }

  function expandOrder ($card, orderId) {
    const $body = $card.find('.gwot-card__body')

    // Toggle open/closed
    if (!$body.is(':hidden')) {
      $body.attr('hidden', true)
      return
    }

    $body.removeAttr('hidden')

    // Always target the table body directly (avoid stale $items refs)
    const $tbody = $card.find('.gwot-items-table tbody')
    $tbody.html('<tr><td colspan="4">Loading items…</td></tr>')

    // Optional: abort any in-flight request for this card to avoid race conditions
    const prevReq = $card.data('gwotReq')
    if (prevReq && prevReq.abort) prevReq.abort()

    const req = $.post(GWOT.ajaxUrl, {
      action: 'gwot_fetch_order_items',
      nonce: GWOT.nonce,
      orderId: orderId
    })
      .done(res => {
        const $tbodyNow = $card.find('.gwot-items-table tbody') // re-select to be safe

        if (!res || !res.success) {
          $tbodyNow.html('<tr><td colspan="4">Failed to load items.</td></tr>')
          return
        }

        const items = res.data.items || []
        if (!items.length) {
          $tbodyNow.html('<tr><td colspan="4">No items found.</td></tr>')
          return
        }

        const rows = items
          .map(i => {
            const sku = i.sku ? i.sku : '-'
            return `
        <tr data-item-id="${i.item_id}">
          <td>${i.title}</td>
          <td>${sku}</td>
          <td>${i.qty}</td>
          <td><button type="button" class="button gwot-edit-item">Edit</button></td>
        </tr>`
          })
          .join('')

        $tbodyNow.html(rows)
      })
      .fail(() => {
        const $tbodyNow = $card.find('.gwot-items-table tbody')
        $tbodyNow.html('<tr><td colspan="4">Error fetching items.</td></tr>')
      })
      .always(() => {
        $card.removeData('gwotReq')
      })

    $card.data('gwotReq', req)
  }

  // Initial fetch when page loads
  $(document).ready(() => {
    if ($container.length) {
      fetchOrders(1)

      // Delegate pagination
      $(document).on('click', '.gwot-page', function (e) {
        e.preventDefault()
        const page = parseInt($(this).attr('data-page'), 10) || 1
        if (!$(this).is(':disabled')) fetchOrders(page)
      })

      // Delegate expand buttons
      $(document).on('click', '.gwot-expand', function () {
        const orderId = parseInt($(this).attr('data-order-id'), 10)
        const $card = $(this).closest('.gwot-card')
        expandOrder($card, orderId)
      })
    }
  })

  // Open modal
  $(document).on('click', '.gwot-edit-item', function () {
    const itemId = $(this).closest('tr').data('item-id')

    $.post(GWOT.ajaxUrl, {
      action: 'gwot_get_item_meta',
      nonce: GWOT.nonce,
      item_id: itemId
    }).done(res => {
      if (!res.success) return alert('Failed to load item data.')

      const d = res.data
      $('#gwot-item-id').val(d.item_id)
      $('#gwot-item-name').val(d.title)
      $('#gwot-item-qty').val(d.qty)
      $('#gwot-item-gwordered').val(d.gw_ordered_qty)
      $('#gwot-item-received').val(d.gw_received_qty)
      $('#gwot-item-delivered').val(d.gw_delivered_qty)
      $('#gwot-modal').removeAttr('hidden')
    })
  })

  // Close modal
  $(document).on(
    'click',
    '#gwot-modal-cancel, .gwot-modal__overlay',
    function () {
      $('#gwot-modal').attr('hidden', true)
    }
  )

  // Save changes
  $(document).on('submit', '#gwot-modal-form', function (e) {
    e.preventDefault()

    const data = {
      action: 'gwot_update_item_meta',
      nonce: GWOT.nonce,
      item_id: $('#gwot-item-id').val(),
      gw_ordered_qty: $('#gwot-item-gwordered').val(),
      gw_received_qty: $('#gwot-item-received').val(),
      gw_delivered_qty: $('#gwot-item-delivered').val()
    }

    $.post(GWOT.ajaxUrl, data).done(res => {
      if (!res.success) {
        alert(res.data?.message || 'Error saving item.')
        return
      }

      alert('Item updated successfully.')
      $('#gwot-modal').attr('hidden', true)
    })
  })
})(jQuery)
