<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ProduksExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Services\ProdukRules;
use App\Services\SettingService;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ProdukController extends BaseApiController
{
    /**
     * Display a listing of produks.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('produk.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = MasterProduk::select([
                'id', 'ulid', 'kode_produk', 'barcode', 'is_serial', 'nama_produk', 'gambar',
                'brand_id', 'tipe_id', 'kategori_id', 'grup_id',
                'unit_1', 'konversi_1', 'harga_1',
                'unit_2', 'konversi_2', 'harga_2',
                'unit_3', 'konversi_3', 'harga_3',
                'unit_4', 'harga_4',
                'status', 'created_at'
            ])
            ->with([
                'brand:id,kode_brand,nama_brand',
                'tipe:id,kode_tipe,nama_tipe',
                'kategori:id,kode_kategori,nama_kategori',
                'grup:id,kode_grup,nama_grup',
            ]);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%");
            });
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by tipe
        if ($request->filled('tipe_id')) {
            $query->where('tipe_id', $request->tipe_id);
        }

        // Filter by kategori
        if ($request->filled('kategori_id')) {
            $query->where('kategori_id', $request->kategori_id);
        }

        // Filter by grup
        if ($request->filled('grup_id')) {
            $query->where('grup_id', $request->grup_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by serial flag (modul serial — dropdown produk serial di Input Pembelian Serial)
        if ($request->filled('is_serial')) {
            $query->where('is_serial', $request->boolean('is_serial'));
        }

        // Sort
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['kode_produk', 'barcode', 'nama_produk', 'harga_4', 'created_at'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $produks = $query->paginate($perPage);

        return $this->success([
            'produks' => $produks->items(),
            'pagination' => [
                'current_page' => $produks->currentPage(),
                'last_page' => $produks->lastPage(),
                'per_page' => $produks->perPage(),
                'total' => $produks->total(),
            ],
        ]);
    }

    /**
     * Store a newly created produk.
     */
    public function store(Request $request, UploadService $uploadService): JsonResponse
    {
        if (!auth()->user()->can('produk.create')) {
            return $this->error('Unauthorized', 403);
        }

        // Produk serial (modul A+): satuan/harga/min-stok/barcode disembunyikan & diisi otomatis.
        $isSerial = $request->boolean('is_serial');

        // Gate Modul Elektronik: produk serial baru hanya bila modul aktif.
        if ($isSerial && !SettingService::isElektronikEnabled()) {
            return $this->error('Modul Elektronik nonaktif — tidak dapat membuat produk serial. Aktifkan di Pengaturan → Modul.', 422);
        }
        $unitRule = $isSerial ? 'nullable|string|max:30' : ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'];
        $konvRule = $isSerial ? 'nullable|integer|min:1' : 'required|integer|min:1';
        $konv4Rule = $isSerial ? 'nullable|integer|in:1' : 'required|integer|in:1';
        $hargaRule = $isSerial ? 'nullable|numeric|min:0' : 'required|numeric|gt:0';
        $minStokRule = $isSerial ? 'nullable|integer|min:0' : 'required|integer|min:0';

        // Basic validation
        $validated = $request->validate([
            'kode_produk' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_]+$/',
                'unique:master_produk,kode_produk',
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('master_produk', 'barcode'),
            ],
            'is_serial' => 'nullable|boolean',
            'nama_produk' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:master_brand,id',
            'tipe_id' => 'nullable|exists:master_tipe,id',
            'kategori_id' => 'nullable|exists:master_kategori,id',
            'grup_id' => 'nullable|exists:master_grup,id',
            'gambar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'minimum_stok' => $minStokRule,
            'unit_1' => $unitRule,
            'konversi_1' => $konvRule,
            'harga_1' => $hargaRule,
            'unit_2' => $unitRule,
            'konversi_2' => $konvRule,
            'harga_2' => $hargaRule,
            'unit_3' => $unitRule,
            'konversi_3' => $konvRule,
            'harga_3' => $hargaRule,
            'unit_4' => $unitRule,
            'konversi_4' => $konv4Rule,
            'harga_4' => $hargaRule,
            'status' => 'required|in:active,inactive',
        ], [
            'kode_produk.regex' => 'Kode hanya boleh berisi huruf, angka, dan underscore (_)',
            'konversi_4.in' => 'Konversi Unit 4 harus selalu = 1',
            'harga_1.gt' => 'Harga Unit 1 harus lebih dari 0',
            'harga_2.gt' => 'Harga Unit 2 harus lebih dari 0',
            'harga_3.gt' => 'Harga Unit 3 harus lebih dari 0',
            'harga_4.gt' => 'Harga Unit 4 harus lebih dari 0',
            'unit_1.regex' => 'Satuan 1 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_2.regex' => 'Satuan 2 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_3.regex' => 'Satuan 3 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_4.regex' => 'Satuan 4 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
        ]);

        // Serial: scaffold 1 UNIT (barcode/satuan/harga/min-stok tak dipakai); skip validasi multi-unit.
        $validated['is_serial'] = $isSerial;
        if ($isSerial) {
            $validated = $this->applySerialScaffolding($validated);
        } else {
            $validationResult = $this->validateUnitsAndPrices($validated);
            if ($validationResult !== true) {
                return $this->error($validationResult, 422);
            }
        }

        if ($response = $this->validateProdukMasterReferences($validated)) {
            return $response;
        }

        // Format code and name
        $validated['kode_produk'] = SettingService::formatCode($validated['kode_produk']);
        $validated['nama_produk'] = SettingService::formatName($validated['nama_produk']);

        // Format unit names (uppercase)
        $validated['unit_1'] = strtoupper(trim($validated['unit_1']));
        $validated['unit_2'] = strtoupper(trim($validated['unit_2']));
        $validated['unit_3'] = strtoupper(trim($validated['unit_3']));
        $validated['unit_4'] = strtoupper(trim($validated['unit_4']));

        // Calculate prices if AUTO mode (serial: skip — harga master tak dipakai, harga riil per-unit)
        $priceMode = SettingService::getPriceInputMode();
        if (!$isSerial && $priceMode === 'auto') {
            $validated = $this->calculatePrices($validated);
        }

        // Handle image upload — webp + smart-resize via UploadService (konsisten dgn upload lain)
        if ($request->hasFile('gambar')) {
            $validated['gambar'] = $uploadService->uploadImage($request->file('gambar'), 'products')['path'];
        }

        // Create produk
        $produk = MasterProduk::create($validated);

        // Load relations for response
        $produk->load(['brand:id,ulid,nama_brand', 'tipe:id,ulid,nama_tipe', 'kategori:id,ulid,nama_kategori', 'grup:id,ulid,nama_grup']);

        return $this->success([
            'produk' => $produk,
        ], 'Produk berhasil dibuat', 201);
    }

    /**
     * Display the specified produk.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('produk.view')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::with([
            'brand:id,ulid,nama_brand',
            'tipe:id,ulid,nama_tipe',
            'kategori:id,ulid,nama_kategori',
            'grup:id,ulid,nama_grup',
            'inventoryStocks.warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        // Make relation IDs visible for edit form
        if ($produk->brand) {
            $produk->brand->makeVisible('id');
        }
        if ($produk->tipe) {
            $produk->tipe->makeVisible('id');
        }
        if ($produk->kategori) {
            $produk->kategori->makeVisible('id');
        }
        if ($produk->grup) {
            $produk->grup->makeVisible('id');
        }

        // Build warehouse stocks array with ALL warehouses (including inactive)
        $allWarehouses = MasterWarehouse::select('id', 'ulid', 'kode_warehouse', 'nama_warehouse', 'status')
            ->orderBy('kode_warehouse')
            ->get();

        // Create lookup from existing stocks using getAttribute (bypasses hidden)
        $existingStocks = [];
        foreach ($produk->inventoryStocks as $stock) {
            $whId = $stock->getAttribute('warehouse_id');
            $existingStocks[$whId] = $stock;
        }

        // Build complete warehouse stocks array
        $warehouseStocks = $allWarehouses->map(function ($warehouse) use ($existingStocks, $produk) {
            $stock = $existingStocks[$warehouse->id] ?? null;

            return [
                'warehouse_id' => $warehouse->id,
                'warehouse' => [
                    'id' => $warehouse->id,
                    'ulid' => $warehouse->ulid,
                    'kode_warehouse' => $warehouse->kode_warehouse,
                    'nama_warehouse' => $warehouse->nama_warehouse,
                    'status' => $warehouse->status,
                ],
                'qty' => $stock ? (int) $stock->qty : 0,
                'avg_cost' => $stock ? (float) $stock->avg_cost : (float) $produk->avg_cost,
            ];
        });

        // Replace inventoryStocks with complete warehouse stocks
        $produk->setRelation('inventoryStocks', collect());
        $produkArray = $produk->toArray();
        $produkArray['warehouse_stocks'] = $warehouseStocks;

        return $this->success([
            'produk' => $produkArray,
        ]);
    }

    /**
     * Update the specified produk.
     */
    public function update(Request $request, string $ulid, UploadService $uploadService): JsonResponse
    {
        if (!auth()->user()->can('produk.update')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        // is_serial IMMUTABLE — pakai nilai existing (toggle read-only saat edit), abaikan request.
        $isSerial = (bool) $produk->is_serial;
        $unitRule = $isSerial ? 'nullable|string|max:30' : ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'];
        $konvRule = $isSerial ? 'nullable|integer|min:1' : 'required|integer|min:1';
        $konv4Rule = $isSerial ? 'nullable|integer|in:1' : 'required|integer|in:1';
        $hargaRule = $isSerial ? 'nullable|numeric|min:0' : 'required|numeric|gt:0';
        $minStokRule = $isSerial ? 'nullable|integer|min:0' : 'required|integer|min:0';

        // Validation (kode_produk cannot be changed)
        $validated = $request->validate([
            'barcode' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('master_produk', 'barcode')->ignore($produk->id),
            ],
            'nama_produk' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:master_brand,id',
            'tipe_id' => 'nullable|exists:master_tipe,id',
            'kategori_id' => 'nullable|exists:master_kategori,id',
            'grup_id' => 'nullable|exists:master_grup,id',
            'gambar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'minimum_stok' => $minStokRule,
            'unit_1' => $unitRule,
            'konversi_1' => $konvRule,
            'harga_1' => $hargaRule,
            'unit_2' => $unitRule,
            'konversi_2' => $konvRule,
            'harga_2' => $hargaRule,
            'unit_3' => $unitRule,
            'konversi_3' => $konvRule,
            'harga_3' => $hargaRule,
            'unit_4' => $unitRule,
            'konversi_4' => $konv4Rule,
            'harga_4' => $hargaRule,
            'status' => 'required|in:active,inactive',
        ], [
            'konversi_4.in' => 'Konversi Unit 4 harus selalu = 1',
            'harga_1.gt' => 'Harga Unit 1 harus lebih dari 0',
            'harga_2.gt' => 'Harga Unit 2 harus lebih dari 0',
            'harga_3.gt' => 'Harga Unit 3 harus lebih dari 0',
            'harga_4.gt' => 'Harga Unit 4 harus lebih dari 0',
            'unit_1.regex' => 'Satuan 1 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_2.regex' => 'Satuan 2 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_3.regex' => 'Satuan 3 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
            'unit_4.regex' => 'Satuan 4 hanya boleh berisi huruf dan angka (tanpa spasi/karakter khusus)',
        ]);

        // Serial: scaffold 1 UNIT; skip validasi multi-unit. (is_serial tak diubah — immutable)
        if ($isSerial) {
            $validated = $this->applySerialScaffolding($validated);
        } else {
            $validationResult = $this->validateUnitsAndPrices($validated);
            if ($validationResult !== true) {
                return $this->error($validationResult, 422);
            }
        }

        if ($response = $this->validateProdukMasterReferences($validated)) {
            return $response;
        }

        // Format name
        $validated['nama_produk'] = SettingService::formatName($validated['nama_produk']);

        // Format unit names (uppercase)
        $validated['unit_1'] = strtoupper(trim($validated['unit_1']));
        $validated['unit_2'] = strtoupper(trim($validated['unit_2']));
        $validated['unit_3'] = strtoupper(trim($validated['unit_3']));
        $validated['unit_4'] = strtoupper(trim($validated['unit_4']));

        // Calculate prices if AUTO mode (serial: skip)
        $priceMode = SettingService::getPriceInputMode();
        if (!$isSerial && $priceMode === 'auto') {
            $validated = $this->calculatePrices($validated);
        }

        // Handle image upload — webp + smart-resize; hapus gambar lama via oldPath
        if ($request->hasFile('gambar')) {
            $validated['gambar'] = $uploadService
                ->uploadImage($request->file('gambar'), 'products', $produk->gambar)['path'];
        }

        // Update produk
        $produk->update($validated);

        // Load relations for response
        $produk->load(['brand:id,ulid,nama_brand', 'tipe:id,ulid,nama_tipe', 'kategori:id,ulid,nama_kategori', 'grup:id,ulid,nama_grup']);

        return $this->success([
            'produk' => $produk,
        ], 'Produk berhasil diupdate');
    }

    /**
     * Toggle status (activate/deactivate) the specified produk.
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('produk.update')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $newStatus = $produk->status === 'active' ? 'inactive' : 'active';
        $produk->update(['status' => $newStatus]);

        $message = $newStatus === 'active'
            ? 'Produk berhasil diaktifkan'
            : 'Produk berhasil dinonaktifkan';

        return $this->success(['produk' => $produk], $message);
    }

    /**
     * Delete the specified produk.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('produk.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        // Check if product has any stock (qty != 0) in any warehouse
        $hasStock = $produk->inventoryStocks()->where('qty', '!=', 0)->exists();
        if ($hasStock) {
            return $this->error('Tidak dapat menghapus Produk karena masih memiliki stok di gudang. Pastikan stok = 0 di semua gudang.', 422);
        }

        // Check if product has any stock card history
        // This covers all transactions: pembelian, penjualan, opname, adjustment, transfer, repack
        $stockCardCount = $produk->stockCards()->count();
        if ($stockCardCount > 0) {
            return $this->error("Tidak dapat menghapus Produk karena sudah memiliki {$stockCardCount} riwayat kartu stok", 422);
        }

        // Check if product has any registered serial units (modul serial A+)
        $serialUnitCount = $produk->serialUnits()->count();
        if ($serialUnitCount > 0) {
            return $this->error("Tidak dapat menghapus Produk karena memiliki {$serialUnitCount} unit serial terdaftar", 422);
        }

        // Delete image if exists
        if ($produk->gambar) {
            Storage::disk('public')->delete($produk->gambar);
        }

        $produk->delete();

        return $this->success(null, 'Produk berhasil dihapus');
    }

    /**
     * Delete produk image.
     */
    public function deleteImage(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('produk.update')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        if ($produk->gambar) {
            Storage::disk('public')->delete($produk->gambar);
            $produk->update(['gambar' => null]);
        }

        return $this->success(['produk' => $produk->fresh()], 'Gambar berhasil dihapus');
    }

    /**
     * Export produks to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('produk.view')) {
            return $this->error('Unauthorized', 403);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');
        $filename = 'master_produk_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new ProduksExport(
                $canViewHpp,
                $request->input('search'),
                $request->filled('brand_id') ? (int) $request->input('brand_id') : null,
                $request->filled('tipe_id') ? (int) $request->input('tipe_id') : null,
                $request->filled('kategori_id') ? (int) $request->input('kategori_id') : null,
                $request->filled('grup_id') ? (int) $request->input('grup_id') : null,
                $request->input('status'),
            ),
            $filename
        );
    }

    /**
     * Get list of active produks for dropdowns.
     */
    public function list(Request $request): JsonResponse
    {
        $query = MasterProduk::active()
            ->select('id', 'ulid', 'kode_produk', 'barcode', 'is_serial', 'nama_produk', 'unit_4', 'harga_4');

        // Optional search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%");
            });
        }

        // Filter produk serial / non-serial (mis. Register Unit Serial hanya butuh serial)
        if ($request->filled('is_serial')) {
            $query->where('is_serial', $request->boolean('is_serial'));
        }

        $produks = $query->orderBy('nama_produk')->limit(50)->get()->makeVisible('id');

        return $this->success([
            'produks' => $produks,
        ]);
    }

    /**
     * Get price input mode setting.
     */
    public function getPriceMode(): JsonResponse
    {
        return $this->success([
            'price_input_mode' => SettingService::getPriceInputMode(),
        ]);
    }

    /**
     * Modul Serial (A+) — produk serial: scaffold 1 UNIT.
     * Barcode/satuan/harga/min-stok TIDAK dipakai (harga riil per-unit di register serial).
     */
    private function applySerialScaffolding(array $validated): array
    {
        foreach ([1, 2, 3, 4] as $i) {
            $validated["unit_{$i}"] = 'UNIT';
            $validated["konversi_{$i}"] = 1;
            $validated["harga_{$i}"] = 0;
        }
        $validated['minimum_stok'] = 0;
        $validated['barcode'] = null;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateProdukMasterReferences(array $validated): ?JsonResponse
    {
        $errors = ProdukRules::masterReferenceErrors(
            $validated['kategori_id'] ?? null,
            $validated['grup_id'] ?? null,
        );

        if ($errors) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    /**
     * Validate units and prices according to business rules.
     *
     * @return true|string True if valid, error message otherwise
     */
    private function validateUnitsAndPrices(array $data): bool|string
    {
        $konversi_1 = (int) $data['konversi_1'];
        $konversi_2 = (int) $data['konversi_2'];
        $konversi_3 = (int) $data['konversi_3'];
        $konversi_4 = (int) $data['konversi_4']; // Always 1

        // Validate konversi order: STRICTLY DECREASING (except when = 1)
        // Rule: konversi_1 > konversi_2 > konversi_3 >= 1
        // Exception: if konversi = 1, subsequent konversi can also be 1 (auto-lock)

        // Check konversi_1 vs konversi_2
        if ($konversi_1 < $konversi_2) {
            return 'Konversi Unit 1 harus lebih besar dari Konversi Unit 2';
        }
        if ($konversi_1 === $konversi_2 && $konversi_1 > 1) {
            return 'Konversi Unit 1 dan Unit 2 tidak boleh sama (kecuali = 1)';
        }

        // Check konversi_2 vs konversi_3
        if ($konversi_2 < $konversi_3) {
            return 'Konversi Unit 2 harus lebih besar dari Konversi Unit 3';
        }
        if ($konversi_2 === $konversi_3 && $konversi_2 > 1) {
            return 'Konversi Unit 2 dan Unit 3 tidak boleh sama (kecuali = 1)';
        }

        // Check konversi_3 vs konversi_4 (konversi_4 always = 1)
        if ($konversi_3 < $konversi_4) {
            return 'Konversi Unit 3 harus lebih besar atau sama dengan Konversi Unit 4';
        }

        // Price validation (MANUAL mode only)
        // Rule: Harga tidak boleh sama/lebih besar dari atasnya KECUALI locked (harus sama)
        $priceMode = SettingService::getPriceInputMode();

        // Normalize unit names for comparison
        $units = [
            1 => strtoupper(trim($data['unit_1'])),
            2 => strtoupper(trim($data['unit_2'])),
            3 => strtoupper(trim($data['unit_3'])),
            4 => strtoupper(trim($data['unit_4'])),
        ];

        // Determine which units are locked (auto-lock)
        $lockFrom = null;
        if ($konversi_1 === 1) {
            $lockFrom = 1;
        } elseif ($konversi_2 === 1) {
            $lockFrom = 2;
        } elseif ($konversi_3 === 1) {
            $lockFrom = 3;
        }

        // Get harga values
        $harga_1 = (float) $data['harga_1'];
        $harga_2 = (float) $data['harga_2'];
        $harga_3 = (float) $data['harga_3'];
        $harga_4 = (float) $data['harga_4'];

        // MANUAL mode: Validate price rules
        // Rule 1: Harga tidak boleh sama/lebih besar dari atasnya KECUALI locked (harus sama)
        // Rule 2: PPU (Price Per Unit) harus naik (beli eceran lebih mahal per unit)
        if ($priceMode === 'manual') {
            // Calculate PPU (Price Per Unit) = harga / konversi
            $ppu_1 = $konversi_1 > 0 ? $harga_1 / $konversi_1 : 0;
            $ppu_2 = $konversi_2 > 0 ? $harga_2 / $konversi_2 : 0;
            $ppu_3 = $konversi_3 > 0 ? $harga_3 / $konversi_3 : 0;
            $ppu_4 = $harga_4; // konversi_4 = 1, so ppu_4 = harga_4

            // Check harga_2 vs harga_1
            if ($harga_1 > 0 && $harga_2 > 0) {
                if ($lockFrom === 1) {
                    // Locked from unit 1: h2 must = h1
                    if (abs($harga_2 - $harga_1) > 0.01) {
                        return 'Harga Unit 2 harus sama dengan Harga Unit 1 (locked)';
                    }
                } else {
                    // Not locked: h2 must be < h1 (harga turun)
                    if ($harga_2 >= $harga_1) {
                        $formatted = SettingService::formatCurrency($harga_1);
                        return "Harga Unit 2 harus lebih kecil dari Harga Unit 1 (< {$formatted})";
                    }
                    // Also check PPU ascending (ppu2 >= ppu1)
                    if ($ppu_2 < $ppu_1) {
                        $ppuFormatted1 = SettingService::formatCurrency(round($ppu_1));
                        $ppuFormatted2 = SettingService::formatCurrency(round($ppu_2));
                        return "PPU Unit 2 terlalu murah ({$ppuFormatted2}/unit < {$ppuFormatted1}/unit)";
                    }
                }
            }

            // Check harga_3 vs harga_2
            if ($harga_2 > 0 && $harga_3 > 0) {
                if ($lockFrom !== null && $lockFrom <= 2) {
                    // Locked from unit 1 or 2: h3 must = lock source
                    $lockSourceHarga = $lockFrom === 1 ? $harga_1 : $harga_2;
                    if (abs($harga_3 - $lockSourceHarga) > 0.01) {
                        return "Harga Unit 3 harus sama dengan Harga Unit {$lockFrom} (locked)";
                    }
                } else {
                    // Not locked: h3 must be < h2 (harga turun)
                    if ($harga_3 >= $harga_2) {
                        $formatted = SettingService::formatCurrency($harga_2);
                        return "Harga Unit 3 harus lebih kecil dari Harga Unit 2 (< {$formatted})";
                    }
                    // Also check PPU ascending (ppu3 >= ppu2)
                    if ($ppu_3 < $ppu_2) {
                        $ppuFormatted2 = SettingService::formatCurrency(round($ppu_2));
                        $ppuFormatted3 = SettingService::formatCurrency(round($ppu_3));
                        return "PPU Unit 3 terlalu murah ({$ppuFormatted3}/unit < {$ppuFormatted2}/unit)";
                    }
                }
            }

            // Check harga_4 vs harga_3
            if ($harga_3 > 0 && $harga_4 > 0) {
                if ($lockFrom !== null && $lockFrom <= 3) {
                    // Locked: h4 must = lock source
                    $lockSourceHarga = $lockFrom === 1 ? $harga_1 : ($lockFrom === 2 ? $harga_2 : $harga_3);
                    if (abs($harga_4 - $lockSourceHarga) > 0.01) {
                        return "Harga Unit 4 harus sama dengan Harga Unit {$lockFrom} (locked)";
                    }
                } else {
                    // Not locked: h4 must be < h3 (harga turun)
                    if ($harga_4 >= $harga_3) {
                        $formatted = SettingService::formatCurrency($harga_3);
                        return "Harga Unit 4 harus lebih kecil dari Harga Unit 3 (< {$formatted})";
                    }
                    // Also check PPU ascending (ppu4 >= ppu3)
                    if ($ppu_4 < $ppu_3) {
                        $ppuFormatted3 = SettingService::formatCurrency(round($ppu_3));
                        $ppuFormatted4 = SettingService::formatCurrency(round($ppu_4));
                        return "PPU Unit 4 terlalu murah ({$ppuFormatted4}/unit < {$ppuFormatted3}/unit)";
                    }
                }
            }
        }

        // Validate auto-lock: locked units must have same name and konversi
        if ($lockFrom !== null) {
            $sourceUnit = $units[$lockFrom];

            for ($i = $lockFrom + 1; $i <= 4; $i++) {
                // Check unit name
                if ($units[$i] !== $sourceUnit) {
                    return "Unit {$i} harus sama dengan Unit {$lockFrom} ({$sourceUnit}) karena Konversi = 1";
                }
                // Check konversi
                $currentKonversi = (int) $data["konversi_{$i}"];
                if ($currentKonversi !== 1) {
                    return "Konversi Unit {$i} harus = 1 karena mengikuti Unit {$lockFrom}";
                }
            }
        }

        // Validate unique unit names (except for locked units)
        $checkedUnits = [];
        $unlockLimit = $lockFrom ?? 4;

        for ($i = 1; $i <= $unlockLimit; $i++) {
            $unitName = $units[$i];
            foreach ($checkedUnits as $prevIndex => $prevName) {
                if ($unitName === $prevName) {
                    return "Unit {$i} ({$unitName}) tidak boleh sama dengan Unit {$prevIndex} kecuali melalui mekanisme auto-lock (konversi = 1)";
                }
            }
            $checkedUnits[$i] = $unitName;
        }

        return true;
    }

    /**
     * Calculate prices based on harga_1 (AUTO mode).
     * harga_n = (harga_1 / konversi_1) * konversi_n
     */
    private function calculatePrices(array $data): array
    {
        $harga_1 = (float) $data['harga_1'];
        $konversi_1 = (int) $data['konversi_1'];

        if ($konversi_1 > 0) {
            $basePrice = $harga_1 / $konversi_1; // Price per smallest unit

            $data['harga_2'] = round($basePrice * (int) $data['konversi_2'], 2);
            $data['harga_3'] = round($basePrice * (int) $data['konversi_3'], 2);
            $data['harga_4'] = round($basePrice * 1, 2); // konversi_4 = 1
        }

        return $data;
    }
}
