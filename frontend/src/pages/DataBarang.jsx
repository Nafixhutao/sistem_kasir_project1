import React, { useState, useEffect, useRef } from 'react'
import { Plus, Search, Edit3, Trash2, Package, Boxes, AlertTriangle, Tags, Camera, Keyboard, ScanLine, X, Barcode, Upload } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { cleanNominalInput, formatNominalInput } from '../utils/nominalInput'
import { playScanBeep } from '../utils/audioFeedback'
import api, { getApiErrorMessage } from '../services/api'
import Modal from '../components/Common/Modal'
import toast from 'react-hot-toast'
import { BrowserMultiFormatReader } from '@zxing/browser'

const emptyForm = { kode_barang: '', nama_barang: '', kategori: '', stok: '', harga_beli: '', harga_jual: '', supplier_id: '' }

export default function DataBarang() {
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [barang, setBarang] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [editData, setEditData] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [inputMode, setInputMode] = useState('manual')
  const [usbInputActive, setUsbInputActive] = useState(false)
  const [scannerActive, setScannerActive] = useState(false)
  const [scannerError, setScannerError] = useState('')
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteLoading, setDeleteLoading] = useState(false)
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const scanLoopRef = useRef(null)
  const importInputRef = useRef(null)
  const scannerControlsRef = useRef(null)

  useEffect(() => {
    fetchBarang()
    api.get('/suppliers').then(res => setSuppliers(res.data)).catch(e => toast.error(getApiErrorMessage(e, 'Gagal memuat pilihan supplier')))
  }, [])

  useEffect(() => {
    if (modalOpen && inputMode === 'otomatis' && !editData) startScanner()
    return stopScanner
  }, [modalOpen, inputMode, editData])

  const fetchBarang = async () => {
    try {
      const res = await api.get('/barang')
      setBarang(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat data barang'))
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = {
      ...form,
      stok: Number(form.stok),
      harga_beli: Number(form.harga_beli),
      harga_jual: Number(form.harga_jual),
      supplier_id: form.supplier_id === '' || form.supplier_id == null ? null : Number(form.supplier_id),
    }

    try {
      if (editData) await api.put(`/barang/${editData.id}`, payload)
      else await api.post('/barang', payload)
      fetchBarang()
      toast.success(editData ? 'Barang berhasil diperbarui' : 'Barang berhasil ditambahkan')
      closeModal()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan barang'))
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleteLoading(true)
    try {
      await api.delete(`/barang/${deleteTarget.id}`)
      fetchBarang()
      toast.success('Barang berhasil dihapus')
      setDeleteTarget(null)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menghapus barang'))
    } finally {
      setDeleteLoading(false)
    }
  }

  const normalizeImportHeader = (header = '') => header
    .toString()
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_]/g, '')

  const parseImportNumber = (value) => {
    if (typeof value === 'number') return value
    const cleaned = String(value ?? '')
      .replace(/rp/gi, '')
      .replace(/\s/g, '')
      .replace(/\./g, '')
      .replace(',', '.')
    return Number(cleaned || 0)
  }

  const normalizeImportRows = (rows) => rows.map(row => {
    const item = Object.fromEntries(Object.entries(row).map(([key, value]) => [normalizeImportHeader(key), value]))
    return {
      kode_barang: String(item.kode_barang || item.kode || item.barcode || '').trim(),
      nama_barang: String(item.nama_barang || item.nama || item.barang || '').trim(),
      kategori: String(item.kategori || item.category || '').trim(),
      stok: parseImportNumber(item.stok),
      harga_beli: parseImportNumber(item.harga_beli || item.modal || item.harga_modal),
      harga_jual: parseImportNumber(item.harga_jual || item.harga || item.jual),
    }
  }).filter(item => item.kode_barang && item.nama_barang)

  const parseImportText = (text) => {
    const rows = text.split(/\r?\n/).map(row => row.trim()).filter(Boolean)
    if (rows.length < 2) return []
    const separator = rows[0].includes(';') ? ';' : rows[0].includes('\t') ? '\t' : ','
    const headers = rows[0].split(separator).map(normalizeImportHeader)
    const parsedRows = rows.slice(1).map(row => {
      const values = row.split(separator).map(v => v.trim())
      const item = {}
      headers.forEach((header, index) => { item[header] = values[index] })
      return item
    })
    return normalizeImportRows(parsedRows)
  }

  const parseImportWorkbook = async (file) => {
    const readXlsxFile = (await import('read-excel-file/browser')).default
    const rows = await readXlsxFile(file)
    if (rows.length < 2) return []
    const headers = rows[0].map(normalizeImportHeader)
    const parsedRows = rows.slice(1).map(row => {
      const item = {}
      headers.forEach((header, index) => { item[header] = row[index] ?? '' })
      return item
    })
    return normalizeImportRows(parsedRows)
  }

  const handleImportFile = async (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    try {
      const isExcel = /\.xlsx$/i.test(file.name)
      const items = isExcel ? await parseImportWorkbook(file) : parseImportText(await file.text())
      if (!items.length) return toast.error('Format file tidak terbaca. Gunakan header kode_barang,nama_barang,kategori,stok,harga_beli,harga_jual.')
      const invalidItem = items.find(item => item.kode_barang.length > 50 || item.nama_barang.length > 150 || item.kategori.length > 100)
      if (invalidItem) return toast.error('Import dibatalkan: kode maksimal 50, nama barang 150, dan kategori 100 karakter. Format kolom kode sebagai Text agar nol awal tetap tersimpan.')
      const res = await api.post('/barang/import', { items })
      toast.success(`${res.data.created} barang baru, ${res.data.updated} barang diperbarui`)
      fetchBarang()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal import barang'))
    } finally {
      event.target.value = ''
    }
  }

  const openCreate = (mode = 'manual') => {
    setEditData(null)
    setInputMode(mode)
    setForm(emptyForm)
    setModalOpen(true)
  }

  const openEdit = (item) => {
    setEditData(item)
    setInputMode('manual')
    setForm({ ...item, supplier_id: item.supplier_id ?? '' })
    setModalOpen(true)
  }

  const closeModal = () => {
    setModalOpen(false)
    setEditData(null)
    setForm(emptyForm)
    setScannerActive(false)
    setScannerError('')
    stopScanner()
  }

  const startScanner = async () => {
    setScannerError('')
    stopScanner()
    if (!navigator.mediaDevices?.getUserMedia) {
      setScannerError('Kamera tidak tersedia di browser ini. Kode barang bisa diketik manual sebagai cadangan.')
      return
    }

    try {
      setScannerActive(true)
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
        if (code) {
          setForm(prev => ({ ...prev, kode_barang: code }))
          playScanBeep()
          toast.success(`Kode barang terbaca: ${code}`)
          controls.stop()
          scannerControlsRef.current = null
          setScannerActive(false)
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
    setScannerActive(false)
    if (videoRef.current) videoRef.current.srcObject = null
  }

  const runBarcodeDetector = async () => {
    if (!('BarcodeDetector' in window)) {
      setScannerError('Scanner otomatis belum didukung browser ini. Pastikan memakai HTTPS/ngrok, izinkan kamera, atau ketik kode manual.')
      return
    }
    try {
      setScannerActive(true)
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
        if (!videoRef.current || !modalOpen || inputMode !== 'otomatis') return
        try {
          const results = await detector.detect(videoRef.current)
          const code = results?.[0]?.rawValue?.trim()
          if (code) {
            setForm(prev => ({ ...prev, kode_barang: code }))
            playScanBeep()
            toast.success(`Kode barang terbaca: ${code}`)
            stopScanner()
            return
          }
        } catch (e) {}
        scanLoopRef.current = requestAnimationFrame(scan)
      }
      scan()
    } catch (e) {
      setScannerActive(false)
      setScannerError('Izin kamera ditolak atau kamera tidak ditemukan. Buka lewat HTTPS/ngrok lalu izinkan kamera.')
    }
  }

  const filtered = barang.filter(b =>
    b.nama_barang.toLowerCase().includes(search.toLowerCase()) ||
    b.kode_barang.toLowerCase().includes(search.toLowerCase()) ||
    String(b.kategori || '').toLowerCase().includes(search.toLowerCase())
  )
  const lowStock = barang.filter(item => Number(item.stok) < 5).length
  const categories = new Set(barang.map(item => item.kategori).filter(Boolean)).size

  return (
    <div className="inventory-page">
      <div className="page-heading inventory-heading">
        <div>
          <span>Inventaris Toko</span>
          <h1>Data Barang</h1>
          <p>Kelola stok, kategori, harga beli, dan harga jual barang Sultan Cell.</p>
        </div>
        {canWrite && <div className="sales-heading-actions">
          <input ref={importInputRef} type="file" accept=".csv,.txt,.xlsx" onChange={handleImportFile} hidden />
          <button type="button" onClick={() => importInputRef.current?.click()} className="scan-open-button secondary">
            <Upload size={19} />
            <span>Import XLSX/CSV</span>
          </button>
          <button onClick={() => openCreate('manual')} className="inventory-add-button">
            <Plus size={19} />
            <span>Tambah Barang</span>
          </button>
        </div>}
      </div>

      <div className="inventory-summary">
        <div className="inventory-summary-card">
          <div className="summary-icon blue"><Boxes size={22} /></div>
          <div>
            <span>Total Barang</span>
            <strong>{barang.length}</strong>
          </div>
        </div>
        <div className="inventory-summary-card">
          <div className="summary-icon amber"><AlertTriangle size={22} /></div>
          <div>
            <span>Stok Menipis</span>
            <strong>{lowStock}</strong>
          </div>
        </div>
        <div className="inventory-summary-card">
          <div className="summary-icon green"><Tags size={22} /></div>
          <div>
            <span>Kategori</span>
            <strong>{categories}</strong>
          </div>
        </div>
      </div>

      <section className="inventory-panel">
        <div className="inventory-toolbar">
          <div>
            <h2>Daftar Barang</h2>
            <p>{filtered.length} barang ditampilkan</p>
          </div>
          {lowStock > 0 && (
            <div className="inventory-alert-badge">
              <AlertTriangle size={17} />
              <span>{lowStock} stok menipis</span>
            </div>
          )}
          <label className="inventory-search">
            <Search size={19} />
            <input type="text" maxLength={150} placeholder="Cari kode, nama, atau kategori..." value={search} onChange={e => setSearch(e.target.value)} />
          </label>
        </div>

        <div className="inventory-table-wrap">
          <table className="inventory-table">
            <thead>
              <tr>
                <th>Kode</th>
                <th>Nama Barang</th>
                <th>Kategori</th>
                <th>Supplier</th>
                <th>Stok</th>
                <th className="money-header">Harga Beli</th>
                <th className="money-header">Harga Jual</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(item => (
                <tr key={item.id}>
                  <td><span className="item-code">{item.kode_barang}</span></td>
                  <td>
                    <div className="item-name-cell">
                      <div className="item-avatar"><Package size={18} /></div>
                      <div>
                        <strong>{item.nama_barang}</strong>
                        <span>Margin {formatRupiah(Number(item.harga_jual) - Number(item.harga_beli))}</span>
                      </div>
                    </div>
                  </td>
                  <td><span className="category-pill">{item.kategori}</span></td>
                  <td>{item.supplier?.nama_supplier || '-'}</td>
                  <td>
                    <div className="stock-cell">
                      <span className={`stock-pill ${Number(item.stok) < 5 ? 'low' : 'safe'}`}>{item.stok} pcs</span>
                      {Number(item.stok) < 5 && <span className="low-stock-note"><AlertTriangle size={13} />Menipis</span>}
                    </div>
                  </td>
                  <td className="money-cell">{formatRupiah(item.harga_beli)}</td>
                  <td className="money-cell strong">{formatRupiah(item.harga_jual)}</td>
                  <td>
                    {canWrite && <div className="table-actions">
                      <button onClick={() => openEdit(item)} className="icon-action edit" aria-label={`Edit ${item.nama_barang}`}>
                        <Edit3 size={17} />
                      </button>
                      <button onClick={() => setDeleteTarget(item)} className="icon-action delete" aria-label={`Hapus ${item.nama_barang}`}>
                        <Trash2 size={17} />
                      </button>
                    </div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {!loading && filtered.length === 0 && (
            <div className="inventory-empty">
              <Package size={40} />
              <p>Tidak ada data barang</p>
              <span>Coba ubah kata kunci pencarian atau tambah barang baru.</span>
            </div>
          )}
        </div>
      </section>

      <Modal isOpen={modalOpen} onClose={closeModal} title={editData ? 'Edit Barang' : inputMode === 'otomatis' ? 'Input Barang Otomatis' : 'Input Barang Manual'} size="lg">
        <form onSubmit={handleSubmit} className="inventory-form">
          {!editData && (
            <div className="inventory-input-mode">
              <button type="button" onClick={() => { stopScanner(); setInputMode('manual') }} className={inputMode === 'manual' ? 'active' : ''}>
                <Keyboard size={18} />
                <span>Manual</span>
              </button>
              <button type="button" onClick={() => setInputMode('otomatis')} className={inputMode === 'otomatis' ? 'active' : ''}>
                <Camera size={18} />
                <span>Otomatis Kamera</span>
              </button>
            </div>
          )}

          {inputMode === 'otomatis' && !editData && (
            <div className="inventory-scanner-panel">
              <div className="inventory-scanner-header">
                <div>
                  <strong>Scan Kode Barang</strong>
                  <span>Arahkan kamera ke barcode. Nama barang tetap diketik manual.</span>
                </div>
                {scannerActive ? (
                  <button type="button" onClick={stopScanner}><X size={18} /> Stop</button>
                ) : (
                  <button type="button" onClick={startScanner}><Camera size={18} /> Scan</button>
                )}
              </div>
              <div className="inventory-camera-frame">
                <video ref={videoRef} playsInline muted autoPlay></video>
                <div><ScanLine size={30} /></div>
              </div>
              {scannerError && <p className="inventory-scanner-warning">{scannerError}</p>}
            </div>
          )}

          <div className="form-grid two">
            <label className="field-group">
              <span>{inputMode === 'otomatis' && !editData ? 'Kode Barang dari Scan' : 'Kode Barang'}</span>
              <div className="barcode-input-row">
                <input
                  type="text"
                  autoFocus={usbInputActive}
                  value={form.kode_barang}
                  onChange={e => setForm({ ...form, kode_barang: e.target.value })}
                  maxLength={50}
                  onKeyDown={e => {
                    if (e.key === 'Enter' && form.kode_barang.trim()) {
                      e.preventDefault()
                      playScanBeep()
                      toast.success(`Kode barang masuk: ${form.kode_barang}`)
                    }
                  }}
                  required
                />
                {!editData && (
                  <button type="button" onClick={() => { setUsbInputActive(true); toast.success('Scanner USB aktif. Klik/scan pada kolom kode barang.') }}>
                    <Barcode size={18} />
                    <span>Scanner USB</span>
                  </button>
                )}
              </div>
              {usbInputActive && <p className="scanner-help-text">Scanner USB aktif. Arahkan alat ke barcode, kode akan masuk ke kolom ini.</p>}
            </label>
            <label className="field-group">
              <span>Kategori</span>
              <select value={form.kategori} onChange={e => setForm({ ...form, kategori: e.target.value })} required>
                <option value="">Pilih kategori</option>
                <option>HP</option>
                <option>Aksesoris</option>
                <option>Kartu</option>
                <option>Lainnya</option>
              </select>
            </label>
          </div>

          <label className="field-group">
            <span>Nama Barang</span>
            <input type="text" maxLength={150} value={form.nama_barang} onChange={e => setForm({ ...form, nama_barang: e.target.value })} required />
          </label>

          <div className="form-grid three">
            <label className="field-group">
              <span>Supplier Utama (opsional)</span>
              <select value={form.supplier_id} onChange={e => setForm({ ...form, supplier_id: e.target.value })}>
                <option value="">Belum ditentukan</option>
                {suppliers.map(supplier => <option key={supplier.id} value={supplier.id}>{supplier.nama_supplier}</option>)}
              </select>
            </label>
            <label className="field-group">
              <span>Stok</span>
              <input type="number" value={form.stok} onChange={e => setForm({ ...form, stok: e.target.value })} required />
            </label>
            <label className="field-group">
              <span>Harga Beli</span>
              <input type="text" inputMode="numeric" value={formatNominalInput(form.harga_beli)} onChange={e => setForm({ ...form, harga_beli: cleanNominalInput(e.target.value) })} required />
            </label>
            <label className="field-group">
              <span>Harga Jual</span>
              <input type="text" inputMode="numeric" value={formatNominalInput(form.harga_jual)} onChange={e => setForm({ ...form, harga_jual: cleanNominalInput(e.target.value) })} required />
            </label>
          </div>

          <div className="form-actions">
            <button type="button" onClick={closeModal} className="secondary-button">Batal</button>
            <button type="submit" className="primary-action-button">Simpan Barang</button>
          </div>
        </form>
      </Modal>

      <Modal isOpen={Boolean(deleteTarget)} onClose={() => !deleteLoading && setDeleteTarget(null)} title="Hapus Barang" size="sm">
        {deleteTarget && (
          <div className="confirm-action-content">
            <div className="form-alert error">
              Hapus barang "{deleteTarget.nama_barang}"? Aksi ini tidak bisa dibatalkan.
            </div>
            <div className="modal-form-actions">
              <button type="button" className="secondary-button" onClick={() => setDeleteTarget(null)} disabled={deleteLoading}>Batal</button>
              <button type="button" className="ui-button danger" onClick={handleDelete} disabled={deleteLoading}>
                {deleteLoading ? 'Menghapus...' : 'Hapus Barang'}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
