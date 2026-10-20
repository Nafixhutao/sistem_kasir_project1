import React, { useEffect, useState } from 'react'
import { Activity, DatabaseBackup, Download, Search, Settings, Upload } from 'lucide-react'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'
import Modal from '../components/Common/Modal'
import { formatDateTime } from '../utils/formatDateTime'

export default function Pengaturan() {
  const { user } = useAuth()
  const canWrite = user?.role === 'kasir'
  const [activeTab, setActiveTab] = useState('aktivitas')
  const [activityData, setActivityData] = useState([])
  const [module, setModule] = useState('semua')
  const [search, setSearch] = useState('')
  const [backupLoading, setBackupLoading] = useState(false)
  const [restoreLoading, setRestoreLoading] = useState(false)
  const [backupFile, setBackupFile] = useState(null)
  const [confirmText, setConfirmText] = useState('')
  const [showRestoreConfirm, setShowRestoreConfirm] = useState(false)

  useEffect(() => { fetchActivity() }, [module])

  const fetchActivity = async () => {
    try {
      const res = await api.get(`/activity-logs?module=${module}&search=${encodeURIComponent(search)}`)
      setActivityData(res.data)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat riwayat aktivitas'))
    }
  }

  const downloadBackup = async () => {
    setBackupLoading(true)
    try {
      const res = await api.get('/backup-data')
      const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `backup-sultan-cell-${new Date().toISOString().slice(0, 10)}.json`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
      toast.success('Backup data berhasil diunduh')
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal membuat backup data'))
    } finally {
      setBackupLoading(false)
    }
  }

  const restoreBackup = async (e) => {
    e.preventDefault()
    if (!backupFile) return toast.error('Pilih file backup dulu')
    if (confirmText !== 'RESTORE SULTAN CELL') return toast.error('Teks konfirmasi belum sesuai')
    setShowRestoreConfirm(true)
  }

  const submitRestoreBackup = async () => {
    if (!backupFile) return toast.error('Pilih file backup dulu')
    setRestoreLoading(true)
    try {
      const text = await backupFile.text()
      const backup = JSON.parse(text)
      await api.post('/backup-data/restore', { backup, confirm_text: confirmText })
      toast.success('Restore backup berhasil. Silakan refresh website.')
      setBackupFile(null)
      setConfirmText('')
      setShowRestoreConfirm(false)
    } catch (e) {
      if (e instanceof SyntaxError) {
        toast.error('File backup bukan JSON yang valid.')
        return
      }
      toast.error(getApiErrorMessage(e, 'Gagal restore backup'))
    } finally {
      setRestoreLoading(false)
    }
  }

  return (
    <div className="brilink-page">
      <div className="page-heading brilink-heading">
        <div>
          <span>Pengaturan Sistem</span>
          <h1>Pengaturan</h1>
          <p>Kelola audit aktivitas dan cadangan data website Sultan Cell.</p>
        </div>
      </div>

      <div className="settings-tab-bar">
        <button type="button" className={activeTab === 'aktivitas' ? 'active' : ''} onClick={() => setActiveTab('aktivitas')}>
          <Activity size={18} /> Riwayat Aktivitas
        </button>
        {canWrite && <button type="button" className={activeTab === 'backup' ? 'active' : ''} onClick={() => setActiveTab('backup')}>
          <DatabaseBackup size={18} /> Backup Data
        </button>}
      </div>

      {activeTab === 'aktivitas' ? (
        <>
          <section className="brilink-filter-panel activity-filter-panel">
            <label className="field-group"><span>Cari Aktivitas</span><input maxLength={100} value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === 'Enter' && fetchActivity()} placeholder="Cari modul, aksi, atau keterangan" /></label>
            <label className="field-group"><span>Modul</span><select value={module} onChange={e => setModule(e.target.value)}><option value="semua">Semua</option><option>Barang</option><option>Penjualan</option><option>E-Wallet</option><option>Pengeluaran</option><option>Backup</option><option>Biaya Admin</option><option>Auth</option><option>Midtrans</option></select></label>
            <button type="button" onClick={fetchActivity} className="filter-search-button"><Search size={17} />Cari</button>
          </section>

          <section className="brilink-table-panel">
            <div className="brilink-table-header"><div><h2>Log Aktivitas</h2><p>{activityData.length} aktivitas terbaru</p></div><Activity size={20} /></div>
            <div className="brilink-table-wrap">
              <table className="brilink-table">
                <thead><tr><th>Waktu</th><th>Modul</th><th>Aksi</th><th>Keterangan</th><th>User</th></tr></thead>
                <tbody>{activityData.map(item => (
                  <tr key={item.id}>
                    <td className="nowrap">{formatDateTime(item.created_at)}</td>
                    <td><span className="service-pill blue">{item.module}</span></td>
                    <td>{item.action}</td>
                    <td>{item.description || '-'}</td>
                    <td>{item.user_name}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          </section>
        </>
      ) : (
        <div className="settings-backup-grid">
          <section className="brilink-form-card settings-backup-card">
            <div className="brilink-section-title"><DatabaseBackup size={22} /><div><h2>Backup JSON</h2><p>Simpan file backup di flashdisk atau cloud agar data toko punya cadangan.</p></div></div>
            <button type="button" onClick={downloadBackup} disabled={backupLoading} className="primary-action-button settings-download-button">
              <Download size={18} />
              {backupLoading ? 'Membuat Backup...' : 'Download Backup Data'}
            </button>
          </section>

          <section className="brilink-form-card settings-restore-card">
            <div className="brilink-section-title"><Upload size={22} /><div><h2>Restore Backup</h2><p>Gunakan hanya jika ingin mengembalikan data dari file backup. Data lama akan diganti.</p></div></div>
            <form onSubmit={restoreBackup} className="brilink-form-grid settings-restore-form">
              <label className="field-group full">
                <span>File Backup JSON</span>
                <input type="file" accept="application/json,.json" onChange={e => setBackupFile(e.target.files?.[0] || null)} />
              </label>
              <label className="field-group full">
                <span>Ketik RESTORE SULTAN CELL</span>
                <input value={confirmText} onChange={e => setConfirmText(e.target.value)} placeholder="RESTORE SULTAN CELL" />
              </label>
              <div className="form-actions full">
                <button type="submit" disabled={restoreLoading} className="report-action-button reset">
                  <Upload size={18} />
                  {restoreLoading ? 'Merestore...' : 'Restore Backup'}
                </button>
              </div>
            </form>
          </section>

          <section className="brilink-table-panel settings-note-card">
            <Settings size={22} />
            <div>
              <h2>Catatan Backup</h2>
              <p>Backup menyimpan data penting aplikasi. Restore hanya dipakai saat ingin mengembalikan data dari file cadangan yang valid.</p>
            </div>
          </section>
        </div>
      )}

      <Modal isOpen={showRestoreConfirm} onClose={() => !restoreLoading && setShowRestoreConfirm(false)} title="Restore Backup" size="sm">
        <div className="confirm-action-content">
          <div className="form-alert error">
            Restore backup akan mengganti data yang ada sekarang. Pastikan file backup sudah benar sebelum melanjutkan.
          </div>
          <div className="modal-form-actions">
            <button type="button" className="secondary-button" onClick={() => setShowRestoreConfirm(false)} disabled={restoreLoading}>Batal</button>
            <button type="button" className="ui-button danger" onClick={submitRestoreBackup} disabled={restoreLoading}>
              {restoreLoading ? 'Merestore...' : 'Restore Backup'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
import { useAuth } from '../hooks/useAuth'
