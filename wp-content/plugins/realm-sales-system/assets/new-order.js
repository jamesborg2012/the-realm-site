;(function ($) {
  'use strict'

  // ---- State -------------------------------------------------------------
  // Cart rows: { product_id, name, sku, price, priceExcl, priceIncl, qty }.
  // Prices are captured once at scan time (latest product price) and never
  // refreshed afterwards. Line subtotals/discounts are computed on the ex-VAT
  // base (priceExcl) so the figures match what the order records; the grand
  // Total to Pay uses the inclusive price (priceIncl) — what the customer pays.
  var cart = []
  var discountPct = 0 // applied store discount % (0 when no/expired member)
  var pendingProduct = null // product awaiting modal confirmation

  // Marketing mode (internal store purchases): no member section, no customer
  // fields and no per-line discounts. The cart drops its Discount column and the
  // order is posted to the marketing place-order endpoint.
  var isMarketing = rssData.mode === 'marketing'
  var cartCols = isMarketing ? 5 : 6

  // ---- Elements ----------------------------------------------------------
  var $feedback = $('#rss-feedback')
  var $memberNumber = $('#rss-member-number')
  var $memberStatus = $('#rss-member-status')
  var $userId = $('#rss-user-id')
  var $firstName = $('#rss-first-name')
  var $lastName = $('#rss-last-name')
  var $email = $('#rss-email')
  var $cartBody = $('#rss-cart-body')
  var $totalDiscount = $('#rss-total-discount')
  var $totalPay = $('#rss-total-pay')
  var $scanResult = $('#rss-scan-result')
  var $searchInput = $('#rss-search-input')
  var $searchBtn = $('#rss-search-btn')
  var $searchResults = $('#rss-search-results')

  // Latest search hits, indexed so result rows can reference them by position
  // (avoids stuffing the full product payload into DOM data attributes).
  var searchResults = []

  var video = document.getElementById('rss-video')
  var canvas = document.getElementById('rss-canvas')

  // ---- Scanner -----------------------------------------------------------
  var stream = null
  var scanning = false
  var quaggaRunning = false
  var lastScanAt = 0

  var hasNative = 'BarcodeDetector' in window
  var barcodeDetector = hasNative
    ? new BarcodeDetector({
        formats: ['ean_13', 'ean_8', 'upc_a', 'code_128', 'code_39']
      })
    : null

  // ---- Helpers -----------------------------------------------------------
  // Round to the store's price decimals. WooCommerce rounds each line's subtotal
  // and total before deriving the discount, so the cart mirrors that (rounding
  // the product first, then subtracting) to land on the same figures as the order.
  function roundDec (value) {
    var dec = rssData.decimals
    var factor = Math.pow(10, dec)
    return Math.round(((Number(value) || 0) + Number.EPSILON) * factor) / factor
  }

  function money (value) {
    var n = (Number(value) || 0).toFixed(rssData.decimals)
    return rssData.currencySym + n
  }

  function showFeedback (message, type) {
    $feedback
      .removeClass('notice-error notice-success notice-warning')
      .addClass(type === 'success' ? 'notice-success' : 'notice-error')
      .html('<p>' + message + '</p>')
      .prop('hidden', false)
  }

  function clearFeedback () {
    $feedback.prop('hidden', true).empty()
  }

  function sanitizeCode (c) {
    return (c || '').trim().replace(/[^0-9A-Za-z\-_.]/g, '')
  }

  function sleep (ms) {
    return new Promise(function (r) { setTimeout(r, ms) })
  }

  // Compute a line's figures from its frozen prices, qty and discount. A line
  // discount is either a percentage or a fixed amount; the fixed amount is money
  // off the inclusive (shelf) price — what the customer saves — so it is backed
  // out to the ex-VAT base via the product's own incl/excl ratio. Subtotal and
  // discounted total are rounded independently (mirroring WooCommerce), so the
  // figures match the order the server builds with the identical calculation.
  function lineMath (row) {
    var qty = row.qty
    var exSub = row.priceExcl * qty
    var incSub = row.priceIncl * qty
    var taxFactor = exSub > 0 ? incSub / exSub : 1

    var exTotalRaw
    if (row.discType === 'amount') {
      var amt = Math.max(0, Math.min(Number(row.discValue) || 0, incSub))
      exTotalRaw = (incSub - amt) / taxFactor
    } else {
      var pct = Math.max(0, Math.min(Number(row.discValue) || 0, 100))
      exTotalRaw = exSub * (1 - pct / 100)
    }

    var exSubR = roundDec(exSub)
    var exTotalR = roundDec(exTotalRaw)
    return {
      exSubtotal: exSubR,
      exTotal: exTotalR,
      lineDiscount: exSubR - exTotalR,
      // Inclusive total derived from the rounded ex-VAT total (× tax factor),
      // exactly how WC re-adds tax — so Total to Pay matches the order to the cent.
      incTotal: roundDec(exTotalR * taxFactor),
      incSubtotal: roundDec(incSub)
    }
  }

  // ---- Cart rendering ----------------------------------------------------
  function renderCart () {
    $cartBody.empty()

    if (!cart.length) {
      $cartBody.append(
        '<tr class="rss-cart-empty"><td colspan="' + cartCols + '">No products added yet.</td></tr>'
      )
      $totalDiscount.text('—')
      $totalPay.text('—')
      return
    }

    var totalDiscount = 0
    var totalPay = 0

    cart.forEach(function (row, index) {
      var m = lineMath(row)

      totalDiscount += m.lineDiscount
      totalPay += m.incTotal

      var $tr = $('<tr/>')
      $tr.append($('<td/>').text(row.name + (row.sku ? ' (' + row.sku + ')' : '')))
      $tr.append($('<td class="rss-num"/>').text(money(row.priceExcl)))

      var $qtyCell = $('<td class="rss-num"/>')
      var $qtyInput = $('<input type="number" min="1" step="1" class="rss-qty-input"/>')
        .val(row.qty)
        .data('index', index)
      $qtyCell.append($qtyInput)
      $tr.append($qtyCell)

      // Editable per-line discount: a value + a type toggle (% or currency).
      // Marketing orders carry no discounts, so the column is omitted entirely.
      if (!isMarketing) {
        var $discCell = $('<td class="rss-num rss-disc-cell"/>')
        var $discInput = $('<input type="number" min="0" class="rss-disc-input"/>')
          .attr('step', row.discType === 'amount' ? '0.01' : '1')
          .val(row.discValue)
          .data('index', index)
        var $discType = $('<select class="rss-disc-type"/>').data('index', index)
        $discType.append($('<option value="percent">%</option>'))
        $discType.append($('<option value="amount"></option>').val('amount').text(rssData.currencySym))
        $discType.val(row.discType)
        $discCell.append($discInput).append($discType)
        $discCell.append($('<span class="rss-disc-amount"/>').text('−' + money(m.lineDiscount)))
        $tr.append($discCell)
      }

      $tr.append($('<td class="rss-num"/>').text(money(m.exTotal)))

      var $removeCell = $('<td class="rss-num"/>')
      var $remove = $('<button type="button" class="button-link rss-remove">Remove</button>')
        .data('index', index)
      $removeCell.append($remove)
      $tr.append($removeCell)

      $cartBody.append($tr)
    })

    $totalDiscount.text(money(totalDiscount))
    $totalPay.text(money(totalPay))
  }

  function addToCart (product, qty) {
    var existing = cart.find(function (r) { return r.product_id === product.product_id })
    if (existing) {
      existing.qty += qty
    } else {
      var priceIncl = Number(product.price_incl)
      if (isNaN(priceIncl)) priceIncl = Number(product.price) || 0
      var priceExcl = Number(product.price_excl)
      if (isNaN(priceExcl)) priceExcl = priceIncl
      cart.push({
        product_id: product.product_id,
        name: product.name,
        sku: product.sku,
        price: Number(product.price) || 0,
        priceExcl: priceExcl,
        priceIncl: priceIncl,
        qty: qty,
        // New lines default to the member's standard store discount (0 if none);
        // staff can override the value or switch to a fixed amount per line.
        discType: 'percent',
        discValue: discountPct
      })
    }
    renderCart()
  }

  // ---- Member verify -----------------------------------------------------
  function verifyMember () {
    var number = $.trim($memberNumber.val())
    if (!number) {
      $memberStatus.prop('hidden', false).attr('class', 'rss-member-status rss-status-error')
        .text('Please enter a member number.')
      return
    }

    $.post(rssData.ajaxUrl, {
      action: 'rss_verify_member',
      nonce: rssData.nonce,
      member_number: number
    }).done(function (res) {
      if (!res.success) {
        $memberStatus.prop('hidden', false).attr('class', 'rss-member-status rss-status-error')
          .text(res.data && res.data.message ? res.data.message : 'Member not found.')
        resetMemberDiscount()
        return
      }

      var d = res.data
      $firstName.val(d.first_name || '')
      $lastName.val(d.last_name || '')
      $email.val(d.email || '')
      $userId.val(d.user_id || 0)

      discountPct = d.discount_applies ? Number(d.discount_pct) || 0 : 0
      // Applying a member resets every line to their standard discount; staff
      // then bump individual lines (e.g. honorary members on old stock).
      applyDiscountToAllLines(discountPct)

      var cls = d.discount_applies ? 'rss-status-ok' : 'rss-status-warn'
      var msg = d.message || ('Member verified — ' + discountPct + '% store discount applied.')
      $memberStatus.prop('hidden', false).attr('class', 'rss-member-status ' + cls).text(msg)

      $('#rss-clear-member').prop('hidden', false)
      renderCart()
    }).fail(function () {
      showFeedback(rssData.i18n.networkError)
    })
  }

  function resetMemberDiscount () {
    discountPct = 0
    $userId.val(0)
    applyDiscountToAllLines(0)
  }

  // Reset every cart line to a flat percentage discount (used when the member
  // changes). renderCart() is called by the caller or here.
  function applyDiscountToAllLines (pct) {
    cart.forEach(function (r) {
      r.discType = 'percent'
      r.discValue = pct
    })
    renderCart()
  }

  function clearMember () {
    $memberNumber.val('')
    $memberStatus.prop('hidden', true).empty()
    $('#rss-clear-member').prop('hidden', true)
    resetMemberDiscount()
  }

  // ---- Scanner lifecycle -------------------------------------------------
  function startCamera () {
    // The camera API only exists in a secure context (HTTPS or http://localhost).
    // On plain HTTP, navigator.mediaDevices is undefined — guard so we surface a
    // helpful message instead of an uncaught TypeError.
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      $scanResult.text(
        window.isSecureContext
          ? 'Camera not available on this device/browser.'
          : 'Camera needs a secure connection (HTTPS or localhost). This page is served over plain HTTP, so the browser blocks camera access.'
      )
      return
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(function (s) {
        stream = s
        video.srcObject = stream
        return video.play()
      })
      .then(function () {
        scanning = true
        $('#rss-start-scan').prop('disabled', true)
        $('#rss-stop-scan').prop('disabled', false)
        $scanResult.text(hasNative ? 'Scanning…' : 'Scanning (fallback)…')
        if (hasNative) {
          tickNative()
        } else {
          startQuagga()
        }
      })
      .catch(function (e) {
        $scanResult.text('Camera error: ' + e.message)
      })
  }

  function stopCamera () {
    scanning = false
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop() })
    }
    stream = null
    $('#rss-start-scan').prop('disabled', false)
    $('#rss-stop-scan').prop('disabled', true)
    if (quaggaRunning && window.Quagga) {
      Quagga.stop()
      quaggaRunning = false
    }
  }

  function pauseScanning () {
    scanning = false
    if (quaggaRunning && window.Quagga) {
      Quagga.stop()
      quaggaRunning = false
    }
  }

  function resumeScanning () {
    if (!stream) return
    scanning = true
    if (hasNative) {
      tickNative()
    } else {
      startQuagga()
    }
  }

  function tickNative () {
    var ctx = canvas.getContext('2d')
    ;(async function loop () {
      while (scanning) {
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
        try {
          var barcodes = await barcodeDetector.detect(canvas)
          if (barcodes.length) {
            throttleHandle(sanitizeCode(barcodes[0].rawValue))
          }
        } catch (err) {}
        await sleep(150)
      }
    })()
  }

  function startQuagga () {
    if (!window.Quagga) {
      $scanResult.text('Scanner library not loaded.')
      return
    }
    Quagga.init({
      inputStream: {
        name: 'Live',
        type: 'LiveStream',
        target: video.parentElement,
        constraints: { facingMode: 'environment' }
      },
      decoder: {
        readers: ['ean_reader', 'ean_8_reader', 'upc_reader', 'code_128_reader', 'code_39_reader']
      },
      locate: true
    }, function (err) {
      if (err) {
        $scanResult.text('Scanner init error')
        return
      }
      Quagga.start()
      quaggaRunning = true
      Quagga.onDetected(function (data) {
        if (!data.codeResult || !data.codeResult.code) return
        throttleHandle(sanitizeCode(data.codeResult.code))
      })
    })
  }

  function throttleHandle (code) {
    var now = Date.now()
    if (now - lastScanAt < 1200) return
    lastScanAt = now
    onScan(code)
  }

  function onScan (code) {
    pauseScanning()
    $scanResult.text('Looking up ' + code + '…')

    $.post(rssData.ajaxUrl, {
      action: 'rss_lookup_barcode',
      nonce: rssData.nonce,
      code: code,
      // Marketing mode makes the server price the line at cost (zero-VAT) and
      // reject products with no cost price.
      mode: rssData.mode
    }).done(function (res) {
      if (!res.success) {
        $scanResult.text(res.data && res.data.message ? res.data.message : rssData.i18n.notFound)
        setTimeout(resumeScanning, 1200)
        return
      }
      $scanResult.text('Product found.')
      openModal(res.data)
    }).fail(function () {
      $scanResult.text(rssData.i18n.networkError)
      setTimeout(resumeScanning, 1200)
    })
  }

  // ---- Confirm modal -----------------------------------------------------
  function openModal (product) {
    pendingProduct = product
    $('#rss-modal-name').text(product.name)
    $('#rss-modal-sku').text(product.sku || '—')
    $('#rss-modal-price').html(product.price_html || money(product.price))
    $('#rss-modal-qty').val(1)
    $('#rss-modal').prop('hidden', false)
  }

  function closeModal () {
    $('#rss-modal').prop('hidden', true)
    pendingProduct = null
  }

  function confirmModalAdd () {
    if (!pendingProduct) return
    var qty = parseInt($('#rss-modal-qty').val() || '1', 10)
    if (isNaN(qty) || qty < 1) qty = 1
    addToCart(pendingProduct, qty)
    closeModal()
    resumeScanning()
  }

  // ---- Product search (camera-free manual entry) -------------------------
  function doSearch () {
    var term = $.trim($searchInput.val())
    if (term.length < 2) {
      searchResults = []
      $searchResults.html('<p class="rss-search-hint">Enter at least 2 characters to search.</p>')
      return
    }

    $searchBtn.prop('disabled', true)
    $searchResults.html('<p class="rss-search-hint">Searching…</p>')

    $.post(rssData.ajaxUrl, {
      action: 'rss_search_products',
      nonce: rssData.nonce,
      term: term,
      // In marketing mode results carry cost prices (and a cost_missing flag on
      // products with no recorded cost price).
      mode: rssData.mode
    }).done(function (res) {
      if (!res.success) {
        searchResults = []
        $searchResults.html(
          '<p class="rss-search-hint">' +
          (res.data && res.data.message ? res.data.message : 'No products found.') +
          '</p>'
        )
        return
      }
      searchResults = res.data.products || []
      renderSearchResults()
    }).fail(function () {
      $searchResults.html('<p class="rss-search-hint">' + rssData.i18n.networkError + '</p>')
    }).always(function () {
      $searchBtn.prop('disabled', false)
    })
  }

  function renderSearchResults () {
    $searchResults.empty()

    if (!searchResults.length) {
      $searchResults.html('<p class="rss-search-hint">No products found.</p>')
      return
    }

    var $list = $('<ul class="rss-result-list"/>')

    searchResults.forEach(function (p, i) {
      var meta = []
      if (p.sku) meta.push(p.sku)
      if (p.stock_qty !== null && typeof p.stock_qty !== 'undefined') {
        meta.push('stock: ' + p.stock_qty)
      } else if (p.stock_status === 'onbackorder') {
        meta.push('on backorder')
      }

      var $li = $('<li class="rss-result-item"/>')
      $li.append($('<span class="rss-result-name"/>').text(p.name))
      $li.append($('<span class="rss-result-meta"/>').text(meta.join(' · ')))
      $li.append($('<span class="rss-result-price"/>').html(p.price_html || money(p.price)))

      // Marketing mode: a product with no recorded cost price cannot be added.
      // Disable its Add button and label it so staff get plain, visible feedback.
      var $add = $('<button type="button" class="button button-small rss-result-add"/>')
        .attr('data-index', i)
      if (isMarketing && p.cost_missing) {
        $li.addClass('rss-result-nocost')
        $add.text('No cost price').prop('disabled', true)
      } else {
        $add.text('Add')
      }
      $li.append($add)
      $list.append($li)
    })

    $searchResults.append($list)
  }

  // ---- Place order -------------------------------------------------------
  function placeOrder () {
    clearFeedback()

    if (!cart.length) {
      showFeedback(rssData.i18n.emptyCart)
      return
    }

    var $btn = $('#rss-place-order')

    // Items carry no discount in marketing mode (the server ignores any sent),
    // but the shared shape keeps the standard endpoint happy.
    var items = cart.map(function (r) {
      return {
        product_id: r.product_id,
        qty: r.qty,
        disc_type: r.discType,
        disc_value: r.discValue
      }
    })

    var payload

    if (isMarketing) {
      // Marketing order: no member, no customer fields — the server attributes
      // it to the store marketing account and applies no discounts.
      payload = {
        action: 'rss_place_marketing_order',
        nonce: rssData.nonce,
        order_notes: $('#rss-order-notes').val(),
        items: JSON.stringify(items)
      }
    } else {
      var first = $.trim($firstName.val())
      var last = $.trim($lastName.val())
      var email = $.trim($email.val())

      if (!first || !last || !email) {
        showFeedback(rssData.i18n.missingFields)
        return
      }

      // Spec: the guest warning is specifically for orders with no member number.
      // An expired/inactive member (number present) already saw their own notice
      // and the order is still attributed to their account.
      var memberNumber = $.trim($memberNumber.val())
      if (!memberNumber) {
        if (!window.confirm(rssData.i18n.guestConfirm)) {
          return
        }
      }

      payload = {
        action: 'rss_place_order',
        nonce: rssData.nonce,
        customer_first: first,
        customer_last: last,
        customer_email: email,
        user_id: $userId.val(),
        member_number: memberNumber,
        order_notes: $('#rss-order-notes').val(),
        items: JSON.stringify(items)
      }
    }

    $btn.prop('disabled', true).text(rssData.i18n.placing)

    $.post(rssData.ajaxUrl, payload).done(function (res) {
      if (!res.success) {
        showFeedback(res.data && res.data.message ? res.data.message : 'Could not place order.')
        $btn.prop('disabled', false).text('Place Order')
        return
      }
      var link = ' <a href="' + res.data.edit_url + '" target="_blank">View order</a>'
      showFeedback(res.data.message + ' (' + res.data.total_html + ')' + link, 'success')
      resetForm()
      $btn.prop('disabled', false).text('Place Order')
    }).fail(function () {
      showFeedback(rssData.i18n.networkError)
      $btn.prop('disabled', false).text('Place Order')
    })
  }

  function resetForm () {
    cart = []
    renderCart()
    clearMember()
    $firstName.val('')
    $lastName.val('')
    $email.val('')
    $('#rss-order-notes').val('')
  }

  // ---- Events ------------------------------------------------------------
  $('#rss-verify-member').on('click', verifyMember)
  $memberNumber.on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); verifyMember() }
  })
  $('#rss-clear-member').on('click', clearMember)

  $('#rss-start-scan').on('click', startCamera)
  $('#rss-stop-scan').on('click', stopCamera)

  $searchBtn.on('click', doSearch)
  $searchInput.on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doSearch() }
  })
  $searchResults.on('click', '.rss-result-add', function () {
    var index = parseInt($(this).attr('data-index'), 10)
    var product = searchResults[index]
    if (!product) return
    if (isMarketing && product.cost_missing) {
      showFeedback('Cannot add ' + product.name + ' — no cost price recorded.')
      return
    }
    openModal(product)
  })

  $('#rss-modal-add').on('click', confirmModalAdd)
  $('#rss-modal-cancel').on('click', function () {
    closeModal()
    resumeScanning()
  })

  $cartBody.on('change', '.rss-qty-input', function () {
    var index = $(this).data('index')
    var qty = parseInt($(this).val() || '1', 10)
    if (isNaN(qty) || qty < 1) qty = 1
    cart[index].qty = qty
    renderCart()
  })

  $cartBody.on('change', '.rss-disc-input', function () {
    var index = $(this).data('index')
    var value = parseFloat($(this).val())
    if (isNaN(value) || value < 0) value = 0
    cart[index].discValue = value
    renderCart()
  })

  $cartBody.on('change', '.rss-disc-type', function () {
    var index = $(this).data('index')
    var row = cart[index]
    var newType = this.value === 'amount' ? 'amount' : 'percent'
    if (newType === row.discType) return

    // Preserve the discount across the toggle: carry the current line discount
    // into the new unit so the customer's price doesn't jump on a unit switch.
    var m = lineMath(row)
    if (newType === 'amount') {
      row.discValue = roundDec(m.incSubtotal - m.incTotal) // inclusive € off
    } else {
      row.discValue = m.incSubtotal > 0
        ? roundDec((m.incSubtotal - m.incTotal) / m.incSubtotal * 100)
        : 0
    }
    row.discType = newType
    renderCart()
  })

  $cartBody.on('click', '.rss-remove', function () {
    var index = $(this).data('index')
    cart.splice(index, 1)
    renderCart()
  })

  $('#rss-place-order').on('click', placeOrder)

  window.addEventListener('pagehide', stopCamera)

  renderCart()
})(jQuery)
