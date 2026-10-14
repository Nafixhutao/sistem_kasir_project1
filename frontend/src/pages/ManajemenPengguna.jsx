import React, { useEffect, useState } from 'react'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'

const empty = { name: '', username: '', password: '', password_confirmation: '' }
export default function ManajemenPengguna() {
  const [users, setUsers] = useState([])
  const [form, setForm] = useState(empty)
  const [target, setTarget] = useState(null)
  const [reset, setReset] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const load = async () => { try { setUsers((await api.get('/users')).data) } catch (e) { toast.error(getApiErrorMessage(e, 'Gagal memuat Kasir')) } }
  useEffect(() => { load() }, [])
  const create = async e => {
    e.preventDefault(); setBusy(true)
    try { await api.post('/users', form); setForm(empty); toast.success('Kasir berhasil dibuat'); await load() }
    catch (e) { toast.error(getApiErrorMessage(e, 'Gagal membuat Kasir')) }
    finally { setBusy(false) }
  }
  const toggle = async user => {
    if (!window.confirm(`${user.is_active ? 'Nonaktifkan' : 'Aktifkan'} Kasir ${user.username}?`)) return
    setBusy(true)
    try { await api.patch(`/users/${user.id}/status`, { is_active: !user.is_active }); await load() }
    catch (e) { toast.error(getApiErrorMessage(e, 'Gagal mengubah status')) }
    finally { setBusy(false) }
  }
  const submitReset = async e => {
    e.preventDefault(); setBusy(true)
    try { await api.post(`/users/${target.id}/reset-password`, reset); setReset({ current_password: '', password: '', password_confirmation: '' }); setTarget(null); toast.success('Password Kasir direset; sesi Kasir dicabut') }
    catch (e) { toast.error(getApiErrorMessage(e, 'Reset gagal')) }
    finally { setBusy(false) }
  }
  return <div className="brilink-page user-management-page">
    <div className="page-heading"><div><span>Pengaturan Sistem</span><h1>Manajemen Pengguna</h1><p>Kelola akun Kasir tanpa mengubah role atau data operasional.</p></div></div>
    <section className="brilink-form-card"><div className="brilink-section-title"><h2>Tambah Kasir</h2></div><form className="brilink-form-grid" onSubmit={create}>
      {['name','username'].map(key => <label className="field-group" key={key}><span>{key === 'name' ? 'Nama' : 'Username'}</span><input required maxLength={50} autoComplete="off" value={form[key]} onChange={e => setForm({ ...form, [key]: e.target.value })} /></label>)}
      {['password','password_confirmation'].map(key => <label className="field-group" key={key}><span>{key === 'password' ? 'Password Kasir Baru' : 'Konfirmasi Password'}</span><input type="password" required minLength={8} maxLength={72} autoComplete="new-password" value={form[key]} onChange={e => setForm({ ...form, [key]: e.target.value })} /></label>)}
      <div className="form-actions full"><button disabled={busy} className="primary-action-button">Tambah Kasir</button></div>
    </form></section>
    <section className="brilink-table-panel"><div className="brilink-table-header"><div><h2>Daftar Kasir</h2><p>{users.length} akun ditampilkan</p></div></div><div className="brilink-table-wrap"><table className="brilink-table"><thead><tr><th>Nama</th><th>Username</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{users.map(user => <tr key={user.id}><td>{user.name}</td><td>{user.username}</td><td>{user.is_active ? 'Aktif' : 'Nonaktif'}</td><td><div className="user-management-actions"><button className="secondary-button" disabled={busy} onClick={() => toggle(user)}>{user.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button><button className="secondary-button" disabled={busy} onClick={() => { setTarget(user); setReset({ current_password: '', password: '', password_confirmation: '' }) }}>Reset Password</button></div></td></tr>)}</tbody></table></div></section>
    {target && <section className="brilink-form-card"><div className="brilink-section-title"><h2>Reset password {target.username}</h2></div><form className="brilink-form-grid" onSubmit={submitReset}>
      {['current_password','password','password_confirmation'].map(key => <label className="field-group" key={key}><span>{key === 'current_password' ? 'Password Anda (Admin)' : key === 'password' ? 'Password Baru Kasir' : 'Konfirmasi Password Baru'}</span><input type="password" required minLength={key === 'current_password' ? undefined : 8} maxLength={72} autoComplete={key === 'current_password' ? 'current-password' : 'new-password'} value={reset[key]} onChange={e => setReset({ ...reset, [key]: e.target.value })} /></label>)}
      <div className="form-actions full"><button disabled={busy} className="primary-action-button">Reset Password Kasir</button><button type="button" className="secondary-button" disabled={busy} onClick={() => { setTarget(null); setReset({ current_password: '', password: '', password_confirmation: '' }) }}>Batal</button></div>
    </form></section>}
  </div>
}
