<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'modul' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $query = ActivityLog::query()->orderBy('dibuat_pada', 'desc');
        if ($request->user()->peran === 'admin') {
            $query->select(['id','nama_pengguna','modul','aksi','keterangan','dibuat_pada','diperbarui_pada']);
        }

        if (!empty($filters['modul']) && $filters['modul'] !== 'semua') {
            $query->where('modul', $filters['modul']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('modul', 'like', "%{$search}%")
                    ->orWhere('aksi', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(250)->get());
    }

    /** Daftar modul yang benar-benar ada di riwayat aktivitas (untuk pilihan filter). */
    public function modul()
    {
        $modul = ActivityLog::query()
            ->select('modul')
            ->distinct()
            ->orderBy('modul')
            ->pluck('modul')
            ->filter()
            ->values();

        return response()->json($modul);
    }
}
