<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'module' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $query = ActivityLog::query()->orderBy('created_at', 'desc');
        if ($request->user()->role === 'admin') {
            $query->select(['id','user_name','module','action','description','created_at','updated_at']);
        }

        if (!empty($filters['module']) && $filters['module'] !== 'semua') {
            $query->where('module', $filters['module']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(250)->get());
    }
}
