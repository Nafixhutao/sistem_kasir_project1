import React, { useEffect, useState } from 'react'
import { Plus, Search, Edit3, Trash2, Package } from 'lucide-react'
import { useAuth } from '../hooks/useAuth'
import api, { getApiErrorMessage } from '../services/api'
import Modal from '../components/Common/Modal'
import toast from 'react-hot-toast'

const empty = { nama_supplier: '', no_telepon: '', alamat: '', keterangan: '' }
export default function DataSupplier() {
  const { user } = useAuth()
  const canManage = user?.role === 'admin'
  const [suppliers, setSuppliers] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(empty)
  const [target, setTarget] = useState(null)
  const [busy, setBusy] = useState(false)
  const load = async () => {
    try { setSuppliers((await api.get('/suppliers')).data) }
    catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat supplier')) }
    finally { setLoading(false) }
  }
  useEffect(() => { load() }, [])
  const edit = item => {
    if (!canManage) return
    setEditing(item)
    setForm(Object.fromEntries(Object.keys(empty).map(key => [key, item?.[key] ?? ''])))
    setOpen(true)
  }
  const close = () => { if (!busy) { setOpen(false); setEditing(null); setForm(empty) } }
  const save = async e => {
    e.preventDefault()
    if (!canManage || busy) return
    setBusy(true)
    try {
      if (editing) await api.put(`/suppliers/${editing.id}`, form)
      else await api.post('/suppliers', form)
      setOpen(false); setEditing(null); setForm(empty)
      toast.success('Supplier berhasil disimpan'); await load()
    } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menyimpan supplier')) }
    finally { setBusy(false) }
  }
  const remove = async () => {
    if (!canManage || !target || busy) return
    setBusy(true)
    try { await api.delete(`/suppliers/${target.id}`); setTarget(null); toast.success('Supplier berhasil dihapus'); await load() }
    catch (e) { toast.error(getApiErrorMessage(e, 'Gagal menghapus supplier')) }
    finally { setBusy(false) }
  }
  const filtered = suppliers.filter(item => [item.nama_supplier, item.no_telepon, item.alamat].some(value => String(value ?? '').toLowerCase().includes(search.toLowerCase())))
  return <div className="inventory-page">
    <div className="page-heading inventory-heading"><div><span>Inventaris Toko</span><h1>Data Supplier</h1><p>{canManage ? 'Kelola supplier utama barang Sultan Cell.' : 'Lihat supplier barang Sultan Cell.'}</p></div>
      {canManage && <button className="inventory-add-button" onClick={() => edit(null)}><Plus size={19} /><span>Tambah Supplier</span></button>}
    </div>
    <section className="inventory-panel">
      <div className="inventory-toolbar"><div><h2>Daftar Supplier</h2><p>{filtered.length} supplier ditampilkan</p></div><label className="inventory-search"><Search size={19} /><input maxLength={150} placeholder="Cari nama, telepon, atau alamat..." value={search} onChange={e => setSearch(e.target.value)} /></label></div>
      <div className="inventory-table-wrap"><table className="inventory-table supplier-table"><colgroup><col className="supplier-name-col" /><col className="supplier-phone-col" /><col className="supplier-address-col" /><col className="supplier-note-col" /><col className="supplier-count-col" />{canManage && <col className="supplier-action-col" />}</colgroup><thead><tr><th>Nama Supplier</th><th>No. Telepon</th><th>Alamat</th><th>Keterangan</th><th>Jumlah Barang</th>{canManage && <th>Aksi</th>}</tr></thead>
        <tbody>{filtered.map(item => <tr key={item.id}><td>{item.nama_supplier}</td><td>{item.no_telepon || '-'}</td><td>{item.alamat || '-'}</td><td>{item.keterangan || '-'}</td><td>{item.barang_count}</td>{canManage && <td><div className="table-actions"><button className="icon-action edit" aria-label={`Edit ${item.nama_supplier}`} onClick={() => edit(item)}><Edit3 size={17} /></button><button className="icon-action delete" aria-label={`Hapus ${item.nama_supplier}`} disabled={busy || item.barang_count > 0} title={item.barang_count > 0 ? 'Masih digunakan Barang' : 'Hapus Supplier'} onClick={() => setTarget(item)}><Trash2 size={17} /></button></div></td>}</tr>)}</tbody>
      </table>{!loading && !filtered.length && <div className="inventory-empty"><Package size={40} /><p>Tidak ada data supplier</p></div>}</div>
    </section>
    <Modal isOpen={canManage && open} onClose={close} title={editing ? 'Edit Supplier' : 'Tambah Supplier'}>
      <form className="inventory-form" onSubmit={save}>
        <label className="field-group"><span>Nama Supplier</span><input required maxLength={150} value={form.nama_supplier} onChange={e => setForm({ ...form, nama_supplier: e.target.value })} /></label>
        <label className="field-group"><span>No. Telepon (opsional)</span><input type="tel" maxLength={30} value={form.no_telepon} onChange={e => setForm({ ...form, no_telepon: e.target.value })} /></label>
        <label className="field-group"><span>Alamat (opsional)</span><textarea maxLength={255} value={form.alamat} onChange={e => setForm({ ...form, alamat: e.target.value })} /></label>
        <label className="field-group"><span>Keterangan (opsional)</span><textarea maxLength={500} value={form.keterangan} onChange={e => setForm({ ...form, keterangan: e.target.value })} /></label>
        <div className="form-actions"><button type="button" className="secondary-button" disabled={busy} onClick={close}>Batal</button><button className="primary-action-button" disabled={busy}>{busy ? 'Menyimpan...' : 'Simpan Supplier'}</button></div>
      </form>
    </Modal>
    <Modal isOpen={canManage && Boolean(target)} onClose={() => !busy && setTarget(null)} title="Hapus Supplier" size="sm">
      <div className="confirm-action-content"><p>Hapus supplier {target?.nama_supplier}? Supplier yang masih digunakan Barang tidak dapat dihapus.</p><div className="modal-form-actions"><button className="secondary-button" disabled={busy} onClick={() => setTarget(null)}>Batal</button><button className="ui-button danger" disabled={busy} onClick={remove}>{busy ? 'Menghapus...' : 'Hapus Supplier'}</button></div></div>
    </Modal>
  </div>
}
