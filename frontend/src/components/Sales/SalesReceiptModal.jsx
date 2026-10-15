import React from 'react'
import { Printer } from 'lucide-react'
import Modal from '../Common/Modal'
import { formatRupiah } from '../../utils/formatRupiah'

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
  }).format(new Date(value)).replace(/\./g, ':')
}

const printReceipt = () => {
  window.setTimeout(() => window.print(), 80)
}

export default function SalesReceiptModal({ isOpen, onClose, receipt }) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Struk Pembayaran" size="sm">
      {receipt && (
        <div className="receipt-preview">
          <div className="receipt-print-area">
            <div className="receipt-store">
              <strong>SULTAN CELL</strong>
              <span>Konter HP & Agen BRILink</span>
              <em>Struk Transaksi Penjualan</em>
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-meta">
              <div><span>No</span><strong>{receipt.kode_transaksi}</strong></div>
              <div><span>Waktu</span><strong>{formatReceiptDate(receipt.waktu)}</strong></div>
              <div><span>Kasir</span><strong>{receipt.kasir || '-'}</strong></div>
              <div><span>Bayar</span><strong>{receipt.metode_pembayaran.toUpperCase()}</strong></div>
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-section-label">Daftar Barang</div>
            <div className="receipt-items">
              {receipt.items.map(item => (
                <div key={`${item.id}-${item.kode_barang}`} className="receipt-item">
                  <div>
                    <strong>{item.nama_barang}</strong>
                    <span>{item.kode_barang}</span>
                  </div>
                  <p>{item.jumlah} x {formatRupiah(item.harga_jual)}</p>
                  <b>{formatRupiah(item.harga_jual * item.jumlah)}</b>
                </div>
              ))}
            </div>

            <div className="receipt-divider"></div>

            <div className="receipt-total-row receipt-grand-total">
              <span>Total</span>
              <strong>{formatRupiah(receipt.total)}</strong>
            </div>
            <div className="receipt-total-row">
              <span>Bayar</span>
              <strong>{formatRupiah(receipt.uang_bayar)}</strong>
            </div>
            <div className="receipt-total-row">
              <span>Kembali</span>
              <strong>{formatRupiah(receipt.kembalian)}</strong>
            </div>

            <div className="receipt-footer">
              <p>Terima kasih sudah berbelanja</p>
              <span>Simpan struk ini sebagai bukti transaksi.</span>
            </div>
          </div>

          <div className="receipt-actions no-print">
            <button type="button" className="secondary-button" onClick={onClose}>Tutup</button>
            <button type="button" className="print-receipt-button" onClick={printReceipt}>
              <Printer size={18} />
              <span>Cetak Struk</span>
            </button>
          </div>
        </div>
      )}
    </Modal>
  )
}
