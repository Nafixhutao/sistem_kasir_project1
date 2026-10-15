import React from 'react'
import { Banknote, CheckCircle2, QrCode } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import Modal from '../Common/Modal'
import { formatRupiah } from '../../utils/formatRupiah'
import { formatNominalInput } from '../../utils/nominalInput'

const qrisSupportedApps = ['GoPay', 'ShopeePay', 'DANA', 'OVO', 'Mobile Banking']

export default function SalesPaymentModal({
  isOpen,
  onClose,
  totalHarga,
  totalItems,
  paymentMethod,
  onPaymentMethodChange,
  uangBayar,
  onUangBayarChange,
  uangBayarValue,
  kembalian,
  loading,
  onCheckout,
  qrisData,
  qrisLoading,
  qrisChecking,
  qrisError,
  qrisStatus,
  onCreateQris,
  onCopyQrisUrl,
  onOpenSimulator,
  onCheckQrisStatus,
  onOpenPaymentPage,
  onManualConfirm,
  manualConfirming,
  formatQrisStatus,
}) {
  const isQrisPaid = ['settlement', 'capture'].includes(qrisStatus)
  const isSumopod = qrisData?.provider === 'sumopod'
  // Midtrans mengirim langsung URL gambar QR; SumoPod hanya tautan halaman bayar,
  // sehingga QR digambar sendiri (kode QRIS asli bila tersedia, jika tidak tautannya).
  const qrImageUrl = qrisData?.qr_image_url || (isSumopod ? null : qrisData?.qris_url)
  const qrValue = isSumopod ? (qrisData?.payment_code || qrisData?.payment_url || null) : null
  const hasQrisQr = Boolean(qrImageUrl || qrValue)

  const handleQrisMethodClick = () => {
    onPaymentMethodChange('qris')
    onCreateQris()
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Pembayaran" size="lg">
      <div className="payment-layout">
        <div className="payment-summary">
          <span>Total Belanja</span>
          <strong>{formatRupiah(totalHarga)}</strong>
          <p>{totalItems} item dalam keranjang</p>
        </div>

        <div className="payment-methods">
          <button onClick={() => onPaymentMethodChange('tunai')} className={paymentMethod === 'tunai' ? 'active' : ''}>
            <Banknote size={20} />
            <span>Tunai</span>
          </button>
          <button onClick={handleQrisMethodClick} className={paymentMethod === 'qris' ? 'active' : ''}>
            <QrCode size={20} />
            <span>QRIS</span>
          </button>
        </div>

        {paymentMethod === 'tunai' ? (
          <div className="cash-payment">
            <label className="field-group">
              <span>Uang Bayar</span>
              <input type="text" inputMode="numeric" value={uangBayar} onChange={e => onUangBayarChange(formatNominalInput(e.target.value))} placeholder="Contoh: 50.000" autoFocus />
            </label>
            <div className="quick-cash">
              {[50000, 100000, 200000, 500000].map(value => (
                <button key={value} onClick={() => onUangBayarChange(formatNominalInput(value))}>{formatRupiah(value)}</button>
              ))}
            </div>
            <div className={`change-box ${kembalian >= 0 && uangBayar ? 'ready' : ''}`}>
              <span>Kembalian</span>
              <strong>{uangBayar ? formatRupiah(Math.max(kembalian, 0)) : 'Rp 0'}</strong>
            </div>
            <button onClick={() => onCheckout('tunai')} disabled={loading || !uangBayar || uangBayarValue < totalHarga} className="confirm-payment-button">
              {loading ? 'Memproses...' : 'Konfirmasi Tunai'}
            </button>
          </div>
        ) : (
          <div className="qris-payment">
            <div className="qris-box">
              {qrImageUrl ? (
                <img src={qrImageUrl} alt="QRIS" className="midtrans-qris-image" loading="eager" />
              ) : qrValue ? (
                <div className="qris-code">
                  <QRCodeSVG value={qrValue} size={220} level="M" marginSize={2} />
                </div>
              ) : (
                <div className="qris-placeholder">
                  <QrCode size={48} />
                  <span>QRIS belum dibuat</span>
                </div>
              )}
              <div>
                <h3>QRIS Sultan Cell</h3>
                <p>{qrisData?.order_id ? `Nomor pembayaran: ${qrisData.order_id}` : 'QRIS akan tampil otomatis setelah dibuat.'}</p>
                {!(isSumopod && !qrisData?.payment_code && hasQrisQr) && (
                  <div className="qris-app-list" aria-label="Aplikasi yang mendukung scan QRIS">
                    {qrisSupportedApps.map(app => <span key={app}>{app}</span>)}
                  </div>
                )}
                <p className="qris-app-note">
                  {isSumopod && !qrisData?.payment_code && hasQrisQr
                    ? 'QR di atas berisi tautan halaman pembayaran SumoPod. Bisa juga dibuka langsung lewat tombol di bawah.'
                    : 'Satu QRIS ini dapat discan dari aplikasi pembayaran pelanggan yang mendukung QRIS.'}
                </p>
              </div>
            </div>
            {qrisLoading && <div className="qris-status"><QrCode size={20} /><span>Membuat QRIS pembayaran...</span></div>}
            {qrisError && <div className="scanner-warning">{qrisError}</div>}
            {qrisData?.order_id && (
              <div className={`qris-midtrans-status ${isQrisPaid ? 'paid' : ''}`}>
                <span>Status Pembayaran</span>
                <strong>{formatQrisStatus(qrisStatus)}</strong>
              </div>
            )}
            <div className="qris-status">
              <CheckCircle2 size={20} />
              <span>
                {isQrisPaid
                  ? 'Pembayaran diterima. Nota akan muncul otomatis.'
                  : isSumopod && qrValue && !qrisData?.payment_code
                    ? 'Scan QR, bayar di halaman SumoPod, lalu tekan "I have already paid" di halaman itu. Aplikasi mengecek status pembayaran otomatis setiap beberapa detik, jadi status akan berubah hijau begitu SumoPod menerima pembayaran.'
                    : 'Scan QRIS menggunakan GoPay, ShopeePay, mobile banking, atau e-wallet lain yang mendukung QRIS. Sistem akan menyimpan transaksi otomatis setelah pembayaran berhasil.'}
              </span>
            </div>
            <button type="button" onClick={onCreateQris} disabled={qrisLoading} className="secondary-button">
              {qrisLoading ? 'Memuat QRIS...' : 'Buat Ulang QRIS'}
            </button>
            {isSumopod ? (
              <>
                <button type="button" onClick={onOpenPaymentPage} disabled={!qrisData?.payment_url} className="secondary-button">
                  Buka Halaman Pembayaran
                </button>
                <button type="button" onClick={onCopyQrisUrl} disabled={!qrisData?.payment_url} className="secondary-button">
                  Salin Link Pembayaran
                </button>
              </>
            ) : (
              <>
                <button type="button" onClick={onCopyQrisUrl} disabled={!qrisData?.qris_url} className="secondary-button">
                  Salin URL QRIS
                </button>
                <button type="button" onClick={onOpenSimulator} className="secondary-button">
                  Buka Simulator Pembayaran
                </button>
              </>
            )}
            <button type="button" onClick={() => onCheckQrisStatus(true)} disabled={qrisChecking || !qrisData?.order_id} className="secondary-button">
              {qrisChecking ? 'Mengecek...' : 'Cek Status Pembayaran'}
            </button>
            {qrisData?.allow_manual_confirm && qrisData?.order_id && !isQrisPaid && (
              <button type="button" onClick={onManualConfirm} disabled={manualConfirming} className="secondary-button">
                {manualConfirming ? 'Mengonfirmasi...' : 'Konfirmasi Manual (Sandbox)'}
              </button>
            )}
            {loading && (
              <button type="button" disabled className="confirm-payment-button qris">
                Memproses transaksi otomatis...
              </button>
            )}
          </div>
        )}
      </div>
    </Modal>
  )
}
