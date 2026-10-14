import React, { useEffect, useMemo, useState } from 'react'
import { CreditCard, Landmark, ReceiptText, Save, Settings, SlidersHorizontal, WalletCards } from 'lucide-react'
import { formatRupiah } from '../utils/formatRupiah'
import { getJenisKartuNasabah } from '../utils/brilinkNasabah'
import {
  createBiayaAdminSection,
  isSameBiayaAdminGroup,
  layananBiayaAdminBrilink,
  nasabahBiayaAdminSections,
  sortBiayaAdminRanges,
} from '../utils/biayaAdminBrilink'
import api, { getApiErrorMessage } from '../services/api'
import toast from 'react-hot-toast'
import { useAuth } from '../hooks/useAuth'

const layananIcons = {
  transfer: Landmark,
  tarik_tunai: CreditCard,
  setor_tunai: SlidersHorizontal,
  tagihan: ReceiptText,
  pulsa_paket_data: WalletCards,
  ewallet: WalletCards,
}

export default function PengaturanBiayaAdmin() {
  const { user } = useAuth()
  const canManage = user?.role === 'admin'
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => { fetchData() }, [])

  const fetchData = async () => {
    try {
      const res = await api.get('/biaya-admin')
      setRows(Array.isArray(res.data) ? res.data : res.data.data || [])
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal memuat biaya admin'))
    } finally {
      setLoading(false)
    }
  }

  const groups = useMemo(() => {
    return layananBiayaAdminBrilink.map(layanan => ({
      ...layanan,
      icon: layananIcons[layanan.key] || SlidersHorizontal,
      sections: layanan.usesNasabah
        ? nasabahBiayaAdminSections.map(section => createBiayaAdminSection(rows, layanan.key, section.key))
        : [createBiayaAdminSection(rows, layanan.key, null)],
    }))
  }, [rows])

  const updateRange = (jenisTransaksi, jenisNasabah, rangeIndex, field, value) => {
    if (!canManage) return
    const targetRows = rows.length ? rows : flattenGroups(groups)
    const nextRows = targetRows.map(row => ({ ...row }))
    const matching = sortBiayaAdminRanges(
      nextRows.filter(row => isSameBiayaAdminGroup(row, jenisTransaksi, jenisNasabah))
    )

    if (!matching.length) {
      const fallback = createBiayaAdminSection([], jenisTransaksi, jenisNasabah).ranges.map(range => ({
        ...range,
        jenis_transaksi: jenisTransaksi,
        jenis_nasabah: jenisNasabah,
        jenis_kartu: jenisNasabah ? getJenisKartuNasabah(jenisNasabah) : null,
      }))
      fallback[rangeIndex][field] = value
      setRows([...nextRows.filter(row => !isSameBiayaAdminGroup(row, jenisTransaksi, jenisNasabah)), ...fallback])
      return
    }

    const row = matching[rangeIndex]
    row[field] = value
    setRows(nextRows)
  }

  const saveSection = async (section) => {
    if (!canManage) return
    try {
      const payload = {
        jenis_nasabah: section.jenisNasabah,
        ranges: section.ranges.map(range => ({
          nominal_min: Number(range.nominal_min) || 0,
          nominal_max: range.nominal_max === '' || range.nominal_max === null ? null : Number(range.nominal_max),
          biaya_admin: Number(range.biaya_admin) || 0,
          aktif: Boolean(range.aktif),
        })),
      }
      const res = await api.put(`/biaya-admin/${section.jenisTransaksi}`, payload)
      const updated = Array.isArray(res.data) ? res.data : []
      setRows(current => [
        ...current.filter(row => !isSameBiayaAdminGroup(row, section.jenisTransaksi, section.jenisNasabah)),
        ...updated,
      ])
      toast.success(`Biaya admin ${section.title} berhasil disimpan`)
    } catch (e) {
      toast.error(getApiErrorMessage(e, 'Gagal menyimpan biaya admin'))
    }
  }

  const flattenGroups = (items) => items.flatMap(layanan => layanan.sections.flatMap(section => section.ranges.map(range => ({
    ...range,
    jenis_transaksi: section.jenisTransaksi,
    jenis_nasabah: section.jenisNasabah,
    jenis_kartu: section.jenisNasabah ? getJenisKartuNasabah(section.jenisNasabah) : null,
  }))))

  const formatRangeText = (range) => {
    const max = range.nominal_max ? formatRupiah(range.nominal_max) : 'ke atas'
    return `${formatRupiah(range.nominal_min)} - ${max}`
  }

  const totalSections = groups.reduce((sum, layanan) => sum + layanan.sections.length, 0)
  const totalRanges = groups.reduce((sum, layanan) => sum + layanan.sections.reduce((sectionSum, section) => sectionSum + section.ranges.length, 0), 0)

  if (loading) return <div className="dashboard-loading"><div></div><span>Memuat biaya admin...</span></div>

  return (
    <div className="admin-fee-page">
      <div className="page-heading admin-fee-heading">
        <div>
          <span>Pengaturan BRILink</span>
          <h1>Biaya Admin</h1>
          <p>{canManage ? 'Atur range biaya admin per layanan BRILink, jenis nasabah, dan nominal transaksi.' : 'Lihat tarif biaya admin yang ditetapkan Admin untuk pendataan transaksi BRILink.'}</p>
        </div>
      </div>

      <div className="admin-fee-summary">
        <div className="brilink-summary-card"><div className="summary-icon blue"><Settings size={22} /></div><span>Layanan BRILink</span><strong>{layananBiayaAdminBrilink.length}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon green"><CreditCard size={22} /></div><span>Bagian Tarif</span><strong>{totalSections}</strong></div>
        <div className="brilink-summary-card"><div className="summary-icon amber"><ReceiptText size={22} /></div><span>Total Range</span><strong>{totalRanges}</strong></div>
      </div>

      <section className="admin-service-list">
        {groups.map(layanan => {
          const Icon = layanan.icon

          return (
            <article key={layanan.key} className={`admin-service-card ${layanan.key === 'ewallet' ? 'ewallet-service-card' : ''}`}>
              <div className="admin-service-header">
                <div className="admin-fee-icon"><Icon size={21} /></div>
                <div>
                  <h2>{layanan.label}</h2>
                  <p>{layanan.usesNasabah ? 'Biaya dipisah untuk Nasabah Internal dan Eksternal.' : 'Biaya hanya berdasarkan range nominal transaksi.'}</p>
                </div>
              </div>

              <div className={layanan.usesNasabah ? 'admin-section-grid' : 'admin-section-grid single'}>
                {layanan.sections.map(section => (
                  <div key={`${section.jenisTransaksi}-${section.jenisNasabah || 'ewallet'}`} className={`admin-fee-card active ${layanan.key === 'ewallet' ? 'ewallet-fee-card' : ''}`}>
                    <div className="admin-fee-current">
                      <span>{section.title}</span>
                      <strong>{section.subtitle}</strong>
                    </div>

                    <div className="admin-range-list">
                      <div className="admin-range-title">
                        <strong>Aturan Range Bertingkat</strong>
                        <span>Total bayar = nominal transaksi + biaya admin.</span>
                      </div>
                      {section.ranges.map((range, rangeIndex) => (
                        <div key={rangeIndex} className="admin-range-row">
                          <label>
                            <span>Dari</span>
                            <input type="number" readOnly={!canManage} value={range.nominal_min} onChange={e => updateRange(section.jenisTransaksi, section.jenisNasabah, rangeIndex, 'nominal_min', e.target.value)} />
                          </label>
                          <label>
                            <span>Sampai</span>
                            <input type="number" readOnly={!canManage} value={range.nominal_max ?? ''} placeholder="Ke atas" onChange={e => updateRange(section.jenisTransaksi, section.jenisNasabah, rangeIndex, 'nominal_max', e.target.value)} />
                          </label>
                          <label>
                            <span>Admin</span>
                            <input type="number" readOnly={!canManage} value={range.biaya_admin} onChange={e => updateRange(section.jenisTransaksi, section.jenisNasabah, rangeIndex, 'biaya_admin', e.target.value)} />
                          </label>
                          <p>{formatRangeText(range)} = {formatRupiah(range.biaya_admin)}</p>
                        </div>
                      ))}
                    </div>

                    {canManage && <button onClick={() => saveSection(section)} className="admin-save-button">
                      <Save size={17} />
                      <span>Simpan {section.title}</span>
                    </button>}
                  </div>
                ))}
              </div>
            </article>
          )
        })}
      </section>
    </div>
  )
}
