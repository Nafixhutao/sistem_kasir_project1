import React, { useState, useEffect } from 'react'
import { TrendingUp, Activity, DollarSign, AlertTriangle, Clock, ArrowUpRight, Trophy } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

export default function Dashboard() {
  const [data, setData] = useState({
    total_penjualan_hari_ini: 0,
    total_transaksi_brilink: 0,
    total_keuntungan_admin: 0,
    stok_menipis: [],
    transaksi_terbaru: [],
    produk_terlaris: [],
    aktivitas_terbaru: []
  })
  const [loading, setLoading] = useState(true)

  useEffect(() => { fetchDashboard() }, [])
  const fetchDashboard = async () => {
    try { const res = await api.get('/dashboard'); setData(res.data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat dashboard')) } finally { setLoading(false) }
  }

  const cards = [
    { title: 'Penjualan Hari Ini', value: formatRupiah(data.total_penjualan_hari_ini), icon: TrendingUp, tone: 'green', note: 'Omzet kasir harian' },
    { title: 'Transaksi BRILink', value: data.total_transaksi_brilink + ' transaksi', icon: Activity, tone: 'blue', note: 'Jumlah layanan diproses' },
    { title: 'Profit Admin BRILink', value: formatRupiah(data.total_keuntungan_admin), icon: DollarSign, tone: 'amber', note: 'Akumulasi biaya admin' },
  ]

  if (loading) return <div className="dashboard-loading"><div></div><span>Memuat dashboard...</span></div>

  return (
    <div className="dashboard-page">
      <div className="page-heading">
        <div>
          <span>Ringkasan Operasional</span>
          <h1>Dashboard</h1>
          <p>Monitor penjualan, layanan BRILink, dan stok yang perlu diperhatikan hari ini.</p>
        </div>
        <div className="heading-badge">
          <Clock size={18} />
          <span>Update real-time</span>
        </div>
      </div>

      <div className="stat-grid">
        {cards.map((card, i) => (
          <div key={i} className={`stat-card tone-${card.tone}`}>
            <div className="stat-card-top">
              <div className="stat-icon"><card.icon size={24} /></div>
              <ArrowUpRight size={18} />
            </div>
            <p>{card.title}</p>
            <strong>{card.value}</strong>
            <span>{card.note}</span>
          </div>
        ))}
      </div>

      <div className="dashboard-panels">
        <section className="dashboard-panel">
          <div className="panel-title">
            <div className="panel-icon danger"><AlertTriangle size={20} /></div>
            <div>
              <h2>Stok Menipis</h2>
              <p>Barang yang perlu segera dicek ulang.</p>
            </div>
          </div>
          {data.stok_menipis.length === 0 ? <div className="empty-state"><p>Semua stok aman</p><span>Tidak ada barang di bawah batas stok.</span></div> : (
            <div className="list-stack">{data.stok_menipis.map(item => (
              <div key={item.id} className="inventory-row">
                <div><p>{item.nama_barang}</p><span>{item.kode_barang}</span></div>
                <strong>Sisa {item.stok}</strong>
              </div>
            ))}</div>
          )}
        </section>

        <section className="dashboard-panel">
          <div className="panel-title">
            <div className="panel-icon info"><Clock size={20} /></div>
            <div>
              <h2>Transaksi Terbaru</h2>
              <p>Aktivitas kasir paling baru.</p>
            </div>
          </div>
          {data.transaksi_terbaru.length === 0 ? <div className="empty-state"><p>Belum ada transaksi hari ini</p><span>Transaksi baru akan tampil otomatis.</span></div> : (
            <div className="list-stack">{data.transaksi_terbaru.map(t => (
              <div key={t.id} className="transaction-row">
                <div><p>{t.kode_transaksi}</p>
                  <span><em className={t.status === 'lunas' ? 'paid' : 'pending'}>{t.status}</em>{t.metode_pembayaran}</span>
                </div>
                <strong>{formatRupiah(t.total_harga)}</strong>
              </div>
            ))}</div>
          )}
        </section>
      </div>

      <div className="dashboard-panels">
        <section className="dashboard-panel">
          <div className="panel-title">
            <div className="panel-icon info"><Trophy size={20} /></div>
            <div>
              <h2>Produk Terlaris Hari Ini</h2>
              <p>Produk dengan jumlah penjualan terbanyak.</p>
            </div>
          </div>
          {data.produk_terlaris.length === 0 ? <div className="empty-state"><p>Belum ada produk terjual</p><span>Produk terlaris akan muncul setelah transaksi.</span></div> : (
            <div className="list-stack">{data.produk_terlaris.map(item => (
              <div key={item.kode_barang} className="inventory-row">
                <div><p>{item.nama_barang}</p><span>{item.kode_barang}</span></div>
                <strong>{item.total_jual} terjual</strong>
              </div>
            ))}</div>
          )}
        </section>

        <section className="dashboard-panel">
          <div className="panel-title">
            <div className="panel-icon info"><Activity size={20} /></div>
            <div>
              <h2>Aktivitas Sistem Terbaru</h2>
              <p>Audit singkat aktivitas penting di website.</p>
            </div>
          </div>
          {data.aktivitas_terbaru.length === 0 ? <div className="empty-state"><p>Belum ada aktivitas</p><span>Aktivitas baru akan tampil setelah ada transaksi atau perubahan data.</span></div> : (
            <div className="list-stack">{data.aktivitas_terbaru.map(item => (
              <div key={item.id} className="transaction-row">
                <div><p>{item.modul} - {item.aksi}</p><span>{item.keterangan}</span></div>
                <strong>{new Date(item.dibuat_pada).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</strong>
              </div>
            ))}</div>
          )}
        </section>
      </div>
    </div>
  )
}
