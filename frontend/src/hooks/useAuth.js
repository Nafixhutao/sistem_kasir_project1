import { useState, useEffect, createContext, useContext, createElement } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { getApiErrorMessage } from '../services/api'

const notifyAuthChanged = () => window.dispatchEvent(new Event('auth-token-changed'))

const AuthContext = createContext(null)
export function useAuth() { return useContext(AuthContext) }
export function AuthProvider({ children }) {
  const [token, setToken] = useState(localStorage.getItem('token'))
  const [user, setUser] = useState(null)
  const navigate = useNavigate()

  useEffect(() => {
    if (!token) return
    let cancelled = false
    api.get('/me').then(res => {
      if (!cancelled && localStorage.getItem('token') === token) setUser(res.data)
    }).catch(() => {
      if (!cancelled && localStorage.getItem('token') === token) {
        localStorage.removeItem('token'); setToken(null); setUser(null); notifyAuthChanged()
      }
    })
    return () => { cancelled = true }
  }, [token])

  useEffect(() => {
    const syncToken = () => {
      const nextToken = localStorage.getItem('token')
      if (token !== nextToken) setUser(null)
      setToken(nextToken)
    }
    window.addEventListener('auth-token-changed', syncToken)
    window.addEventListener('storage', syncToken)

    return () => {
      window.removeEventListener('auth-token-changed', syncToken)
      window.removeEventListener('storage', syncToken)
    }
  }, [token])

  const login = async (username, password) => {
    try {
      const res = await api.post('/login', { username, password })
      localStorage.setItem('token', res.data.token)
      setToken(res.data.token)
      setUser(res.data.user)
      notifyAuthChanged()
      navigate('/dashboard')
      return { success: true }
    } catch (e) {
      return { success: false, message: getApiErrorMessage(e, 'Login gagal') }
    }
  }

  const clearSession = (message) => {
    localStorage.removeItem('token')
    setToken(null)
    setUser(null)
    notifyAuthChanged()
    navigate('/login', { replace: true, state: message ? { message } : null })
  }

  const logout = async () => {
    try { await api.post('/logout') } catch (e) {}
    clearSession()
  }

  const passwordChanged = () => clearSession('Password berhasil diubah. Silakan login kembali.')

  return createElement(AuthContext.Provider, { value: { token, user, login, logout, passwordChanged } }, children)
}
