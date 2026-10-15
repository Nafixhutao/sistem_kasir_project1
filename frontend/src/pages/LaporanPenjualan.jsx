import React, { useState, useEffect, useMemo } from 'react'
import { FileText, CalendarDays, WalletCards, ReceiptText, TrendingUp, Printer, FileSpreadsheet, RotateCcw, Search, XCircle, Eye } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'
import Modal from '../components/Common/Modal'
import Button from '../components/ui/Button'
import Textarea from '../components/ui/Textarea'

const toInputDate = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export default function LaporanPenjualan() {
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [data, setData] = useState({ transaksi: [], total_penjualan: 0, total_transaksi: 0, chart_data: [] })
  const [customerReturns, setCustomerReturns] = useState([])
  const [startDate, setStartDate] = useState(toInputDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)))
  const [endDate, setEndDate] = useState(toInputDate(new Date()))
  const [loading, setLoading] = useState(false)
  const [reportError, setReportError] = useState('')
  const [resetting, setResetting] = useState(false)
  const [showResetConfirm, setShowResetConfirm] = useState(false)
  const [reportPrintMode, setReportPrintMode] = useState(false)
  const [search, setSearch] = useState('')
  const [paymentFilter, setPaymentFilter] = useState('semua')
  const [statusFilter, setStatusFilter] = useState('semua')
  const [selectedTransaction, setSelectedTransaction] = useState(null)
  const [cancelTarget, setCancelTarget] = useState(null)
  const [cancelReason, setCancelReason] = useState('')
  const [canceling, setCanceling] = useState(false)
  const [cancelError, setCancelError] = useState('')

  useEffect(() => { fetchLaporan() }, [startDate, endDate])

  useEffect(() => {
    document.body.classList.toggle('report-print-mode', reportPrintMode)
    return () => document.body.classList.remove('report-print-mode')
  }, [reportPrintMode])

  useEffect(() => {
    const closePrintMode = () => setReportPrintMode(false)
    window.addEventListener('afterprint', closePrintMode)
    return () => window.removeEventListener('afterprint', closePrintMode)
  }, [])

  useEffect(() => {
    document.body.classList.toggle('receipt-print-mode', Boolean(selectedTransaction))
    return () => document.body.classList.remove('receipt-print-mode')
  }, [selectedTransaction])

  const fetchLaporan = async () => {
    setLoading(true)
    setReportError('')
    try {
      const salesRes = await api.get(`/laporan/penjualan?start_date=${startDate}&end_date=${endDate}`)
      setData(salesRes.data)

      try {
        const returnRes = await api.get(`/laporan/retur-pelanggan?start_date=2000-01-01&end_date=${toInputDate(new Date())}`)
        setCustomerReturns(Array.isArray(returnRes.data.retur) ? returnRes.data.retur : [])
      } catch (returnError) {
        setCustomerReturns([])
        toast.error(getApiErrorMessage(returnError, 'Gagal memuat penanda retur pelanggan'))
      }
    } catch (e) {
      const message = getApiErrorMessage(e, 'Gagal memuat laporan penjualan')
      setReportError(message)
      toast.error(message)
    } finally {
      setLoading(false)
    }
  }

  const formatDateTime = (value) => {
    return new Intl.DateTimeFormat('id-ID', {
      weekday: 'long',
      day: '2-digit',
      month: 'long',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    }).format(new Date(value)).replace(/\./g, ':')
  }

  const escapeExcel = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

  const printReport = () => {
    if (data.transaksi.length === 0) return toast.error('Tidak ada transaksi untuk dicetak')
    setReportPrintMode(true)
    window.setTimeout(() => {
      window.print()
      window.setTimeout(() => setReportPrintMode(false), 2000)
    }, 80)
  }

  const exportExcel = () => {
    if (filteredTransactions.length === 0) return toast.error('Tidak ada transaksi untuk diexport')

    const formatItems = (transaction) => transaction.details?.map(item => `${item.nama_barang} (${item.kode_barang}) - ${item.jumlah} x ${formatRupiah(item.harga_satuan)}`).join('; ') || '-'

    const rows = filteredTransactions.map(transaction => {
      const items = formatItems(transaction)
      return `
        <tr>
          <td>${escapeExcel(transaction.kode_transaksi)}</td>
          <td>${escapeExcel(formatDateTime(transaction.created_at || transaction.tanggal))}</td>
          <td>${escapeExcel(transaction.metode_pembayaran?.toUpperCase())}</td>
          <td>${escapeExcel(transaction.status)}</td>
          <td>${escapeExcel(transaction.has_customer_return ? 'Ada Retur' : '-')}</td>
          <td>${Number(transaction.nilai_retur || 0)}</td>
          <td>${escapeExcel(items)}</td>
          <td>${Number(transaction.total_harga)}</td>
        </tr>
      `
    }).join('')

    const html = `
      <html>
        <head><meta charset="UTF-8"></head>
        <body>
          <table border="1">
            <tr><th colspan="8">Laporan Penjualan Sultan Cell</th></tr>
            <tr><td colspan="8">Periode: ${escapeExcel(startDate)} sampai ${escapeExcel(endDate)}</td></tr>
            <tr><td colspan="8">Dicetak: ${escapeExcel(formatDateTime(new Date()))}</td></tr>
            <tr>
              <th>Kode Transaksi</th>
              <th>Waktu</th>
              <th>Pembayaran</th>
              <th>Status</th>
              <th>Penanda Retur</th>
              <th>Nilai Retur</th>
              <th>Detail Barang</th>
              <th>Total</th>
            </tr>
            ${rows}
            <tr>
              <td colspan="7"><strong>Penjualan Kotor</strong></td>
              <td><strong>${Number(filteredTotal)}</strong></td>
            </tr>
            <tr>
              <td colspan="7"><strong>Retur Pelanggan</strong></td>
              <td><strong>${Number(filteredReturnValue)}</strong></td>
            </tr>
            <tr>
              <td colspan="7"><strong>Penjualan Bersih</strong></td>
              <td><strong>${Number(filteredNetTotal)}</strong></td>
            </tr>
          </table>
        </body>
      </html>
    `

    const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `laporan-penjualan-${startDate}-${endDate}.xls`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    toast.success('Laporan Excel berhasil dibuat')
  }

  const resetReport = async () => {
    if (data.transaksi.length === 0) return toast.error('Tidak ada transaksi untuk direset')
    setShowResetConfirm(true)
  }

  const submitResetReport = async () => {
    setResetting(true)
    try {
      const res = await api.delete('/laporan/penjualan/reset', {
        data: { start_date: startDate, end_date: endDate }
      })
      toast.success(`${res.data.deleted || 0} transaksi berhasil direset`)
      setShowResetConfirm(false)
      fetchLaporan()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal reset laporan'))
    } finally {
      setResetting(false)
    }
  }

  const openCancelDialog = (transaction) => {
    if (transaction.status === 'batal') return toast.error('Transaksi sudah batal')
    setCancelTarget(transaction)
    setCancelReason('')
    setCancelError('')
  }

  const closeCancelDialog = () => {
    if (canceling) return
    setCancelTarget(null)
    setCancelReason('')
    setCancelError('')
  }

  const submitCancelTransaction = async (event) => {
    event.preventDefault()
    if (!cancelTarget) return
    if (!cancelReason.trim()) {
      setCancelError('Alasan pembatalan wajib diisi.')
      return
    }

    setCanceling(true)
    try {
      await api.post(`/penjualan/${cancelTarget.id}/cancel`, { alasan: cancelReason.trim() })
      toast.success('Transaksi dibatalkan dan stok dikembalikan')
      setCancelTarget(null)
      setCancelReason('')
      setCancelError('')
      fetchLaporan()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal membatalkan transaksi'))
    } finally {
      setCanceling(false)
    }
  }

  const printTransactionReceipt = () => {
    window.setTimeout(() => window.print(), 80)
  }

  const returnedByDetail = useMemo(() => {
    return customerReturns.reduce((map, item) => {
      const key = String(item.detail_penjualan_id || '')
      if (!key) return map
      map[key] = (map[key] || 0) + Number(item.jumlah_retur || 0)
      return map
    }, {})
  }, [customerReturns])

  const annotatedTransactions = useMemo(() => {
    return data.transaksi.map(transaction => {
      let nilaiRetur = 0
      const details = (transaction.details || []).map(detail => {
        const jumlahSudahRetur = returnedByDetail[String(detail.id)] || 0
        const nilaiReturDetail = jumlahSudahRetur * Number(detail.harga_satuan || 0)
        nilaiRetur += nilaiReturDetail
        return {
          ...detail,
          jumlah_sudah_retur: jumlahSudahRetur,
          nilai_retur: nilaiReturDetail,
        }
      })

      return {
        ...transaction,
        details,
        has_customer_return: details.some(detail => detail.jumlah_sudah_retur > 0),
        nilai_retur: nilaiRetur,
      }
    })
  }, [data.transaksi, returnedByDetail])

  const filteredTransactions = annotatedTransactions.filter(transaction => {
    const itemText = transaction.details?.map(item => `${item.nama_barang} ${item.kode_barang}`).join(' ') || ''
    const keyword = `${transaction.kode_transaksi} ${itemText}`.toLowerCase()
    const matchSearch = keyword.includes(search.toLowerCase())
    const matchPayment = paymentFilter === 'semua' || transaction.metode_pembayaran === paymentFilter
    const matchStatus = statusFilter === 'semua' || transaction.status === statusFilter
    return matchSearch && matchPayment && matchStatus
  })
  const activeTransactions = filteredTransactions.filter(t => t.status === 'lunas')
  const filteredTotal = activeTransactions.reduce((sum, t) => sum + Number(t.total_harga || 0), 0)
  const filteredReturnValue = activeTransactions.reduce((sum, t) => sum + Number(t.nilai_retur || 0), 0)
  const filteredNetTotal = Math.max(filteredTotal - filteredReturnValue, 0)
  const transactionsWithReturn = filteredTransactions.filter(t => t.has_customer_return).length
  const getStatusClass = (status) => status === 'lunas' ? 'paid' : status === 'batal' ? 'cancelled' : 'pending'
  const getTransactionItems = (transaction) => transaction.details?.length
    ? transaction.details.map(item => `${item.nama_barang} (${item.jumlah}x)`).join(', ')
    : '-'

  return (
    <div className="report-page">
      <div className="report-print-area">
      <div className="page-heading report-heading">
        <div>
          <span>Laporan Kasir</span>
          <h1>Laporan Penjualan</h1>
          <p>Pantau transaksi penjualan dalam satu halaman laporan.</p>
        </div>
        <div className="report-heading-actions">
          <div className="report-date-range">
            <CalendarDays size={18} />
            <span>{startDate} sampai {endDate}</span>
          </div>
          <div className="report-action-buttons no-print">
            <button type="button" onClick={printReport} className="report-action-button print">
              <Printer size={17} />
              <span>PDF / Print</span>
            </button>
            <button type="button" onClick={exportExcel} className="report-action-button excel">
              <FileSpreadsheet size={17} />
              <span>Excel</span>
            </button>
            {canWrite && <button type="button" onClick={resetReport} disabled={resetting || loading} className="report-action-button reset">
              <RotateCcw size={17} />
              <span>{resetting ? 'Reset...' : 'Reset'}</span>
            </button>}
          </div>
        </div>
      </div>

      <section className="report-filter-panel">
        <label className="field-group">
          <span>Tanggal Mulai</span>
          <input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} />
        </label>
        <label className="field-group">
          <span>Tanggal Akhir</span>
          <input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} />
        </label>
        <label className="field-group">
          <span>Cari Transaksi / Barang</span>
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Kode transaksi atau nama barang" />
        </label>
        <label className="field-group">
          <span>Metode Pembayaran</span>
          <select value={paymentFilter} onChange={e => setPaymentFilter(e.target.value)}><option value="semua">Semua</option><option value="tunai">Tunai</option><option value="qris">QRIS</option></select>
        </label>
        <label className="field-group">
          <span>Status</span>
          <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)}><option value="semua">Semua</option><option value="lunas">Lunas</option><option value="pending">Pending</option><option value="batal">Batal</option></select>
        </label>
        <div className="field-group">
          <span>&nbsp;</span>
          <button type="button" className="report-action-button print"><Search size={17} />Filter Aktif</button>
        </div>
      </section>

      <div className="report-summary-grid">
        <div className="report-card primary">
          <div><TrendingUp size={24} /></div>
          <span>Penjualan Kotor</span>
          <strong>{formatRupiah(filteredTotal)}</strong>
        </div>
        <div className="report-card">
          <div><RotateCcw size={24} /></div>
          <span>Retur Pelanggan</span>
          <strong>{formatRupiah(filteredReturnValue)}</strong>
        </div>
        <div className="report-card">
          <div><WalletCards size={24} /></div>
          <span>Penjualan Bersih</span>
          <strong>{formatRupiah(filteredNetTotal)}</strong>
        </div>
        <div className="report-card">
          <div><FileText size={24} /></div>
          <span>Transaksi Ada Retur</span>
          <strong>{transactionsWithReturn}</strong>
        </div>
      </div>

      <section className="report-table-panel">
        <div className="report-table-header">
          <div>
            <h2>Riwayat Transaksi</h2>
            <p>{loading ? 'Memuat data...' : `${filteredTransactions.length} transaksi ditemukan`}</p>
          </div>
        </div>

        <div className="report-table-wrap">
          <table className="report-table">
            <thead>
              <tr>
                <th>Kode</th>
                <th>Nama Barang</th>
                <th>Tanggal</th>
                <th>Pembayaran</th>
                <th>Status</th>
                <th>Retur</th>
                <th className="money-header">Nilai Retur</th>
                <th className="money-header">Total</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {filteredTransactions.map(transaction => (
                <tr key={transaction.id}>
                  <td><span className="item-code">{transaction.kode_transaksi}</span></td>
                  <td>
                    <div className="report-item-list">
                      <strong>{getTransactionItems(transaction)}</strong>
                      <span>{transaction.details?.length || 0} item terjual</span>
                    </div>
                  </td>
                  <td>{new Date(transaction.tanggal).toLocaleDateString('id-ID')}</td>
                  <td><span className={`payment-pill ${transaction.metode_pembayaran}`}>{transaction.metode_pembayaran}</span></td>
                  <td><span className={`status-pill ${getStatusClass(transaction.status)}`}>{transaction.status}</span></td>
                  <td>{transaction.has_customer_return ? <span className="status-pill pending">Ada Retur</span> : '-'}</td>
                  <td className="money-cell">{transaction.nilai_retur ? formatRupiah(transaction.nilai_retur) : '-'}</td>
                  <td className="money-cell strong">{formatRupiah(transaction.total_harga)}</td>
                  <td>
                    <div className="table-actions">
                      <button type="button" className="table-action-button" onClick={() => setSelectedTransaction(transaction)}><Eye size={15} />Detail</button>
                      {canWrite && <button type="button" className="table-action-button danger" onClick={() => openCancelDialog(transaction)} disabled={transaction.status === 'batal'}><XCircle size={15} />Batal</button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {loading && (
            <div className="report-empty">
              <ReceiptText size={42} />
              <p>Memuat riwayat transaksi</p>
              <span>Sistem sedang mengambil data penjualan dari database.</span>
            </div>
          )}

          {!loading && reportError && (
            <div className="report-empty error">
              <XCircle size={42} />
              <p>Riwayat transaksi gagal dimuat</p>
              <span>{reportError}</span>
            </div>
          )}

          {!loading && !reportError && filteredTransactions.length === 0 && (
            <div className="report-empty">
              <FileText size={42} />
              <p>Tidak ada data penjualan</p>
              <span>Coba ubah periode tanggal, filter pembayaran/status, atau buat transaksi baru.</span>
            </div>
          )}
        </div>
      </section>
      </div>

      <Modal isOpen={Boolean(selectedTransaction)} onClose={() => setSelectedTransaction(null)} title="Detail Transaksi" size="sm">
        {selectedTransaction && (
          <div className="receipt-preview">
            <div className="receipt-print-area">
              <div className="receipt-store">
                <strong>SULTAN CELL</strong>
                <span>Konter HP & Agen BRILink</span>
                <em>Struk Transaksi Penjualan</em>
              </div>
              <div className="receipt-divider"></div>
              <div className="receipt-meta">
                <div><span>No</span><strong>{selectedTransaction.kode_transaksi}</strong></div>
                <div><span>Waktu</span><strong>{formatDateTime(selectedTransaction.created_at || selectedTransaction.tanggal)}</strong></div>
                <div><span>Kasir</span><strong>{selectedTransaction.kasir || '-'}</strong></div>
                <div><span>Status</span><strong>{selectedTransaction.status}</strong></div>
                <div><span>Bayar</span><strong>{selectedTransaction.metode_pembayaran?.toUpperCase()}</strong></div>
                <div><span>Retur</span><strong>{selectedTransaction.has_customer_return ? 'Ada Retur' : '-'}</strong></div>
              </div>
              <div className="receipt-divider"></div>
              <div className="receipt-section-label">Daftar Barang</div>
              <div className="receipt-items">
                {selectedTransaction.details?.map(item => (
                  <div key={item.id} className="receipt-item">
                    <div>
                      <strong>{item.nama_barang}</strong>
                      <span>{item.kode_barang}</span>
                    </div>
                    <p>{item.jumlah} x {formatRupiah(item.harga_satuan)}</p>
                    <b>{formatRupiah(item.subtotal)}</b>
                  </div>
                ))}
              </div>
              <div className="receipt-divider"></div>
              <div className="receipt-total-row receipt-grand-total"><span>Total</span><strong>{formatRupiah(selectedTransaction.total_harga)}</strong></div>
              {selectedTransaction.has_customer_return && (
                <>
                  <div className="receipt-total-row"><span>Retur</span><strong>{formatRupiah(selectedTransaction.nilai_retur)}</strong></div>
                  <div className="receipt-total-row"><span>Bersih</span><strong>{formatRupiah(Math.max(Number(selectedTransaction.total_harga || 0) - Number(selectedTransaction.nilai_retur || 0), 0))}</strong></div>
                </>
              )}
              <div className="receipt-total-row"><span>Bayar</span><strong>{formatRupiah(selectedTransaction.uang_bayar || selectedTransaction.total_harga)}</strong></div>
              <div className="receipt-total-row"><span>Kembali</span><strong>{formatRupiah(selectedTransaction.kembalian || 0)}</strong></div>
              {selectedTransaction.alasan_batal && <div className="receipt-note">Batal: {selectedTransaction.alasan_batal}</div>}
              <div className="receipt-footer">
                <p>Terima kasih sudah berbelanja</p>
                <span>Simpan struk ini sebagai bukti transaksi.</span>
              </div>
            </div>
            <div className="receipt-actions no-print">
              <button type="button" className="secondary-button" onClick={() => setSelectedTransaction(null)}>Tutup</button>
              <button type="button" className="print-receipt-button" onClick={printTransactionReceipt}>
                <Printer size={18} />
                <span>Cetak Struk</span>
              </button>
            </div>
          </div>
        )}
      </Modal>

      <Modal isOpen={Boolean(cancelTarget)} onClose={closeCancelDialog} title="Batalkan Transaksi" size="md">
        {cancelTarget && (
          <form className="cancel-transaction-form" onSubmit={submitCancelTransaction}>
            <div className="form-alert warning">
              Stok barang dari transaksi {cancelTarget.kode_transaksi} akan dikembalikan otomatis setelah pembatalan disimpan.
            </div>
            <label className="field-group">
              <span>Alasan Pembatalan</span>
              <Textarea
                rows={4}
                value={cancelReason}
                onChange={event => {
                  setCancelReason(event.target.value)
                  setCancelError('')
                }}
                placeholder="Contoh: pelanggan membatalkan pembelian"
              />
              {cancelError && <small className="field-error">{cancelError}</small>}
            </label>
            <div className="modal-form-actions">
              <Button type="button" variant="secondary" onClick={closeCancelDialog} disabled={canceling}>Batal</Button>
              <Button type="submit" className="danger" disabled={canceling}>{canceling ? 'Memproses...' : 'Batalkan Transaksi'}</Button>
            </div>
          </form>
        )}
      </Modal>

      <Modal isOpen={showResetConfirm} onClose={() => !resetting && setShowResetConfirm(false)} title="Reset Laporan Penjualan" size="sm">
        <div className="confirm-action-content">
          <div className="form-alert warning">
            Reset {data.transaksi.length} transaksi penjualan dari {startDate} sampai {endDate}? Data transaksi dan detail barang akan dihapus dari laporan.
          </div>
          <div className="modal-form-actions">
            <Button type="button" variant="secondary" onClick={() => setShowResetConfirm(false)} disabled={resetting}>Batal</Button>
            <Button type="button" className="danger" onClick={submitResetReport} disabled={resetting}>{resetting ? 'Memproses...' : 'Reset Laporan'}</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
