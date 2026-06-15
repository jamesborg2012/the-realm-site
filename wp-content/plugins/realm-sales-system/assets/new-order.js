;(function ($) {
  'use strict'

  // ---- State -------------------------------------------------------------
  // Cart rows: { product_id, name, sku, price, qty }. Price is captured once
  // at scan time (latest product price) and never refreshed afterwards.
  var cart = []
  var discountPct = 0 // applied store discount % (0 when no/expired member)
  var pendingProduct = null // product awaiting modal confirmation

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

  // ---- Cart rendering ----------------------------------------------------
  function renderCart () {
    $cartBody.empty()

    if (!cart.length) {
      $cartBody.append(
        '<tr class="rss-cart-empty"><td colspan="6">No products added yet.</td></tr>'
      )
      $totalDiscount.text('—')
      $totalPay.text('—')
      return
    }

    var totalDiscount = 0
    var totalPay = 0

    cart.forEach(function (row, index) {
      var lineSubtotal = row.price * row.qty
      var lineDiscount = lineSubtotal * (discountPct / 100)
      var lineTotal = lineSubtotal - lineDiscount

      totalDiscount += lineDiscount
      totalPay += lineTotal

      var $tr = $('<tr/>')
      $tr.append($('<td/>').text(row.name + (row.sku ? ' (' + row.sku + ')' : '')))
      $tr.append($('<td class="rss-num"/>').text(money(row.price)))

      var $qtyCell = $('<td class="rss-num"/>')
      var $qtyInput = $('<input type="number" min="1" step="1" class="rss-qty-input"/>')
        .val(row.qty)
        .data('index', index)
      $qtyCell.append($qtyInput)
      $tr.append($qtyCell)

      $tr.append($('<td class="rss-num"/>').text(money(lineDiscount)))
      $tr.append($('<td class="rss-num"/>').text(money(lineTotal)))

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
      cart.push({
        product_id: product.product_id,
        name: product.name,
        sku: product.sku,
        price: Number(product.price) || 0,
        qty: qty
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
      code: code
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

  // ---- Place order -------------------------------------------------------
  function placeOrder () {
    clearFeedback()

    var first = $.trim($firstName.val())
    var last = $.trim($lastName.val())
    var email = $.trim($email.val())

    if (!first || !last || !email) {
      showFeedback(rssData.i18n.missingFields)
      return
    }

    if (!cart.length) {
      showFeedback(rssData.i18n.emptyCart)
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

    var $btn = $('#rss-place-order')
    $btn.prop('disabled', true).text(rssData.i18n.placing)

    var items = cart.map(function (r) {
      return { product_id: r.product_id, qty: r.qty }
    })

    $.post(rssData.ajaxUrl, {
      action: 'rss_place_order',
      nonce: rssData.nonce,
      customer_first: first,
      customer_last: last,
      customer_email: email,
      user_id: $userId.val(),
      member_number: memberNumber,
      order_notes: $('#rss-order-notes').val(),
      items: JSON.stringify(items)
    }).done(function (res) {
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

  $cartBody.on('click', '.rss-remove', function () {
    var index = $(this).data('index')
    cart.splice(index, 1)
    renderCart()
  })

  $('#rss-place-order').on('click', placeOrder)

  window.addEventListener('pagehide', stopCamera)

  renderCart()
})(jQuery)
