import React, { useEffect, useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import Header from './Header'
import { useAuth } from '../../hooks/useAuth'

export default function Layout() {
  const [sidebarOpen, setSidebarOpen] = useState(() => {
    const saved = localStorage.getItem('sultan-sidebar-open')
    if (saved !== null) return saved === 'true'
    return window.innerWidth > 720
  })
  const { user, logout } = useAuth()

  useEffect(() => {
    localStorage.setItem('sultan-sidebar-open', String(sidebarOpen))
  }, [sidebarOpen])

  return (
    <div className="app-shell">
      <Sidebar isOpen={sidebarOpen} toggleSidebar={() => setSidebarOpen(!sidebarOpen)} onLogout={logout} role={user?.role} />
      <div className="app-content">
        <Header user={user} />
        <main className="app-main">
          <div className="page-transition"><Outlet /></div>
        </main>
      </div>
    </div>
  )
}
