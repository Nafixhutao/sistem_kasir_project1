<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\BusinessBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    public function download()
    {
        $payload = DB::transaction(function () {
            $payload = [
                'app' => 'Sultan Cell',
                'backup_at' => now()->toDateTimeString(),
                'tables' => [],
            ];

            foreach (BusinessBackup::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $payload['tables'][$table] = DB::table($table)->orderBy('id')->get();
                }
            }
            return $payload;
        });

        ActivityLog::record('Backup', 'download', 'Mengunduh backup data website');

        return response()->json($payload);
    }

    public function restore(Request $request)
    {
        $request->validate([
            'backup' => 'required|array',
            'confirm_text' => 'required|in:RESTORE SULTAN CELL',
        ]);

        try {
            DB::transaction(function () use ($request) {
                [$tables, $order] = BusinessBackup::prepare($request->input('backup'));
                // Lock parent first, remove children first. FK checks remain enabled.
                foreach ($order as $table) DB::table($table)->select('id')->orderBy('id')->lockForUpdate()->get();
                foreach (array_reverse($order) as $table) DB::table($table)->delete();
                foreach ($order as $table) {
                    foreach (array_chunk($tables[$table], 200) as $rows) if ($rows) DB::table($table)->insert($rows);
                    if (DB::table($table)->count() !== count($tables[$table])) throw new \RuntimeException('Restore row verification failed.');
                }
                // Include audit in the transaction; a failure cannot commit a partial restore.
                ActivityLog::create([
                    'user_name' => $request->user()->username, 'module' => 'Backup', 'action' => 'restore',
                    'description' => 'Restore backup data website', 'properties' => [],
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Do not expose or log SQL/bindings/backup contents (may contain credentials).
            return response()->json(['message' => 'Restore gagal. Seluruh perubahan data dibatalkan.'], 500);
        }

        return response()->json(['message' => 'Restore backup berhasil']);
    }
}
