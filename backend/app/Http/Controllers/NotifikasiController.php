<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = (int) ($data['limit'] ?? 10);

        $notifikasi = Notifikasi::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('dibuat_pada')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $notifikasi,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return Notifikasi::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('dibaca_at')
            ->count();
    }

    public function count(Request $request)
    {
        return response()->json([
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $notifikasi = Notifikasi::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($notifikasi->dibaca_at === null) {
            $notifikasi->forceFill(['dibaca_at' => now()])->save();
        }

        return response()->json($notifikasi->fresh());
    }

    public function markAllAsRead(Request $request)
    {
        Notifikasi::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('dibaca_at')
            ->update(['dibaca_at' => now(), 'diperbarui_pada' => now()]);

        return response()->json([
            'message' => 'Semua notifikasi sudah dibaca',
            'unread_count' => 0,
        ]);
    }
}
