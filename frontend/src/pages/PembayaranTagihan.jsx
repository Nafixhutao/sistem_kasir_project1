import React, { useState, useEffect } from 'react'
import { Receipt, Plus, FileText, WalletCards } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import { getLabelJenisNasabah } from '../utils/brilinkNasabah'
import { useBiayaAdminBrilink } from '../hooks/useBiayaAdminBrilink'
import NasabahKartuFields from '../components/Brilink/NasabahKartuFields'
import ProviderFields, { normalizeProvider } from '../components/Brilink/ProviderFields'
import BrilinkReceiptModal from '../components/Brilink/BrilinkReceiptModal'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const emptyForm = { provider: 'BRILink Mobile', nama_provider: '', jenis_layanan: 'pln', jenis_nasabah: 'internal', nomor_pelanggan: '', nama_pelanggan: '', jumlah_tagihan: '' }

export default function PembayaranTagihan() {
  const [data, setData] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [receipt, setReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const { hitung } = useBiayaAdminBrilink()

  useEffect(() => { fetchData() }, [])
  const fetchData = async () => { try { const res = await api.get('/tagihan'); setData(res.data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat pembayaran tagihan')) } }

  const handleSubmit = async (e) => {
    e.preventDefault(); setLoading(true)
    try {
      const res = await api.post('/tagihan', { ...form, provider: normalizeProvider(form), jumlah_tagihan: parseNominalInput(form.jumlah_tagihan) })
      toast.success('Pembayaran tagihan berhasil!')
      setReceipt({ type: 'tagihan', data: res.data })
      setShowReceipt(true)
      fetchData(); setShowForm(false); setForm(emptyForm)
    } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menyimpan pembayaran tagihan')) } finally { setLoading(false) }
  }

  const totalTagihan = data.reduce((sum, item) => sum + Number(item.jumlah_tagihan || 0), 0)
  const totalAdmin = data.reduce((sum, item) => sum + Number(item.biaya_admin || 0), 0)
  const previewNominal = parseNominalInput(form.jumlah_tagihan)
  const previewAdmin = hitung('tagihan', previewNominal, form.jenis_nasabah)
  const previewTotal = previewNominal + previewAdmin

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div><span>Layanan Tagihan</span><h1>Pembayaran Tagihan</h1><p>PLN, PDAM, BPJS, IndiHome, angsuran, dan tagihan lainnya.</p></div>
        <button onClick={() => setShowForm(!showForm)} className="brilink-primary-button"><Plus size={19} /> Bayar Tagihan</button>
      </div>
      <div className="brilink-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><Receipt size={22} /></div><span>Total Pembayaran</span><strong>{data.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><WalletCards size={22} /></div><span>Total Tagihan</span><strong>{formatRupiah(totalTagihan)}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><FileText size={22} /></div><span>Admin Fee</span><strong>{formatRupiah(totalAdmin)}</strong></div>
      </div>

      {showForm && (
        <section className="brilink-form-card">
          <div className="brilink-section-title"><Receipt size={20} /><div><h2>Form Pembayaran</h2><p>Masukkan layanan dan data pelanggan tagihan.</p></div></div>
          <form onSubmit={handleSubmit} className="brilink-form-grid">
            <ProviderFields form={form} setForm={setForm} />
            <label className="field-group"><span>Jenis Layanan</span><select value={form.jenis_layanan} onChange={e => setForm({ ...form, jenis_layanan: e.target.value })}><option value="pln">PLN</option><option value="pdam">PDAM</option><option value="bpjs">BPJS</option><option value="indihome">IndiHome</option><option value="angsuran">Angsuran</option><option value="lainnya">Lainnya</option></select></label>
            <NasabahKartuFields value={form.jenis_nasabah} onChange={jenisNasabah => setForm({ ...form, jenis_nasabah: jenisNasabah })} />
            <label className="field-group"><span>No. Pelanggan</span><input type="text" maxLength={50} value={form.nomor_pelanggan} onChange={e => setForm({ ...form, nomor_pelanggan: e.target.value })} required /></label>
            <label className="field-group"><span>Nama Pelanggan</span><input type="text" maxLength={100} value={form.nama_pelanggan} onChange={e => setForm({ ...form, nama_pelanggan: e.target.value })} required /></label>
            <label className="field-group"><span>Jumlah Tagihan</span><input type="text" inputMode="numeric" value={form.jumlah_tagihan} onChange={e => setForm({ ...form, jumlah_tagihan: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
            <div className="brilink-calculation-box full"><span>Admin otomatis: <strong>{formatRupiah(previewAdmin)}</strong></span><span>Total bayar: <strong>{formatRupiah(previewTotal)}</strong></span></div>
            <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button">{loading ? 'Memproses...' : 'Bayar Tagihan'}</button></div>
          </form>
        </section>
      )}

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat Pembayaran</h2><p>{data.length} pembayaran tercatat</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Provider</th><th>Layanan</th><th>Nasabah</th><th>Pelanggan</th><th className="money-header">Tagihan</th><th className="money-header">Admin</th><th className="money-header">Total</th></tr></thead>
            <tbody>{data.map(t => (
              <tr key={t.id}>
                <td><span className="item-code">{t.kode_transaksi}</span></td><td className="nowrap">{new Date(t.tanggal).toLocaleDateString('id-ID')}</td><td><span className="service-pill green">{t.provider || '-'}</span></td><td><span className="service-pill purple">{t.jenis_layanan}</span></td>
                <td><div className="person-cell"><strong>{getLabelJenisNasabah(t.jenis_nasabah)}</strong><span>{t.jenis_kartu || '-'}</span></div></td>
                <td><div className="person-cell"><strong>{t.nama_pelanggan}</strong><span>{t.nomor_pelanggan}</span></div></td>
                <td className="money-cell">{formatRupiah(t.jumlah_tagihan)}</td><td className="money-cell admin">{formatRupiah(t.biaya_admin)}</td><td className="money-cell strong">{formatRupiah(t.total_bayar)}</td>
              </tr>
            ))}</tbody>
          </table>
          {data.length === 0 && <div className="brilink-empty"><Receipt size={42} /><p>Belum ada pembayaran tagihan</p><span>Pembayaran baru akan muncul di sini.</span></div>}
        </div>
      </section>

      <BrilinkReceiptModal
        isOpen={showReceipt}
        receipt={receipt}
        onClose={() => setShowReceipt(false)}
      />
    </div>
  )
}
