import React, { useEffect, useState } from 'react'
import { WalletCards, Plus, RefreshCcw } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { formatNominalInput, parseNominalInput } from '../utils/nominalInput'
import { useBiayaAdminBrilink } from '../hooks/useBiayaAdminBrilink'
import ProviderFields, { normalizeProvider } from '../components/Brilink/ProviderFields'
import BrilinkReceiptModal from '../components/Brilink/BrilinkReceiptModal'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

export default function EWallet() {
  const [data, setData] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [receipt, setReceipt] = useState(null)
  const [showReceipt, setShowReceipt] = useState(false)
  const { hitung } = useBiayaAdminBrilink()
  const [form, setForm] = useState({
    jenis_transaksi: 'top_up',
    provider: 'BRILink Mobile',
    nama_provider: '',
    jenis_ewallet: 'DANA',
    nomor_ewallet: '',
    nama_customer: '',
    nominal: '',
    keterangan: ''
  })

  useEffect(() => { fetchData() }, [])

  const fetchData = async () => {
    try {
      const res = await api.get('/ewallet')
      setData(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat transaksi e-wallet'))
    }
  }

  const submit = async (e) => {
    e.preventDefault()
    setLoading(true)
    try {
      const res = await api.post('/ewallet', { ...form, provider: normalizeProvider(form), nominal: parseNominalInput(form.nominal) })
      toast.success('Transaksi e-wallet berhasil dicatat')
      setReceipt({ type: 'ewallet', data: res.data })
      setShowReceipt(true)
      setData(prev => [res.data, ...prev])
      setForm({ jenis_transaksi: 'top_up', provider: 'BRILink Mobile', nama_provider: '', jenis_ewallet: 'DANA', nomor_ewallet: '', nama_customer: '', nominal: '', keterangan: '' })
      setShowForm(false)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan transaksi e-wallet'))
    } finally {
      setLoading(false)
    }
  }

  const totalAdmin = data.reduce((sum, item) => sum + Number(item.biaya_admin || 0), 0)
  const previewNominal = parseNominalInput(form.nominal)
  const previewAdmin = hitung('ewallet', previewNominal)
  const previewTotal = previewNominal + previewAdmin

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div>
          <span>Layanan E-Wallet</span>
          <h1>Top Up & Pencairan E-Wallet</h1>
          <p>Catat transaksi top up dan pencairan dana e-wallet seperti DANA, OVO, GoPay, dan ShopeePay.</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="brilink-primary-button"><Plus size={19} /> Transaksi Baru</button>
      </div>

      <div className="brilink-summary-grid two">
        <div className="brilink-summary-card"><div className="summary-icon blue"><WalletCards size={22} /></div><span>Total Transaksi</span><strong>{data.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><RefreshCcw size={22} /></div><span>Total Admin</span><strong>{formatRupiah(totalAdmin)}</strong></div>
      </div>

      {showForm && (
        <section className="brilink-form-card">
          <div className="brilink-section-title"><WalletCards size={20} /><div><h2>Form E-Wallet</h2><p>Nominal akan dihitung dengan biaya admin range bertingkat.</p></div></div>
          <form onSubmit={submit} className="brilink-form-grid">
            <ProviderFields form={form} setForm={setForm} />
            <label className="field-group"><span>Jenis Transaksi</span><select value={form.jenis_transaksi} onChange={e => setForm({ ...form, jenis_transaksi: e.target.value })}><option value="top_up">Top Up</option><option value="pencairan">Pencairan Dana</option></select></label>
            <label className="field-group"><span>Jenis E-Wallet</span><select value={form.jenis_ewallet} onChange={e => setForm({ ...form, jenis_ewallet: e.target.value })}><option>DANA</option><option>OVO</option><option>GoPay</option><option>ShopeePay</option><option>LinkAja</option><option>Lainnya</option></select></label>
            <label className="field-group"><span>Nomor E-Wallet</span><input maxLength={30} value={form.nomor_ewallet} onChange={e => setForm({ ...form, nomor_ewallet: e.target.value })} required placeholder="08xxxxxxxxxx" /></label>
            <label className="field-group"><span>Nama Customer</span><input maxLength={100} value={form.nama_customer} onChange={e => setForm({ ...form, nama_customer: e.target.value })} placeholder="Opsional" /></label>
            <label className="field-group"><span>Nominal</span><input type="text" inputMode="numeric" value={form.nominal} onChange={e => setForm({ ...form, nominal: formatNominalInput(e.target.value) })} required placeholder="Contoh: 50.000" /></label>
            <label className="field-group"><span>Keterangan</span><input value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} placeholder="Catatan tambahan" /></label>
            <div className="brilink-calculation-box full"><span>Admin otomatis: <strong>{formatRupiah(previewAdmin)}</strong></span><span>Total bayar: <strong>{formatRupiah(previewTotal)}</strong></span></div>
            <div className="form-actions full"><button type="button" onClick={() => setShowForm(false)} className="secondary-button">Batal</button><button type="submit" disabled={loading} className="primary-action-button"><Plus size={18} />{loading ? 'Menyimpan...' : 'Simpan Transaksi'}</button></div>
          </form>
        </section>
      )}

      <section className="brilink-table-panel">
        <div className="brilink-table-header"><div><h2>Riwayat E-Wallet</h2><p>{data.length} transaksi tercatat</p></div></div>
        <div className="brilink-table-wrap">
          <table className="brilink-table ewallet-table">
            <colgroup>
              <col className="ewallet-code-col" />
              <col className="ewallet-date-col" />
              <col className="ewallet-type-col" />
              <col className="ewallet-provider-col" />
              <col className="ewallet-provider-col" />
              <col className="ewallet-number-col" />
              <col className="ewallet-money-col" />
              <col className="ewallet-money-col" />
              <col className="ewallet-money-col" />
            </colgroup>
            <thead><tr><th>Kode</th><th>Tanggal</th><th>Jenis</th><th>Provider</th><th>E-Wallet</th><th>Nomor</th><th className="money-header">Nominal</th><th className="money-header">Admin</th><th className="money-header">Total</th></tr></thead>
            <tbody>{data.map(item => (
              <tr key={item.id}>
                <td><span className="item-code">{item.kode_transaksi}</span></td>
                <td className="nowrap">{new Date(item.tanggal).toLocaleDateString('id-ID')}</td>
                <td><span className="service-pill blue">{item.jenis_transaksi === 'top_up' ? 'Top Up' : 'Pencairan'}</span></td>
                <td>{item.provider}</td>
                <td>{item.jenis_ewallet || item.provider}</td>
                <td>{item.nomor_ewallet}</td>
                <td className="money-cell">{formatRupiah(item.nominal)}</td>
                <td className="money-cell admin">{formatRupiah(item.biaya_admin)}</td>
                <td className="money-cell strong">{formatRupiah(item.total_bayar)}</td>
              </tr>
            ))}</tbody>
          </table>
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
