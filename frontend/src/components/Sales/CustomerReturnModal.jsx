import React, { useEffect, useMemo, useState } from 'react'
import Dialog from '../ui/Dialog'
import Input from '../ui/Input'
import Select from '../ui/Select'
import Textarea from '../ui/Textarea'
import Button from '../ui/Button'
import { formatRupiah } from '../../utils/formatRupiah'

const alasanOptions = [
  'Barang Rusak',
  'Barang Cacat',
  'Salah Barang',
  'Tidak Sesuai Pesanan',
  'Tidak Berfungsi',
  'Lainnya',
]

const penyelesaianOptions = [
  { value: 'pengembalian_dana', label: 'Pengembalian Dana' },
  { value: 'penggantian_barang', label: 'Penggantian Barang' },
]

const formatDateTime = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(value)).replace(/\./g, ':')
}

export default function CustomerReturnModal({ open, transaction, loading, onClose, onSubmit }) {
  const details = useMemo(() => transaction?.details || [], [transaction])
  const [selectedDetailId, setSelectedDetailId] = useState('')
  const [jumlahRetur, setJumlahRetur] = useState('1')
  const [alasanRetur, setAlasanRetur] = useState('')
  const [penyelesaianRetur, setPenyelesaianRetur] = useState('')
  const [keterangan, setKeterangan] = useState('')
  const [errors, setErrors] = useState({})

  useEffect(() => {
    if (!open || !transaction) return
    const availableDetail = transaction.details?.find(item => Number(item.jumlah_bisa_retur ?? item.jumlah ?? 0) > 0)
    setSelectedDetailId(String(availableDetail?.id || transaction.details?.[0]?.id || ''))
    setJumlahRetur('1')
    setAlasanRetur('')
    setPenyelesaianRetur('')
    setKeterangan('')
    setErrors({})
  }, [open, transaction])

  const selectedDetail = details.find(item => String(item.id) === String(selectedDetailId)) || details[0]
  const jumlahDibeli = Number(selectedDetail?.jumlah || 0)
  const jumlahBisaRetur = Number(selectedDetail?.jumlah_bisa_retur ?? jumlahDibeli)

  const validate = () => {
    const nextErrors = {}
    const jumlah = Number(jumlahRetur)

    if (!transaction?.id || !selectedDetail?.id) {
      nextErrors.transaksi = 'Data transaksi tidak valid.'
    }
    if (!jumlah || jumlah <= 0) {
      nextErrors.jumlahRetur = 'Jumlah retur wajib lebih dari 0.'
    } else if (jumlah > jumlahBisaRetur) {
      nextErrors.jumlahRetur = `Jumlah retur tidak boleh melebihi sisa barang yang bisa diretur (${jumlahBisaRetur} pcs).`
    }
    if (!alasanRetur) {
      nextErrors.alasanRetur = 'Alasan retur wajib dipilih.'
    }
    if (!penyelesaianRetur) {
      nextErrors.penyelesaianRetur = 'Penyelesaian retur wajib dipilih.'
    }

    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    if (!validate()) return

    onSubmit({
      detail_penjualan_id: selectedDetail.id,
      jumlah: Number(jumlahRetur),
      alasan_retur: alasanRetur,
      metode_pengembalian_dana: penyelesaianRetur,
      keterangan: keterangan.trim() || null,
    })
  }

  return (
    <Dialog open={open} onOpenChange={(value) => !value && onClose()} title="Retur Pelanggan" size="xl">
      {transaction && (
        <form className="customer-return-modal" onSubmit={handleSubmit}>
          {errors.transaksi && <div className="form-alert error">{errors.transaksi}</div>}
          <div className="customer-return-grid">
            <section className="customer-return-panel">
              <div className="customer-return-section-title">
                <span>Informasi Transaksi</span>
                <p>Data berikut hanya sebagai referensi dan tidak dapat diubah.</p>
              </div>

              {details.length > 1 && (
                <label className="field-group">
                  <span>Barang Diretur</span>
                  <Select value={selectedDetailId} onChange={event => setSelectedDetailId(event.target.value)}>
                    {details.map(item => (
                      <option key={item.id} value={item.id}>{item.nama_barang} - {item.jumlah} pcs</option>
                    ))}
                  </Select>
                </label>
              )}

              <div className="return-info-grid">
                <div className="return-info-item"><span>Nomor Transaksi</span><strong>{transaction.kode_transaksi}</strong></div>
                <div className="return-info-item"><span>Tanggal Transaksi</span><strong>{formatDateTime(transaction.dibuat_pada || transaction.tanggal)}</strong></div>
                <div className="return-info-item"><span>Nama Barang</span><strong>{selectedDetail?.nama_barang || '-'}</strong></div>
                <div className="return-info-item"><span>Kode Barang</span><strong>{selectedDetail?.kode_barang || '-'}</strong></div>
                <div className="return-info-item"><span>Jumlah Dibeli</span><strong>{jumlahDibeli} pcs</strong></div>
                {selectedDetail?.jumlah_sudah_retur !== undefined && (
                  <div className="return-info-item"><span>Sudah Diretur</span><strong>{Number(selectedDetail.jumlah_sudah_retur || 0)} pcs</strong></div>
                )}
                <div className="return-info-item"><span>Metode Pembayaran</span><strong>{transaction.metode_pembayaran?.toUpperCase() || '-'}</strong></div>
                <div className="return-info-item full"><span>Total Transaksi</span><strong>{formatRupiah(transaction.total_harga)}</strong></div>
              </div>
            </section>

            <section className="customer-return-panel">
              <div className="customer-return-section-title">
                <span>Form Retur</span>
                <p>Stok barang akan diperbarui sesuai penyelesaian retur setelah data disimpan.</p>
              </div>

              <div className="return-form-grid">
                <label className="field-group">
                  <span>Jumlah Retur</span>
                  <Input
                    type="number"
                    min="1"
                    max={jumlahBisaRetur || 1}
                    value={jumlahRetur}
                    onChange={event => setJumlahRetur(event.target.value)}
                    placeholder="Masukkan jumlah retur"
                  />
                  {errors.jumlahRetur && <small className="field-error">{errors.jumlahRetur}</small>}
                </label>

                <label className="field-group">
                  <span>Alasan Retur</span>
                  <Select value={alasanRetur} onChange={event => setAlasanRetur(event.target.value)}>
                    <option value="">Pilih alasan retur</option>
                    {alasanOptions.map(option => <option key={option} value={option}>{option}</option>)}
                  </Select>
                  {errors.alasanRetur && <small className="field-error">{errors.alasanRetur}</small>}
                </label>

                <label className="field-group">
                  <span>Penyelesaian Retur</span>
                  <Select value={penyelesaianRetur} onChange={event => setPenyelesaianRetur(event.target.value)}>
                    <option value="">Pilih penyelesaian</option>
                    {penyelesaianOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </Select>
                  {errors.penyelesaianRetur && <small className="field-error">{errors.penyelesaianRetur}</small>}
                </label>

                <label className="field-group full">
                  <span>Keterangan</span>
                  <Textarea
                    rows={4}
                    value={keterangan}
                    onChange={event => setKeterangan(event.target.value)}
                    placeholder="Tambahkan keterangan jika diperlukan"
                  />
                </label>
              </div>
            </section>
          </div>

          <div className="modal-form-actions">
            <Button type="button" variant="secondary" onClick={onClose} disabled={loading}>Batal</Button>
            <Button type="submit" disabled={loading}>{loading ? 'Menyimpan...' : 'Simpan Retur'}</Button>
          </div>
        </form>
      )}
    </Dialog>
  )
}
