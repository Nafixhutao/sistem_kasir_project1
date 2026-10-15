import React, { useState, useEffect, useRef } from 'react'
import { Search, ShoppingCart, Camera, Barcode } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { parseNominalInput } from '../utils/nominalInput'
import { playScanBeep } from '../utils/audioFeedback'
import api, { getApiErrorMessage } from '../services/api'
import SalesCartPanel from '../components/Sales/SalesCartPanel'
import SalesPaymentModal from '../components/Sales/SalesPaymentModal'
import SalesReceiptModal from '../components/Sales/SalesReceiptModal'
import SalesScannerModals from '../components/Sales/SalesScannerModals'
import toast from 'react-hot-toast'
import { BrowserMultiFormatReader } from '@zxing/browser'
import { useBusinessRequest } from '../hooks/useBusinessRequest'

export default function TransaksiPenjualan() {
  const businessRequest = useBusinessRequest()
  const [cart, setCart] = useState([])
  const [searchTerm, setSearchTerm] = useState('')
  const [products, setProducts] = useState([])
  const [showPayment, setShowPayment] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState('tunai')
  const [uangBayar, setUangBayar] = useState('')
  const [loading, setLoading] = useState(false)
  const [qrisLoading, setQrisLoading] = useState(false)
  const [qrisChecking, setQrisChecking] = useState(false)
  const [qrisData, setQrisData] = useState(null)
  const [qrisError, setQrisError] = useState('')
  const [qrisStatus, setQrisStatus] = useState('')
  const [manualConfirming, setManualConfirming] = useState(false)
  const [scannerOpen, setScannerOpen] = useState(false)
  const [usbScannerOpen, setUsbScannerOpen] = useState(false)
  const [scannerError, setScannerError] = useState('')
  const [manualBarcode, setManualBarcode] = useState('')
  const [lastReceipt, setLastReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const scanLoopRef = useRef(null)
  const lastScanRef = useRef('')
  const scannerControlsRef = useRef(null)
  const qrisAutoCheckoutRef = useRef(false)

  useEffect(() => { fetchProducts() }, [])

  useEffect(() => {
    if (scannerOpen) startScanner()
    return stopScanner
  }, [scannerOpen])

  useEffect(() => {
    document.body.classList.toggle('receipt-print-mode', Boolean(showReceipt && lastReceipt))
    return () => document.body.classList.remove('receipt-print-mode')
  }, [showReceipt, lastReceipt])

  useEffect(() => {
    if (paymentMethod !== 'qris' || !qrisData?.order_id || ['settlement', 'capture'].includes(qrisStatus)) return

    const interval = window.setInterval(() => {
      checkQrisStatus(false)
    }, 5000)

    return () => window.clearInterval(interval)
  }, [paymentMethod, qrisData?.order_id, qrisStatus])

  useEffect(() => {
    if (paymentMethod !== 'qris') return
    if (!qrisData?.order_id || !['settlement', 'capture'].includes(qrisStatus)) return
    if (loading || qrisAutoCheckoutRef.current) return

    qrisAutoCheckoutRef.current = true
    handleCheckout('qris')
  }, [paymentMethod, qrisData?.order_id, qrisStatus, loading])

  const fetchProducts = async () => {
    try {
      const res = await api.get('/barang')
      setProducts(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat produk'))
    }
  }

  const addToCart = (p) => {
    if (!p) return
    if (Number(p.stok) < 1) return toast.error('Stok habis')
    const existing = cart.find(i => i.id === p.id)
    if (existing) {
      if (existing.jumlah >= Number(p.stok)) return toast.error('Stok tidak cukup')
      setCart(cart.map(i => i.id === p.id ? { ...i, jumlah: i.jumlah + 1 } : i))
    } else {
      setCart([...cart, { ...p, jumlah: 1 }])
    }
  }

  const addByCode = (code) => {
    const cleanCode = String(code || '').trim().toLowerCase()
    if (!cleanCode) return
    const product = products.find(p => String(p.kode_barang).toLowerCase() === cleanCode)
    if (!product) return toast.error(`Produk ${code} tidak ditemukan`)
    addToCart(product)
    playScanBeep()
    toast.success(`${product.nama_barang} masuk keranjang`)
  }

  const updateQty = (id, qty) => {
    if (qty < 1) return removeFromCart(id)
    const product = products.find(x => x.id === id)
    if (qty > Number(product?.stok)) return toast.error('Stok tidak cukup')
    setCart(cart.map(i => i.id === id ? { ...i, jumlah: qty } : i))
  }

  const removeFromCart = (id) => setCart(cart.filter(i => i.id !== id))
  const totalHarga = cart.reduce((sum, item) => sum + (Number(item.harga_jual) * item.jumlah), 0)
  const uangBayarValue = parseNominalInput(uangBayar)
  const kembalian = uangBayar ? uangBayarValue - totalHarga : 0

  const copyQrisUrl = async () => {
    if (!qrisData?.qris_url) return toast.error('QRIS belum dibuat')
    try {
      await navigator.clipboard.writeText(qrisData.qris_url)
      toast.success('URL QRIS disalin')
    } catch (e) {
      toast.error('Gagal menyalin URL QRIS')
    }
  }

  const openMidtransSimulator = () => {
    window.location.assign('https://simulator.sandbox.midtrans.com/')
  }

  const openPaymentPage = () => {
    const url = qrisData?.payment_url || qrisData?.qris_url
    if (!url) return toast.error('Link pembayaran belum tersedia')
    window.open(url, '_blank', 'noopener,noreferrer')
  }

  const closePaymentModal = () => {
    setShowPayment(false)
    setPaymentMethod('tunai')
    setUangBayar('')
    setQrisData(null)
    setQrisStatus('')
    setQrisError('')
    setManualConfirming(false)
    qrisAutoCheckoutRef.current = false
  }

  const createQris = async () => {
    if (cart.length === 0) return
    setQrisLoading(true)
    setQrisError('')
    setQrisData(null)
    setQrisStatus('')
    setManualConfirming(false)
    qrisAutoCheckoutRef.current = false
    try {
      const res = await api.post('/penjualan/qris', {
        items: cart.map(i => ({
          id: i.id,
          kode_barang: i.kode_barang,
          nama_barang: i.nama_barang,
          harga_jual: Number(i.harga_jual),
          jumlah: i.jumlah
        }))
      })
      setQrisData(res.data)
      setQrisStatus(res.data?.status || 'pending')
    } catch (e) {
      setQrisError(getApiErrorMessage(e, 'Gagal membuat QRIS'))
    } finally {
      setQrisLoading(false)
    }
  }

  const checkQrisStatus = async (showMessage = true) => {
    if (!qrisData?.order_id) {
      if (showMessage) toast.error('Buat QRIS dulu')
      return ''
    }

    setQrisChecking(true)
    setQrisError('')
    try {
      const res = await api.post('/penjualan/qris/status', { order_id: qrisData.order_id })
      const latestStatus = res.data?.status || 'unknown'
      setQrisStatus(latestStatus)
      if (showMessage) {
        if (['settlement', 'capture'].includes(latestStatus)) {
          toast.success('Pembayaran QRIS sudah diterima')
        } else {
          toast(`Status QRIS: ${formatQrisStatus(latestStatus)}`)
        }
      }
      return latestStatus
    } catch (e) {
      setQrisError(getApiErrorMessage(e, 'Gagal mengecek status pembayaran QRIS'))
      return ''
    } finally {
      setQrisChecking(false)
    }
  }

  const manualConfirmQris = async () => {
    if (!qrisData?.order_id) return toast.error('Buat QRIS dulu')
    if (!window.confirm('Tandai pembayaran ini LUNAS secara manual?\n\nHanya untuk ujicoba sandbox — pastikan pembayaran benar-benar sudah diterima.')) return

    setManualConfirming(true)
    setQrisError('')
    try {
      await api.post('/penjualan/qris/manual-confirm', { order_id: qrisData.order_id })
      setQrisStatus('settlement')
      toast.success('Pembayaran ditandai lunas (sandbox)')
    } catch (e) {
      setQrisError(getApiErrorMessage(e, 'Gagal mengonfirmasi pembayaran'))
    } finally {
      setManualConfirming(false)
    }
  }

  const formatQrisStatus = (status) => {
    const labels = {
      pending: 'Menunggu pembayaran',
      settlement: 'Berhasil dibayar',
      capture: 'Berhasil dibayar',
      expire: 'Kedaluwarsa',
      cancel: 'Dibatalkan',
      deny: 'Ditolak',
      failure: 'Gagal',
      unknown: 'Belum diketahui'
    }

    return labels[status] || status || 'Menunggu pembayaran'
  }

  const playPaymentSuccessSound = () => {
    const AudioContext = window.AudioContext || window.webkitAudioContext
    if (!AudioContext) return
    const ctx = new AudioContext()
    const gain = ctx.createGain()
    gain.gain.value = 0.07
    gain.connect(ctx.destination)

    const notes = [660, 880, 1046]
    notes.forEach((frequency, index) => {
      const oscillator = ctx.createOscillator()
      oscillator.type = 'sine'
      oscillator.frequency.value = frequency
      oscillator.connect(gain)
      oscillator.start(ctx.currentTime + index * 0.09)
      oscillator.stop(ctx.currentTime + index * 0.09 + 0.12)
    })
  }

  const speakQrisPaymentSuccess = (amount) => {
    if (!('speechSynthesis' in window)) return
    const nominalText = new Intl.NumberFormat('id-ID').format(Number(amount || 0))
    const utterance = new SpeechSynthesisUtterance(`Pembayaran ${nominalText} rupiah berhasil diterima`)
    utterance.lang = 'id-ID'
    utterance.rate = 0.95
    utterance.pitch = 1
    window.speechSynthesis.cancel()
    window.speechSynthesis.speak(utterance)
  }

  const handleCheckout = async (method = paymentMethod) => {
    if (businessRequest.isBusy()) return
    if (cart.length === 0) return toast.error('Keranjang kosong')
    if (method === 'tunai' && uangBayarValue < totalHarga) return toast.error('Uang bayar kurang')
    if (method === 'qris' && qrisData?.order_id && !['settlement', 'capture'].includes(qrisStatus)) {
      const latestStatus = await checkQrisStatus(false)
      if (!['settlement', 'capture'].includes(latestStatus)) {
        return toast.error('Pembayaran QRIS belum berhasil. Selesaikan pembayaran lalu cek status.')
      }
    }
    if (method === 'qris' && !qrisData?.order_id) {
      return toast.error('Buat QRIS dulu sebelum konfirmasi')
    }
    const stockProblem = cart.find(item => item.jumlah > Number(item.stok))
    if (stockProblem) return toast.error(`Stok ${stockProblem.nama_barang} tidak cukup`)

    const receiptItems = cart.map(i => ({
      id: i.id,
      kode_barang: i.kode_barang,
      nama_barang: i.nama_barang,
      harga_jual: Number(i.harga_jual),
      jumlah: i.jumlah
    }))
    const receiptTotal = totalHarga
    const receiptPaid = method === 'tunai' ? uangBayarValue : receiptTotal

    setLoading(true)
    try {
      const res = await businessRequest.submit('/penjualan', {
        items: receiptItems,
        metode_pembayaran: method,
        uang_bayar: method === 'tunai' ? receiptPaid : null,
        qris_order_id: method === 'qris' ? qrisData?.order_id : null
      })
      if (!res) return
      if (method === 'qris') {
        playPaymentSuccessSound()
        speakQrisPaymentSuccess(receiptTotal)
      }
      toast.success(`Pembayaran ${method.toUpperCase()} berhasil: ${res.data.transaksi.kode_transaksi}`)
      setLastReceipt({
        kode_transaksi: res.data?.transaksi?.kode_transaksi || `TRX-${Date.now()}`,
        waktu: new Date().toISOString(),
        metode_pembayaran: method,
        items: receiptItems,
        total: Number(res.data?.transaksi?.total_harga || receiptTotal),
        uang_bayar: receiptPaid,
        kembalian: method === 'tunai' ? Math.max(receiptPaid - receiptTotal, 0) : 0
      })
      setShowReceipt(true)
      setCart([])
      closePaymentModal()
      fetchProducts()
    } catch (e) {
      if (method === 'qris') qrisAutoCheckoutRef.current = false
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan transaksi penjualan'))
    } finally {
      setLoading(false)
    }
  }

  const startScanner = async () => {
    setScannerError('')
    stopScanner()
    if (!navigator.mediaDevices?.getUserMedia) {
      setScannerError('Kamera tidak tersedia di browser ini. Gunakan HTTPS/ngrok atau input barcode manual.')
      return
    }

    try {
      const reader = new BrowserMultiFormatReader()
      const constraints = {
        audio: false,
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      }
      const controls = await reader.decodeFromConstraints(constraints, videoRef.current, (result, error, controls) => {
        const code = result?.getText?.()?.trim()
        if (code && code !== lastScanRef.current) {
          lastScanRef.current = code
          addByCode(code)
          controls.stop()
          scannerControlsRef.current = null
          setScannerOpen(false)
        }
      })
      scannerControlsRef.current = controls
    } catch (e) {
      runBarcodeDetector()
    }
  }

  const stopScanner = () => {
    if (scanLoopRef.current) cancelAnimationFrame(scanLoopRef.current)
    scannerControlsRef.current?.stop?.()
    scannerControlsRef.current = null
    streamRef.current?.getTracks().forEach(track => track.stop())
    streamRef.current = null
    scanLoopRef.current = null
    lastScanRef.current = ''
    if (videoRef.current) videoRef.current.srcObject = null
  }

  const runBarcodeDetector = async () => {
    if (!('BarcodeDetector' in window)) {
      setScannerError('Kamera tidak bisa membaca otomatis di browser ini. Pastikan memakai HTTPS/ngrok, izinkan kamera, atau gunakan input manual.')
      return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
      })
      streamRef.current = stream
      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }

      const detector = new window.BarcodeDetector({ formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'qr_code'] })
      const scan = async () => {
        if (!scannerOpen || !videoRef.current) return
        try {
          const results = await detector.detect(videoRef.current)
          const code = results?.[0]?.rawValue?.trim()
          if (code && code !== lastScanRef.current) {
            lastScanRef.current = code
            addByCode(code)
            setScannerOpen(false)
            return
          }
        } catch (e) {}
        scanLoopRef.current = requestAnimationFrame(scan)
      }
      scan()
    } catch (e) {
      setScannerError('Izin kamera ditolak atau kamera tidak ditemukan. Buka lewat HTTPS/ngrok lalu izinkan kamera.')
    }
  }

  const filteredProducts = products.filter(p =>
    p.nama_barang.toLowerCase().includes(searchTerm.toLowerCase()) ||
    p.kode_barang.toLowerCase().includes(searchTerm.toLowerCase())
  )
  const productCount = filteredProducts.length
  const totalItems = cart.reduce((sum, item) => sum + item.jumlah, 0)

  return (
    <div className="sales-page">
      <div className="page-heading sales-heading">
        <div>
          <span>Kasir Penjualan</span>
          <h1>Transaksi Penjualan</h1>
          <p>Pilih produk, scan barcode dengan kamera, lalu selesaikan pembayaran tunai atau QRIS.</p>
        </div>
        <div className="sales-heading-actions">
          <button className="scan-open-button" onClick={() => setUsbScannerOpen(true)}>
            <Barcode size={19} />
            <span>Scanner USB</span>
          </button>
          <button className="scan-open-button secondary" onClick={() => setScannerOpen(true)}>
            <Camera size={19} />
            <span>Kamera</span>
          </button>
        </div>
      </div>

      <div className="sales-grid">
        <section className="product-panel">
          <div className="sales-toolbar">
            <label className="sales-search">
              <Search size={19} />
              <input type="text" placeholder="Cari produk dari nama atau kode..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)} />
            </label>
            <span>{productCount} produk</span>
          </div>

          <div className="product-grid">
            {filteredProducts.map(product => (
              <button key={product.id} onClick={() => addToCart(product)} disabled={Number(product.stok) < 1} className={`product-card ${Number(product.stok) < 1 ? 'disabled' : ''}`}>
                <div className="product-card-top">
                  <div className="product-icon"><ShoppingCart size={18} /></div>
                  <span className={Number(product.stok) < 5 ? 'stock-mini low' : 'stock-mini'}>Stok {product.stok}</span>
                </div>
                <strong>{product.nama_barang}</strong>
                <span>{product.kode_barang}</span>
                <p>{formatRupiah(product.harga_jual)}</p>
              </button>
            ))}
          </div>
        </section>

        <SalesCartPanel
          cart={cart}
          totalHarga={totalHarga}
          totalItems={totalItems}
          onCheckout={() => setShowPayment(true)}
          onRemove={removeFromCart}
          onUpdateQty={updateQty}
        />
      </div>

      <SalesScannerModals
        scannerOpen={scannerOpen}
        usbScannerOpen={usbScannerOpen}
        scannerError={scannerError}
        manualBarcode={manualBarcode}
        onManualBarcodeChange={setManualBarcode}
        onSubmitBarcode={addByCode}
        onCloseScanner={() => setScannerOpen(false)}
        onCloseUsbScanner={() => setUsbScannerOpen(false)}
        videoRef={videoRef}
      />

      <SalesPaymentModal
        isOpen={showPayment}
        onClose={closePaymentModal}
        totalHarga={totalHarga}
        totalItems={totalItems}
        paymentMethod={paymentMethod}
        onPaymentMethodChange={setPaymentMethod}
        uangBayar={uangBayar}
        onUangBayarChange={setUangBayar}
        uangBayarValue={uangBayarValue}
        kembalian={kembalian}
        loading={loading}
        onCheckout={handleCheckout}
        qrisData={qrisData}
        qrisLoading={qrisLoading}
        qrisChecking={qrisChecking}
        qrisError={qrisError}
        qrisStatus={qrisStatus}
        onCreateQris={createQris}
        onCopyQrisUrl={copyQrisUrl}
        onOpenSimulator={openMidtransSimulator}
        onCheckQrisStatus={checkQrisStatus}
        onOpenPaymentPage={openPaymentPage}
        onManualConfirm={manualConfirmQris}
        manualConfirming={manualConfirming}
        formatQrisStatus={formatQrisStatus}
      />

      <SalesReceiptModal
        isOpen={showReceipt}
        receipt={lastReceipt}
        onClose={() => setShowReceipt(false)}
      />
    </div>
  )
}
