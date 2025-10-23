;(function () {
  const video = document.getElementById('rbs-video')
  const canvas = document.getElementById('rbs-canvas')
  const startBtn = document.getElementById('rbs-start')
  const stopBtn = document.getElementById('rbs-stop')
  const resultEl = document.getElementById('rbs-result')
  const logEl = document.getElementById('rbs-log')

  const qtyEl = document.getElementById('rbs-qty')
  const modeEl = document.getElementById('rbs-mode')
  const fieldEl = document.getElementById('rbs-field') // if you have a SKU/meta switch

  // New UI bits
  const panelEl = document.getElementById('rbs-product-panel')
  const nameEl = document.getElementById('rbs-product-name')
  const skuEl = document.getElementById('rbs-product-sku')
  const stockEl = document.getElementById('rbs-product-stock')
  const saveBtn = document.getElementById('rbs-save')
  const cancelBtn = document.getElementById('rbs-cancel')

  let stream = null
  let scanning = false
  let useNative = 'BarcodeDetector' in window
  let barcodeDetector = useNative
    ? new window.BarcodeDetector({
        formats: [
          'ean_13',
          'ean_8',
          'upc_a',
          'upc_e',
          'code_128',
          'code_39',
          'qr_code'
        ]
      })
    : null
  let quaggaRunning = false
  let lastScanAt = 0

  // Current selected product (from lookup)
  let currentProduct = null

  function log (msg) {
    const p = document.createElement('div')
    p.textContent = msg
    logEl.prepend(p)
  }

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
      useNative ? tickNative() : startQuagga()
    } catch (e) {
      log('Camera error: ' + e.message)
    }
  }

  function stopCamera () {
    scanning = false
    if (stream) stream.getTracks().forEach(t => t.stop())
    stream = null
    startBtn.disabled = false
    stopBtn.disabled = true
    if (quaggaRunning) {
      Quagga.stop()
      quaggaRunning = false
    }
  }

  // Soft pause/resume scanning (don’t kill the camera stream unless user clicks Stop)
  function pauseScanning () {
    scanning = false
    if (quaggaRunning && window.Quagga) {
      Quagga.stop()
      quaggaRunning = false
    }
  }
  function resumeScanning () {
    if (stream && !scanning) {
      scanning = true
      useNative ? tickNative() : startQuagga()
    }
  }

  async function tickNative () {
    const ctx = canvas.getContext('2d')
    while (scanning) {
      canvas.width = video.videoWidth
      canvas.height = video.videoHeight
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      try {
        const barcodes = await barcodeDetector.detect(canvas)
        if (barcodes && barcodes.length) {
          const code = sanitizeCode(barcodes[0].rawValue)
          throttleHandle(code)
        }
      } catch (e) {
        log('Detect error: ' + e.message)
      }
      await sleep(140)
    }
  }

  function startQuagga () {
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
            'upc_e_reader',
            'code_128_reader',
            'code_39_reader'
          ]
        },
        locate: true
      },
      err => {
        if (err) {
          log('Quagga init error: ' + err)
          return
        }
        Quagga.start()
        quaggaRunning = true
        Quagga.onDetected(data => {
          if (!data || !data.codeResult || !data.codeResult.code) return
          const code = sanitizeCode(data.codeResult.code)
          throttleHandle(code)
        })
      }
    )
  }

  function throttleHandle (code) {
    const now = Date.now()
    if (now - lastScanAt < 1100) return
    lastScanAt = now
    onScannedCode(code)
  }

  async function onScannedCode (code) {
    if (!code) return
    // 1) Pause scanning while we look up
    pauseScanning()
    setUIBusy(`Looking up ${code}…`)
    try {
      const res = await fetch(rbs.restLookupUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          nonce: rbs.nonce,
          code,
          field: fieldEl ? fieldEl.value : 'sku'
        })
      })
      const data = await res.json()
      if (!res.ok) {
        // 2) Not found → unpause and try again
        setUIError(`Not found: ${code}`)
        setTimeout(() => {
          clearUIPanel()
          resumeScanning()
        }, 700)
        return
      }
      // 3) Found → show panel with current stock, wait for Save
      currentProduct = data
      fillUIPanel(data)
    } catch (e) {
      setUIError('Network error')
      setTimeout(() => {
        clearUIPanel()
        resumeScanning()
      }, 700)
    }
  }

  // SAVE: call /adjust with product_id, mode, qty
  async function onSaveClick () {
    if (!currentProduct) return
    saveBtn.disabled = true
    setUIBusy('Saving…')
    try {
      const res = await fetch(rbs.restAdjustUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          nonce: rbs.nonce,
          product_id: currentProduct.product_id,
          mode: modeEl.value, // 'inc' or 'set'
          qty: parseInt(qtyEl.value || '1', 10)
        })
      })
      const data = await res.json()
      if (!res.ok) {
        setUIError(data?.message || 'Save failed')
        saveBtn.disabled = false
        return
      }
      // Success → show confirmation and auto-resume scanning
      setUISuccess(`Saved: ${data.name} • ${data.old_qty} → ${data.new_qty}`)
      vibrate(40)
      setTimeout(() => {
        currentProduct = null
        clearUIPanel()
        resumeScanning()
      }, 900)
    } catch (e) {
      setUIError('Network error')
      saveBtn.disabled = false
    }
  }

  function onCancelClick () {
    currentProduct = null
    clearUIPanel()
    resumeScanning()
  }

  function fillUIPanel (data) {
    nameEl.textContent = data.name || ''
    skuEl.textContent = data.sku || '—'
    stockEl.textContent = String(data.stock_qty ?? 0)
    panelEl.classList.remove('hidden')
    resultEl.textContent = 'Product found. Adjust and tap Save.'
    saveBtn.disabled = false
  }

  function clearUIPanel () {
    panelEl.classList.add('hidden')
    resultEl.textContent = ''
    saveBtn.disabled = false
  }

  function setUIBusy (msg) {
    resultEl.textContent = msg
  }
  function setUIError (msg) {
    resultEl.textContent = '❌ ' + msg
  }
  function setUISuccess (msg) {
    resultEl.textContent = '✅ ' + msg
  }

  function sanitizeCode (code) {
    return (code || '').trim().replace(/[^0-9A-Za-z\-_.]/g, '')
  }

  function sleep (ms) {
    return new Promise(r => setTimeout(r, ms))
  }
  function vibrate (ms) {
    if (navigator.vibrate) navigator.vibrate(ms)
  }

  // Hooks
  startBtn.addEventListener('click', startCamera)
  stopBtn.addEventListener('click', stopCamera)
  if (saveBtn) saveBtn.addEventListener('click', onSaveClick)
  if (cancelBtn) cancelBtn.addEventListener('click', onCancelClick)
  window.addEventListener('pagehide', stopCamera)
})()
