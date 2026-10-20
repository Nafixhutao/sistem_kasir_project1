import React, { useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { LogIn, Eye, EyeOff, ShieldCheck, User, LockKeyhole, KeyRound, ArrowLeft, ReceiptText, WalletCards, Package, BarChart3 } from 'lucide-react'
import api, { getApiErrorMessage } from '../services/api'
import sultanCellLogoRound from '../assets/sultan-cell-logo-round.png'

export default function Login() {
  const location = useLocation()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState(location.state?.message || '')
  const [messageType, setMessageType] = useState(location.state?.message ? 'success' : 'error')
  const [usernameError, setUsernameError] = useState('')
  const [passwordError, setPasswordError] = useState('')
  const [loading, setLoading] = useState(false)
  const { login } = useAuth()
  const [mode, setMode] = useState('login')
  const [newPassword, setNewPassword] = useState('')
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('')
  const [adminPassword, setAdminPassword] = useState('')
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showAdminPassword, setShowAdminPassword] = useState(false)
  const [lupaLoading, setLupaLoading] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    setMessageType('error')
    setUsernameError('')
    setPasswordError('')

    if (!username.trim()) {
      setUsernameError('Username wajib diisi.')
      setLoading(false)
      return
    }

    if (!password.trim()) {
      setPasswordError('Password wajib diisi.')
      setLoading(false)
      return
    }

    const result = await login(username, password)
    if (!result.success) {
      setError(result.message)
    }
    setLoading(false)
  }

  const backToLogin = () => {
    setMode('login')
    setError('')
    setMessageType('error')
    setNewPassword('')
    setNewPasswordConfirmation('')
    setAdminPassword('')
  }

  const handleLupaPassword = async (e) => {
    e.preventDefault()
    setLupaLoading(true)
    setError('')
    setMessageType('error')

    if (!username.trim()) { setError('Username wajib diisi.'); setLupaLoading(false); return }
    if (!newPassword) { setError('Password baru wajib diisi.'); setLupaLoading(false); return }
    if (newPassword !== newPasswordConfirmation) { setError('Konfirmasi password baru tidak cocok.'); setLupaLoading(false); return }
    if (!adminPassword) { setError('Password admin wajib diisi sebagai otorisasi reset.'); setLupaLoading(false); return }

    try {
      const res = await api.post('/lupa-password', {
        username,
        password: newPassword,
        password_confirmation: newPasswordConfirmation,
        admin_password: adminPassword,
      })
      backToLogin()
      setError(res.data.message)
      setMessageType('success')
    } catch (e2) {
      setError(getApiErrorMessage(e2, 'Reset password gagal. Silakan coba lagi.'))
    }
    setLupaLoading(false)
  }

  const handleForgotPassword = () => {
    setUsernameError('')
    setPasswordError('')
    setMessageType('error')
    setError('')
    setMode('lupa')
  }

  return (
    <main className="login-page">
      <section className="login-brand-panel">
        <div className="login-brand-lockup">
          <div className="brand-mark">
            <img src={sultanCellLogoRound} alt="Sultan Cell" />
          </div>
          <div>
            <h1>Sultan <span>Cell</span></h1>
            <p className="brand-kicker">Konter HP & Agen BRILink</p>
          </div>
        </div>

        <div className="login-copy">
          <h2>Sistem Operasional <span>Sultan Cell</span></h2>
          <p>Kelola kasir, stok barang, dan transaksi harian dengan lebih mudah dan rapi.</p>
        </div>

        <div className="login-feature-grid">
          <div className="login-feature-card">
            <div className="login-feature-icon blue"><ReceiptText size={23} /></div>
            <div>
              <strong>Penjualan</strong>
              <span>Transaksi kasir cepat dan mudah</span>
            </div>
          </div>
          <div className="login-feature-card">
            <div className="login-feature-icon green"><WalletCards size={23} /></div>
            <div>
              <strong>BRILink</strong>
              <span>Pengelolaan transfer, tarik tunai, setor tunai, tagihan</span>
            </div>
          </div>
          <div className="login-feature-card">
            <div className="login-feature-icon purple"><Package size={23} /></div>
            <div>
              <strong>Stok Barang</strong>
              <span>Kelola stok barang secara real-time</span>
            </div>
          </div>
          <div className="login-feature-card">
            <div className="login-feature-icon orange"><BarChart3 size={23} /></div>
            <div>
              <strong>Laporan</strong>
              <span>Laporan penjualan dan transaksi harian</span>
            </div>
          </div>
        </div>

        <p className="login-copyright login-copyright-left">© 2026 Sultan Cell - Konter HP & Agen BRILink</p>
      </section>

      <section className="login-form-panel" aria-label="Form login">
        <div className="login-card">
          <div className="login-welcome">
            <h2>Selamat Datang!</h2>
            <p>Silakan masuk untuk membuka dashboard operasional.</p>
          </div>

          <form onSubmit={mode === 'lupa' ? handleLupaPassword : handleSubmit} className="login-form" noValidate>
            {error && <div className={`form-error animate-fadeIn ${messageType === 'success' ? 'success' : ''}`}>{error}</div>}
            <label className="field-group">
              <span>Username</span>
              <div className={`input-with-icon ${usernameError ? 'has-error' : ''}`}>
                <User size={20} />
                <input type="text" name="username" maxLength={50} value={username} onChange={e => { setUsername(e.target.value); setUsernameError('') }} placeholder="Masukkan username" required />
              </div>
              {usernameError && <p className="field-warning animate-fadeIn">{usernameError}</p>}
            </label>

              {mode === 'lupa' ? (
                <>
                  <div className="login-admin-note">
                    <KeyRound size={18} />
                    <span>Buat password baru: isi username akun Anda, lalu minta pemilik toko memasukkan password admin sebagai otorisasi.</span>
                  </div>
                  <label className="field-group">
                    <span>Password Baru</span>
                    <div className="password-field input-with-icon">
                      <LockKeyhole size={19} />
                      <input type={showNewPassword ? 'text' : 'password'} value={newPassword} onChange={e => setNewPassword(e.target.value)} placeholder="Masukkan password baru" required minLength={8} />
                      <button type="button" onClick={() => setShowNewPassword(!showNewPassword)} aria-label={showNewPassword ? 'Sembunyikan password baru' : 'Tampilkan password baru'}>
                        {showNewPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                      </button>
                    </div>
                  </label>
                  <label className="field-group">
                    <span>Konfirmasi Password Baru</span>
                    <div className="password-field input-with-icon">
                      <LockKeyhole size={19} />
                      <input type={showNewPassword ? 'text' : 'password'} value={newPasswordConfirmation} onChange={e => setNewPasswordConfirmation(e.target.value)} placeholder="Ulangi password baru" required />
                    </div>
                  </label>
                  <label className="field-group">
                    <span>Password Admin (Otorisasi)</span>
                    <div className="password-field input-with-icon">
                      <ShieldCheck size={19} />
                      <input type={showAdminPassword ? 'text' : 'password'} value={adminPassword} onChange={e => setAdminPassword(e.target.value)} placeholder="Masukkan password admin" required />
                      <button type="button" onClick={() => setShowAdminPassword(!showAdminPassword)} aria-label={showAdminPassword ? 'Sembunyikan password admin' : 'Tampilkan password admin'}>
                        {showAdminPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                      </button>
                    </div>
                  </label>
                  <div className="login-options">
                    <button type="button" onClick={backToLogin}><ArrowLeft size={14} style={{ verticalAlign: '-2px' }} /> Kembali ke login</button>
                  </div>
                  <button type="submit" disabled={lupaLoading} className="primary-login-button">
                    <KeyRound size={19} />
                    {lupaLoading ? 'Memproses...' : 'Buat Password Baru'}
                  </button>
                </>
              ) : (
                <>
                  <label className="field-group">
                    <span>Password</span>
                    <div className={`password-field input-with-icon ${passwordError ? 'has-error' : ''}`}>
                      <LockKeyhole size={19} />
                      <input type={showPassword ? 'text' : 'password'} value={password} onChange={e => { setPassword(e.target.value); setPasswordError('') }} placeholder="Masukkan password" required />
                      <button type="button" onClick={() => setShowPassword(!showPassword)} aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}>
                        {showPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                      </button>
                    </div>
                    {passwordError && <p className="field-warning animate-fadeIn">{passwordError}</p>}
                  </label>
                  <div className="login-options">
                    <label>
                      <input type="checkbox" defaultChecked />
                      <span>Ingat saya</span>
                    </label>
                    <button type="button" onClick={handleForgotPassword}>Lupa password?</button>
                  </div>
                  <button type="submit" disabled={loading} className="primary-login-button">
                    <LogIn size={19} />
                    {loading ? 'Memproses...' : 'Masuk'}
                  </button>
                </>
              )}
            <div className="login-divider"><span>atau</span></div>
            <div className="login-admin-note">
              <ShieldCheck size={18} />
              <span>Akses khusus kasir &amp; admin Sultan Cell.</span>
            </div>
          </form>

        </div>
      </section>
    </main>
  )
}
