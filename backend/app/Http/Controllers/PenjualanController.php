<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiPenjualan;
use App\Models\DetailPenjualan;
use App\Models\Barang;
use App\Models\ActivityLog;
use App\Models\ReturPenjualan;
use App\Models\ReturPelanggan;
use App\Models\PembayaranQris;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\NotifikasiService;
use App\Services\BusinessRequest;
use Illuminate\Support\Facades\Validator;

class PenjualanController extends Controller
{
    /** Jeda minimum (detik) antar pengecekan status ke SumoPod. */
    private const SUMOPOD_STATUS_TTL = 5;

    public function index()
    {
        return response()->json(
            TransaksiPenjualan::with('details')->orderBy('created_at', 'desc')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|min:1|max:4294967295|exists:barang,id',
            'items.*.jumlah' => 'required|integer|min:1',
            'metode_pembayaran' => 'required|in:tunai,qris',
            'uang_bayar' => 'required_if:metode_pembayaran,tunai|nullable|numeric|min:0',
            'qris_order_id' => 'required_if:metode_pembayaran,qris|nullable|string|max:100',
        ], [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Minimal pilih 1 barang untuk transaksi.',
            'items.*.id.exists' => 'Ada barang yang tidak ditemukan di database.',
            'items.*.jumlah.min' => 'Jumlah barang minimal 1.',
            'metode_pembayaran.required' => 'Metode pembayaran wajib dipilih.',
            'uang_bayar.required_if' => 'Uang bayar wajib diisi untuk pembayaran tunai.',
            'qris_order_id.required_if' => 'Order ID QRIS wajib tersedia sebelum transaksi QRIS disimpan.',
            'qris_order_id.unique' => 'Order ID QRIS ini sudah pernah dipakai transaksi.',
        ]);

        $fingerprint = [
            'items' => array_map(fn($item) => ['id'=>(int)$item['id'],'jumlah'=>(int)$item['jumlah']], $data['items']),
            'metode_pembayaran' => $data['metode_pembayaran'],
            'uang_bayar' => $data['metode_pembayaran']==='tunai' ? (float)$data['uang_bayar'] : null,
            'qris_order_id' => $data['metode_pembayaran']==='qris' ? $data['qris_order_id'] : null,
        ];
        [$transaksi] = BusinessRequest::execute(TransaksiPenjualan::class, $data['request_id'], $request->user()->id, $fingerprint, function () use ($data, $request) {
            // Check a new QRIS order only after the replay check; retries keep the old result.
            // Alur SumoPod sudah mencatat baris pembayaran sebelum nota dibuat, jadi yang
            // dilarang adalah memakai ulang order_id yang sudah tertaut ke transaksi lain.
            Validator::make($data, ['qris_order_id'=>'nullable|string|max:100'], [
                'qris_order_id.required' => 'Order ID QRIS wajib diisi untuk pembayaran QRIS.',
                'qris_order_id.max' => 'Order ID QRIS terlalu panjang.',
            ])->validate();
            $items = collect($data['items'])->map(function ($item) {
                $barang = Barang::lockForUpdate()->findOrFail($item['id']);
                $jumlah = (int) $item['jumlah'];

                if ($barang->stok < $jumlah) {
                    abort(422, 'Stok ' . $barang->nama_barang . ' tidak cukup. Sisa stok: ' . $barang->stok . ' pcs.');
                }

                return [
                    'barang' => $barang,
                    'jumlah' => $jumlah,
                    'subtotal' => (float) $barang->harga_jual * $jumlah,
                ];
            });

            $totalHarga = $items->sum('subtotal');
            if ($data['metode_pembayaran'] === 'tunai' && (float) $data['uang_bayar'] < $totalHarga) {
                abort(422, 'Uang bayar kurang dari total belanja.');
            }

            $transaksi = TransaksiPenjualan::create([
                'kode_transaksi' => 'TRX-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
                'tanggal' => Carbon::today(),
                'metode_pembayaran' => $data['metode_pembayaran'],
                'status' => 'lunas',
                'total_harga' => $totalHarga,
                'uang_bayar' => $data['metode_pembayaran'] === 'tunai' ? $data['uang_bayar'] : $totalHarga,
                'kembalian' => $data['metode_pembayaran'] === 'tunai' ? ((float) $data['uang_bayar'] - $totalHarga) : 0,
                'kasir' => $request->user()->username
            ]);

            foreach ($items as $item) {
                $barang = $item['barang'];
                DetailPenjualan::create([
                    'transaksi_penjualan_id' => $transaksi->id,
                    'barang_id' => $barang->id,
                    'kode_barang' => $barang->kode_barang,
                    'nama_barang' => $barang->nama_barang,
                    'jumlah' => $item['jumlah'],
                    'harga_satuan' => $barang->harga_jual,
                    'harga_beli_satuan' => $barang->harga_beli ?? 0,
                    'subtotal' => $item['subtotal']
                ]);

                $previousStock = (int) $barang->stok;
                $barang->stok -= $item['jumlah'];
                $barang->save();
                NotifikasiService::syncStockNotification($barang, $previousStock);
            }

            if ($data['metode_pembayaran'] === 'qris') {
                $pembayaran = PembayaranQris::where('order_id', $data['qris_order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($pembayaran && $pembayaran->transaksi_penjualan_id) {
                    abort(422, 'Order ID QRIS ini sudah pernah dipakai transaksi.');
                }

                if ($pembayaran) {
                    // Cek ulang ke SumoPod lebih dulu: bila pelanggan sudah benar-benar membayar
                    // tetapi webhook belum sampai, status lokal disamakan sebelum dinilai.
                    $pembayaran = $this->syncSumopodStatus($pembayaran);

                    // Baris sudah dibuat saat QRIS diminta (alur SumoPod). Pembayaran wajib
                    // sudah dikonfirmasi sebelum nota disimpan.
                    if ($pembayaran->status_pembayaran !== 'settlement') {
                        abort(422, 'Pembayaran QRIS belum dikonfirmasi (status: ' . $pembayaran->status_pembayaran . ').');
                    }

                    $pembayaran->update([
                        'transaksi_penjualan_id' => $transaksi->id,
                        'nominal' => $totalHarga,
                    ]);
                } else {
                    // Alur Midtrans: status sudah dicek langsung ke Midtrans oleh kasir.
                    PembayaranQris::create([
                        'order_id' => $data['qris_order_id'],
                        'provider' => 'midtrans',
                        'transaksi_penjualan_id' => $transaksi->id,
                        'nominal' => $totalHarga,
                        'status_pembayaran' => 'settlement',
                        'payment_response' => [
                            'source' => 'manual_status_check',
                            'confirmed_at' => now()->toISOString(),
                        ],
                    ]);
                }
            }

            ActivityLog::record('Penjualan', 'create', 'Mencatat transaksi penjualan ' . $transaksi->kode_transaksi, $transaksi->fresh('details')->toArray());
            return $transaksi;
        });
        $transaksi->load('details');

        return response()->json([
            'transaksi' => $transaksi,
            'message' => 'Transaksi berhasil'
        ]);
    }

    private function paymentProvider(): string
    {
        return config('services.payment.provider', 'midtrans') === 'sumopod' ? 'sumopod' : 'midtrans';
    }

    public function qris(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|min:1|max:4294967295|exists:barang,id',
            'items.*.jumlah' => 'required|integer|min:1',
        ], [
            'items.required' => 'Keranjang masih kosong.',
            'items.*.id.exists' => 'Ada barang yang tidak ditemukan di database.',
        ]);

        $items = collect($data['items'])->map(function ($item) {
            $barang = Barang::findOrFail($item['id']);
            $jumlah = (int) $item['jumlah'];
            if ($barang->stok < $jumlah) {
                abort(422, 'Stok ' . $barang->nama_barang . ' tidak cukup. Sisa stok: ' . $barang->stok . ' pcs.');
            }

            return [
                'barang' => $barang,
                'jumlah' => $jumlah,
                'subtotal' => (float) $barang->harga_jual * $jumlah,
            ];
        });
        $totalHarga = $items->sum('subtotal');

        return $this->paymentProvider() === 'sumopod'
            ? $this->createSumopodQris($items, $totalHarga)
            : $this->createMidtransQris($items, $totalHarga);
    }

    /**
     * SumoPod Managed Payment: pembuatan pembayaran dicatat lebih dulu dengan status
     * pending, lalu webhook payment.completed menandainya lunas.
     */
    /**
     * Alamat halaman simulasi pembayaran yang dipakai sebagai isi QR selama mode
     * demo sandbox. Dikosongkan bila flag konfirmasi manual dimatikan atau provider
     * sudah bukan sandbox, supaya QR tidak pernah mengarah ke simulasi saat
     * pembayaran sungguhan sudah aktif.
     */
    private function simulasiBayarUrl(string $orderId): ?string
    {
        if (!config('services.sumopod.allow_manual_confirm', false)) {
            return null;
        }

        if (!str_contains((string) config('services.sumopod.base_url'), 'sandbox')) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/bayar-simulasi/' . urlencode($orderId);
    }

    private function createSumopodQris($items, $totalHarga)
    {
        $apiKey = config('services.sumopod.api_key');
        if (!$apiKey) {
            return response()->json([
                'message' => 'SUMOPOD_API_KEY belum diisi di file .env backend.'
            ], 422);
        }

        $orderId = 'QRIS-' . date('YmdHis') . '-' . strtoupper(Str::random(5));
        $baseUrl = rtrim(config('services.sumopod.base_url'), '/');
        $appUrl = rtrim(config('app.url'), '/');

        $payload = [
            'order_id' => $orderId,
            'amount' => (int) round($totalHarga),
            'currency' => 'IDR',
            'expires_in_hours' => 24,
            'payment_method_type_code' => 'QRIS',
            'success_return_url' => $appUrl . '/transaksi-penjualan',
            'cancel_return_url' => $appUrl . '/transaksi-penjualan',
        ];

        $response = Http::withHeaders(['X-Api-Key' => $apiKey])
            ->acceptJson()
            ->post($baseUrl . '/payments', $payload);

        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('message') ?: 'Gagal membuat pembayaran QRIS SumoPod.',
                'sumopod' => $response->json(),
            ], $response->status() ?: 422);
        }

        $body = $response->json();
        $paymentUrl = $body['payment_link_url'] ?? null;
        // payment_code hanya muncul bila "Use custom checkout page" diaktifkan di dashboard.
        $paymentCode = $body['payment_code'] ?? $body['paymentCode'] ?? null;

        PembayaranQris::create([
            'order_id' => $orderId,
            'provider' => 'sumopod',
            'transaksi_penjualan_id' => null,
            'nominal' => $totalHarga,
            'payment_url' => $paymentUrl,
            'status_pembayaran' => 'pending',
            'transaction_id' => $body['payment_id'] ?? null,
            'payment_response' => $body + ['source' => 'cashier_checkout'],
        ]);

        return response()->json([
            'provider' => 'sumopod',
            'order_id' => $orderId,
            'qris_url' => $paymentUrl,
            'qr_image_url' => null,
            'payment_url' => $paymentUrl,
            'payment_code' => $paymentCode,
            'gross_amount' => (int) round($totalHarga),
            'status' => 'pending',
            'expires_at' => $body['expires_at'] ?? null,
            'fee' => $body['fee'] ?? null,
            'net_amount' => $body['net_amount'] ?? null,
            'allow_manual_confirm' => (bool) config('services.sumopod.allow_manual_confirm', false),
            'simulation_url' => $this->simulasiBayarUrl($orderId),
            'sumopod' => $body,
        ]);
    }

    private function createMidtransQris($items, $totalHarga)
    {
        $serverKey = config('services.midtrans.server_key');
        if (!$serverKey) {
            return response()->json([
                'message' => 'MIDTRANS_SERVER_KEY belum diisi di file .env backend.'
            ], 422);
        }

        $orderId = 'QRIS-' . date('YmdHis') . '-' . strtoupper(Str::random(5));
        $baseUrl = rtrim(config('services.midtrans.base_url'), '/');
        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($totalHarga),
            ],
            'item_details' => $items->map(function($item) {
                $barang = $item['barang'];
                return [
                    'id' => (string) $barang->kode_barang,
                    'price' => (int) round($barang->harga_jual),
                    'quantity' => (int) $item['jumlah'],
                    'name' => substr($barang->nama_barang, 0, 50),
                ];
            })->values()->all(),
        ];

        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->post($baseUrl . '/v2/charge', $payload);

        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('status_message') ?: 'Gagal membuat QRIS Midtrans.',
                'midtrans' => $response->json(),
            ], $response->status());
        }

        $body = $response->json();
        $qrisUrl = collect($body['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null;

        return response()->json([
            'provider' => 'midtrans',
            'order_id' => $orderId,
            'qris_url' => $qrisUrl,
            'qr_image_url' => $qrisUrl,
            'payment_url' => null,
            'payment_code' => null,
            'gross_amount' => (int) round($totalHarga),
            'status' => $body['transaction_status'] ?? 'pending',
            'allow_manual_confirm' => false,
            'midtrans' => $body,
        ]);
    }

    public function qrisStatus(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|string|max:100',
        ]);

        // Status disimpan lokal, diperbarui oleh webhook payment.completed / payment.failed /
        // payment.expired, dan dicek ulang langsung ke SumoPod bila webhook belum sampai.
        $sumopod = PembayaranQris::where('order_id', $data['order_id'])
            ->where('provider', 'sumopod')
            ->first();

        if ($sumopod) {
            $sumopod = $this->syncSumopodStatus($sumopod);

            return response()->json([
                'provider' => 'sumopod',
                'order_id' => $sumopod->order_id,
                'status' => $sumopod->status_pembayaran,
                'gross_amount' => $sumopod->nominal,
                'paid_at' => optional($sumopod->paid_at)->toISOString(),
                'allow_manual_confirm' => (bool) config('services.sumopod.allow_manual_confirm', false),
            ]);
        }

        $serverKey = config('services.midtrans.server_key');
        if (!$serverKey) {
            return response()->json([
                'message' => 'MIDTRANS_SERVER_KEY belum diisi di file .env backend.'
            ], 422);
        }

        $baseUrl = rtrim(config('services.midtrans.base_url'), '/');
        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->get($baseUrl . '/v2/' . urlencode($data['order_id']) . '/status');

        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('status_message') ?: 'Gagal mengecek status pembayaran Midtrans.',
                'midtrans' => $response->json(),
            ], $response->status());
        }

        $body = $response->json();

        return response()->json([
            'provider' => 'midtrans',
            'order_id' => $data['order_id'],
            'status' => $body['transaction_status'] ?? 'unknown',
            'fraud_status' => $body['fraud_status'] ?? null,
            'payment_type' => $body['payment_type'] ?? null,
            'gross_amount' => $body['gross_amount'] ?? null,
            'allow_manual_confirm' => false,
            'midtrans' => $body,
        ]);
    }

    public function invoice($id)
    {
        return response()->json(
            TransaksiPenjualan::with('details')->findOrFail($id)
        );
    }

    public function cancel(Request $request, $id)
    {
        $data = $request->validate([
            'alasan' => 'required|string|min:3',
        ]);

        $transaksi = DB::transaction(function () use ($data, $id) {
            $transaksi = TransaksiPenjualan::with('details')->lockForUpdate()->findOrFail($id);

            if ($transaksi->status === 'batal') {
                abort(422, 'Transaksi sudah dibatalkan.');
            }

            foreach ($transaksi->details as $detail) {
                $barang = Barang::lockForUpdate()->find($detail->barang_id);
                if ($barang) {
                    $jumlahReturLama = ReturPenjualan::where('detail_penjualan_id', $detail->id)->sum('jumlah');
                    $jumlahReturPelanggan = ReturPelanggan::where('detail_penjualan_id', $detail->id)->sum('jumlah_retur');
                    $jumlahRetur = (int) $jumlahReturLama + (int) $jumlahReturPelanggan;
                    $jumlahKembali = max((int) $detail->jumlah - (int) $jumlahRetur, 0);
                    $barang->stok += $jumlahKembali;
                    $barang->save();
                }
            }

            $transaksi->update([
                'status' => 'batal',
                'alasan_batal' => $data['alasan'],
                'dibatalkan_pada' => now(),
            ]);

            return $transaksi->fresh('details');
        });

        ActivityLog::record('Penjualan', 'cancel', 'Membatalkan transaksi ' . $transaksi->kode_transaksi . ': ' . $data['alasan'], $transaksi->toArray());

        return response()->json([
            'message' => 'Transaksi berhasil dibatalkan dan stok dikembalikan',
            'transaksi' => $transaksi,
        ]);
    }

    public function retur(Request $request, $id)
    {
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'detail_penjualan_id' => 'required_without:detail_id|integer|min:1|max:4294967295|exists:detail_penjualan,id',
            'detail_id' => 'required_without:detail_penjualan_id|integer|min:1|max:4294967295|exists:detail_penjualan,id',
            'jumlah' => 'required|integer|min:1',
            'alasan_retur' => 'required_without:alasan|nullable|in:Barang Rusak,Barang Cacat,Salah Barang,Tidak Sesuai Pesanan,Tidak Berfungsi,Lainnya',
            'alasan' => 'required_without:alasan_retur|nullable|string|min:3|max:255',
            'metode_pengembalian_dana' => 'required_without:alasan|nullable|in:pengembalian_dana,penggantian_barang,tunai,qris',
            'keterangan' => 'nullable|string',
        ], [
            'detail_penjualan_id.required_without' => 'Data transaksi harus valid.',
            'detail_id.required_without' => 'Data transaksi harus valid.',
            'jumlah.required' => 'Jumlah retur wajib diisi.',
            'jumlah.min' => 'Jumlah retur wajib lebih dari 0.',
            'alasan_retur.required_without' => 'Alasan retur wajib dipilih.',
            'alasan_retur.in' => 'Alasan retur tidak valid.',
            'metode_pengembalian_dana.required_without' => 'Penyelesaian retur wajib dipilih.',
            'metode_pengembalian_dana.in' => 'Penyelesaian retur tidak valid.',
        ]);

        $fingerprint = [
            'transaksi_penjualan_id'=>(int)$id,
            'detail_penjualan_id'=>(int)($data['detail_penjualan_id'] ?? $data['detail_id']),
            'jumlah'=>(int)$data['jumlah'],
            'alasan_retur'=>$data['alasan_retur'] ?? $data['alasan'],
            'metode_pengembalian_dana'=>$data['metode_pengembalian_dana'] ?? 'tunai',
            'keterangan'=>$data['keterangan'] ?? null,
        ];
        [$retur] = BusinessRequest::execute(ReturPelanggan::class, $data['request_id'], $request->user()->id, $fingerprint, function () use ($data, $id) {
            $transaksi = TransaksiPenjualan::findOrFail($id);
            if ($transaksi->status === 'batal') {
                abort(422, 'Transaksi batal tidak bisa diretur.');
            }

            $detailPenjualanId = $data['detail_penjualan_id'] ?? $data['detail_id'];
            $alasanRetur = $data['alasan_retur'] ?? $data['alasan'];
            $metodePengembalianDana = $data['metode_pengembalian_dana'] ?? 'tunai';

            $detail = DetailPenjualan::where('transaksi_penjualan_id', $id)
                ->where('id', $detailPenjualanId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $data['jumlah'] > (int) $detail->jumlah) {
                abort(422, 'Jumlah retur melebihi jumlah pembelian.');
            }

            $jumlahReturLama = ReturPenjualan::where('detail_penjualan_id', $detail->id)->sum('jumlah');
            $jumlahReturPelanggan = ReturPelanggan::where('detail_penjualan_id', $detail->id)->sum('jumlah_retur');
            $jumlahSudahRetur = (int) $jumlahReturLama + (int) $jumlahReturPelanggan;
            $sisaBisaRetur = (int) $detail->jumlah - (int) $jumlahSudahRetur;

            if ((int) $data['jumlah'] > $sisaBisaRetur) {
                abort(422, 'Jumlah retur melebihi sisa barang yang bisa diretur. Sisa retur: ' . $sisaBisaRetur . ' pcs.');
            }

            $barang = Barang::lockForUpdate()->findOrFail($detail->barang_id);
            if ($metodePengembalianDana !== 'penggantian_barang') {
                $previousStock = (int) $barang->stok;
                $barang->stok += (int) $data['jumlah'];
                $barang->save();
                NotifikasiService::syncStockNotification($barang, $previousStock);
            }

            $retur = ReturPelanggan::create([
                'nomor_retur' => $this->generateNomorReturPelanggan(),
                'transaksi_penjualan_id' => $transaksi->id,
                'detail_penjualan_id' => $detail->id,
                'barang_id' => $barang->id,
                'kode_transaksi' => $transaksi->kode_transaksi,
                'kode_barang' => $detail->kode_barang,
                'nama_barang' => $detail->nama_barang,
                'jumlah_dibeli' => (int) $detail->jumlah,
                'jumlah_retur' => (int) $data['jumlah'],
                'alasan_retur' => $alasanRetur,
                'metode_pengembalian_dana' => $metodePengembalianDana,
                'keterangan' => $data['keterangan'] ?? null,
                'tanggal_retur' => today(),
            ])->load(['barang', 'transaksi']);

        $metodeLabel = match ($retur->metode_pengembalian_dana) {
            'penggantian_barang' => 'Penggantian Barang',
            'pengembalian_dana' => 'Pengembalian Dana',
            'qris' => 'QRIS',
            default => 'Tunai',
        };

        ActivityLog::record(
            'Retur Pelanggan',
            'create',
            'Admin membuat retur pelanggan untuk barang ' . $retur->nama_barang . ' sebanyak ' . $retur->jumlah_retur . ' pcs dengan alasan ' . $retur->alasan_retur . ' dan penyelesaian retur ' . $metodeLabel . '.',
            $retur->toArray()
        );
        NotifikasiService::returPelangganProcessed($retur);
            return $retur;
        });
        $retur->load(['barang', 'transaksi']);

        return response()->json([
            'message' => 'Retur berhasil dicatat dan stok diperbarui',
            'retur' => $retur,
        ]);
    }

    public function qrisWebhook(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|string|max:100',
            'status_code' => 'required',
            'gross_amount' => 'required',
            'signature_key' => 'required|string',
            'transaction_status' => 'required|string',
            'transaction_id' => 'nullable|string|max:100',
        ]);

        $serverKey = config('services.midtrans.server_key');
        if (!$serverKey) {
            return response()->json(['message' => 'MIDTRANS_SERVER_KEY belum diisi di file .env backend.'], 422);
        }

        $signature = hash('sha512', $data['order_id'] . $data['status_code'] . $data['gross_amount'] . $serverKey);

        if (!hash_equals($signature, $data['signature_key'])) {
            return response()->json(['message' => 'Signature Midtrans tidak valid'], 403);
        }

        $qris = PembayaranQris::where('order_id', $data['order_id'])->first();
        if ($qris) {
            $previousStatus = $qris->status_pembayaran;
            $qris->update([
                'status_pembayaran' => $this->normalizeQrisPaymentStatus($data['transaction_status']),
                'transaction_id' => $data['transaction_id'] ?? null,
                'payment_response' => $request->all(),
            ]);
            NotifikasiService::qrisStatusChanged($qris->fresh(), $previousStatus, $qris->fresh()->status_pembayaran);
        }

        ActivityLog::record('Midtrans', 'webhook', 'Webhook Midtrans untuk order ' . $data['order_id'], $request->all());

        return response()->json(['message' => 'Webhook diterima']);
    }

    /**
     * Webhook SumoPod Managed Payment.
     * Verifikasi memakai header X-Webhook-Token (whtok_...) sesuai dokumentasi SumoPod.
     */
    public function sumopodWebhook(Request $request)
    {
        $token = config('services.sumopod.webhook_token');
        if (!$token) {
            return response()->json(['message' => 'SUMOPOD_WEBHOOK_TOKEN belum diisi di file .env backend.'], 503);
        }

        $given = $request->header('X-Webhook-Token');
        if (!$given || !hash_equals($token, $given)) {
            return response()->json(['message' => 'Token webhook SumoPod tidak valid'], 403);
        }

        $payload = $request->all();
        $fields = $this->flattenWebhookPayload($payload);
        $orderId = $fields['order_id'] ?? null;
        $event = (string) ($payload['type'] ?? $payload['event'] ?? '');
        $eventStatus = (string) ($fields['status'] ?? '');

        if (!$orderId) {
            return response()->json(['message' => 'order_id tidak ditemukan pada payload'], 202);
        }

        $qris = PembayaranQris::where('order_id', $orderId)->where('provider', 'sumopod')->first();
        if (!$qris) {
            return response()->json(['message' => 'Pembayaran SumoPod tidak ditemukan: ' . $orderId], 202);
        }

        $newStatus = $this->mapSumopodStatus(trim($event . ' ' . $eventStatus));
        $previousStatus = $qris->status_pembayaran;

        $response = (array) $qris->payment_response;
        $response['last_webhook'] = $payload;
        $response['last_webhook_at'] = now()->toISOString();
        $qris->payment_response = $response;

        if ($newStatus && $newStatus !== $previousStatus) {
            $qris->status_pembayaran = $newStatus;
            if ($newStatus === 'settlement') {
                $qris->paid_at = now();
            }
        }
        $qris->save();

        if ($qris->status_pembayaran !== $previousStatus) {
            NotifikasiService::qrisStatusChanged($qris->fresh(), $previousStatus, $qris->status_pembayaran);
        }

        ActivityLog::record('SumoPod', 'webhook', 'Webhook SumoPod untuk order ' . $orderId, $payload);

        return response()->json([
            'message' => 'Webhook diterima',
            'order_id' => $orderId,
            'status' => $qris->status_pembayaran,
        ]);
    }

    /**
     * Samakan status pembayaran SumoPod dengan status sebenarnya di sisi SumoPod.
     *
     * Webhook tetap jalur utama, tetapi bila webhook tidak sampai (URL salah, server
     * sempat mati, dsb.) status lokal bisa tertinggal "pending" padahal pelanggan sudah
     * membayar. Endpoint ini adalah sumber yang sama dengan yang dipakai halaman
     * pembayaran SumoPod saat menampilkan hasil ke pelanggan.
     */
    private function syncSumopodStatus(PembayaranQris $qris): PembayaranQris
    {
        if ($qris->provider !== 'sumopod' || !$qris->transaction_id) {
            return $qris;
        }

        if (in_array($qris->status_pembayaran, ['settlement', 'expire', 'cancel', 'deny'], true)) {
            return $qris;
        }

        // Halaman kasir mem-polling tiap beberapa detik; batasi agar tidak membanjiri SumoPod.
        $cacheKey = 'sumopod:status:' . $qris->order_id;
        if (Cache::has($cacheKey)) {
            return $qris;
        }
        Cache::put($cacheKey, true, self::SUMOPOD_STATUS_TTL);

        $baseUrl = rtrim(config('services.sumopod.base_url'), '/');

        try {
            $response = Http::withHeaders(['X-Api-Key' => config('services.sumopod.api_key')])
                ->acceptJson()
                ->timeout(5)
                ->get($baseUrl . '/public/payments/' . urlencode($qris->transaction_id) . '/status');
        } catch (\Throwable $e) {
            return $qris; // status lokal tetap dipakai bila SumoPod tidak bisa dihubungi
        }

        if (!$response->successful()) {
            return $qris;
        }

        $status = $this->mapSumopodStatus((string) $response->json('status'));
        if (!$status || $status === $qris->status_pembayaran) {
            return $qris;
        }

        $previousStatus = $qris->status_pembayaran;
        $qris->status_pembayaran = $status;
        if ($status === 'settlement' && !$qris->paid_at) {
            $qris->paid_at = now();
        }
        $qris->payment_response = array_merge((array) $qris->payment_response, [
            'status_poll' => [
                'at' => now()->toISOString(),
                'status' => $response->json('status'),
            ],
        ]);
        $qris->save();

        NotifikasiService::qrisStatusChanged($qris->fresh(), $previousStatus, $status);

        return $qris;
    }

    private function mapSumopodStatus(string $haystack): ?string
    {
        $value = strtolower($haystack);

        if (str_contains($value, 'completed') || str_contains($value, 'paid')
            || str_contains($value, 'settle') || str_contains($value, 'success')) {
            return 'settlement';
        }
        if (str_contains($value, 'expire')) {
            return 'expire';
        }
        if (str_contains($value, 'fail') || str_contains($value, 'cancel') || str_contains($value, 'deny')) {
            return 'cancel';
        }
        if (str_contains($value, 'pending')) {
            return 'pending';
        }

        return null;
    }

    /**
     * Payload SumoPod belum tentu datar, jadi order_id/status dicari rekursif.
     */
    private function flattenWebhookPayload(array $payload): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found) {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (!array_key_exists($key, $found)) {
                    $found[$key] = $value;
                }
            }
        };
        $walk($payload);

        return $found;
    }

    /**
     * Konfirmasi manual pembayaran QRIS — hanya aktif bila SUMOPOD_ALLOW_MANUAL_CONFIRM=true.
     * Dipakai untuk ujicoba sandbox selama webhook belum bisa dipicu dari dashboard SumoPod.
     */
    public function qrisManualConfirm(Request $request)
    {
        if (!config('services.sumopod.allow_manual_confirm', false)) {
            return response()->json(['message' => 'Konfirmasi manual hanya tersedia di mode sandbox.'], 403);
        }

        $data = $request->validate([
            'order_id' => 'required|string|max:100',
        ]);

        $qris = PembayaranQris::where('order_id', $data['order_id'])
            ->where('provider', 'sumopod')
            ->first();

        if (!$qris) {
            return response()->json(['message' => 'Pembayaran QRIS SumoPod tidak ditemukan.'], 404);
        }

        if ($qris->status_pembayaran === 'settlement') {
            return response()->json(['message' => 'Pembayaran sudah lunas.', 'status' => 'settlement']);
        }

        $previousStatus = $qris->status_pembayaran;
        $qris->status_pembayaran = 'settlement';
        $qris->paid_at = now();
        $qris->payment_response = array_merge((array) $qris->payment_response, [
            'manual_confirm' => [
                'by' => $request->user()->username ?? $request->user()->name ?? 'unknown',
                'at' => now()->toISOString(),
                'environment' => 'sandbox',
            ],
        ]);
        $qris->save();

        NotifikasiService::qrisStatusChanged($qris->fresh(), $previousStatus, 'settlement');
        ActivityLog::record('SumoPod', 'manual-confirm', 'Konfirmasi manual sandbox untuk order ' . $qris->order_id, [
            'order_id' => $qris->order_id,
            'nominal' => $qris->nominal,
        ]);

        return response()->json([
            'message' => 'Pembayaran ditandai lunas (konfirmasi manual sandbox).',
            'status' => 'settlement',
        ]);
    }

    private function normalizeQrisPaymentStatus(string $status): string
    {
        return match ($status) {
            'settlement', 'capture' => 'settlement',
            'expire' => 'expire',
            'cancel', 'deny', 'failure' => 'cancel',
            default => 'pending',
        };
    }

    private function generateNomorReturPelanggan(): string
    {
        $prefix = 'RET-P-' . now()->format('Ymd') . '-';
        $lastRetur = ReturPelanggan::where('nomor_retur', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('nomor_retur')
            ->first();

        $nextNumber = $lastRetur
            ? ((int) substr($lastRetur->nomor_retur, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
