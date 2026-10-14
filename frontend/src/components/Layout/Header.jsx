import React, { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Bell, Calendar, CheckCircle2, Clock, PackageMinus, PackageX, RefreshCw, RotateCcw, User, XCircle } from 'lucide-react'
import api, { getApiErrorMessage } from '../../services/api'

export default function Header({ user }) {
  const [now, setNow] = useState(new Date())
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notifications, setNotifications] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  const wrapperRef = useRef(null)
  const navigate = useNavigate()

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(timer)
  }, [])

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await api.get('/notifikasi/unread-count')
      setUnreadCount(Number(response.data?.unread_count || 0))
    } catch {
      // Count polling should stay quiet; dropdown fetch shows actionable errors.
    }
  }, [])

  const fetchNotifications = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/notifikasi', { params: { limit: 10 } })
      setNotifications(response.data?.data || [])
      setUnreadCount(Number(response.data?.unread_count || 0))
    } catch (err) {
      setError(getApiErrorMessage(err, 'Gagal memuat notifikasi.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchUnreadCount()
    const timer = setInterval(fetchUnreadCount, 45000)
    return () => clearInterval(timer)
  }, [fetchUnreadCount])

  useEffect(() => {
    if (open) fetchNotifications()
  }, [open, fetchNotifications])

  useEffect(() => {
    const closeOnOutsideClick = (event) => {
      if (wrapperRef.current && !wrapperRef.current.contains(event.target)) setOpen(false)
    }
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('mousedown', closeOnOutsideClick)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [])

  const today = now.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
  const time = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).replace(/\./g, ':')
  const hasUnread = unreadCount > 0
  const unreadLabel = unreadCount > 99 ? '99+' : String(unreadCount)

  const relativeTime = (value) => {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    const diffSeconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000))
    if (diffSeconds < 60) return 'baru saja'
    const diffMinutes = Math.floor(diffSeconds / 60)
    if (diffMinutes < 60) return `${diffMinutes} menit lalu`
    const diffHours = Math.floor(diffMinutes / 60)
    if (diffHours < 24) return `${diffHours} jam lalu`
    const diffDays = Math.floor(diffHours / 24)
    if (diffDays < 30) return `${diffDays} hari lalu`
    return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
  }

  const notificationIcon = (type) => {
    if (type === 'stok_habis') return <PackageX size={18} />
    if (type === 'stok_menipis') return <PackageMinus size={18} />
    if (type === 'qris_berhasil') return <CheckCircle2 size={18} />
    if (type === 'qris_gagal') return <XCircle size={18} />
    if (type?.startsWith('retur_')) return <RotateCcw size={18} />
    return <AlertTriangle size={18} />
  }

  const markNotificationRead = async (notification) => {
    try {
      if (!notification.dibaca_at) {
        const response = await api.patch(`/notifikasi/${notification.id}/read`)
        setNotifications((items) => items.map((item) => item.id === notification.id ? response.data : item))
        setUnreadCount((count) => Math.max(count - 1, 0))
      }
      if (notification.url) {
        setOpen(false)
        const ownerPaths = ['/dashboard','/barang','/data-supplier','/laporan-penjualan','/retur-supplier','/retur-barang-pelanggan','/pengeluaran-toko','/riwayat-brilink','/biaya-admin','/pengaturan','/manajemen-pengguna']
        navigate(user?.role === 'admin' && !ownerPaths.includes(notification.url.split('?')[0]) ? '/dashboard' : notification.url)
      }
    } catch (err) {
      setError(getApiErrorMessage(err, 'Gagal menandai notifikasi.'))
    }
  }

  const markAllRead = async () => {
    try {
      await api.patch('/notifikasi/read-all')
      setNotifications((items) => items.map((item) => ({ ...item, dibaca_at: item.dibaca_at || new Date().toISOString() })))
      setUnreadCount(0)
    } catch (err) {
      setError(getApiErrorMessage(err, 'Gagal menandai semua notifikasi.'))
    }
  }

  return (
    <header className="topbar">
      <div className="topbar-date">
        <Calendar size={20} />
        <span>{today}</span>
        <span className="topbar-time-divider"></span>
        <Clock size={18} />
        <span>{time}</span>
      </div>
      <div className="topbar-actions">
        <div className="notification-wrapper" ref={wrapperRef}>
          <button
            type="button"
            className="notification-button"
            aria-label={hasUnread ? `Notifikasi, ${unreadLabel} belum dibaca` : 'Notifikasi'}
            aria-haspopup="menu"
            aria-expanded={open}
            onClick={() => setOpen((value) => !value)}
          >
            <Bell size={20} />
            {hasUnread && <span className="notification-badge">{unreadLabel}</span>}
          </button>
          {open && (
            <div className="notification-dropdown" role="menu" aria-label="Daftar notifikasi">
              <div className="notification-dropdown-header">
                <div>
                  <h2>Notifikasi</h2>
                  <p>{hasUnread ? `${unreadLabel} belum dibaca` : 'Semua sudah dibaca'}</p>
                </div>
                <button type="button" onClick={markAllRead} disabled={!hasUnread || loading}>
                  Tandai semua sudah dibaca
                </button>
              </div>

              {loading && (
                <div className="notification-state">
                  <RefreshCw size={18} className="notification-spinner" />
                  <span>Memuat notifikasi...</span>
                </div>
              )}

              {!loading && error && (
                <div className="notification-state notification-error">
                  <AlertTriangle size={18} />
                  <span>{error}</span>
                  <button type="button" onClick={fetchNotifications}>Coba lagi</button>
                </div>
              )}

              {!loading && !error && notifications.length === 0 && (
                <div className="notification-state">
                  <Bell size={18} />
                  <span>Belum ada notifikasi</span>
                </div>
              )}

              {!loading && !error && notifications.length > 0 && (
                <div className="notification-list">
                  {notifications.map((notification) => {
                    const unread = !notification.dibaca_at
                    return (
                      <button
                        type="button"
                        key={notification.id}
                        role="menuitem"
                        className={`notification-item ${unread ? 'unread' : ''}`}
                        onClick={() => markNotificationRead(notification)}
                      >
                        <span className={`notification-item-icon ${notification.tipe || ''}`}>{notificationIcon(notification.tipe)}</span>
                        <span className="notification-item-body">
                          <strong>{notification.judul}</strong>
                          <span>{notification.pesan}</span>
                          <small>{relativeTime(notification.created_at)}</small>
                        </span>
                        {unread && <span className="notification-unread-dot" aria-label="Belum dibaca"></span>}
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}
        </div>
        <div className="user-chip">
          <div className="user-avatar">
            <User size={17} />
          </div>
          <div>
            <p>{user?.name || 'Admin'}</p>
            <span>{user?.role === 'admin' ? 'Admin' : 'Kasir'}</span>
          </div>
        </div>
      </div>
    </header>
  )
}
