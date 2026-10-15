import React, { useEffect, useState } from 'react'
import { Activity, Search } from 'lucide-react'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'
import { formatDateTime } from '../utils/formatDateTime'

export default function RiwayatAktivitas() {
  const [data, setData] = useState([])
  const [module, setModule] = useState('semua')
  const [search, setSearch] = useState('')

  useEffect(() => { fetchData() }, [module])

  const fetchData = async () => {
    try {
      const res = await api.get(`/activity-logs?module=${module}&search=${encodeURIComponent(search)}`)
      setData(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat riwayat aktivitas'))
    }
  }

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div><span>Audit Sistem</span><h1>Riwayat Aktivitas</h1><p>Lihat aktivitas penting seperti transaksi, stok, backup, dan pengaturan.</p></div>
      </div>
      <section className="brilink-filter-panel activity-filter-panel">
        <label className="field-group"><span>Cari Aktivitas</span><input maxLength={100} value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === 'Enter' && fetchData()} placeholder="Cari modul, aksi, atau keterangan" /></label>
        <label className="field-group"><span>Modul</span><select value={module} onChange={e => setModule(e.target.value)}><option value="semua">Semua</option><option>Barang</option><option>Penjualan</option><option>E-Wallet</option><option>Pengeluaran</option><option>Backup</option><option>Biaya Admin</option><option>Auth</option><option>Midtrans</option></select></label>
        <button type="button" onClick={fetchData} className="filter-search-button"><Search size={17} />Cari</button>
      </section>
      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Log Aktivitas</h2><p>{data.length} aktivitas terbaru</p></div><Activity size={20} /></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Waktu</th><th>Modul</th><th>Aksi</th><th>Keterangan</th><th>User</th></tr></thead>
            <tbody>{data.map(item => (
              <tr key={item.id}>
                <td className="nowrap">{formatDateTime(item.created_at)}</td>
                <td><span className="service-pill blue">{item.module}</span></td>
                <td>{item.action}</td>
                <td>{item.description || '-'}</td>
                <td>{item.user_name}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
