<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SuppliersExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterSupplier;
use App\Services\SettingService;
use App\Services\SupplierRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SupplierController extends BaseApiController
{
    /**
     * Display a listing of suppliers.
     */
    public function index(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = MasterSupplier::query();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_supplier', 'like', "%{$search}%")
                  ->orWhere('nama_supplier', 'like', "%{$search}%")
                  ->orWhere('nama_pic', 'like', "%{$search}%")
                  ->orWhere('telepon', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['kode_supplier', 'nama_supplier', 'nama_pic', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $suppliers = $query->paginate($perPage);

        return $this->success([
            'suppliers' => $suppliers->items(),
            'pagination' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'kode_supplier' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                'unique:master_supplier,kode_supplier',
            ],
            'nama_supplier' => 'required|string|max:100',
            'nama_pic' => 'required|string|max:100',
            'telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string',
            'npwp' => 'nullable|string|max:30',
            'bank_nama' => 'nullable|string|max:100',
            'bank_rekening' => 'nullable|string|max:30',
            'bank_atas_nama' => 'nullable|string|max:100',
            'tempo_default' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive',
        ], [
            'kode_supplier.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-)',
        ]);

        // Format kode and nama based on settings
        $validated['kode_supplier'] = SettingService::formatCode($validated['kode_supplier']);
        $validated['nama_supplier'] = SettingService::formatName($validated['nama_supplier']);
        $validated['nama_pic'] = SettingService::formatName($validated['nama_pic']);

        // Set default tempo if not provided
        if (!isset($validated['tempo_default'])) {
            $validated['tempo_default'] = 0;
        }

        // Create supplier
        $supplier = MasterSupplier::create($validated);

        return $this->success([
            'supplier' => $supplier,
        ], 'Supplier berhasil dibuat', 201);
    }

    /**
     * Display the specified supplier.
     */
    public function show(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.view')) {
            return $this->error('Unauthorized', 403);
        }

        $supplier = MasterSupplier::with([
            'createdBy:id,name,email',
            'updatedBy:id,name,email'
        ])->where('ulid', $ulid)->first();

        if (!$supplier) {
            return $this->error('Supplier tidak ditemukan', 404);
        }

        return $this->success([
            'supplier' => $supplier,
        ]);
    }

    /**
     * Update the specified supplier.
     * Note: kode_supplier cannot be changed after creation.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.update')) {
            return $this->error('Unauthorized', 403);
        }

        $supplier = MasterSupplier::where('ulid', $ulid)->first();

        if (!$supplier) {
            return $this->error('Supplier tidak ditemukan', 404);
        }

        // kode_supplier is NOT in validation - cannot be updated
        $validated = $request->validate([
            'nama_supplier' => 'required|string|max:100',
            'nama_pic' => 'required|string|max:100',
            'telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string',
            'npwp' => 'nullable|string|max:30',
            'bank_nama' => 'nullable|string|max:100',
            'bank_rekening' => 'nullable|string|max:30',
            'bank_atas_nama' => 'nullable|string|max:100',
            'tempo_default' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive',
        ]);

        // Format nama based on settings
        $validated['nama_supplier'] = SettingService::formatName($validated['nama_supplier']);
        $validated['nama_pic'] = SettingService::formatName($validated['nama_pic']);

        // Set default tempo if not provided
        if (!isset($validated['tempo_default'])) {
            $validated['tempo_default'] = 0;
        }

        if ($supplier->status === 'active' && $validated['status'] === 'inactive') {
            $message = SupplierRules::deactivationBlockMessage($supplier);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        // Update supplier
        $supplier->update($validated);

        return $this->success([
            'supplier' => $supplier,
        ], 'Supplier berhasil diupdate');
    }

    /**
     * Toggle status (activate/deactivate) the specified supplier.
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.update')) {
            return $this->error('Unauthorized', 403);
        }

        $supplier = MasterSupplier::where('ulid', $ulid)->first();

        if (!$supplier) {
            return $this->error('Supplier tidak ditemukan', 404);
        }

        if ($supplier->status === 'active') {
            $message = SupplierRules::deactivationBlockMessage($supplier);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        // Toggle status
        $newStatus = $supplier->status === 'active' ? 'inactive' : 'active';
        $supplier->update(['status' => $newStatus]);

        $message = $newStatus === 'active'
            ? 'Supplier berhasil diaktifkan'
            : 'Supplier berhasil dinonaktifkan';

        return $this->success(['supplier' => $supplier], $message);
    }

    /**
     * Permanently delete the specified supplier.
     * Will fail if there are purchase orders using this supplier.
     */
    public function destroy(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('supplier.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $supplier = MasterSupplier::where('ulid', $ulid)->first();

        if (!$supplier) {
            return $this->error('Supplier tidak ditemukan', 404);
        }

        $message = SupplierRules::deletionBlockMessage($supplier);
        if ($message) {
            return $this->error($message, 422);
        }

        // Permanently delete
        $supplier->delete();

        return $this->success(null, 'Supplier berhasil dihapus permanen');
    }

    /**
     * Export suppliers to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('supplier.view')) {
            return $this->error('Unauthorized', 403);
        }

        $filename = 'master_supplier_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new SuppliersExport(
                $request->input('search'),
                $request->input('status'),
            ),
            $filename
        );
    }

    /**
     * Get list of active suppliers for dropdowns.
     */
    public function list(Request $request): JsonResponse
    {
        $suppliers = MasterSupplier::active()
            ->select('id', 'ulid', 'kode_supplier', 'nama_supplier', 'tempo_default')
            ->orderBy('nama_supplier')
            ->get()
            ->makeVisible('id');

        return $this->success([
            'suppliers' => $suppliers,
        ]);
    }
}
