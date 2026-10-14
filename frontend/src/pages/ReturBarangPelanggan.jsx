import React, { useEffect, useMemo, useState } from 'react'
import { ClipboardList, Eye, FileText, PackageCheck, ReceiptText, RotateCcw, Search, WalletCards, XCircle } from 'lucide-react'
import toast from 'react-hot-toast'
import Modal from '../components/Common/Modal'
import CustomerReturnModal from '../components/Sales/CustomerReturnModal'
import api, { getApiErrorMessage } from '../services/api'
import { formatRupiah } from '../utils/formatRupiah'
import { useBusinessRequest } from '../hooks/useBusinessRequest'

const toInputDate = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const today = toInputDate(new Date())
const firstDayThisMonth = toInputDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1))

const formatDateTime = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(value)).replace(/\./g, ':')
}

const formatPenyelesaian = (value) => {
  if (value === 'pengembalian_dana') return 'Pengembalian Dana'
  if (value === 'penggantian_barang') return 'Penggantian Barang'
  if (value === 'qris') return 'QRIS'
  if (value === 'tunai') return 'Tunai'
  return '-'
}

export default function ReturBarangPelanggan() {
  const businessRequest = useBusinessRequest()
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [transactions, setTransactions] = useState([])
  const [transactionLoading, setTransactionLoading] = useState(false)
  const [transactionError, setTransactionError] = useState('')
  const [selectedTransaction, setSelectedTransaction] = useState(null)
  const [returnTransaction, setReturnTransaction] = useState(null)
  const [savingReturn, setSavingReturn] = useState(false)
  const [allReturnRecords, setAllReturnRecords] = useState([])
  const [returnReport, setReturnReport] = useState({ retur: [], total_retur: 0, total_barang_retur: 0, filter_options: { alasan: [] } })
  const [returnLoading, setReturnLoading] = useState(false)
  const [filters, setFilters] = useState({
    search: '',
    status: 'semua',
  })
  const [returnFilters, setReturnFilters] = useState({
    start_date: firstDayThisMonth,
    end_date: today,
    search: '',
    alasan: 'semua',
    metode: 'semua',
  })

  useEffect(() => {
    if (canWrite) fetchTransactions()
    fetchAllReturnRecords()
    fetchReturnHistory()
  }, [])

  const buildReturnQuery = (source = returnFilters) => {
    const params = new URLSearchParams()
    Object.entries(source).forEach(([key, value]) => {
      if (value) params.append(key, value)
    })
    return params.toString()
  }

  const fetchTransactions = async () => {
    setTransactionLoading(true)
    setTransactionError('')
    try {
      const res = await api.get('/penjualan')
      setTransactions(Array.isArray(res.data) ? res.data : [])
    } catch (e) {
      const message = getApiErrorMessage(e, 'Gagal memuat transaksi penjualan')
      setTransactionError(message)
      toast.error(message)
    } finally {
      setTransactionLoading(false)
    }
  }

  const fetchAllReturnRecords = async () => {
    try {
      const res = await api.get(`/laporan/retur-pelanggan?start_date=2000-01-01&end_date=${today}`)
      setAllReturnRecords(Array.isArray(res.data.retur) ? res.data.retur : [])
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat penanda retur pelanggan'))
    }
  }

  const fetchReturnHistory = async () => {
    setReturnLoading(true)
    try {
      const res = await api.get(`/laporan/retur-pelanggan?${buildReturnQuery()}`)
      setReturnReport({
        retur: Array.isArray(res.data.retur) ? res.data.retur : [],
        total_retur: res.data.total_retur || 0,
        total_barang_retur: res.data.total_barang_retur || 0,
        filter_options: res.data.filter_options || { alasan: [] },
      })
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat riwayat retur pelanggan'))
    } finally {
      setReturnLoading(false)
    }
  }

  const returnedByDetail = useMemo(() => {
    return allReturnRecords.reduce((map, item) => {
      const key = String(item.detail_penjualan_id || '')
      if (!key) return map
      map[key] = (map[key] || 0) + Number(item.jumlah_retur || 0)
      return map
    }, {})
  }, [allReturnRecords])

  const annotatedTransactions = useMemo(() => {
    return transactions.map(transaction => {
      const details = (transaction.details || []).map(detail => {
        const jumlahSudahRetur = returnedByDetail[String(detail.id)] || 0
        return {
          ...detail,
          jumlah_sudah_retur: jumlahSudahRetur,
          jumlah_bisa_retur: Math.max(Number(detail.jumlah || 0) - jumlahSudahRetur, 0),
        }
      })
      return {
        ...transaction,
        details,
        has_customer_return: details.some(detail => detail.jumlah_sudah_retur > 0),
      }
    })
  }, [transactions, returnedByDetail])

  const filteredTransactions = annotatedTransactions.filter(transaction => {
    const itemText = transaction.details?.map(item => `${item.nama_barang} ${item.kode_barang}`).join(' ') || ''
    const keyword = `${transaction.kode_transaksi} ${itemText}`.toLowerCase()
    const matchSearch = keyword.includes(filters.search.toLowerCase())
    const matchStatus = filters.status === 'semua' || transaction.status === filters.status
    return matchSearch && matchStatus
  })

  const transactionsWithReturn = annotatedTransactions.filter(transaction => transaction.has_customer_return).length
  const availableReturnTransactions = filteredTransactions.filter(transaction =>
    transaction.status !== 'batal' && transaction.details?.some(item => Number(item.jumlah_bisa_retur || 0) > 0)
  ).length
  const totalReturnRefund = returnReport.retur.filter(item => ['pengembalian_dana', 'tunai', 'qris'].includes(item.metode_pengembalian_dana)).length
  const totalReturnReplacement = returnReport.retur.filter(item => item.metode_pengembalian_dana === 'penggantian_barang').length

  const getTransactionItems = (transaction) => transaction.details?.length
    ? transaction.details.map(item => `${item.nama_barang} (${item.jumlah}x)`).join(', ')
    : '-'

  const getStatusClass = (status) => status === 'lunas' ? 'paid' : status === 'batal' ? 'cancelled' : 'pending'

  const openReturnForm = (transaction) => {
    if (transaction.status === 'batal') return toast.error('Transaksi batal tidak bisa diretur')
    if (!transaction.details?.length) return toast.error('Detail barang tidak tersedia')
    if (!transaction.details.some(item => Number(item.jumlah_bisa_retur || 0) > 0)) {
      return toast.error('Semua barang pada transaksi ini sudah diretur')
    }
    setReturnTransaction(transaction)
  }

  const submitCustomerReturn = async (payload) => {
    if (!returnTransaction) return
    if (businessRequest.isBusy()) return
    setSavingReturn(true)
    try {
      const result = await businessRequest.submit(`/penjualan/${returnTransaction.id}/retur`, payload)
      if (!result) return
      toast.success('Retur berhasil dicatat dan stok diperbarui')
      setReturnTransaction(null)
      await Promise.all([fetchTransactions(), fetchAllReturnRecords(), fetchReturnHistory()])
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal mencatat retur'))
    } finally {
      setSavingReturn(false)
    }
  }

  return (
    <div className="brilink-page retur-supplier-page">
      <div className="page-heading brilink-heading">
        <div>
          <span>Operasional Toko</span>
          <h1>Retur Barang Pelanggan</h1>
          <p>Cari transaksi asal, pilih barang yang dibeli pelanggan, lalu catat proses retur tanpa menghapus transaksi penjualan.</p>
        </div>
        <div className="report-heading-actions">
          <div className="report-date-range"><RotateCcw size={18} /><span>{returnFilters.start_date} sampai {returnFilters.end_date}</span></div>
        </div>
      </div>

      {canWrite && <>
      <section className="brilink-filter-panel retur-supplier-filter-panel">
        <label className="field-group">
          <span>Cari Transaksi / Barang</span>
          <input value={filters.search} onChange={e => setFilters({ ...filters, search: e.target.value })} placeholder="Nomor transaksi, kode, atau nama barang" />
        </label>
        <label className="field-group">
          <span>Status Transaksi</span>
          <select value={filters.status} onChange={e => setFilters({ ...filters, status: e.target.value })}>
            <option value="semua">Semua</option>
            <option value="lunas">Lunas</option>
            <option value="pending">Pending</option>
            <option value="batal">Batal</option>
          </select>
        </label>
        <button type="button" onClick={fetchTransactions} className="filter-search-button"><Search size={17} />Cari</button>
      </section>

      <div className="brilink-summary-grid retur-supplier-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><ReceiptText size={22} /></div><span>Transaksi Ditemukan</span><strong>{filteredTransactions.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><PackageCheck size={22} /></div><span>Bisa Diretur</span><strong>{availableReturnTransactions}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><RotateCcw size={22} /></div><span>Transaksi Ada Retur</span><strong>{transactionsWithReturn}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon red"><ClipboardList size={22} /></div><span>Total Barang Retur</span><strong>{returnReport.total_barang_retur} pcs</strong></div>
      </div>

      <section className="report-table-panel">
        <div className="report-table-header">
          <div>
            <h2>Transaksi Asal Retur</h2>
            <p>{transactionLoading ? 'Memuat data...' : `${filteredTransactions.length} transaksi ditemukan`}</p>
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
                <th>Penanda Retur</th>
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
                  <td className="money-cell strong">{formatRupiah(transaction.total_harga)}</td>
                  <td>
                    <div className="table-actions">
                      <button type="button" className="table-action-button" onClick={() => setSelectedTransaction(transaction)}><Eye size={15} />Detail</button>
                      <button type="button" className="table-action-button" onClick={() => openReturnForm(transaction)} disabled={transaction.status === 'batal'}><RotateCcw size={15} />Retur</button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {transactionLoading && (
            <div className="report-empty">
              <ReceiptText size={42} />
              <p>Memuat transaksi penjualan</p>
              <span>Sistem sedang mengambil data transaksi asal retur.</span>
            </div>
          )}

          {!transactionLoading && transactionError && (
            <div className="report-empty error">
              <XCircle size={42} />
              <p>Transaksi gagal dimuat</p>
              <span>{transactionError}</span>
            </div>
          )}

          {!transactionLoading && !transactionError && filteredTransactions.length === 0 && (
            <div className="report-empty">
              <FileText size={42} />
              <p>Tidak ada transaksi</p>
              <span>Coba ubah kata kunci pencarian atau filter status transaksi.</span>
            </div>
          )}
        </div>
      </section>

      </>}
      <section className="report-filter-panel return-history-filter-panel">
        <label className="field-group">
          <span>Tanggal Mulai</span>
          <input type="date" value={returnFilters.start_date} onChange={e => setReturnFilters({ ...returnFilters, start_date: e.target.value })} />
        </label>
        <label className="field-group">
          <span>Tanggal Akhir</span>
          <input type="date" value={returnFilters.end_date} onChange={e => setReturnFilters({ ...returnFilters, end_date: e.target.value })} />
        </label>
        <label className="field-group">
          <span>Alasan Retur</span>
          <select value={returnFilters.alasan} onChange={e => setReturnFilters({ ...returnFilters, alasan: e.target.value })}>
            <option value="semua">Semua</option>
            {returnReport.filter_options.alasan?.map(option => <option key={option} value={option}>{option}</option>)}
          </select>
        </label>
        <label className="field-group">
          <span>Penyelesaian</span>
          <select value={returnFilters.metode} onChange={e => setReturnFilters({ ...returnFilters, metode: e.target.value })}>
            <option value="semua">Semua</option>
            <option value="pengembalian_dana">Pengembalian Dana</option>
            <option value="penggantian_barang">Penggantian Barang</option>
          </select>
        </label>
        <label className="field-group">
          <span>Pencarian</span>
          <input value={returnFilters.search} onChange={e => setReturnFilters({ ...returnFilters, search: e.target.value })} onKeyDown={e => e.key === 'Enter' && fetchReturnHistory()} placeholder="Nomor retur, transaksi, barang" />
        </label>
        <button type="button" onClick={fetchReturnHistory} className="filter-search-button"><Search size={17} />Cari</button>
      </section>

      <div className="brilink-summary-grid return-history-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><RotateCcw size={22} /></div><span>Total Retur</span><strong>{returnReport.total_retur}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><FileText size={22} /></div><span>Total Barang Retur</span><strong>{returnReport.total_barang_retur} pcs</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><WalletCards size={22} /></div><span>Pengembalian Dana</span><strong>{totalReturnRefund}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon purple"><PackageCheck size={22} /></div><span>Penggantian Barang</span><strong>{totalReturnReplacement}</strong></div>
      </div>

      <section className="report-table-panel">
        <div className="report-table-header">
          <div>
            <h2>Riwayat Retur Barang Pelanggan</h2>
            <p>{returnLoading ? 'Memuat data...' : `${returnReport.retur.length} retur pelanggan ditemukan`}</p>
          </div>
        </div>

        <div className="report-table-wrap">
          <table className="report-table return-history-table">
            <thead>
              <tr>
                <th>Nomor Retur</th>
                <th>Tanggal Retur</th>
                <th>Nomor Transaksi</th>
                <th>Nama Barang</th>
                <th>Jumlah Retur</th>
                <th>Alasan Retur</th>
                <th>Penyelesaian</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              {returnReport.retur.map(item => (
                <tr key={item.id}>
                  <td><span className="item-code">{item.nomor_retur}</span></td>
                  <td>{new Date(item.tanggal_retur).toLocaleDateString('id-ID')}</td>
                  <td>{item.kode_transaksi}</td>
                  <td>
                    <div className="report-item-list">
                      <strong>{item.nama_barang}</strong>
                      <span>{item.kode_barang}</span>
                    </div>
                  </td>
                  <td>{item.jumlah_retur} pcs</td>
                  <td>{item.alasan_retur}</td>
                  <td><span className={`payment-pill ${item.metode_pengembalian_dana}`}>{formatPenyelesaian(item.metode_pengembalian_dana)}</span></td>
                  <td>{item.keterangan || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {!returnLoading && returnReport.retur.length === 0 && (
            <div className="report-empty">
              <RotateCcw size={42} />
              <p>Tidak ada retur pelanggan</p>
              <span>Retur barang pelanggan yang tersimpan akan muncul di sini.</span>
            </div>
          )}
        </div>
      </section>

      <Modal isOpen={Boolean(selectedTransaction)} onClose={() => setSelectedTransaction(null)} title="Detail Transaksi" size="lg">
        {selectedTransaction && (
          <div className="detail-grid">
            <div><span>Nomor Transaksi</span><strong>{selectedTransaction.kode_transaksi}</strong></div>
            <div><span>Tanggal</span><strong>{formatDateTime(selectedTransaction.created_at || selectedTransaction.tanggal)}</strong></div>
            <div><span>Status</span><strong>{selectedTransaction.status}</strong></div>
            <div><span>Pembayaran</span><strong>{selectedTransaction.metode_pembayaran?.toUpperCase() || '-'}</strong></div>
            <div><span>Total</span><strong>{formatRupiah(selectedTransaction.total_harga)}</strong></div>
            <div><span>Penanda Retur</span><strong>{selectedTransaction.has_customer_return ? 'Ada Retur' : 'Belum Ada Retur'}</strong></div>
            <div className="full">
              <span>Barang Dibeli</span>
              <strong>{selectedTransaction.details?.map(item => `${item.nama_barang} (${item.jumlah} pcs, sisa retur ${item.jumlah_bisa_retur} pcs)`).join(', ') || '-'}</strong>
            </div>
          </div>
        )}
      </Modal>

      <CustomerReturnModal
        open={Boolean(returnTransaction)}
        transaction={returnTransaction}
        loading={savingReturn}
        onClose={() => !savingReturn && setReturnTransaction(null)}
        onSubmit={submitCustomerReturn}
      />
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
