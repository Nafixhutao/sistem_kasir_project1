<?php
namespace App\Http\Controllers;
use App\Models\Supplier;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
class SupplierController extends Controller
{
    public function index()
    {
        return response()->json(Supplier::withCount('barang')->orderBy('nama_supplier')->get());
    }
    public function show($supplier)
    {
        return response()->json(Supplier::withCount('barang')->findOrFail($supplier));
    }
    private function validated(Request $request): array
    {
        return $request->validate([
            'nama_supplier'=>'required|string|max:150',
            'no_telepon'=>'nullable|string|max:30',
            'alamat'=>'nullable|string|max:255',
            'keterangan'=>'nullable|string|max:500',
        ]);
    }
    private function record(Supplier $supplier, string $action): void
    {
        ActivityLog::record('Supplier', $action, 'Data supplier '.$supplier->nama_supplier,
            ['supplier_id'=>$supplier->id, 'nama_supplier'=>$supplier->nama_supplier]);
    }
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $supplier = DB::transaction(function () use ($data) {
            $supplier = Supplier::create($data); $this->record($supplier, 'create'); return $supplier;
        });
        return response()->json($supplier->loadCount('barang'), 201);
    }
    public function update(Request $request, $supplier)
    {
        $data = $this->validated($request);
        $row = DB::transaction(function () use ($data, $supplier) {
            $row = Supplier::lockForUpdate()->findOrFail($supplier);
            $row->update($data); $this->record($row, 'update'); return $row;
        });
        return response()->json($row->loadCount('barang'));
    }
    public function destroy($supplier)
    {
        try {
            DB::transaction(function () use ($supplier) {
                $row = Supplier::lockForUpdate()->findOrFail($supplier);
                if ($row->barang()->exists()) abort(422, 'Supplier masih digunakan Barang dan tidak dapat dihapus.');
                $row->delete(); $this->record($row, 'delete');
            });
        } catch (QueryException $e) {
            // FK is the final protection against a concurrent Barang association.
            if (($e->errorInfo[1] ?? null) !== 1451) throw $e;
            return response()->json(['message'=>'Supplier masih digunakan Barang dan tidak dapat dihapus.'], 422);
        }
        return response()->json(['message'=>'Supplier berhasil dihapus.']);
    }
}
