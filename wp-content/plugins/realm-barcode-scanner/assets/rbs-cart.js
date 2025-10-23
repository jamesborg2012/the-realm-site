;(function () {
  const video = document.getElementById('rbs-cart-video')
  const canvas = document.getElementById('rbs-cart-canvas')
  const startBtn = document.getElementById('rbs-cart-start')
  const stopBtn = document.getElementById('rbs-cart-stop')
  const resultEl = document.getElementById('rbs-cart-result')
  const panelEl = document.getElementById('rbs-cart-panel')
  const nameEl = document.getElementById('rbs-prod-name')
  const skuEl = document.getElementById('rbs-prod-sku')
  const priceEl = document.getElementById('rbs-prod-price')
  const qtyEl = document.getElementById('rbs-cart-qty')
  const addBtn = document.getElementById('rbs-cart-add')
  const cancelBtn = document.getElementById('rbs-cart-cancel')

  let stream = null
  let scanning = false
  let quaggaRunning = false
  let currentProduct = null
  let lastScanAt = 0

  const hasNative = 'BarcodeDetector' in window
  const barcodeDetector = hasNative
    ? new BarcodeDetector({
        formats: ['ean_13', 'ean_8', 'upc_a', 'code_128', 'code_39']
      })
    : null

  async function startCamera () {
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' }
      })
      video.srcObject = stream
      await video.play()
      scanning = true
      startBtn.disabled = true
      stopBtn.disabled = false
      resultEl.textContent = hasNative
        ? 'Using native scanner...'
        : 'Using Quagga fallback...'
      hasNative ? tickNative() : startQuagga()
    } catch (e) {
      resultEl.textContent = 'Camera error: ' + e.message
    }
  }

  function stopCamera () {
    scanning = false
    if (stream) stream.getTracks().forEach(t => t.stop())
    stream = null
    startBtn.disabled = false
    stopBtn.disabled = true
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
    hasNative ? tickNative() : startQuagga()
  }

  async function tickNative () {
    const ctx = canvas.getContext('2d')
    while (scanning) {
      canvas.width = video.videoWidth
      canvas.height = video.videoHeight
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      try {
        const barcodes = await barcodeDetector.detect(canvas)
        if (barcodes.length) {
          const code = sanitize(barcodes[0].rawValue)
          throttleHandle(code)
        }
      } catch (err) {}
      await sleep(150)
    }
  }

  function startQuagga () {
    if (!window.Quagga) {
      resultEl.textContent = 'Quagga not loaded.'
      return
    }
    Quagga.init(
      {
        inputStream: {
          name: 'Live',
          type: 'LiveStream',
          target: video.parentElement,
          constraints: { facingMode: 'environment' }
        },
        decoder: {
          readers: [
            'ean_reader',
            'ean_8_reader',
            'upc_reader',
            'code_128_reader',
            'code_39_reader'
          ]
        },
        locate: true
      },
      err => {
        if (err) {
          resultEl.textContent = 'Quagga init error'
          return
        }
        Quagga.start()
        quaggaRunning = true
        Quagga.onDetected(data => {
          if (!data.codeResult?.code) return
          const code = sanitize(data.codeResult.code)
          throttleHandle(code)
        })
      }
    )
  }

  function throttleHandle (code) {
    const now = Date.now()
    if (now - lastScanAt < 1200) return
    lastScanAt = now
    onScan(code)
  }

  async function onScan (code) {
    pauseScanning()
    resultEl.textContent = 'Looking up ' + code + '...'
    try {
      const res = await fetch(rbsCart.lookupUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nonce: rbsCart.nonce, code })
      })
      const data = await res.json()
      if (!res.ok) {
        resultEl.textContent = rbsCart.notFound
        setTimeout(resumeScanning, 1000)
        return
      }
      currentProduct = data
      nameEl.textContent = data.name
      skuEl.textContent = data.sku || '-'
      priceEl.innerHTML = data.price_html || ''
      panelEl.classList.remove('hidden')
      resultEl.textContent = 'Product found.'
    } catch (err) {
      resultEl.textContent = 'Network error'
      setTimeout(resumeScanning, 1000)
    }
  }

  async function addToCart () {
    if (!currentProduct) return
    addBtn.disabled = true
    const qty = parseInt(qtyEl.value || '1', 10)
    resultEl.textContent = 'Adding to cart...'

    try {
      const formData = new FormData()
      formData.append('action', 'rbs_add_to_cart')
      formData.append('nonce', rbsCart.nonce)
      formData.append('product_id', currentProduct.product_id)
      formData.append('quantity', qty)

      const res = await fetch(rbsCart.ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
      const data = await res.json()

      if (!data.success) {
        resultEl.textContent =
          '❌ ' + (data.data?.message || 'Add to cart failed')
        addBtn.disabled = false
        return
      }

      // ✅ Success message
      resultEl.textContent = data.data.message || rbsCart.addSuccess

      // Optional WooCommerce mini-cart update
      if (window.jQuery) {
        jQuery(document.body).trigger('wc_fragment_refresh')
      }

      panelEl.classList.add('hidden')
      addBtn.disabled = false
      currentProduct = null
      setTimeout(resumeScanning, 1000)
    } catch (err) {
      resultEl.textContent = 'Network error: ' + err.message
      addBtn.disabled = false
    }
  }

  function cancelPanel () {
    currentProduct = null
    panelEl.classList.add('hidden')
    resumeScanning()
  }

  function sanitize (c) {
    return (c || '').trim().replace(/[^0-9A-Za-z\-_.]/g, '')
  }
  function sleep (ms) {
    return new Promise(r => setTimeout(r, ms))
  }

  startBtn.addEventListener('click', startCamera)
  stopBtn.addEventListener('click', stopCamera)
  addBtn.addEventListener('click', addToCart)
  cancelBtn.addEventListener('click', cancelPanel)
  window.addEventListener('pagehide', stopCamera)
})()
