import React from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { LayoutDashboard, Package, ShoppingCart, FileText, Send, Wallet, HandCoins, Receipt, Smartphone, History, Settings, LogOut, ChevronLeft, ChevronRight, WalletCards, ReceiptText, BadgeDollarSign, Truck, PackageMinus, Undo2, UsersRound } from 'lucide-react'
import sultanCellLogo from '../../assets/sultan-cell-logo-round.png'

const menuItems = [
  { divider: true, label: 'OPERASIONAL TOKO' },
  { path: '/dashboard', icon: LayoutDashboard, label: 'Dashboard' },
  { path: '/barang', icon: Package, label: 'Data Barang' },
  { path: '/data-supplier', icon: Truck, label: 'Data Supplier' },
  { path: '/transaksi-penjualan', icon: ShoppingCart, label: 'Transaksi Penjualan' },
  { path: '/laporan-penjualan', icon: FileText, label: 'Laporan Penjualan' },
  { path: '/pengeluaran-toko', icon: ReceiptText, label: 'Pengeluaran Toko' },
  { path: '/retur-supplier', icon: PackageMinus, label: 'Retur Supplier' },
  { path: '/retur-barang-pelanggan', icon: Undo2, label: 'Retur Barang Pelanggan' },
  { divider: true, label: 'LAYANAN BRILINK' },
  { path: '/transfer', icon: Send, label: 'Transfer' },
  { path: '/tarik-tunai', icon: Wallet, label: 'Tarik Tunai' },
  { path: '/setor-tunai', icon: HandCoins, label: 'Setor Tunai' },
  { path: '/tagihan', icon: Receipt, label: 'Pembayaran Tagihan' },
  { path: '/pulsa', icon: Smartphone, label: 'Pulsa & Paket Data' },
  { path: '/ewallet', icon: WalletCards, label: 'Top Up / Cair E-Wallet' },
  { path: '/riwayat-brilink', icon: History, label: 'Riwayat BRILink' },
  { path: '/biaya-admin', icon: BadgeDollarSign, label: 'Biaya Admin' },
  { divider: true, label: '' },
  { path: '/pengaturan', icon: Settings, label: 'Pengaturan' },
]

export default function Sidebar({ isOpen, toggleSidebar, onLogout, role }) {
  const navigate = useNavigate()
  const ownerPaths = ['/dashboard','/barang','/data-supplier','/laporan-penjualan','/pengeluaran-toko','/retur-supplier','/retur-barang-pelanggan','/riwayat-brilink','/biaya-admin','/pengaturan']
  const settingsPaths = ['/biaya-admin', '/pengaturan']
  const items = role === 'admin' ? [
    { divider: true, label: 'MONITORING OPERASIONAL' },
    ...menuItems.filter(item => ownerPaths.includes(item.path) && !settingsPaths.includes(item.path)),
    { divider: true, label: 'PENGATURAN SISTEM' },
    ...menuItems.filter(item => settingsPaths.includes(item.path)),
    { path: '/manajemen-pengguna', icon: UsersRound, label: 'Manajemen Pengguna' },
  ] : menuItems

  const navigateInCurrentTab = (event, path) => {
    if (event.button === 2) return
    event.preventDefault()
    navigate(path)
  }

  return (
    <aside className={`app-sidebar ${isOpen ? 'sidebar-open' : 'sidebar-closed'}`}>
      <div className="sidebar-brand">
        <div className="sidebar-brand-lockup">
          <button type="button" className="sidebar-logo" onClick={() => !isOpen && toggleSidebar()} aria-label="Buka sidebar">
            <img src={sultanCellLogo} alt="Sultan Cell" />
          </button>
          {isOpen && (
            <div>
              <span>Sultan Cell</span>
              <p>{role === 'admin' ? 'Admin' : 'Kasir'}</p>
            </div>
          )}
        </div>
        <button onClick={toggleSidebar} className="sidebar-toggle" aria-label={isOpen ? 'Tutup sidebar' : 'Buka sidebar'}>
          {isOpen ? <ChevronLeft size={20} /> : <ChevronRight size={20} />}
        </button>
      </div>
      <nav className="sidebar-nav">
        {items.map((item, index) => {
          if (item.divider) return (
            <div key={index} className={`sidebar-divider ${index === 0 ? 'first' : ''}`}>
              {isOpen && <p>{item.label}</p>}
            </div>
          )
          return (
            <NavLink key={item.path} to={item.path}
              onClick={(event) => navigateInCurrentTab(event, item.path)}
              onAuxClick={(event) => navigateInCurrentTab(event, item.path)}
              className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}>
              <item.icon size={20} />
              {isOpen && <span>{item.label}</span>}
            </NavLink>
          )
        })}
      </nav>
      <div className="sidebar-footer">
        <button onClick={onLogout} className="logout-button">
          <LogOut size={20} />
          {isOpen && <span>Logout</span>}
        </button>
      </div>
    </aside>
  )
}
