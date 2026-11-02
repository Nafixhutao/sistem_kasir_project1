import React, { useEffect } from 'react'
import { Printer } from 'lucide-react'
import Modal from '../Common/Modal'
import { formatRupiah } from '../../utils/formatRupiah'
import { getLabelJenisNasabah } from '../../utils/brilinkNasabah'

const formatReceiptDate = (value) => {
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).format(new Date(value || Date.now())).replace(/\./g, ':')
}

const humanize = (value) => {
  if (!value) return '-'
  return String(value)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, letter => letter.toUpperCase())
}

const optionalRow = (label, value) => {
  if (value === undefined || value === null || value === '') return null
  return { label, value }
}

const configs = {
  transfer: {
    service: 'Transfer',
    amountLabel: 'Nominal Transfer',
    amountKey: 'nominal_transfer',
    rows: item => [
      optionalRow('Jenis', item.jenis_transfer),
      optionalRow('Bank/Tujuan', item.bank_tujuan),
      optionalRow('Penerima', item.nama_penerima),
      optionalRow('No Tujuan', item.nomor_rekening_tujuan),
      optionalRow('Nasabah', getLabelJenisNasabah(item.jenis_nasabah)),
      optionalRow('Kartu', item.jenis_kartu),
    ],
  },
  tarik_tunai: {
    service: 'Tarik Tunai',
    amountLabel: 'Nominal Tarik',
    amountKey: 'nominal_tarik',
    rows: item => [
      optionalRow('Penerima', item.nama_penerima),
      optionalRow('No Rekening', item.nomor_rekening),
      optionalRow('No HP', item.nomor_hp),
      optionalRow('Nasabah', getLabelJenisNasabah(item.jenis_nasabah)),
      optionalRow('Kartu', item.jenis_kartu),
    ],
  },
  setor_tunai: {
    service: 'Setor Tunai',
    amountLabel: 'Nominal Setor',
    amountKey: 'nominal_setor',
    rows: item => [
      optionalRow('Jenis Setoran', humanize(item.jenis_setoran)),
      optionalRow('Rekening Tujuan', item.nomor_rekening_tujuan),
      optionalRow('Pemilik Rekening', item.nama_pemilik_rekening),
      optionalRow('Bank Tujuan', item.bank_tujuan),
      optionalRow('Sumber Dana', item.sumber_dana),
      optionalRow('Nasabah', getLabelJenisNasabah(item.jenis_nasabah)),
      optionalRow('Kartu', item.jenis_kartu),
    ],
  },
  tagihan: {
    service: 'Pembayaran Tagihan',
    amountLabel: 'Jumlah Tagihan',
    amountKey: 'jumlah_tagihan',
    rows: item => [
      optionalRow('Layanan', humanize(item.jenis_layanan)),
      optionalRow('Pelanggan', item.nama_pelanggan),
      optionalRow('No Pelanggan', item.nomor_pelanggan),
      optionalRow('Nasabah', getLabelJenisNasabah(item.jenis_nasabah)),
      optionalRow('Kartu', item.jenis_kartu),
    ],
  },
  ewallet: {
    service: 'E-Wallet',
    amountLabel: 'Nominal',
    amountKey: 'nominal',
    rows: item => [
      optionalRow('Jenis', item.jenis_transaksi === 'top_up' ? 'Top Up' : 'Pencairan Dana'),
      optionalRow('E-Wallet', item.jenis_ewallet),
      optionalRow('Nomor', item.nomor_ewallet),
      optionalRow('Customer', item.nama_customer),
    ],
  },
  pulsa_paket_data: {
    service: 'Pulsa / Paket Data',
    amountLabel: 'Harga Produk',
    amountKey: 'harga',
    rows: item => [
      optionalRow('Operator', item.operator),
      optionalRow('Jenis', humanize(item.jenis_layanan)),
      optionalRow('Nomor Tujuan', item.nomor_tujuan),
      optionalRow('Produk', item.produk),
      optionalRow('Nasabah', getLabelJenisNasabah(item.jenis_nasabah)),
      optionalRow('Kartu', item.jenis_kartu),
    ],
  },
}

const printReceipt = () => {
  window.setTimeout(() => window.print(), 80)
}

export default function BrilinkReceiptModal({ isOpen, onClose, receipt }) {
  useEffect(() => {
    document.body.classList.toggle('receipt-print-mode', Boolean(isOpen && receipt))
    return () => document.body.classList.remove('receipt-print-mode')
  }, [isOpen, receipt])

  const config = receipt ? configs[receipt.type] : null
  const item = receipt?.data || {}
  const detailRows = config?.rows(item).filter(Boolean) || []
  const nominal = Number(item[config?.amountKey] || 0)
  const admin = Number(item.biaya_admin || 0)
  const total = Number(item.total_bayar || nominal + admin)

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Bukti Transaksi BRILink" size="sm">
      {receipt && config && (
        <div className="receipt-preview">
          <div className="receipt-print-area">
            <div className="receipt-store">
              <strong>SULTAN CELL</strong>
              <span>Konter HP & Agen BRILink</span>
              <em>Bukti Pendataan Transaksi BRILink</em>
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-meta">
              <div><span>No</span><strong>{item.kode_transaksi}</strong></div>
              <div><span>Waktu</span><strong>{formatReceiptDate(item.dibuat_pada || item.tanggal)}</strong></div>
              <div><span>Layanan</span><strong>{config.service}</strong></div>
              <div><span>Penyedia</span><strong>{item.penyedia || '-'}</strong></div>
              <div><span>Status</span><strong>{humanize(item.status || 'sukses')}</strong></div>
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-section-label">Detail Layanan</div>
            <div className="receipt-items">
              {detailRows.map(row => (
                <div key={row.label} className="receipt-item receipt-info-item">
                  <div>
                    <strong>{row.label}</strong>
                    <span>{row.value}</span>
                  </div>
                </div>
              ))}
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-total-row">
              <span>{config.amountLabel}</span>
              <strong>{formatRupiah(nominal)}</strong>
            </div>
            <div className="receipt-total-row">
              <span>Biaya Admin</span>
              <strong>{formatRupiah(admin)}</strong>
            </div>
            <div className="receipt-total-row receipt-grand-total">
              <span>Total Bayar</span>
              <strong>{formatRupiah(total)}</strong>
            </div>

            <div className="receipt-footer">
              <p>Transaksi berhasil dicatat</p>
              <span>Bukti ini adalah bukti pendataan pada sistem web Sultan Cell, bukan bukti resmi aplikasi penyedia.</span>
            </div>
          </div>

          <div className="receipt-actions no-print">
            <button type="button" className="secondary-button" onClick={onClose}>Tutup</button>
            <button type="button" className="print-receipt-button" onClick={printReceipt}>
              <Printer size={18} />
              <span>Cetak Bukti</span>
            </button>
          </div>
        </div>
      )}
    </Modal>
  )
}
