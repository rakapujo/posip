<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CheckoutSalesAction;
use App\Actions\Sales\VoidSalesAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnits;
use App\Models\DocSales;
use App\Models\DocSalesPayment;
use App\Models\DocSalesReturn;
use App\Models\InventoryStock;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterCustomer;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosCashTransaction;
use App\Models\PosTerminalShift;
use App\Models\SerialUnit;
use App\Services\PromoService;
use App\Services\SalesCalculationService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends BaseApiController
{
    use AttachesSerialUnits;

    /**
     * Get active terminal for the current user.
     */
    public function activeTerminal(): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $userId = auth()->id();

        // Find terminal where user has an active shift
        $terminal = MasterPosTerminal::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'defaultCustomer:id,ulid,kode_customer,nama,jenis,tipe_customer_id,kategori_customer_id',
            'defaultCustomer.tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'defaultCustomer.kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai',
            'defaultMetodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran,metode',
            'allowedPaymentMethods:id,ulid,kode_pembayaran,nama_pembayaran,metode,jenis,logo,qr_code,biaya_tambahan_tipe,biaya_tambahan_nilai',
            'activeShift:id,ulid,terminal_id,user_id,started_at,is_locked,locked_at',
        ])
        ->where('active_user_id', $userId)
        ->first();

        if (!$terminal) {
            return $this->error('Anda tidak memiliki terminal aktif. Mulai shift terlebih dahulu.', 422);
        }

        // Make IDs visible for form mapping
        $terminal->makeVisible('id');
        if ($terminal->warehouse) $terminal->warehouse->makeVisible('id');
        if ($terminal->defaultCustomer) $terminal->defaultCustomer->makeVisible('id');
        if ($terminal->defaultMetodePembayaran) $terminal->defaultMetodePembayaran->makeVisible('id');
        if ($terminal->allowedPaymentMethods) {
            $terminal->allowedPaymentMethods->each->makeVisible('id');
        }
        if ($terminal->activeShift) {
            $terminal->activeShift->makeVisible('id');
        }

        // Get tax settings
        $taxSettings = SettingService::getSalesTaxSettings();

        return $this->success([
            'terminal' => $terminal,
            'tax_settings' => $taxSettings,
            'negative_stock_allowed' => SettingService::isNegativeStockAllowed(),
        ]);
    }

    /**
     * Get active promos for the current user's terminal + customer context.
     * Frontend calls this on shift start + periodically (5 min) + on customer change.
     *
     * Returns promos with their details, ready for frontend matching.
     */
    public function activePromos(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        // Terminal context (user's active terminal)
        $terminal = MasterPosTerminal::where('active_user_id', auth()->id())->first();
        $terminalId = $terminal?->id;

        // Optional customer context (frontend can pass customer_ulid)
        $customerTypeId = null;
        $customerCategoryId = null;
        if ($request->filled('customer_ulid')) {
            $customer = MasterCustomer::where('ulid', $request->customer_ulid)
                ->select('id', 'tipe_customer_id', 'kategori_customer_id')->first();
            $customerTypeId = $customer?->tipe_customer_id;
            $customerCategoryId = $customer?->kategori_customer_id;
        }

        $promos = PromoService::getActivePromos($terminalId, $customerTypeId, customerCategoryId: $customerCategoryId);

        // Shift liveness flag — frontend polls this every 5 min.
        // After admin force-close: active_user_id = null → $terminal = null → false.
        // Frontend shows blocking dialog when false, preventing zombie POS.
        $shiftActive = $terminal !== null && $terminal->activeShift()->exists();

        return $this->success([
            'promos' => $promos,
            'count' => $promos->count(),
            'discount_mode' => SettingService::getDiscountMode(),
            'shift_active' => $shiftActive,
        ]);
    }

    /**
     * Search products for POS (with stock in terminal warehouse).
     */
    public function products(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $warehouseId = $request->input('warehouse_id');
        $search = $request->input('search', '');

        if (!$warehouseId) {
            return $this->error('warehouse_id is required', 422);
        }

        $query = MasterProduk::active()
            ->select([
                'id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'gambar',
                'unit_1', 'unit_2', 'unit_3', 'unit_4',
                'konversi_1', 'konversi_2', 'konversi_3', 'konversi_4',
                'harga_1', 'harga_2', 'harga_3', 'harga_4',
                'avg_cost', 'is_serial',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  // Produk serial: cari juga lewat nomor seri unit (tersedia) agar
                  // ketik/scan SN memunculkan produk induknya di grid.
                  ->orWhereHas('serialUnits', function ($s) use ($search) {
                      $s->where('status', 'tersedia')->where(function ($q) use ($search) {
                          $q->where('serial_number', 'like', "%{$search}%")
                            ->orWhere('kode_internal', 'like', "%{$search}%");
                      });
                  });
            });
        }

        $query->orderByRaw("(SELECT COALESCE(qty, 0) FROM inventory_stock WHERE product_id = master_produk.id AND warehouse_id = ?) > 0 DESC", [$warehouseId])
            ->orderBy('nama_produk')
            ->limit(50);

        $products = $query->get()->makeVisible('id');

        // Attach stock info per product
        $productIds = $products->pluck('id')->toArray();
        $stocks = InventoryStock::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->pluck('qty', 'product_id');

        $products->each(function ($product) use ($stocks) {
            $product->stok = $stocks[$product->id] ?? 0;
        });

        return $this->success([
            'products' => $products,
        ]);
    }

    /**
     * Search product by barcode (exact match for scanning).
     */
    public function productByBarcode(string $barcode, Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $warehouseId = $request->input('warehouse_id');

        $product = MasterProduk::active()
            ->where('barcode', $barcode)
            ->select([
                'id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'gambar',
                'unit_1', 'unit_2', 'unit_3', 'unit_4',
                'konversi_1', 'konversi_2', 'konversi_3', 'konversi_4',
                'harga_1', 'harga_2', 'harga_3', 'harga_4',
                'avg_cost', 'is_serial',
            ])
            ->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $product->makeVisible('id');

        // Attach stock
        $stock = InventoryStock::where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)
            ->value('qty');
        $product->stok = $stock ?? 0;

        return $this->success([
            'product' => $product,
        ]);
    }

    /**
     * Calculate totals (preview before checkout).
     */
    public function calculate(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'subtotal' => 'required|numeric|min:0',
            'discounts' => 'nullable|array|max:3',
            'discounts.*.tipe' => 'required|in:percent,nominal,none',
            'discounts.*.nilai' => 'nullable|numeric|min:0|max:9999999',
            'biaya_kirim' => 'nullable|array',
            'biaya_kirim.tipe' => 'nullable|in:percent,nominal,none',
            'biaya_kirim.nilai' => 'nullable|numeric|min:0|max:9999999',
            'biaya_lain' => 'nullable|array',
            'biaya_lain.tipe' => 'nullable|in:percent,nominal,none',
            'biaya_lain.nilai' => 'nullable|numeric|min:0|max:9999999',
            'payments' => 'nullable|array',
            'payments.*.biaya_tambahan' => 'nullable|numeric|min:0|max:9999999',
        ]);

        $totals = SalesCalculationService::calculateTotals(
            $validated['subtotal'],
            $validated['discounts'] ?? [],
            $validated['biaya_kirim'] ?? [],
            $validated['biaya_lain'] ?? [],
            $validated['payments'] ?? []
        );

        return $this->success($totals);
    }

    /**
     * Process checkout (create sales transaction).
     */
    public function checkout(Request $request, CheckoutSalesAction $action): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'terminal_id' => 'required|exists:master_pos_terminal,id',
            'shift_id' => 'required|exists:pos_terminal_shifts,id',
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'customer_id' => 'required|exists:master_customer,id',
            'discounts' => 'nullable|array|max:3',
            'discounts.*.tipe' => 'required|in:percent,nominal,none',
            'discounts.*.nilai' => 'nullable|numeric|min:0|max:9999999',
            // Per-slot override flags — when true, kasir explicitly chose to skip
            // auto-derive from customer tipe/kategori. Backend respects this instead
            // of forcing anti-fraud override in buildNotaDiscounts().
            'nota_discount_overrides' => 'nullable|array|size:3',
            'nota_discount_overrides.*' => 'boolean',
            'biaya_kirim' => 'nullable|array',
            'biaya_kirim.tipe' => 'nullable|in:percent,nominal,none',
            'biaya_kirim.nilai' => 'nullable|numeric|min:0|max:9999999',
            'biaya_lain' => 'nullable|array',
            'biaya_lain.tipe' => 'nullable|in:percent,nominal,none',
            'biaya_lain.nilai' => 'nullable|numeric|min:0|max:9999999',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:master_produk,id',
            'items.*.unit' => 'required|string',
            'items.*.konversi' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.qty_base' => 'required|numeric|min:0.01',
            'items.*.harga_satuan' => 'required|numeric|min:1|max:9999999999999', // ~10 triliun (batas kolom decimal(15,2))
            'items.*.diskon_1_tipe' => 'nullable|in:percent,nominal,none',
            'items.*.diskon_1_nilai' => 'nullable|numeric|min:0|max:9999999',
            'items.*.diskon_2_tipe' => 'nullable|in:percent,nominal,none',
            'items.*.diskon_2_nilai' => 'nullable|numeric|min:0|max:9999999',
            'items.*.diskon_3_tipe' => 'nullable|in:percent,nominal,none',
            'items.*.diskon_3_nilai' => 'nullable|numeric|min:0|max:9999999',
            'items.*.diskon_4_tipe' => 'nullable|in:percent,nominal,none',
            'items.*.diskon_4_nilai' => 'nullable|numeric|min:0|max:9999999',
            'items.*.diskon_5_tipe' => 'nullable|in:percent,nominal,none',
            'items.*.diskon_5_nilai' => 'nullable|numeric|min:0|max:9999999',
            'items.*.diskon_total' => 'nullable|numeric|min:0',
            'items.*.jumlah' => 'required|numeric|min:0',
            // When true, skip re-running PromoService for this item — keep whatever
            // diskon_1-4 values the frontend sent (typically all 'none' after kasir clear)
            'items.*.override_promo' => 'nullable|boolean',
            // Produk serial: ulid unit (SN) yang dijual. qty_base = jumlah unit.
            'items.*.serial_unit_ids' => 'nullable|array',
            'items.*.serial_unit_ids.*' => 'string',
            'payments' => 'required|array|min:1',
            'payments.*.metode_pembayaran_id' => 'required|exists:master_metode_pembayaran,id',
            'payments.*.nominal' => 'required|numeric|min:0',
            'payments.*.biaya_tambahan' => 'nullable|numeric|min:0|max:9999999',
            'payments.*.reference' => 'nullable|string|max:100',
        ]);

        // Verify shift is active and belongs to current user
        $shift = PosTerminalShift::find($validated['shift_id']);
        if (!$shift || !$shift->isActive()) {
            return $this->error('Shift tidak aktif', 422);
        }
        if ($shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }

        // Verify terminal is still assigned to current user
        $terminal = MasterPosTerminal::find($validated['terminal_id']);
        if (!$terminal || $terminal->active_user_id !== auth()->id()) {
            return $this->error('Terminal tidak lagi aktif untuk Anda. Silakan refresh halaman.', 422);
        }

        // Validate warehouse is active
        $warehouse = MasterWarehouse::find($validated['warehouse_id']);
        if (!$warehouse || !$warehouse->isActive()) {
            return $this->error('Warehouse tidak aktif. Silakan hubungi admin.', 422);
        }

        // Validate customer is active (walk-in customers are always valid)
        $customer = MasterCustomer::find($validated['customer_id']);
        if (!$customer || (!$customer->isActive() && !$customer->isWalkIn())) {
            return $this->error('Customer tidak aktif. Silakan pilih customer lain.', 422);
        }

        // Validate all products are active
        $productIds = array_unique(array_column($validated['items'], 'product_id'));
        $inactiveProducts = MasterProduk::whereIn('id', $productIds)
            ->where('status', '!=', 'active')
            ->pluck('nama_produk');
        if ($inactiveProducts->isNotEmpty()) {
            return $this->error('Produk tidak aktif: ' . $inactiveProducts->implode(', '), 422);
        }

        // Guard serial: produk serial WAJIB pilih nomor seri (SN) — tak boleh dijual sebagai
        // produk biasa (cegah divergensi register vs stok). Validasi rinci (milik produk, gudang,
        // status, jumlah) dilakukan di CheckoutSalesAction::resolveSelectedUnits.
        $serialProductIds = MasterProduk::whereIn('id', $productIds)
            ->where('is_serial', true)
            ->pluck('nama_produk', 'id');
        if ($serialProductIds->isNotEmpty()) {
            foreach ($validated['items'] as $item) {
                if ($serialProductIds->has($item['product_id']) && empty($item['serial_unit_ids'])) {
                    return $this->error(
                        "Produk serial '{$serialProductIds[$item['product_id']]}' wajib memilih nomor seri (SN) unit yang dijual.",
                        422
                    );
                }
            }
        }

        // Validate all payment methods are active
        $paymentMethodIds = array_unique(array_column($validated['payments'], 'metode_pembayaran_id'));
        $inactiveMethods = MasterMetodePembayaran::whereIn('id', $paymentMethodIds)
            ->where('status', '!=', 'active')
            ->pluck('nama_pembayaran');
        if ($inactiveMethods->isNotEmpty()) {
            return $this->error('Metode pembayaran tidak aktif: ' . $inactiveMethods->implode(', '), 422);
        }

        $sales = $action->execute($validated);

        return $this->success([
            'sales' => $sales,
        ], 'Transaksi berhasil', 201);
    }

    /**
     * Get sales history for current shift.
     */
    public function history(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $shiftId = $request->input('shift_id');
        if (!$shiftId) {
            return $this->error('shift_id is required', 422);
        }

        // Verify shift belongs to current user
        $shift = PosTerminalShift::find($shiftId);
        if (!$shift || $shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }

        $query = DocSales::with([
            'customer:id,ulid,kode_customer,nama',
        ])
        ->byShift($shiftId)
        ->orderByDesc('tanggal');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $perPage = $this->getPerPage($request, 20);
        $paginated = $query->paginate($perPage);

        // Summary dari semua data shift (bukan hanya halaman saat ini)
        $summaryBase = DocSales::byShift($shiftId);
        $completedCount = (clone $summaryBase)->completed()->count();
        $completedOmzet = (clone $summaryBase)->completed()->sum('grand_total');
        $voidedCount = (clone $summaryBase)->voided()->count();
        $voidedNominal = (clone $summaryBase)->voided()->sum('grand_total');

        return $this->success([
            'sales' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'summary' => [
                'total_transaksi' => $completedCount,
                'omzet' => (float) $completedOmzet,
                'total_void' => $voidedCount,
                'nominal_void' => (float) $voidedNominal,
            ],
        ]);
    }

    /**
     * Get sales detail (for receipt reprint).
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $sales = DocSales::with([
            'details.product:id,ulid,kode_produk,nama_produk',
            'payments.metodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran',
            'customer:id,ulid,kode_customer,nama,telepon',
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'createdBy:id,name',
            'voidedBy:id,name',
            'returns.details.product:id,ulid,kode_produk,nama_produk',
            'returns.createdBy:id,name',
        ])->where('ulid', $ulid)->first();

        if (!$sales) {
            return $this->error('Transaksi tidak ditemukan', 404);
        }

        $this->attachSerialUnitsToSale($sales);

        return $this->success([
            'sales' => $sales,
        ]);
    }

    /**
     * Public receipt (no auth required).
     */
    public function publicReceipt(string $ulid): JsonResponse
    {
        $sales = DocSales::with([
            'details.product:id,ulid,kode_produk,nama_produk',
            'payments.metodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran',
            'customer:id,ulid,kode_customer,nama',
            'voidedBy:id,name',
            'returns.details.product:id,ulid,kode_produk,nama_produk',
            'returns.createdBy:id,name',
        ])->where('ulid', $ulid)->first();

        if (!$sales) {
            return $this->error('Struk tidak ditemukan', 404);
        }

        $this->attachSerialUnitsToSale($sales);

        // Calculate return status
        $totalBuyBase = $sales->details->sum('qty_base');
        $totalReturnBase = $sales->returns->flatMap->details->sum('qty_base');

        $returnStatus = 'none';
        if ($sales->status === 'voided') {
            $returnStatus = 'voided';
        } elseif ($totalReturnBase > 0 && $totalReturnBase >= $totalBuyBase) {
            $returnStatus = 'retur_full';
        } elseif ($totalReturnBase > 0) {
            $returnStatus = 'retur_partial';
        } else {
            $returnStatus = 'completed';
        }

        $storeInfo = SettingService::getStoreInfo();

        $terminal = MasterPosTerminal::find($sales->terminal_id);
        $returPolicy = [
            'izinkan_retur' => $terminal ? (bool) $terminal->izinkan_retur : false,
            'durasi_retur' => $terminal ? $terminal->durasi_retur : null,
        ];

        return $this->success([
            'sales' => $sales,
            'store' => $storeInfo,
            'receipt_status' => $returnStatus,
            'retur_policy' => $returPolicy,
        ]);
    }

    /**
     * Void a sales transaction.
     */
    public function void(string $ulid, Request $request, VoidSalesAction $action): JsonResponse
    {
        if (!auth()->user()->can('pos.void')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $sales = DocSales::with('shift:id,user_id')->where('ulid', $ulid)->first();

        if (!$sales) {
            return $this->error('Transaksi tidak ditemukan', 404);
        }

        // Verify sales belongs to current user's shift
        if ($sales->shift && $sales->shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses untuk void transaksi ini', 403);
        }

        $sales = $action->execute($sales, $validated['reason']);

        return $this->success([
            'sales' => $sales,
        ], 'Transaksi berhasil di-void');
    }

    /**
     * Get shift report data.
     */
    public function shiftReport(string $shiftUlid): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $shift = PosTerminalShift::with([
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'user:id,ulid,name',
            'forcedByUser:id,ulid,name',
        ])->where('ulid', $shiftUlid)->first();

        if (!$shift) {
            return $this->error('Shift tidak ditemukan', 404);
        }

        // Access: kasir pemilik shift, ATAU admin dengan permission terminal.force-release
        // (kasus admin mau preview shift orang lain untuk force close)
        if ($shift->user_id !== auth()->id() && !auth()->user()->can('terminal.force-release')) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }

        $shiftId = $shift->id;

        // Sales summary
        $allSales = DocSales::byShift($shiftId)->with(['payments', 'details.product:id,ulid,nama_produk'])->get();
        $completedSales = $allSales->where('status', 'completed');
        $voidedSales = $allSales->where('status', 'voided');

        // Penjualan kotor & diskon item breakdown (from details)
        $penjualanKotor = 0.0;
        $diskonLine = [0.0, 0.0, 0.0, 0.0, 0.0];
        $diskonItemTotal = 0.0;
        foreach ($completedSales as $sale) {
            foreach ($sale->details as $d) {
                $bruto = (float) $d->qty * (float) $d->harga_satuan;
                $penjualanKotor += $bruto;
                $diskonItemTotal += (float) $d->diskon_total;
                for ($i = 1; $i <= 5; $i++) {
                    $diskonLine[$i - 1] += (float) $d->{"diskon_{$i}_hasil"};
                }
            }
        }

        // Payment breakdown (only completed)
        $completedIds = $completedSales->pluck('id')->toArray();
        $paymentBreakdown = DocSalesPayment::whereIn('sales_id', $completedIds)
            ->with('metodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran,metode')
            ->get()
            ->groupBy('metode_pembayaran_id')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'nama' => $first->metodePembayaran?->nama_pembayaran ?? 'Unknown',
                    'is_tunai' => $first->metodePembayaran?->metode === 'tunai',
                    'total' => (float) $group->sum('nominal'),
                    'biaya_tambahan' => (float) $group->sum('biaya_tambahan'),
                    'count' => $group->count(),
                ];
            })
            ->values();

        // Kembalian & total diterima
        $totalDiterima = (float) $completedSales->sum('total_bayar');
        $totalKembalian = (float) $completedSales->sum('kembalian');

        // Returns summary
        $returns = DocSalesReturn::byShift($shiftId)->with('sales:id,shift_id')->get();
        $returSesiIni = $returns->filter(fn($r) => $r->sales && $r->sales->shift_id == $shiftId);
        $returSesiSebelumnya = $returns->filter(fn($r) => $r->sales && $r->sales->shift_id != $shiftId);

        // Cash transactions
        $cashTx = PosCashTransaction::byShift($shiftId)->get();
        $setorAwal = (float) $cashTx->where('tipe', 'setor_awal')->sum('nominal');

        $kasMasukItems = $cashTx->where('tipe', 'kas_masuk')->values();
        $kasMasuk = (float) $kasMasukItems->sum('nominal');

        // Separate manual kas_keluar from auto-created refund entries
        $kasKeluarAll = $cashTx->where('tipe', 'kas_keluar');
        $refundTunai = (float) $kasKeluarAll->filter(function ($tx) {
            return str_starts_with($tx->keterangan ?? '', 'Refund retur');
        })->sum('nominal');
        $kasKeluarItems = $kasKeluarAll->filter(function ($tx) {
            return !str_starts_with($tx->keterangan ?? '', 'Refund retur');
        })->values();
        $kasKeluarManual = (float) $kasKeluarItems->sum('nominal');

        // Penjualan tunai (NET = cash received - kembalian)
        $tunaiMethodIds = MasterMetodePembayaran::where('metode', 'tunai')->pluck('id')->toArray();
        $penjualanTunaiNet = 0.0;
        foreach ($completedSales as $sale) {
            $cashReceived = (float) $sale->payments->whereIn('metode_pembayaran_id', $tunaiMethodIds)->sum('nominal');
            $kembalian = (float) ($sale->kembalian ?? 0);
            $penjualanTunaiNet += ($cashReceived - $kembalian);
        }

        // Penjualan non-tunai
        $penjualanNonTunai = $paymentBreakdown
            ->filter(fn ($item) => $item['is_tunai'] === false)
            ->sum('total');

        // Saldo Kas
        $saldoKas = $setorAwal + $penjualanTunaiNet + $kasMasuk - $kasKeluarManual - $refundTunai;

        // Tax info from first completed sale (all same shift = same tax settings)
        $firstSale = $completedSales->first();
        $pajakNama = $firstSale->pajak_nama ?? '';
        $pajakPersen = (float) ($firstSale->pajak_persen ?? 0);

        // Unit serial terjual sesi ini (untuk laporan closing) — daftar flat
        $serialUlids = $completedSales->flatMap(fn ($s) => $s->details)
            ->flatMap(fn ($d) => $d->serial_unit_ids ?? [])->filter()->unique()->values();
        $serialMap = $serialUlids->isEmpty() ? collect()
            : SerialUnit::whereIn('ulid', $serialUlids)
                ->get(['ulid', 'kode_internal', 'serial_number', 'grade', 'battery_condition', 'battery_health', 'account_status'])
                ->keyBy('ulid');
        $serialUnitsSold = [];
        foreach ($completedSales as $sale) {
            foreach ($sale->details as $d) {
                foreach ($d->serial_unit_ids ?? [] as $u) {
                    $unit = $serialMap->get($u);
                    if (!$unit) {
                        continue;
                    }
                    $serialUnitsSold[] = [
                        'nomor_dokumen' => $sale->nomor_dokumen,
                        'product' => $d->product?->nama_produk,
                        'kode_internal' => $unit->kode_internal,
                        'serial_number' => $unit->serial_number,
                        'grade' => $unit->grade,
                        'battery_health' => $unit->battery_health,
                        'account_status' => $unit->account_status,
                        'harga' => (float) $d->harga_satuan,
                    ];
                }
            }
        }

        return $this->success([
            'serial_units_sold' => $serialUnitsSold,
            'shift' => $shift,
            'penjualan' => [
                'jumlah_transaksi' => $completedSales->count(),
                'penjualan_kotor' => $penjualanKotor,
                'diskon_item' => $diskonItemTotal,
                'diskon_line_1' => $diskonLine[0],
                'diskon_line_2' => $diskonLine[1],
                'diskon_line_3' => $diskonLine[2],
                'diskon_line_4' => $diskonLine[3],
                'diskon_line_5' => $diskonLine[4],
                'subtotal' => (float) $completedSales->sum('subtotal'),
                'diskon_nota' => (float) $completedSales->sum('total_diskon'),
                'diskon_nota_l1' => (float) $completedSales->sum('diskon_nota_1_hasil'),
                'diskon_nota_l2' => (float) $completedSales->sum('diskon_nota_2_hasil'),
                'diskon_nota_l3' => (float) $completedSales->sum('diskon_nota_3_hasil'),
                'penjualan_bersih' => (float) $completedSales->sum('total_setelah_diskon'),
                'biaya_kirim' => (float) $completedSales->sum('biaya_kirim_hasil'),
                'biaya_lain' => (float) $completedSales->sum('biaya_lain_hasil'),
                'pajak_nama' => $pajakNama,
                'pajak_persen' => $pajakPersen,
                'pajak_nominal' => (float) $completedSales->sum('pajak_nominal'),
                'pembulatan' => (float) $completedSales->sum('pembulatan'),
                'omzet' => (float) $completedSales->sum('grand_total'),
            ],
            'payment_breakdown' => $paymentBreakdown,
            'total_diterima' => $totalDiterima,
            'total_kembalian' => $totalKembalian,
            'void' => [
                'jumlah' => $voidedSales->count(),
                'nominal' => (float) $voidedSales->sum('grand_total'),
            ],
            'retur' => [
                'jumlah' => $returns->count(),
                'total_refund' => (float) $returns->sum('grand_total'),
                'sesi_ini' => [
                    'jumlah' => $returSesiIni->count(),
                    'nominal' => (float) $returSesiIni->sum('grand_total'),
                ],
                'sesi_sebelumnya' => [
                    'jumlah' => $returSesiSebelumnya->count(),
                    'nominal' => (float) $returSesiSebelumnya->sum('grand_total'),
                ],
            ],
            'kas' => [
                'setor_awal' => $setorAwal,
                'penjualan_tunai' => $penjualanTunaiNet,
                'penjualan_non_tunai' => $penjualanNonTunai,
                'kas_masuk' => $kasMasuk,
                'kas_masuk_detail' => $kasMasukItems->map(fn ($tx) => [
                    'keterangan' => $tx->keterangan,
                    'nominal' => (float) $tx->nominal,
                ])->values(),
                'kas_keluar' => $kasKeluarManual,
                'kas_keluar_detail' => $kasKeluarItems->map(fn ($tx) => [
                    'keterangan' => $tx->keterangan,
                    'nominal' => (float) $tx->nominal,
                ])->values(),
                'refund_tunai' => $refundTunai,
                'saldo' => $saldoKas,
            ],
            'ringkasan' => [
                'total_tunai' => $saldoKas,
                'total_non_tunai' => $penjualanNonTunai,
                'total_semua' => $saldoKas + $penjualanNonTunai,
            ],
        ]);
    }

    /**
     * Lock the current shift (screen lock).
     */
    public function lockShift(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $shiftId = $request->input('shift_id');
        if (!$shiftId) {
            return $this->error('shift_id is required', 422);
        }

        $shift = PosTerminalShift::find($shiftId);
        if (!$shift) {
            return $this->error('Shift tidak ditemukan', 404);
        }

        // Verify user owns this shift
        if ($shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }

        if (!$shift->isActive()) {
            return $this->error('Shift sudah berakhir', 422);
        }

        $shift->lock();

        return $this->success([
            'is_locked' => true,
            'locked_at' => $shift->locked_at,
        ], 'Layar berhasil dikunci');
    }

    /**
     * Unlock the current shift (screen unlock).
     * Accepts either PIN or password for authentication.
     */
    public function unlockShift(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'shift_id' => 'required|exists:pos_terminal_shifts,id',
            'credential' => 'required|string',
        ]);

        $shift = PosTerminalShift::find($validated['shift_id']);
        if (!$shift) {
            return $this->error('Shift tidak ditemukan', 404);
        }

        // Verify user owns this shift
        if ($shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }

        if (!$shift->isActive()) {
            return $this->error('Shift sudah berakhir', 422);
        }

        if (!$shift->isLocked()) {
            return $this->success([
                'is_locked' => false,
            ], 'Layar tidak dalam keadaan terkunci');
        }

        // Verify credential (PIN or password)
        $user = auth()->user();
        $credential = $validated['credential'];
        $isValid = false;

        // Try PIN first (if user has PIN set)
        if ($user->hasPin() && $user->checkPin($credential)) {
            $isValid = true;
        }

        // Try password if PIN didn't match
        if (!$isValid && password_verify($credential, $user->password)) {
            $isValid = true;
        }

        if (!$isValid) {
            return $this->error('PIN atau password salah', 422);
        }

        $shift->unlock();

        return $this->success([
            'is_locked' => false,
        ], 'Layar berhasil dibuka');
    }
}
