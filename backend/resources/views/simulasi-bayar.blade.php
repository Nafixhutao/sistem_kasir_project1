<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="theme-color" content="#1f9d55">
    <title>Simulasi Pembayaran — Sultan Cell</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #f1f5f4;
            color: #17212b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 380px;
            background: #fff;
            border-radius: 18px;
            padding: 26px 22px 22px;
            box-shadow: 0 10px 30px rgba(15, 40, 30, .12);
            text-align: center;
        }
        .brand {
            font-size: 13px;
            letter-spacing: 2.5px;
            font-weight: 700;
            text-transform: uppercase;
            color: #1f9d55;
        }
        .judul { margin: 4px 0 18px; font-size: 15px; color: #6b7a86; font-weight: 500; }
        .badge {
            display: inline-block;
            margin-bottom: 18px;
            padding: 5px 12px;
            border-radius: 999px;
            background: #fff4e0;
            color: #a35c00;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .8px;
        }
        .nominal-label { font-size: 13px; color: #6b7a86; }
        .nominal {
            font-size: 34px;
            font-weight: 700;
            margin: 4px 0 18px;
            letter-spacing: -.5px;
        }
        .rincian {
            text-align: left;
            background: #f7faf9;
            border: 1px solid #e6ecea;
            border-radius: 12px;
            padding: 6px 14px;
            margin-bottom: 20px;
        }
        .rincian div {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            font-size: 13px;
            border-bottom: 1px solid #eef2f1;
        }
        .rincian div:last-child { border-bottom: 0; }
        .rincian span { color: #6b7a86; }
        .rincian strong { font-weight: 600; word-break: break-all; text-align: right; }
        .status-lunas { color: #1f9d55; }
        .status-gagal { color: #c0392b; }
        button {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            background: #1f9d55;
            border: 0;
            border-radius: 12px;
            cursor: pointer;
            font-family: inherit;
        }
        button:active { background: #178049; }
        .catatan {
            margin-top: 16px;
            font-size: 12px;
            line-height: 1.6;
            color: #8a97a1;
        }
        .ikon {
            font-size: 46px;
            line-height: 1;
            margin-bottom: 10px;
        }
        .judul-hasil { margin: 0 0 6px; font-size: 20px; }
        .pesan-hasil { margin: 0 0 4px; font-size: 14px; color: #6b7a86; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Sultan Cell</div>

        @if (!$qris)
            <div class="ikon">🔍</div>
            <h1 class="judul-hasil">Data pembayaran tidak ditemukan</h1>
            <p class="pesan-hasil">Minta kasir membuat ulang QRIS, lalu scan kembali.</p>
        @elseif ($sudahLunas)
            <div class="ikon">✅</div>
            <h1 class="judul-hasil">Pembayaran Berhasil</h1>
            <p class="pesan-hasil">
                Rp {{ number_format((float) $qris->nominal, 0, ',', '.') }} sudah dibayar.
            </p>
            <p class="pesan-hasil">Silakan kembali ke layar kasir — nota dicetak otomatis.</p>
            <div class="rincian" style="margin-top:20px">
                <div><span>Nomor pembayaran</span><strong>{{ $qris->order_id }}</strong></div>
                <div><span>Waktu bayar</span><strong>{{ $qris->paid_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '-' }}</strong></div>
            </div>
            <p class="catatan">Halaman simulasi — tidak ada uang yang berpindah.</p>
        @elseif ($kedaluwarsa)
            <div class="ikon">⌛</div>
            <h1 class="judul-hasil">Pembayaran kedaluwarsa</h1>
            <p class="pesan-hasil">Minta kasir membuat QRIS baru untuk melanjutkan.</p>
            <div class="rincian" style="margin-top:20px">
                <div><span>Nomor pembayaran</span><strong>{{ $qris->order_id }}</strong></div>
                <div><span>Status</span><strong class="status-gagal">{{ $qris->status_pembayaran }}</strong></div>
            </div>
        @else
            <div class="badge">SIMULASI PEMBAYARAN</div>
            <div class="nominal-label">Total tagihan</div>
            <div class="nominal">Rp {{ number_format((float) $qris->nominal, 0, ',', '.') }}</div>

            <div class="rincian">
                <div><span>Nomor pembayaran</span><strong>{{ $qris->order_id }}</strong></div>
                <div><span>Status</span><strong>Menunggu pembayaran</strong></div>
            </div>

            <form method="POST" action="{{ route('simulasi.bayar.proses', ['orderId' => $qris->order_id]) }}">
                @csrf
                <button type="submit">Bayar Sekarang</button>
            </form>

            <p class="catatan">
                Tekan tombol di atas untuk menirukan pembayaran berhasil.<br>
                Status di layar kasir akan berubah lunas dan nota muncul otomatis.<br>
                <strong>Halaman simulasi — tidak ada uang yang benar-benar dibayarkan.</strong>
            </p>
        @endif
    </div>
</body>
</html>
