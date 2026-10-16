import React, { useState, useEffect } from 'react'
import { Send, Plus, Landmark, WalletCards } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import { getLabelJenisNasabah } from '../utils/brilinkNasabah'
import { useBiayaAdminBrilink } from '../hooks/useBiayaAdminBrilink'
import NasabahKartuFields from '../components/Brilink/NasabahKartuFields'
import ProviderFields, { normalizeProvider } from '../components/Brilink/ProviderFields'
import BrilinkReceiptModal from '../components/Brilink/BrilinkReceiptModal'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const emptyForm = {
  provider: 'BRILink Mobile',
  nama_provider: '',
  jenis_transfer: 'Sesama BRI',
  bank_tujuan: 'BRI',
  nomor_rekening_tujuan: '',
  nama_penerima: '',
  jenis_nasabah: 'internal',
  nominal_transfer: '',
  keterangan: ''
}

export default function Transfer() {
  const [data, setData] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [receipt, setReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const { hitung } = useBiayaAdminBrilink()

  useEffect(() => { fetchData() }, [])
  const fetchData = async () => { try { const res = await api.get('/transfer'); setData(res.data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat transfer')) } }

  const handleSubmit = async (e) => {
    e.preventDefault(); setLoading(true)
    try {
      const res = await api.post('/transfer', { ...form, provider: normalizeProvider(form), nominal_transfer: parseNominalInput(form.nominal_transfer) })
      toast.success('Transfer berhasil dicatat!')
      setReceipt({ type: 'transfer', data: res.data })
      setShowReceipt(true)
      fetchData(); setShowForm(false); setForm(emptyForm)
    } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menyimpan transfer')) } finally { setLoading(false) }
  }

  const totalNominal = data.reduce((sum, item) => sum + Number(item.nominal_transfer || 0), 0)
  const totalAdmin = data.reduce((sum, item) => sum + Number(item.biaya_admin || 0), 0)
  const previewAdmin = hitung('transfer', form.nominal_transfer, form.jenis_nasabah)
  const previewTotal = parseNominalInput(form.nominal_transfer) + previewAdmin

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div>
          <span>Layanan BRILink</span>
          <h1>Transfer</h1>
          <p>Catat pendataan transfer sesama BRI, antar bank, dan e-wallet secara fleksibel.</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="brilink-primary-button"><Plus size={19} /> Transaksi Baru</button>
      </div>

      <div className="brilink-summary-grid">
        <div className="brilink-summary-card"><div className="summary-icon blue"><Send size={22} /></div><span>Total Transaksi</span><strong>{data.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><WalletCards size={22} /></div><span>Nominal Transfer</span><strong>{formatRupiah(totalNominal)}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><Landmark size={22} /></div><span>Admin Fee</span><strong>{formatRupiah(totalAdmin)}</strong></div>
      </div>

      {showForm && (
        <section className="brilink-form-card">
          <div className="brilink-section-title"><Send size={20} /><div><h2>Form Transfer</h2><p>Jenis transfer adalah kategori pendataan. Bank atau tujuan bisa diketik manual.</p></div></div>
          <form onSubmit={handleSubmit} className="brilink-form-grid transfer-form-grid">
            <ProviderFields form={form} setForm={setForm} />
            <label className="field-group"><span>Jenis Transfer</span><select value={form.jenis_transfer} onChange={e => {
              const jenis = e.target.value
              setForm({
                ...form,
                jenis_transfer: jenis,
                bank_tujuan: jenis === 'Sesama BRI' ? 'BRI' : ''
              })
            }}><option>Sesama BRI</option><option>Antar Bank</option><option>E-Wallet</option></select></label>
            <label className="field-group"><span>{form.jenis_transfer === 'E-Wallet' ? 'Nama E-Wallet' : 'Bank Tujuan'}</span><input type="text" maxLength={100} value={form.bank_tujuan} onChange={e => setForm({ ...form, bank_tujuan: e.target.value })} required placeholder={form.jenis_transfer === 'E-Wallet' ? 'Contoh: DANA, GoPay, OVO' : 'Contoh: BRI, BCA, Mandiri, BSI'} /></label>
            <label className="field-group"><span>No. Rekening Tujuan</span><input type="text" maxLength={30} value={form.nomor_rekening_tujuan} onChange={e => setForm({ ...form, nomor_rekening_tujuan: e.target.value })} required placeholder="Masukkan nomor tujuan" /></label>
            <label className="field-group"><span>Nama Penerima</span><input type="text" maxLength={100} value={form.nama_penerima} onChange={e => setForm({ ...form, nama_penerima: e.target.value })} required /></label>
            <NasabahKartuFields value={form.jenis_nasabah} onChange={jenisNasabah => setForm({ ...form, jenis_nasabah: jenisNasabah })} />
            <label className="field-group"><span>Nominal Transfer</span><input type="text" inputMode="numeric" value={form.nominal_transfer} onChange={e => setForm({ ...form, nominal_transfer: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
            <label className="field-group"><span>Keterangan</span><input type="text" value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} placeholder="Opsional" /></label>
            <div className="brilink-calculation-box full"><span>Biaya Admin: {formatRupiah(previewAdmin)}</span><strong>Total Bayar: {formatRupiah(previewTotal)}</strong></div>
            <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button">{loading ? 'Memproses...' : 'Simpan Transfer'}</button></div>
          </form>
        </section>
      )}

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat Transfer</h2><p>{data.length} transaksi tercatat</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Provider</th><th>Jenis</th><th>Nasabah</th><th>Penerima</th><th className="money-header">Nominal</th><th className="money-header">Admin</th><th className="money-header">Total</th><th>Status</th></tr></thead>
            <tbody>{data.map(t => (
              <tr key={t.id}>
                <td><span className="item-code">{t.kode_transaksi}</span></td>
                <td className="nowrap">{new Date(t.tanggal).toLocaleDateString('id-ID')}</td>
                <td><span className="service-pill green">{t.provider || '-'}</span></td>
                <td><div className="person-cell"><span className="service-pill blue">{t.jenis_transfer}</span><span>{t.bank_tujuan || '-'}</span></div></td>
                <td><div className="person-cell"><strong>{getLabelJenisNasabah(t.jenis_nasabah)}</strong><span>{t.jenis_kartu || '-'}</span></div></td>
                <td><div className="person-cell"><strong>{t.nama_penerima}</strong><span>{t.nomor_rekening_tujuan}</span></div></td>
                <td className="money-cell">{formatRupiah(t.nominal_transfer)}</td>
                <td className="money-cell admin">{formatRupiah(t.biaya_admin)}</td>
                <td className="money-cell strong">{formatRupiah(t.total_bayar)}</td>
                <td><span className={`status-pill ${t.status === 'sukses' ? 'paid' : 'pending'}`}>{t.status}</span></td>
              </tr>
            ))}</tbody>
          </table>
          {data.length === 0 && <div className="brilink-empty"><Send size={42} /><p>Belum ada transaksi transfer</p><span>Transaksi baru akan muncul di sini.</span></div>}
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
