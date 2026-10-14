import axios from 'axios'

const apiBaseUrl = import.meta.env.VITE_API_URL || '/api'

const api = axios.create({
  baseURL: apiBaseUrl,
  headers: { 'Content-Type': 'application/json' },
})

export const getApiErrorMessage = (error, fallback = 'Terjadi kesalahan. Coba lagi.') => {
  if (!error.response) {
    return 'Tidak bisa terhubung ke backend. Pastikan server Laravel sedang berjalan.'
  }

  if (error.response.status === 429) {
    const seconds = error.response.headers?.['retry-after'] || 60
    return `Terlalu banyak percobaan. Coba kembali dalam ${seconds} detik.`
  }

  const data = error.response.data || {}
  const firstError = data.errors ? Object.values(data.errors).flat()[0] : null
  return firstError || data.message || fallback
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const requestUrl = error.config?.url || ''
    const isAuthRequest = requestUrl.includes('/login')
    if (error.response && error.response.status === 401 && !isAuthRequest) {
      localStorage.removeItem('token')
      window.dispatchEvent(new Event('auth-token-changed'))
    }
    return Promise.reject(error)
  }
)

export default api
