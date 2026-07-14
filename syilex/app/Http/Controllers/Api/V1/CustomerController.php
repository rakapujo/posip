<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomersExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterCustomer;
use App\Models\MasterTipeCustomer;
use App\Models\MasterKategoriCustomer;
use App\Services\CustomerRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends BaseApiController
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = MasterCustomer::with([
            'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai'
        ]);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_customer', 'like', "%{$search}%")
                  ->orWhere('nama', 'like', "%{$search}%")
                  ->orWhere('telepon', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by jenis
        if ($request->filled('jenis')) {
            $query->where('jenis', $request->jenis);
        }

        // Filter by tipe customer
        if ($request->filled('tipe_customer_ulid')) {
            $tipeCustomer = MasterTipeCustomer::where('ulid', $request->tipe_customer_ulid)->first();
            if ($tipeCustomer) {
                $query->where('tipe_customer_id', $tipeCustomer->id);
            }
        }

        // Filter by kategori customer
        if ($request->filled('kategori_customer_ulid')) {
            $kategoriCustomer = MasterKategoriCustomer::where('ulid', $request->kategori_customer_ulid)->first();
            if ($kategoriCustomer) {
                $query->where('kategori_customer_id', $kategoriCustomer->id);
            }
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['kode_customer', 'nama', 'telepon', 'email', 'jenis', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        // Handle sorting by relation fields with JOIN
        if ($sortField === 'tipe_customer_nama') {
            $query->leftJoin('master_tipe_customer', 'master_customer.tipe_customer_id', '=', 'master_tipe_customer.id')
                  ->orderBy('master_tipe_customer.nama_tipe', $sortOrder)
                  ->select('master_customer.*');
        } elseif ($sortField === 'kategori_customer_nama') {
            $query->leftJoin('master_kategori_customer', 'master_customer.kategori_customer_id', '=', 'master_kategori_customer.id')
                  ->orderBy('master_kategori_customer.nama_kategori', $sortOrder)
                  ->select('master_customer.*');
        } elseif (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $customers = $query->paginate($perPage);

        return $this->success([
            'customers' => $customers->items(),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'kode_customer' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                'unique:master_customer,kode_customer',
            ],
            'nama' => 'required|string|max:100',
            'telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string',
            'nik' => 'nullable|string|max:20',
            'npwp' => 'nullable|string|max:30',
            'tipe_customer_ulid' => 'nullable|string|exists:master_tipe_customer,ulid',
            'kategori_customer_ulid' => 'nullable|string|exists:master_kategori_customer,ulid',
            'jenis' => 'required|in:walk_in,spesifik',
            'status' => 'required|in:active,inactive',
        ], [
            'kode_customer.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-)',
        ]);

        // Format kode and nama based on settings
        $validated['kode_customer'] = SettingService::formatCode($validated['kode_customer']);
        $validated['nama'] = SettingService::formatName($validated['nama']);

        // Get tipe customer if provided
        $tipeCustomerId = null;
        if (!empty($validated['tipe_customer_ulid'])) {
            $tipeCustomer = MasterTipeCustomer::where('ulid', $validated['tipe_customer_ulid'])->first();
            if ($message = CustomerRules::storeInactiveTipeBlockMessage($tipeCustomer)) {
                return $this->error($message, 422);
            }
            $tipeCustomerId = $tipeCustomer?->id;
        }

        // Get kategori customer if provided
        $kategoriCustomerId = null;
        if (!empty($validated['kategori_customer_ulid'])) {
            $kategoriCustomer = MasterKategoriCustomer::where('ulid', $validated['kategori_customer_ulid'])->first();
            if ($message = CustomerRules::storeInactiveKategoriBlockMessage($kategoriCustomer)) {
                return $this->error($message, 422);
            }
            $kategoriCustomerId = $kategoriCustomer?->id;
        }

        // Create customer
        $customer = MasterCustomer::create([
            'kode_customer' => $validated['kode_customer'],
            'nama' => $validated['nama'],
            'telepon' => $validated['telepon'],
            'email' => $validated['email'] ?? null,
            'alamat' => $validated['alamat'] ?? null,
            'nik' => $validated['nik'] ?? null,
            'npwp' => $validated['npwp'] ?? null,
            'tipe_customer_id' => $tipeCustomerId,
            'kategori_customer_id' => $kategoriCustomerId,
            'jenis' => $validated['jenis'],
            'status' => $validated['status'],
        ]);

        // Load relationships
        $customer->load([
            'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai'
        ]);

        return $this->success([
            'customer' => $customer,
        ], 'Customer berhasil dibuat', 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.view')) {
            return $this->error('Unauthorized', 403);
        }

        $customer = MasterCustomer::with([
            'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai',
            'createdBy:id,name,email',
            'updatedBy:id,name,email'
        ])->where('ulid', $ulid)->first();

        if (!$customer) {
            return $this->error('Customer tidak ditemukan', 404);
        }

        return $this->success([
            'customer' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     * Note: kode_customer cannot be changed after creation.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.update')) {
            return $this->error('Unauthorized', 403);
        }

        $customer = MasterCustomer::where('ulid', $ulid)->first();

        if (!$customer) {
            return $this->error('Customer tidak ditemukan', 404);
        }

        // Prevent editing walk_in customer type
        if ($message = CustomerRules::walkInTypeChangeBlockMessage($customer, $request->input('jenis', $customer->jenis))) {
            return $this->error($message, 422);
        }

        // kode_customer is NOT in validation - cannot be updated
        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string',
            'nik' => 'nullable|string|max:20',
            'npwp' => 'nullable|string|max:30',
            'tipe_customer_ulid' => 'nullable|string|exists:master_tipe_customer,ulid',
            'kategori_customer_ulid' => 'nullable|string|exists:master_kategori_customer,ulid',
            'jenis' => 'required|in:walk_in,spesifik',
            'status' => 'required|in:active,inactive',
        ]);

        // Format nama based on settings
        $validated['nama'] = SettingService::formatName($validated['nama']);

        // Get tipe customer if provided
        $tipeCustomerId = null;
        if (!empty($validated['tipe_customer_ulid'])) {
            $tipeCustomer = MasterTipeCustomer::where('ulid', $validated['tipe_customer_ulid'])->first();
            if ($message = CustomerRules::inactiveTipeBlockMessage($tipeCustomer, $customer->tipe_customer_id)) {
                return $this->error($message, 422);
            }
            $tipeCustomerId = $tipeCustomer?->id;
        }

        // Get kategori customer if provided
        $kategoriCustomerId = null;
        if (!empty($validated['kategori_customer_ulid'])) {
            $kategoriCustomer = MasterKategoriCustomer::where('ulid', $validated['kategori_customer_ulid'])->first();
            if ($message = CustomerRules::inactiveKategoriBlockMessage($kategoriCustomer, $customer->kategori_customer_id)) {
                return $this->error($message, 422);
            }
            $kategoriCustomerId = $kategoriCustomer?->id;
        }

        // Block deactivation via edit if used as default by POS terminal
        if ($customer->status === 'active' && $validated['status'] === 'inactive') {
            $message = CustomerRules::deactivationBlockMessage($customer);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        // Update customer
        $customer->update([
            'nama' => $validated['nama'],
            'telepon' => $validated['telepon'],
            'email' => $validated['email'] ?? null,
            'alamat' => $validated['alamat'] ?? null,
            'nik' => $validated['nik'] ?? null,
            'npwp' => $validated['npwp'] ?? null,
            'tipe_customer_id' => $tipeCustomerId,
            'kategori_customer_id' => $kategoriCustomerId,
            'jenis' => $validated['jenis'],
            'status' => $validated['status'],
        ]);

        // Load relationships
        $customer->load([
            'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai'
        ]);

        return $this->success([
            'customer' => $customer,
        ], 'Customer berhasil diupdate');
    }

    /**
     * Toggle status (activate/deactivate) the specified customer.
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.update')) {
            return $this->error('Unauthorized', 403);
        }

        $customer = MasterCustomer::where('ulid', $ulid)->first();

        if (!$customer) {
            return $this->error('Customer tidak ditemukan', 404);
        }

        if ($customer->status === 'active') {
            $message = CustomerRules::deactivationBlockMessage($customer);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        // Toggle status
        $newStatus = $customer->status === 'active' ? 'inactive' : 'active';
        $customer->update(['status' => $newStatus]);

        // Load relationships
        $customer->load([
            'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai'
        ]);

        $message = $newStatus === 'active'
            ? 'Customer berhasil diaktifkan'
            : 'Customer berhasil dinonaktifkan';

        return $this->success(['customer' => $customer], $message);
    }

    /**
     * Permanently delete the specified customer.
     * Will fail if there are transactions using this customer.
     */
    public function destroy(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('customer.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $customer = MasterCustomer::where('ulid', $ulid)->first();

        if (!$customer) {
            return $this->error('Customer tidak ditemukan', 404);
        }

        if ($message = CustomerRules::deletionBlockMessage($customer)) {
            return $this->error($message, 422);
        }

        // Permanently delete
        $customer->delete();

        return $this->success(null, 'Customer berhasil dihapus permanen');
    }

    /**
     * Export customers to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('customer.view')) {
            return $this->error('Unauthorized', 403);
        }

        $tipeCustomerId = null;
        if ($request->filled('tipe_customer_ulid')) {
            $tipeCustomer = MasterTipeCustomer::where('ulid', $request->tipe_customer_ulid)->first();
            if ($tipeCustomer) $tipeCustomerId = $tipeCustomer->id;
        }

        $kategoriCustomerId = null;
        if ($request->filled('kategori_customer_ulid')) {
            $kategoriCustomer = MasterKategoriCustomer::where('ulid', $request->kategori_customer_ulid)->first();
            if ($kategoriCustomer) $kategoriCustomerId = $kategoriCustomer->id;
        }

        $filename = 'master_customer_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new CustomersExport(
                $request->input('search'),
                $request->input('status'),
                $request->input('jenis'),
                $tipeCustomerId,
                $kategoriCustomerId,
            ),
            $filename
        );
    }

    /**
     * Get list of active customers for dropdowns.
     */
    public function list(Request $request): JsonResponse
    {
        $query = MasterCustomer::active()
            ->with([
                'tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
                'kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai'
            ])
            ->select('id', 'ulid', 'kode_customer', 'nama', 'telepon', 'jenis', 'tipe_customer_id', 'kategori_customer_id');

        // Filter by jenis if provided
        if ($request->filled('jenis')) {
            $query->where('jenis', $request->jenis);
        }

        $customers = $query->orderBy('nama')->get()->makeVisible('id');

        return $this->success([
            'customers' => $customers,
        ]);
    }
}
