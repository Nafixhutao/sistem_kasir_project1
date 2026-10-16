import React, { useEffect, useState } from 'react'
import { Trash2, WalletCards } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'
import Modal from '../components/Common/Modal'

const toInputDate = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export default function PengeluaranToko() {
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [data, setData] = useState([])
  const [startDate, setStartDate] = useState(toInputDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)))
  const [endDate, setEndDate] = useState(toInputDate(new Date()))
  const [kategori, setKategori] = useState('semua')
  const [loading, setLoading] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteLoading, setDeleteLoading] = useState(false)
  const [form, setForm] = useState({
    tanggal: toInputDate(new Date()),
    kategori: 'Listrik',
    nama_pengeluaran: '',
    nominal: '',
    keterangan: ''
  })

  useEffect(() => { fetchData() }, [startDate, endDate, kategori])

  const fetchData = async () => {
    try {
      const res = await api.get(`/pengeluaran-toko?start_date=${startDate}&end_date=${endDate}&kategori=${kategori}`)
      setData(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat pengeluaran toko'))
    }
  }

  const submit = async (e) => {
    e.preventDefault()
    setLoading(true)
    try {
      await api.post('/pengeluaran-toko', { ...form, nominal: parseNominalInput(form.nominal) })
      toast.success('Pengeluaran toko berhasil dicatat')
      setForm({ tanggal: toInputDate(new Date()), kategori: 'Listrik', nama_pengeluaran: '', nominal: '', keterangan: '' })
      fetchData()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan pengeluaran'))
    } finally {
      setLoading(false)
    }
  }

  const remove = async () => {
    if (!deleteTarget) return
    setDeleteLoading(true)
    try {
      await api.delete(`/pengeluaran-toko/${deleteTarget.id}`)
      toast.success('Pengeluaran dihapus')
      setDeleteTarget(null)
      fetchData()
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menghapus pengeluaran'))
    } finally {
      setDeleteLoading(false)
    }
  }

  const total = data.reduce((sum, item) => sum + Number(item.nominal || 0), 0)

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div><span>Operasional Toko</span><h1>Pengeluaran Toko</h1><p>Catat listrik, gaji, belanja operasional, transport, dan biaya lain untuk arsip operasional.</p></div>
      </div>

      <div className="brilink-summary-grid two">
        <div className="brilink-summary-card"><div className="summary-icon amber"><WalletCards size={22} /></div><span>Total Pengeluaran</span><strong>{formatRupiah(total)}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon blue"><WalletCards size={22} /></div><span>Jumlah Catatan</span><strong>{data.length}</strong></div>
      </div>

      {canWrite && <section className="brilink-form-card">
        <div className="brilink-section-title"><WalletCards size={20} /><div><h2>Form Pengeluaran</h2><p>Data ini disimpan sebagai riwayat biaya operasional toko.</p></div></div>
        <form onSubmit={submit} className="brilink-form-grid">
          <label className="field-group"><span>Tanggal</span><input type="date" value={form.tanggal} onChange={e => setForm({ ...form, tanggal: e.target.value })} /></label>
          <label className="field-group"><span>Kategori</span><select value={form.kategori} onChange={e => setForm({ ...form, kategori: e.target.value })}><option>Listrik</option><option>Gaji</option><option>Belanja Operasional</option><option>Transport</option><option>Lainnya</option></select></label>
          <label className="field-group"><span>Nama Pengeluaran</span><input maxLength={150} value={form.nama_pengeluaran} onChange={e => setForm({ ...form, nama_pengeluaran: e.target.value })} required /></label>
          <label className="field-group"><span>Nominal</span><input type="text" inputMode="numeric" value={form.nominal} onChange={e => setForm({ ...form, nominal: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
          <label className="field-group full"><span>Keterangan</span><textarea value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} /></label>
          <div className="form-actions full"><button disabled={loading} className="primary-action-button">{loading ? 'Menyimpan...' : 'Simpan Pengeluaran'}</button></div>
        </form>
      </section>}

      <section className="brilink-filter-panel">
        <label className="field-group"><span>Tanggal Mulai</span><input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} /></label>
        <label className="field-group"><span>Tanggal Akhir</span><input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} /></label>
        <label className="field-group"><span>Kategori</span><select value={kategori} onChange={e => setKategori(e.target.value)}><option value="semua">Semua</option><option>Listrik</option><option>Gaji</option><option>Belanja Operasional</option><option>Transport</option><option>Lainnya</option></select></label>
      </section>

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat Pengeluaran</h2><p>{data.length} data ditampilkan</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Tanggal</th><th>Kategori</th><th>Nama</th><th className="money-header">Nominal</th><th>Keterangan</th><th>Aksi</th></tr></thead>
            <tbody>{data.map(item => (
              <tr key={item.id}>
                <td className="nowrap">{new Date(item.tanggal).toLocaleDateString('id-ID')}</td>
                <td><span className="service-pill orange">{item.kategori}</span></td>
                <td>{item.nama_pengeluaran}</td>
                <td className="money-cell strong">{formatRupiah(item.nominal)}</td>
                <td>{item.keterangan || '-'}</td>
                <td>{canWrite && <button type="button" className="table-action-button danger" onClick={() => setDeleteTarget(item)}><Trash2 size={15} />Hapus</button>}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </section>

      <Modal isOpen={Boolean(deleteTarget)} onClose={() => !deleteLoading && setDeleteTarget(null)} title="Hapus Pengeluaran" size="sm">
        {deleteTarget && (
          <div className="confirm-action-content">
            <div className="form-alert error">
              Hapus pengeluaran {deleteTarget.nama_pengeluaran}? Data yang sudah dihapus tidak dapat dikembalikan.
            </div>
            <div className="modal-form-actions">
              <button type="button" className="secondary-button" onClick={() => setDeleteTarget(null)} disabled={deleteLoading}>Batal</button>
              <button type="button" className="ui-button danger" onClick={remove} disabled={deleteLoading}>
                {deleteLoading ? 'Menghapus...' : 'Hapus'}
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
