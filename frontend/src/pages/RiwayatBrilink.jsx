import React, { useState, useEffect } from 'react'
import { History, Filter, ReceiptText, WalletCards, Printer, FileSpreadsheet, Search } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { getLabelJenisNasabah } from '../utils/brilinkNasabah'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const toInputDate = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export default function RiwayatBrilink() {
  const [data, setData] = useState({ data: [], total_admin: 0, total_transaksi: 0 })
  const [startDate, setStartDate] = useState(toInputDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)))
  const [endDate, setEndDate] = useState(toInputDate(new Date()))
  const [jenis, setJenis] = useState('semua')
  const [search, setSearch] = useState('')
  const [reportPrintMode, setReportPrintMode] = useState(false)

  useEffect(() => { fetchData() }, [startDate, endDate, jenis])
  useEffect(() => {
    document.body.classList.toggle('report-print-mode', reportPrintMode)
    return () => document.body.classList.remove('report-print-mode')
  }, [reportPrintMode])
  const fetchData = async () => {
    try {
      const res = await api.get(`/riwayat-brilink?start_date=${startDate}&end_date=${endDate}&jenis=${jenis}&search=${encodeURIComponent(search)}`)
      setData(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat riwayat BRILink'))
    }
  }

  const formatRowName = (item) => {
    const name = item.nama_penerima || item.nama_pemilik_rekening || item.nama_pelanggan
    const number = item.nomor_rekening_tujuan || item.nomor_rekening || item.nomor_tujuan || item.nomor_ewallet
    const destination = item.bank_tujuan || item.operator || item.jenis_ewallet

    return [name, number, destination].filter(Boolean).join(' - ') || '-'
  }
  const formatNominal = (item) => item.nominal_transfer || item.nominal_tarik || item.nominal_setor || item.jumlah_tagihan || item.harga || item.nominal || 0
  const escapeExcel = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

  const printReport = () => {
    if (data.data.length === 0) return toast.error('Tidak ada riwayat BRILink untuk dicetak')
    setReportPrintMode(true)
    window.setTimeout(() => {
      window.print()
      window.setTimeout(() => setReportPrintMode(false), 1200)
    }, 80)
  }

  const exportExcel = () => {
    if (data.data.length === 0) return toast.error('Tidak ada riwayat BRILink untuk diexport')
    const rows = data.data.map(item => `
      <tr>
        <td>${escapeExcel(item.kode_transaksi || '-')}</td>
        <td>${escapeExcel(new Date(item.tanggal).toLocaleDateString('id-ID'))}</td>
        <td>${escapeExcel(item.jenis)}</td>
        <td>${escapeExcel(item.provider || '-')}</td>
        <td>${escapeExcel(item.jenis_nasabah ? getLabelJenisNasabah(item.jenis_nasabah) : '-')}</td>
        <td>${escapeExcel(item.jenis_kartu || '-')}</td>
        <td>${escapeExcel(formatRowName(item))}</td>
        <td>${Number(formatNominal(item))}</td>
        <td>${Number(item.biaya_admin || 0)}</td>
        <td>${escapeExcel(item.status || '-')}</td>
      </tr>
    `).join('')
    const html = `<table border="1"><tr><th colspan="10">Riwayat BRILink Sultan Cell</th></tr><tr><td colspan="10">${escapeExcel(startDate)} sampai ${escapeExcel(endDate)}</td></tr><tr><th>Kode</th><th>Tanggal</th><th>Jenis</th><th>Provider</th><th>Nasabah</th><th>Kartu</th><th>Nama/Nomor</th><th>Nominal</th><th>Admin</th><th>Status</th></tr>${rows}</table>`
    const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `riwayat-brilink-${startDate}-${endDate}.xls`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    toast.success('Riwayat BRILink Excel berhasil dibuat')
  }

  return (
    <div className="brilink-page">
      <div className="report-print-area">
      <div className="page-heading brilink-heading">
        <div><span>Monitoring BRILink</span><h1>Riwayat BRILink</h1><p>Gabungan pendataan transfer, tarik tunai, setor tunai, tagihan, pulsa, dan e-wallet.</p></div>
        <div className="report-heading-actions">
          <div className="report-date-range"><History size={18} /><span>{startDate} sampai {endDate}</span></div>
          <div className="report-action-buttons no-print">
            <button type="button" onClick={printReport} className="report-action-button print"><Printer size={17} />PDF / Print</button>
            <button type="button" onClick={exportExcel} className="report-action-button excel"><FileSpreadsheet size={17} />Excel</button>
          </div>
        </div>
      </div>

      <section className="brilink-filter-panel riwayat-filter-panel no-print">
        <label className="field-group"><span>Tanggal Mulai</span><input type="date" value={startDate} onChange={e => setStartDate(e.target.value)} /></label>
        <label className="field-group"><span>Tanggal Akhir</span><input type="date" value={endDate} onChange={e => setEndDate(e.target.value)} /></label>
        <label className="field-group"><span>Jenis Layanan</span><select value={jenis} onChange={e => setJenis(e.target.value)}><option value="semua">Semua</option><option value="transfer">Transfer</option><option value="tarik_tunai">Tarik Tunai</option><option value="setor_tunai">Setor Tunai</option><option value="tagihan">Tagihan</option><option value="pulsa">Pulsa</option><option value="ewallet">E-Wallet</option></select></label>
        <label className="field-group"><span>Cari</span><input maxLength={100} value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === 'Enter' && fetchData()} placeholder="Kode, nama, nomor" /></label>
        <button type="button" onClick={fetchData} className="filter-search-button"><Search size={17} />Cari</button>
      </section>

      <div className="brilink-summary-grid two">
        <div className="brilink-summary-card"><div className="summary-icon blue"><ReceiptText size={22} /></div><span>Total Transaksi</span><strong>{data.total_transaksi}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><WalletCards size={22} /></div><span>Total Keuntungan Admin</span><strong>{formatRupiah(data.total_admin)}</strong></div>
      </div>

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Semua Riwayat</h2><p>{data.data.length} data ditampilkan</p></div><Filter size={20} /></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Jenis</th><th>Provider</th><th>Nasabah</th><th>Kartu</th><th>Nama/Nomor</th><th className="money-header">Nominal</th><th className="money-header">Admin Fee</th><th>Status</th></tr></thead>
            <tbody>{data.data.map((t, index) => (
              <tr key={index}>
                <td><span className="item-code">{t.kode_transaksi || '-'}</span></td>
                <td className="nowrap">{new Date(t.tanggal).toLocaleDateString('id-ID')}</td>
                <td><span className="service-pill blue">{t.jenis}</span></td>
                <td><span className="service-pill green">{t.provider || '-'}</span></td>
                <td>{t.jenis_nasabah ? getLabelJenisNasabah(t.jenis_nasabah) : '-'}</td>
                <td>{t.jenis_kartu || '-'}</td>
                <td>{formatRowName(t)}</td>
                <td className="money-cell">{formatRupiah(formatNominal(t))}</td>
                <td className="money-cell admin">{formatRupiah(t.biaya_admin || 0)}</td>
                <td><span className={`status-pill ${t.status === 'sukses' ? 'paid' : 'pending'}`}>{t.status || '-'}</span></td>
              </tr>
            ))}</tbody>
          </table>
          {data.data.length === 0 && <div className="brilink-empty"><History size={42} /><p>Tidak ada data</p><span>Coba ubah periode atau jenis layanan.</span></div>}
        </div>
      </section>
      </div>
    </div>
  )
}
