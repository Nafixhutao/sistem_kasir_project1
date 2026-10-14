import React, { useState } from 'react'
import api, { getApiErrorMessage } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'

export default function ChangePasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const { passwordChanged } = useAuth()

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (Array.from(password).length < 8 || new TextEncoder().encode(password).length > 72 || password.includes('\0')) {
      return setError('Password baru minimal 8 karakter, maksimal 72 byte, tanpa karakter NUL.')
    }
    if (password !== confirmation) return setError('Konfirmasi password tidak cocok.')
    setLoading(true)
    try {
      await api.post('/change-password', { current_password: currentPassword, password, password_confirmation: confirmation })
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      passwordChanged()
    } catch (err) {
      setError(getApiErrorMessage(err, 'Gagal mengubah password.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="brilink-form-card">
      <div className="brilink-section-title"><div><h2>Ubah Password</h2><p>Seluruh sesi akun Anda akan dicabut. Login kembali setelah berhasil.</p></div></div>
      <form onSubmit={submit} className="brilink-form-grid">
        {error && <div className="form-alert error full" role="alert">{error}</div>}
        <label className="field-group full"><span>Password Lama</span><input type="password" autoComplete="current-password" value={currentPassword} onChange={e => setCurrentPassword(e.target.value)} required /></label>
        <label className="field-group full"><span>Password Baru</span><input type="password" autoComplete="new-password" minLength={8} maxLength={72} value={password} onChange={e => setPassword(e.target.value)} required /><small>Minimal 8 karakter, maksimal 72 byte. Tidak wajib simbol.</small></label>
        <label className="field-group full"><span>Konfirmasi Password Baru</span><input type="password" autoComplete="new-password" maxLength={72} value={confirmation} onChange={e => setConfirmation(e.target.value)} required /></label>
        <div className="form-actions full"><button type="submit" className="primary-action-button" disabled={loading}>{loading ? 'Menyimpan...' : 'Ubah Password'}</button></div>
      </form>
    </section>
  )
}
