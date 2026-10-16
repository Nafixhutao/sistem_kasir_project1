import React, { useState, useEffect } from 'react'
import { HandCoins, Plus, Landmark, ReceiptText } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import { getLabelJenisNasabah } from '../utils/brilinkNasabah'
import { useBiayaAdminBrilink } from '../hooks/useBiayaAdminBrilink'
import NasabahKartuFields from '../components/Brilink/NasabahKartuFields'
import ProviderFields, { normalizeProvider } from '../components/Brilink/ProviderFields'
import BrilinkReceiptModal from '../components/Brilink/BrilinkReceiptModal'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const emptyForm = { provider: 'BRILink Mobile', nama_provider: '', jenis_setoran: 'biasa', jenis_nasabah: 'internal', nomor_rekening_tujuan: '', nama_pemilik_rekening: '', bank_tujuan: '', nominal_setor: '', sumber_dana: '', keterangan: '' }

export default function SetorTunai() {
  const [data, setData] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [receipt, setReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const { hitung } = useBiayaAdminBrilink()

  useEffect(() => { fetchData() }, [])
  const fetchData = async () => { try { const res = await api.get('/setor-tunai'); setData(res.data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat setor tunai')) } }

  const handleSubmit = async (e) => {
    e.preventDefault(); setLoading(true)
    try {
      const res = await api.post('/setor-tunai', { ...form, provider: normalizeProvider(form), nominal_setor: parseNominalInput(form.nominal_setor) })
      toast.success('Setor tunai berhasil!')
      setReceipt({ type: 'setor_tunai', data: res.data })
      setShowReceipt(true)
      fetchData(); setShowForm(false); setForm(emptyForm)
    } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menyimpan setor tunai')) } finally { setLoading(false) }
  }

  const totalNominal = data.reduce((sum, item) => sum + Number(item.nominal_setor || 0), 0)
  const totalAdmin = data.reduce((sum, item) => sum + Number(item.biaya_admin || 0), 0)
  const previewNominal = parseNominalInput(form.nominal_setor)
  const previewAdmin = hitung('setor_tunai', previewNominal, form.jenis_nasabah)
  const previewTotal = previewNominal + previewAdmin

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div><span>Layanan BRILink</span><h1>Setor Tunai</h1><p>Catat setoran tunai ke rekening tujuan, bank, dan sumber dana pelanggan.</p></div>
        <button onClick={() => setShowForm(!showForm)} className="brilink-primary-button"><Plus size={19} /> Transaksi Baru</button>
      </div>
      <div className="brilink-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><HandCoins size={22} /></div><span>Total Setoran</span><strong>{data.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><Landmark size={22} /></div><span>Nominal Setor</span><strong>{formatRupiah(totalNominal)}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><ReceiptText size={22} /></div><span>Admin Fee</span><strong>{formatRupiah(totalAdmin)}</strong></div>
      </div>

      {showForm && (
        <section className="brilink-form-card">
          <div className="brilink-section-title"><HandCoins size={20} /><div><h2>Form Setor Tunai</h2><p>Lengkapi tujuan rekening dan nominal setoran.</p></div></div>
          <form onSubmit={handleSubmit} className="brilink-form-grid">
            <ProviderFields form={form} setForm={setForm} />
            <label className="field-group"><span>Jenis Setoran</span><select value={form.jenis_setoran} onChange={e => setForm({ ...form, jenis_setoran: e.target.value })}><option value="biasa">Biasa</option><option value="tabungan">Tabungan</option></select></label>
            <NasabahKartuFields value={form.jenis_nasabah} onChange={jenisNasabah => setForm({ ...form, jenis_nasabah: jenisNasabah })} />
            <label className="field-group"><span>No. Rekening Tujuan</span><input type="text" maxLength={30} value={form.nomor_rekening_tujuan} onChange={e => setForm({ ...form, nomor_rekening_tujuan: e.target.value })} /></label>
            <label className="field-group"><span>Nama Pemilik Rekening</span><input type="text" maxLength={100} value={form.nama_pemilik_rekening} onChange={e => setForm({ ...form, nama_pemilik_rekening: e.target.value })} /></label>
            <label className="field-group"><span>Bank Tujuan</span><input type="text" maxLength={100} value={form.bank_tujuan} onChange={e => setForm({ ...form, bank_tujuan: e.target.value })} /></label>
            <label className="field-group"><span>Nominal Setor</span><input type="text" inputMode="numeric" value={form.nominal_setor} onChange={e => setForm({ ...form, nominal_setor: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
            <label className="field-group"><span>Sumber Dana</span><input type="text" maxLength={100} value={form.sumber_dana} onChange={e => setForm({ ...form, sumber_dana: e.target.value })} /></label>
            <div className="brilink-calculation-box full"><span>Admin otomatis: <strong>{formatRupiah(previewAdmin)}</strong></span><span>Total bayar: <strong>{formatRupiah(previewTotal)}</strong></span></div>
            <label className="field-group full"><span>Keterangan</span><input type="text" value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} /></label>
            <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button">{loading ? 'Memproses...' : 'Simpan Setoran'}</button></div>
          </form>
        </section>
      )}

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat Setor Tunai</h2><p>{data.length} transaksi tercatat</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Provider</th><th>Jenis</th><th>Nasabah</th><th>Pemilik Rekening</th><th className="money-header">Nominal</th><th className="money-header">Admin</th><th className="money-header">Total</th><th>Status</th></tr></thead>
            <tbody>{data.map(t => (
              <tr key={t.id}>
                <td><span className="item-code">{t.kode_transaksi}</span></td><td className="nowrap">{new Date(t.tanggal).toLocaleDateString('id-ID')}</td><td><span className="service-pill green">{t.provider || '-'}</span></td><td><span className="service-pill green">{t.jenis_setoran}</span></td>
                <td><div className="person-cell"><strong>{getLabelJenisNasabah(t.jenis_nasabah)}</strong><span>{t.jenis_kartu || '-'}</span></div></td>
                <td><div className="person-cell"><strong>{t.nama_pemilik_rekening || '-'}</strong><span>{t.nomor_rekening_tujuan || t.bank_tujuan || '-'}</span></div></td>
                <td className="money-cell">{formatRupiah(t.nominal_setor)}</td><td className="money-cell admin">{formatRupiah(t.biaya_admin)}</td><td className="money-cell strong">{formatRupiah(t.total_bayar)}</td>
                <td><span className={`status-pill ${t.status === 'sukses' ? 'paid' : 'pending'}`}>{t.status}</span></td>
              </tr>
            ))}</tbody>
          </table>
          {data.length === 0 && <div className="brilink-empty"><HandCoins size={42} /><p>Belum ada data setor tunai</p><span>Transaksi baru akan muncul di sini.</span></div>}
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
