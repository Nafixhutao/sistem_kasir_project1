import React, { useState, useEffect } from 'react'
import { Wallet, Plus, Banknote, ReceiptText } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import { getLabelJenisNasabah } from '../utils/brilinkNasabah'
import { useBiayaAdminBrilink } from '../hooks/useBiayaAdminBrilink'
import NasabahKartuFields from '../components/Brilink/NasabahKartuFields'
import ProviderFields, { normalizeProvider } from '../components/Brilink/ProviderFields'
import BrilinkReceiptModal from '../components/Brilink/BrilinkReceiptModal'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const emptyForm = { provider: 'BRILink Mobile', nama_provider: '', nomor_rekening: '', nama_penerima: '', nomor_hp: '', jenis_nasabah: 'internal', nominal_tarik: '' }

export default function TarikTunai() {
  const [data, setData] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [receipt, setReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const { hitung } = useBiayaAdminBrilink()

  useEffect(() => { fetchData() }, [])
  const fetchData = async () => { try { const res = await api.get('/tarik-tunai'); setData(res.data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat tarik tunai')) } }

  const handleSubmit = async (e) => {
    e.preventDefault(); setLoading(true)
    try {
      const res = await api.post('/tarik-tunai', { ...form, provider: normalizeProvider(form), nominal_tarik: parseNominalInput(form.nominal_tarik) })
      toast.success('Tarik tunai berhasil!')
      setReceipt({ type: 'tarik_tunai', data: res.data })
      setShowReceipt(true)
      fetchData(); setShowForm(false); setForm(emptyForm)
    } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menyimpan tarik tunai')) } finally { setLoading(false) }
  }

  const totalNominal = data.reduce((sum, item) => sum + Number(item.nominal_tarik || 0), 0)
  const totalAdmin = data.reduce((sum, item) => sum + Number(item.biaya_admin || 0), 0)
  const previewAdmin = hitung('tarik_tunai', form.nominal_tarik, form.jenis_nasabah)
  const previewTotal = parseNominalInput(form.nominal_tarik) + previewAdmin

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div><span>Layanan BRILink</span><h1>Tarik Tunai</h1><p>Catat tarik tunai pelanggan lengkap dengan nomor rekening dan biaya admin.</p></div>
        <button onClick={() => setShowForm(!showForm)} className="brilink-primary-button"><Plus size={19} /> Transaksi Baru</button>
      </div>
      <div className="brilink-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><Wallet size={22} /></div><span>Total Transaksi</span><strong>{data.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><Banknote size={22} /></div><span>Nominal Tarik</span><strong>{formatRupiah(totalNominal)}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><ReceiptText size={22} /></div><span>Admin Fee</span><strong>{formatRupiah(totalAdmin)}</strong></div>
      </div>

      {showForm && (
        <section className="brilink-form-card">
          <div className="brilink-section-title"><Wallet size={20} /><div><h2>Form Tarik Tunai</h2><p>Isi data penerima dan nominal penarikan.</p></div></div>
          <form onSubmit={handleSubmit} className="brilink-form-grid">
            <ProviderFields form={form} setForm={setForm} />
            <label className="field-group"><span>No. Rekening</span><input type="text" maxLength={30} value={form.nomor_rekening} onChange={e => setForm({ ...form, nomor_rekening: e.target.value })} required /></label>
            <label className="field-group"><span>Nama Penerima</span><input type="text" maxLength={100} value={form.nama_penerima} onChange={e => setForm({ ...form, nama_penerima: e.target.value })} required /></label>
            <label className="field-group"><span>No. HP</span><input type="text" maxLength={20} value={form.nomor_hp} onChange={e => setForm({ ...form, nomor_hp: e.target.value })} required /></label>
            <NasabahKartuFields value={form.jenis_nasabah} onChange={jenisNasabah => setForm({ ...form, jenis_nasabah: jenisNasabah })} />
            <label className="field-group"><span>Nominal Tarik</span><input type="text" inputMode="numeric" value={form.nominal_tarik} onChange={e => setForm({ ...form, nominal_tarik: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
            <div className="brilink-calculation-box full"><span>Biaya Admin: {formatRupiah(previewAdmin)}</span><strong>Total Bayar: {formatRupiah(previewTotal)}</strong></div>
            <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button">{loading ? 'Memproses...' : 'Simpan Tarik Tunai'}</button></div>
          </form>
        </section>
      )}

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat Tarik Tunai</h2><p>{data.length} transaksi tercatat</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Provider</th><th>Penerima</th><th>Nasabah</th><th className="money-header">Nominal</th><th className="money-header">Admin</th><th className="money-header">Total</th><th>Status</th></tr></thead>
            <tbody>{data.map(t => (
              <tr key={t.id}>
                <td><span className="item-code">{t.kode_transaksi}</span></td><td className="nowrap">{new Date(t.tanggal).toLocaleDateString('id-ID')}</td>
                <td><span className="service-pill green">{t.provider || '-'}</span></td>
                <td><div className="person-cell"><strong>{t.nama_penerima}</strong><span>{t.nomor_rekening}</span></div></td>
                <td><div className="person-cell"><strong>{getLabelJenisNasabah(t.jenis_nasabah)}</strong><span>{t.jenis_kartu || '-'}</span></div></td>
                <td className="money-cell">{formatRupiah(t.nominal_tarik)}</td><td className="money-cell admin">{formatRupiah(t.biaya_admin)}</td><td className="money-cell strong">{formatRupiah(t.total_bayar)}</td>
                <td><span className={`status-pill ${t.status === 'sukses' ? 'paid' : 'pending'}`}>{t.status}</span></td>
              </tr>
            ))}</tbody>
          </table>
          {data.length === 0 && <div className="brilink-empty"><Wallet size={42} /><p>Belum ada data tarik tunai</p><span>Transaksi baru akan muncul di sini.</span></div>}
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
