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

        // Filter by serial flag (modul serial — dropdown produk serial di Input Pembelian Serial).
        // Saat Modul Elektronik OFF: paksa non-serial, abaikan filter is_serial=1 dari request.
        if (! SettingService::isElektronikEnabled()) {
            $query->where('is_serial', false);
        } elseif ($request->filled('is_serial')) {
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
            'gambar' => 'nullable|string|max:500',
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
            $validationResult = ProdukRules::validateUnitsAndPrices($validated);
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
            $validated = ProdukRules::calculateAutoPrices($validated);
        }

        // Gambar: path/URL dari ImageUpload (/uploads → WebP), bukan multipart file
        if (array_key_exists('gambar', $validated)) {
            $normalized = $this->normalizeProdukGambarPath($validated['gambar'], $uploadService);
            if ($normalized instanceof JsonResponse) {
                return $normalized;
            }
            $validated['gambar'] = $normalized;
        }

        // Create produk
        $produk = MasterProduk::create($validated);

        // Load relations for response
        $produk->load(['brand:id,ulid,nama_brand', 'tipe:id,ulid,nama_tipe', 'kategori:id,ulid,nama_kategori', 'grup:id,ulid,nama_grup']);
        $produk->makeVisible(['id']);
        if (! auth()->user()->can('stok.view_hpp')) {
            $produk->makeHidden(['avg_cost']);
        }

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
        $canViewHpp = auth()->user()->can('stok.view_hpp');
        $warehouseStocks = $allWarehouses->map(function ($warehouse) use ($existingStocks, $produk, $canViewHpp) {
            $stock = $existingStocks[$warehouse->id] ?? null;

            $row = [
                'warehouse_id' => $warehouse->id,
                'warehouse' => [
                    'id' => $warehouse->id,
                    'ulid' => $warehouse->ulid,
                    'kode_warehouse' => $warehouse->kode_warehouse,
                    'nama_warehouse' => $warehouse->nama_warehouse,
                    'status' => $warehouse->status,
                ],
                'qty' => $stock ? (int) $stock->qty : 0,
            ];
            if ($canViewHpp) {
                $row['avg_cost'] = $stock ? (float) $stock->avg_cost : (float) $produk->avg_cost;
            }

            return $row;
        });

        // Replace inventoryStocks with complete warehouse stocks
        $produk->setRelation('inventoryStocks', collect());
        if (! $canViewHpp) {
            $produk->makeHidden(['avg_cost']);
        }
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
            'gambar' => 'nullable|string|max:500',
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
            $validationResult = ProdukRules::validateUnitsAndPrices($validated);
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
            $validated = ProdukRules::calculateAutoPrices($validated);
        }

        // Gambar: path/URL dari ImageUpload; hapus file lama jika diganti/dikosongkan
        if (array_key_exists('gambar', $validated)) {
            $normalized = $this->normalizeProdukGambarPath($validated['gambar'], $uploadService);
            if ($normalized instanceof JsonResponse) {
                return $normalized;
            }
            if ($produk->gambar && $produk->gambar !== $normalized) {
                try {
                    $uploadService->deleteFile($produk->gambar);
                } catch (\Throwable) {
                    Storage::disk('public')->delete($produk->gambar);
                }
            }
            $validated['gambar'] = $normalized;
        }

        // Update produk
        $produk->update($validated);

        // Load relations for response
        $produk->load(['brand:id,ulid,nama_brand', 'tipe:id,ulid,nama_tipe', 'kategori:id,ulid,nama_kategori', 'grup:id,ulid,nama_grup']);
        if (! auth()->user()->can('stok.view_hpp')) {
            $produk->makeHidden(['avg_cost']);
        }

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
    public function deleteImage(string $ulid, UploadService $uploadService): JsonResponse
    {
        if (!auth()->user()->can('produk.update')) {
            return $this->error('Unauthorized', 403);
        }

        $produk = MasterProduk::where('ulid', $ulid)->first();

        if (!$produk) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        if ($produk->gambar) {
            try {
                $uploadService->deleteFile($produk->gambar);
            } catch (\Throwable) {
                Storage::disk('public')->delete($produk->gambar);
            }
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

        // Filter produk serial / non-serial (mis. Register Unit Serial hanya butuh serial).
        // Saat Modul Elektronik OFF: paksa non-serial, abaikan filter is_serial=1 dari request.
        if (! SettingService::isElektronikEnabled()) {
            $query->where('is_serial', false);
        } elseif ($request->filled('is_serial')) {
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
     * Normalisasi gambar dari ImageUpload (URL atau path) → path relatif folder products.
     *
     * @return string|null|JsonResponse
     */
    private function normalizeProdukGambarPath(mixed $gambar, UploadService $uploadService): string|null|JsonResponse
    {
        if ($gambar === null || $gambar === '') {
            return null;
        }

        $path = $uploadService->toStoragePath((string) $gambar);
        if ($uploadService->extractFolderFromPath($path) !== 'products') {
            return $this->error('Path gambar produk tidak valid (folder harus products).', 422);
        }

        return $path;
    }
}
