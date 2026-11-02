<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiPenjualan;
use App\Models\Barang;
use App\Models\TransaksiTransfer;
use App\Models\TransaksiTarikTunai;
use App\Models\TransaksiSetorTunai;
use App\Models\PembayaranTagihan;
use App\Models\TransaksiPulsa;
use App\Models\TransaksiEwallet;
use App\Models\DetailPenjualan;
use App\Models\ActivityLog;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        $totalPenjualanHariIni = TransaksiPenjualan::whereDate('tanggal', $today)
            ->where('status', 'lunas')
            ->sum('total_harga');

        $transferHariIni = TransaksiTransfer::whereDate('tanggal', $today)->count();
        $tarikTunaiHariIni = TransaksiTarikTunai::whereDate('tanggal', $today)->count();
        $setorTunaiHariIni = TransaksiSetorTunai::whereDate('tanggal', $today)->count();
        $tagihanHariIni = PembayaranTagihan::whereDate('tanggal', $today)->count();
        $pulsaHariIni = TransaksiPulsa::whereDate('tanggal', $today)->count();
        $ewalletHariIni = TransaksiEwallet::whereDate('tanggal', $today)->count();

        $totalTransaksiBrilink = $transferHariIni + $tarikTunaiHariIni + $setorTunaiHariIni + $tagihanHariIni + $pulsaHariIni + $ewalletHariIni;

        $totalAdminFee = TransaksiTransfer::whereDate('tanggal', $today)->sum('biaya_admin') +
                         TransaksiTarikTunai::whereDate('tanggal', $today)->sum('biaya_admin') +
                         TransaksiSetorTunai::whereDate('tanggal', $today)->sum('biaya_admin') +
                         PembayaranTagihan::whereDate('tanggal', $today)->sum('biaya_admin') +
                         TransaksiPulsa::whereDate('tanggal', $today)->sum('biaya_admin') +
                         TransaksiEwallet::whereDate('tanggal', $today)->sum('biaya_admin');

        $stokMenipis = Barang::where('stok', '<', 5)->get();

        $transaksiTerbaru = TransaksiPenjualan::with('details')
            ->orderBy('dibuat_pada', 'desc')
            ->limit(5)
            ->get();

        $produkTerlaris = DetailPenjualan::selectRaw('nama_barang, kode_barang, SUM(jumlah) as total_jual')
            ->whereHas('transaksi', fn($query) => $query->whereDate('tanggal', $today)->where('status', 'lunas'))
            ->groupBy('nama_barang', 'kode_barang')
            ->orderByDesc('total_jual')
            ->limit(5)
            ->get();

        $aktivitasTerbaru = ActivityLog::orderBy('dibuat_pada', 'desc')->limit(6)->get();

        return response()->json([
            'total_penjualan_hari_ini' => $totalPenjualanHariIni,
            'total_transaksi_brilink' => $totalTransaksiBrilink,
            'total_keuntungan_admin' => $totalAdminFee,
            'stok_menipis' => $stokMenipis,
            'transaksi_terbaru' => $transaksiTerbaru,
            'produk_terlaris' => $produkTerlaris,
            'aktivitas_terbaru' => $aktivitasTerbaru,
        ]);
    }
}
