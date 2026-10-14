<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BiayaAdminBrilink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BiayaAdminController extends Controller
{
    public function index()
    {
        // Viewing tariffs must not initialize or modify operational data.
        return response()->json([
            'layanan' => BiayaAdminBrilink::layanan(),
            'data' => BiayaAdminBrilink::query()
                ->orderBy('jenis_transaksi')
                ->orderBy('jenis_nasabah')
                ->orderBy('nominal_min')
                ->get(),
        ]);
    }

    public function update(Request $request, string $jenisTransaksi)
    {
        if (!array_key_exists($jenisTransaksi, BiayaAdminBrilink::layanan())) {
            return response()->json(['message' => 'Jenis transaksi tidak valid'], 422);
        }

        $usesNasabah = BiayaAdminBrilink::usesNasabah($jenisTransaksi);
        $data = $request->validate([
            'jenis_nasabah' => $usesNasabah ? 'required|in:internal,eksternal' : 'nullable|in:internal,eksternal',
            'ranges' => 'required|array|min:1',
            'ranges.*.nominal_min' => 'required|numeric|min:1',
            'ranges.*.nominal_max' => 'nullable|numeric|min:1',
            'ranges.*.biaya_admin' => 'required|numeric|min:0',
            'ranges.*.aktif' => 'nullable|boolean',
        ]);

        $jenisNasabah = $usesNasabah ? BiayaAdminBrilink::normalizeJenisNasabah($data['jenis_nasabah']) : null;
        $ranges = $this->normalizeRanges($data['ranges']);
        $this->validateRangesNotOverlap($ranges);

        DB::transaction(function () use ($jenisTransaksi, $jenisNasabah, $ranges) {
            $this->groupQuery($jenisTransaksi, $jenisNasabah)->delete();

            foreach ($ranges as $range) {
                BiayaAdminBrilink::create([
                    'jenis_transaksi' => $jenisTransaksi,
                    'jenis_nasabah' => $jenisNasabah,
                    'jenis_kartu' => $jenisNasabah ? BiayaAdminBrilink::jenisKartuNasabah($jenisNasabah) : null,
                    'nominal_min' => $range['nominal_min'],
                    'nominal_max' => $range['nominal_max'],
                    'biaya_admin' => $range['biaya_admin'],
                    'aktif' => $range['aktif'],
                ]);
            }
        });

        $updated = $this->groupQuery($jenisTransaksi, $jenisNasabah)
            ->orderBy('nominal_min')
            ->get();

        ActivityLog::record('Biaya Admin', 'update', 'Memperbarui biaya admin BRILink ' . $jenisTransaksi . ($jenisNasabah ? ' ' . $jenisNasabah : ''), $updated->toArray());

        return response()->json($updated);
    }

    private function normalizeRanges(array $ranges): array
    {
        return collect($ranges)
            ->map(fn($range) => [
                'nominal_min' => (float) $range['nominal_min'],
                'nominal_max' => ($range['nominal_max'] ?? null) === '' || ($range['nominal_max'] ?? null) === null
                    ? null
                    : (float) $range['nominal_max'],
                'biaya_admin' => (float) $range['biaya_admin'],
                'aktif' => (bool) ($range['aktif'] ?? true),
            ])
            ->sortBy('nominal_min')
            ->values()
            ->all();
    }

    private function groupQuery(string $jenisTransaksi, ?string $jenisNasabah)
    {
        return BiayaAdminBrilink::query()
            ->where('jenis_transaksi', $jenisTransaksi)
            ->when(
                $jenisNasabah === null,
                fn($query) => $query->whereNull('jenis_nasabah'),
                fn($query) => $query->where('jenis_nasabah', $jenisNasabah)
            );
    }

    private function validateRangesNotOverlap(array $ranges): void
    {
        $previousMax = null;

        foreach ($ranges as $index => $range) {
            if ($range['nominal_max'] !== null && $range['nominal_max'] < $range['nominal_min']) {
                throw ValidationException::withMessages([
                    'ranges.' . $index . '.nominal_max' => 'Nominal maksimum tidak boleh lebih kecil dari nominal minimum.',
                ]);
            }

            if ($previousMax === null && $index > 0) {
                throw ValidationException::withMessages([
                    'ranges' => 'Range nominal tidak boleh memiliki range ke atas sebelum range terakhir.',
                ]);
            }

            if ($previousMax !== null && $range['nominal_min'] <= $previousMax) {
                throw ValidationException::withMessages([
                    'ranges' => 'Range nominal tidak boleh tumpang tindih dalam jenis transaksi dan jenis nasabah yang sama.',
                ]);
            }

            $previousMax = $range['nominal_max'];
        }
    }
}
