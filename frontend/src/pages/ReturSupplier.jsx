import React, { useEffect, useMemo, useState } from 'react'
import { ClipboardList, Eye, FileSpreadsheet, PackageCheck, Printer, RotateCcw, Search, Trash2, Undo2 } from 'lucide-react'
import toast from 'react-hot-toast'
import Modal from '../components/Common/Modal'
import api, { getApiErrorMessage } from '../services/api'
import { useBusinessRequest } from '../hooks/useBusinessRequest'

const toInputDate = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const today = toInputDate(new Date())
const firstDayThisMonth = toInputDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1))

const emptyForm = {
  tanggal_retur: today,
  nama_supplier: '',
  barang_id: '',
  jumlah_retur: '',
  alasan_retur: '',
  keterangan: '',
}

const statusLabels = {
  diproses: 'Diproses',
  diterima: 'Diterima',
  ditolak: 'Ditolak',
}

const statusClasses = {
  diproses: 'pending',
  diterima: 'paid',
  ditolak: 'cancelled',
}

const finalReturStatuses = ['diterima', 'ditolak']

export default function ReturSupplier() {
  const businessRequest = useBusinessRequest()
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [data, setData] = useState([])
  const [barang, setBarang] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [actionLoading, setActionLoading] = useState(false)
  const [selectedRetur, setSelectedRetur] = useState(null)
  const [confirmAction, setConfirmAction] = useState(null)
  const [reportPrintMode, setReportPrintMode] = useState(false)
  const [filters, setFilters] = useState({
    start_date: firstDayThisMonth,
    end_date: today,
    supplier: '',
    status: 'semua',
    search: '',
  })

  useEffect(() => {
    fetchBarang()
    fetchData()
  }, [])

  useEffect(() => {
    document.body.classList.toggle('report-print-mode', reportPrintMode)
    return () => document.body.classList.remove('report-print-mode')
  }, [reportPrintMode])

  const fetchBarang = async () => {
    try {
      const res = await api.get('/barang')
      setBarang(Array.isArray(res.data) ? res.data : [])
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat data barang'))
    }
  }

  const buildQuery = () => {
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params.append(key, value)
    })
    return params.toString()
  }

  const fetchData = async () => {
    try {
      const res = await api.get(`/retur-supplier?${buildQuery()}`)
      setData(Array.isArray(res.data) ? res.data : [])
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat retur supplier'))
    }
  }

  const selectedBarang = useMemo(
    () => barang.find(item => String(item.id) === String(form.barang_id)),
    [barang, form.barang_id]
  )

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (businessRequest.isBusy()) return
    setLoading(true)
    try {
      const result = await businessRequest.submit('/retur-supplier', {
        ...form,
        jumlah_retur: Number(form.jumlah_retur),
      })
      if (!result) return
      toast.success('Retur supplier berhasil dibuat')
      setShowForm(false)
      setForm(emptyForm)
      fetchData()
      fetchBarang()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan retur supplier'))
    } finally {
      setLoading(false)
    }
  }

  const requestStatusUpdate = (item, nextStatus) => {
    if (nextStatus === item.status_retur) return
    if (finalReturStatuses.includes(item.status_retur)) {
      toast.error('Status retur sudah final dan tidak dapat diubah.')
      return
    }
    setConfirmAction({
      type: 'status',
      item,
      nextStatus,
      title: 'Ubah Status Retur',
      message: `Ubah status retur ${item.nomor_retur} menjadi ${statusLabels[nextStatus]}?`,
      confirmLabel: 'Ubah Status',
    })
  }

  const updateStatus = async (item, nextStatus) => {
    try {
      await api.put(`/retur-supplier/${item.id}`, {
        tanggal_retur: item.tanggal_retur,
        nama_supplier: item.nama_supplier,
        barang_id: item.barang_id,
        jumlah_retur: item.jumlah_retur,
        alasan_retur: item.alasan_retur,
        status_retur: nextStatus,
        keterangan: item.keterangan || '',
      })
      toast.success('Status retur berhasil diperbarui')
      fetchData()
      fetchBarang()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memperbarui status retur'))
    }
  }

  const requestDeleteRetur = (item) => {
    setConfirmAction({
      type: 'delete',
      item,
      title: 'Hapus Retur Supplier',
      message: `Hapus retur ${item.nomor_retur}? Jika stok pernah dikurangi, stok akan dikembalikan.`,
      confirmLabel: 'Hapus Retur',
      danger: true,
    })
  }

  const deleteRetur = async (item) => {
    try {
      await api.delete(`/retur-supplier/${item.id}`)
      toast.success('Retur supplier berhasil dihapus')
      fetchData()
      fetchBarang()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menghapus retur supplier'))
    }
  }

  const closeConfirmAction = () => {
    if (actionLoading) return
    setConfirmAction(null)
  }

  const submitConfirmAction = async () => {
    if (!confirmAction) return
    setActionLoading(true)
    try {
      if (confirmAction.type === 'status') {
        await updateStatus(confirmAction.item, confirmAction.nextStatus)
      } else if (confirmAction.type === 'delete') {
        await deleteRetur(confirmAction.item)
      }
      setConfirmAction(null)
    } finally {
      setActionLoading(false)
    }
  }

  const printReport = () => {
    if (data.length === 0) return toast.error('Tidak ada retur supplier untuk dicetak')
    setReportPrintMode(true)
    window.setTimeout(() => {
      window.print()
      window.setTimeout(() => setReportPrintMode(false), 1200)
    }, 80)
  }

  const escapeExcel = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

  const exportExcel = () => {
    if (data.length === 0) return toast.error('Tidak ada retur supplier untuk diexport')
    const rows = data.map(item => `
      <tr>
        <td>${escapeExcel(item.nomor_retur)}</td>
        <td>${escapeExcel(new Date(item.tanggal_retur).toLocaleDateString('id-ID'))}</td>
        <td>${escapeExcel(item.nama_supplier)}</td>
        <td>${escapeExcel(item.kode_barang)}</td>
        <td>${escapeExcel(item.nama_barang)}</td>
        <td>${Number(item.jumlah_retur || 0)}</td>
        <td>${escapeExcel(statusLabels[item.status_retur] || item.status_retur)}</td>
        <td>${escapeExcel(item.alasan_retur)}</td>
        <td>${escapeExcel(item.keterangan || '-')}</td>
      </tr>
    `).join('')
    const html = `<table border="1"><tr><th colspan="9">Retur Supplier Sultan Cell</th></tr><tr><td colspan="9">${escapeExcel(filters.start_date)} sampai ${escapeExcel(filters.end_date)}</td></tr><tr><th>Nomor</th><th>Tanggal</th><th>Supplier</th><th>Kode</th><th>Barang</th><th>Jumlah</th><th>Status</th><th>Alasan</th><th>Keterangan</th></tr>${rows}</table>`
    const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `retur-supplier-${filters.start_date}-${filters.end_date}.xls`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    toast.success('Excel retur supplier berhasil dibuat')
  }

  const totalJumlah = data.reduce((sum, item) => sum + Number(item.jumlah_retur || 0), 0)
  const totalDiterima = data.filter(item => item.status_retur === 'diterima').length
  const totalDiproses = data.filter(item => item.status_retur === 'diproses').length
  const isFinalRetur = (status) => finalReturStatuses.includes(status)

  return (
    <div className="brilink-page retur-supplier-page">
      <div className="report-print-area">
        <div className="page-heading brilink-heading">
          <div>
            <span>Operasional Toko</span>
            <h1>Retur Supplier</h1>
            <p>Catat barang yang dikembalikan ke supplier dan kurangi stok hanya saat retur diterima.</p>
          </div>
          <div className="report-heading-actions">
            <div className="report-date-range"><Undo2 size={18} /><span>{filters.start_date} sampai {filters.end_date}</span></div>
            <div className="report-action-buttons no-print">
              <button type="button" onClick={printReport} className="report-action-button print"><Printer size={17} />PDF / Print</button>
              <button type="button" onClick={exportExcel} className="report-action-button excel"><FileSpreadsheet size={17} />Excel</button>
              {canWrite && <button type="button" onClick={() => setShowForm(!showForm)} className="report-action-button primary"><RotateCcw size={17} />Retur Baru</button>}
            </div>
          </div>
        </div>

        <section className="brilink-filter-panel retur-supplier-filter-panel no-print">
          <label className="field-group"><span>Tanggal Mulai</span><input type="date" value={filters.start_date} onChange={e => setFilters({ ...filters, start_date: e.target.value })} /></label>
          <label className="field-group"><span>Tanggal Akhir</span><input type="date" value={filters.end_date} onChange={e => setFilters({ ...filters, end_date: e.target.value })} /></label>
          <label className="field-group"><span>Status</span><select value={filters.status} onChange={e => setFilters({ ...filters, status: e.target.value })}><option value="semua">Semua</option><option value="diproses">Diproses</option><option value="diterima">Diterima</option><option value="ditolak">Ditolak</option></select></label>
          <label className="field-group"><span>Supplier</span><input maxLength={150} value={filters.supplier} onChange={e => setFilters({ ...filters, supplier: e.target.value })} placeholder="Nama supplier" /></label>
          <label className="field-group"><span>Cari</span><input maxLength={150} value={filters.search} onChange={e => setFilters({ ...filters, search: e.target.value })} onKeyDown={e => e.key === 'Enter' && fetchData()} placeholder="Nomor, kode, nama barang" /></label>
          <button type="button" onClick={fetchData} className="filter-search-button"><Search size={17} />Cari</button>
        </section>

        <div className="brilink-summary-grid retur-supplier-summary-grid">
          <div className="brilink-summary-card"><div className="summary-icon blue"><ClipboardList size={22} /></div><span>Total Retur</span><strong>{data.length}</strong></div>
          <div className="brilink-summary-card"><div className="summary-icon green"><PackageCheck size={22} /></div><span>Diterima</span><strong>{totalDiterima}</strong></div>
          <div className="brilink-summary-card"><div className="summary-icon amber"><RotateCcw size={22} /></div><span>Diproses</span><strong>{totalDiproses}</strong></div>
          <div className="brilink-summary-card"><div className="summary-icon red"><Undo2 size={22} /></div><span>Total Barang Retur</span><strong>{totalJumlah} pcs</strong></div>
        </div>

        {canWrite && showForm && (
          <section className="brilink-form-card no-print">
            <div className="brilink-section-title"><RotateCcw size={20} /><div><h2>Form Retur Supplier</h2><p>Status awal selalu diproses. Stok baru dikurangi setelah status diubah menjadi diterima.</p></div></div>
            <form onSubmit={handleSubmit} className="brilink-form-grid">
              <label className="field-group"><span>Tanggal Retur</span><input type="date" value={form.tanggal_retur} onChange={e => setForm({ ...form, tanggal_retur: e.target.value })} required /></label>
              <label className="field-group"><span>Nama Supplier</span><input maxLength={150} value={form.nama_supplier} onChange={e => setForm({ ...form, nama_supplier: e.target.value })} required placeholder="Contoh: Supplier Aksesoris" /></label>
              <label className="field-group"><span>Barang</span><select value={form.barang_id} onChange={e => setForm({ ...form, barang_id: e.target.value })} required><option value="">Pilih barang</option>{barang.map(item => <option key={item.id} value={item.id}>{item.kode_barang} - {item.nama_barang} (stok {item.stok})</option>)}</select></label>
              <label className="field-group"><span>Stok Saat Ini</span><input value={selectedBarang ? `${selectedBarang.stok} pcs` : '-'} readOnly /></label>
              <label className="field-group"><span>Jumlah Retur</span><input type="number" min="1" value={form.jumlah_retur} onChange={e => setForm({ ...form, jumlah_retur: e.target.value })} required /></label>
              <label className="field-group"><span>Alasan Retur</span><input value={form.alasan_retur} onChange={e => setForm({ ...form, alasan_retur: e.target.value })} required placeholder="Contoh: Barang rusak" /></label>
              <label className="field-group full"><span>Keterangan</span><input value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} placeholder="Catatan tambahan" /></label>
              <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button">{loading ? 'Memproses...' : 'Simpan Retur'}</button></div>
            </form>
          </section>
        )}

        <section className="brilink-table-panel">
          <div className="brilink-table-header"><div><h2>Daftar Retur Supplier</h2><p>{data.length} data ditampilkan</p></div><RotateCcw size={20} /></div>
          <div className="brilink-table-wrap">
            <table className="brilink-table retur-supplier-table">
              <thead><tr><th>Nomor</th><th>Tanggal</th><th>Supplier</th><th>Barang</th><th>Jumlah</th><th>Status</th><th>Keterangan</th><th className="no-print">Aksi</th></tr></thead>
              <tbody>{data.map(item => (
                <tr key={item.id}>
                  <td><span className="item-code">{item.nomor_retur}</span></td>
                  <td>{new Date(item.tanggal_retur).toLocaleDateString('id-ID')}</td>
                  <td><strong>{item.nama_supplier}</strong></td>
                  <td><div className="person-cell"><strong>{item.nama_barang}</strong><span>{item.kode_barang}</span></div></td>
                  <td>{item.jumlah_retur} pcs</td>
                  <td>
                    {!canWrite || isFinalRetur(item.status_retur) ? (
                      <div className="retur-status-final">
                        <span className={`status-pill ${statusClasses[item.status_retur] || 'pending'}`}>{statusLabels[item.status_retur] || item.status_retur}</span>
                        <span className="final-badge">Final</span>
                      </div>
                    ) : (
                      <select className={`status-select ${statusClasses[item.status_retur] || 'pending'}`} value={item.status_retur} onChange={e => requestStatusUpdate(item, e.target.value)}>
                        <option value="diproses">Diproses</option>
                        <option value="diterima">Diterima</option>
                        <option value="ditolak">Ditolak</option>
                      </select>
                    )}
                  </td>
                  <td>{item.keterangan || item.alasan_retur}</td>
                  <td className="action-cell no-print">
                    <button type="button" onClick={() => setSelectedRetur(item)} className="small-action-button view"><Eye size={16} />Detail</button>
                    {canWrite && <button type="button" onClick={() => requestDeleteRetur(item)} className="small-action-button danger"><Trash2 size={16} />Hapus</button>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
            {data.length === 0 && <div className="brilink-empty"><RotateCcw size={42} /><p>Belum ada retur supplier</p><span>Retur baru akan muncul di sini.</span></div>}
          </div>
        </section>
      </div>

      <Modal isOpen={Boolean(selectedRetur)} onClose={() => setSelectedRetur(null)} title="Detail Retur Supplier" size="lg">
        {selectedRetur && (
          <div className="detail-grid">
            <div><span>Nomor Retur</span><strong>{selectedRetur.nomor_retur}</strong></div>
            <div><span>Tanggal</span><strong>{new Date(selectedRetur.tanggal_retur).toLocaleDateString('id-ID')}</strong></div>
            <div><span>Supplier</span><strong>{selectedRetur.nama_supplier}</strong></div>
            <div><span>Status</span><strong>{statusLabels[selectedRetur.status_retur] || selectedRetur.status_retur}{isFinalRetur(selectedRetur.status_retur) ? ' - Final' : ''}</strong></div>
            <div><span>Kode Barang</span><strong>{selectedRetur.kode_barang}</strong></div>
            <div><span>Nama Barang</span><strong>{selectedRetur.nama_barang}</strong></div>
            <div><span>Jumlah Retur</span><strong>{selectedRetur.jumlah_retur} pcs</strong></div>
            <div><span>Stok Dikurangi</span><strong>{selectedRetur.stok_dikurangi ? 'Ya' : 'Belum'}</strong></div>
            <div className="full"><span>Alasan Retur</span><strong>{selectedRetur.alasan_retur}</strong></div>
            <div className="full"><span>Keterangan</span><strong>{selectedRetur.keterangan || '-'}</strong></div>
          </div>
        )}
      </Modal>

      <Modal isOpen={Boolean(confirmAction)} onClose={closeConfirmAction} title={confirmAction?.title || 'Konfirmasi'} size="sm">
        {confirmAction && (
          <div className="confirm-action-content">
            <div className={`form-alert ${confirmAction.danger ? 'error' : 'warning'}`}>
              {confirmAction.message}
            </div>
            <div className="modal-form-actions">
              <button type="button" className="secondary-button" onClick={closeConfirmAction} disabled={actionLoading}>Batal</button>
              <button
                type="button"
                className={confirmAction.danger ? 'ui-button danger' : 'primary-action-button'}
                onClick={submitConfirmAction}
                disabled={actionLoading}
              >
                {actionLoading ? 'Memproses...' : confirmAction.confirmLabel}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
