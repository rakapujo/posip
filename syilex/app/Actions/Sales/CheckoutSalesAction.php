<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Constants\PromoConstants;
use App\Models\DocSales;
use App\Models\DocSalesDetail;
use App\Models\DocSalesPayment;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\PosTerminalShift;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Services\PosCheckoutRules;
use App\Services\PromoService;
use App\Services\SalesCalculationService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutSalesAction
{
    use RequiresAuthenticatedUser;
    use ResolvesSelectedUnits;

    /**
     * Execute the checkout.
     *
     * @param array $data Validated checkout data
     * @return DocSales
     */
    public function execute(array $data): DocSales
    {
        // Defense-in-depth: walaupun PosController sudah check `pos.checkout` permission,
        // kita pastikan user authenticated untuk audit trail (created_by, dll).
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            PosCheckoutRules::assertCheckoutMastersValid($data);

            $terminalId = $data['terminal_id'];
            $shiftId = $data['shift_id'];
            $warehouseId = $data['warehouse_id'];
            $customerId = $data['customer_id'];
            $items = $data['items'];
            $payments = $data['payments'];

            // Re-lock shift row untuk prevent race: admin force-release antara controller
            // cek isActive() dan commit di sini. Tanpa lock, sale bisa ter-commit ke shift
            // yang sudah ditutup (silent data drift di laporan shift).
            $shift = PosTerminalShift::where('id', $shiftId)->lockForUpdate()->first();
            if (!$shift || $shift->ended_at !== null) {
                throw ValidationException::withMessages([
                    'shift' => ['Shift sudah ditutup. Silakan refresh halaman dan mulai shift baru.'],
                ]);
            }

            // Collect product IDs
            $productIds = array_column($items, 'product_id');

            // Lock inventory rows
            $stocks = InventoryStock::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock products
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Validate stock availability
            $this->validateStockAvailability($items, $stocks, $products);

            // Auto-apply promo (anti-fraud: rebuild diskon_1..4 dari DB, override FE)
            $discountMode = SettingService::getDiscountMode();
            $this->applyPromosToItems($items, $products, $customerId, $terminalId, $discountMode);

            // Pre-calculate line discounts to get accurate subtotal
            $processedItems = [];
            foreach ($items as $item) {
                $bruto = $item['qty'] * $item['harga_satuan'];
                $running = $bruto;
                $lineDiscData = [];
                $lineDiscTotal = 0;

                for ($d = 1; $d <= 5; $d++) {
                    $dTipe = $item["diskon_{$d}_tipe"] ?? 'none';
                    $dNilai = (float) ($item["diskon_{$d}_nilai"] ?? 0);
                    $base = $discountMode === 'recursive' ? $running : $bruto;
                    $dHasil = SalesCalculationService::calculateDiscountLevel($dTipe, $dNilai, $base);
                    $running -= $dHasil;
                    $lineDiscTotal += $dHasil;
                    $lineDiscData["diskon_{$d}_tipe"] = $dTipe;
                    $lineDiscData["diskon_{$d}_nilai"] = $dNilai;
                    $lineDiscData["diskon_{$d}_hasil"] = $dHasil;
                }

                $processedItems[] = array_merge($item, $lineDiscData, [
                    'diskon_total' => $lineDiscTotal,
                    'jumlah' => $bruto - $lineDiscTotal,
                    'promo_id' => $item['promo_id'] ?? null,
                ]);
            }

            $subtotal = array_sum(array_column($processedItems, 'jumlah'));

            // Build nota discounts with auto-customer discount. L1+L2 overridden from
            // DB UNLESS kasir explicitly set override flag (nota_discount_overrides[i]=true).
            $notaResult = $this->buildNotaDiscounts(
                $customerId,
                $data['discounts'] ?? [],
                $data['nota_discount_overrides'] ?? []
            );
            $discounts = $notaResult['discounts'];
            $discountLabels = $notaResult['labels'];

            $totals = SalesCalculationService::calculateTotals(
                $subtotal,
                $discounts,
                $data['biaya_kirim'] ?? [],
                $data['biaya_lain'] ?? [],
                $payments
            );

            // Calculate total payment and change
            $totalBayar = array_sum(array_column($payments, 'nominal'));
            $totalBiayaPembayaran = $totals['total_biaya_pembayaran'];

            if ($totalBayar < $totals['grand_total'] + $totalBiayaPembayaran) {
                throw ValidationException::withMessages([
                    'payments' => ['Total pembayaran kurang dari yang harus dibayar.'],
                ]);
            }

            $kembalian = max(0, $totalBayar - $totals['grand_total'] - $totalBiayaPembayaran);

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber('sales', 'doc_sales');

            // Create sales header
            $sales = DocSales::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal' => now(),
                'terminal_id' => $terminalId,
                'shift_id' => $shiftId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'subtotal' => $totals['subtotal'],
                'diskon_nota_1_tipe' => $totals['diskon_nota_1_tipe'],
                'diskon_nota_1_nilai' => $totals['diskon_nota_1_nilai'],
                'diskon_nota_1_hasil' => $totals['diskon_nota_1_hasil'],
                'diskon_nota_1_label' => $discountLabels[0],
                'diskon_nota_2_tipe' => $totals['diskon_nota_2_tipe'],
                'diskon_nota_2_nilai' => $totals['diskon_nota_2_nilai'],
                'diskon_nota_2_hasil' => $totals['diskon_nota_2_hasil'],
                'diskon_nota_2_label' => $discountLabels[1],
                'diskon_nota_3_tipe' => $totals['diskon_nota_3_tipe'],
                'diskon_nota_3_nilai' => $totals['diskon_nota_3_nilai'],
                'diskon_nota_3_hasil' => $totals['diskon_nota_3_hasil'],
                'diskon_nota_3_label' => $discountLabels[2],
                'total_diskon' => $totals['total_diskon'],
                'total_setelah_diskon' => $totals['total_setelah_diskon'],
                'biaya_kirim_tipe' => $totals['biaya_kirim_tipe'],
                'biaya_kirim_nilai' => $totals['biaya_kirim_nilai'],
                'biaya_kirim_hasil' => $totals['biaya_kirim_hasil'],
                'biaya_lain_tipe' => $totals['biaya_lain_tipe'],
                'biaya_lain_nilai' => $totals['biaya_lain_nilai'],
                'biaya_lain_hasil' => $totals['biaya_lain_hasil'],
                'dpp' => $totals['dpp'],
                'pajak_nama' => $totals['pajak_nama'],
                'pajak_persen' => $totals['pajak_persen'],
                'pajak_nominal' => $totals['pajak_nominal'],
                'pembulatan' => $totals['pembulatan'],
                'grand_total' => $totals['grand_total'],
                'total_bayar' => $totalBayar,
                'kembalian' => $kembalian,
                'total_biaya_pembayaran' => $totals['total_biaya_pembayaran'],
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Skip observer for stock updates
            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocks = [];
            foreach ($stocks as $productId => $stock) {
                $runningStocks[$productId] = (float) $stock->qty;
            }

            try {
                // Create details and update stock
                foreach ($processedItems as $item) {
                    $product = $products[$item['product_id']];
                    $currentStock = $runningStocks[$item['product_id']] ?? 0;
                    $isSerial = (bool) $product->is_serial;
                    $oldAvg = (float) $product->avg_cost;

                    // Produk serial: resolve & kunci unit terpilih (milik produk, di gudang POS,
                    // status tersedia, jumlah == qty_base). HPP = rata cost_per_unit unit yang dijual.
                    $serialUnits = null;
                    if ($isSerial) {
                        $serialUnits = $this->resolveSelectedUnits(
                            $item['serial_unit_ids'] ?? [],
                            (int) $item['product_id'],
                            (int) $warehouseId,
                            (int) round((float) $item['qty_base'])
                        );
                        $hpp = round((float) $serialUnits->sum(fn ($u) => (float) $u->cost_per_unit) / $serialUnits->count(), 4);
                    } else {
                        $hpp = $oldAvg;
                    }

                    // Create detail
                    $detail = DocSalesDetail::create([
                        'sales_id' => $sales->id,
                        'product_id' => $item['product_id'],
                        'unit' => $item['unit'],
                        'konversi' => $item['konversi'],
                        'qty' => $item['qty'],
                        'qty_base' => $item['qty_base'],
                        'harga_satuan' => $item['harga_satuan'],
                        'diskon_1_tipe' => $item['diskon_1_tipe'],
                        'diskon_1_nilai' => $item['diskon_1_nilai'],
                        'diskon_1_hasil' => $item['diskon_1_hasil'],
                        'diskon_2_tipe' => $item['diskon_2_tipe'],
                        'diskon_2_nilai' => $item['diskon_2_nilai'],
                        'diskon_2_hasil' => $item['diskon_2_hasil'],
                        'diskon_3_tipe' => $item['diskon_3_tipe'],
                        'diskon_3_nilai' => $item['diskon_3_nilai'],
                        'diskon_3_hasil' => $item['diskon_3_hasil'],
                        'diskon_4_tipe' => $item['diskon_4_tipe'],
                        'diskon_4_nilai' => $item['diskon_4_nilai'],
                        'diskon_4_hasil' => $item['diskon_4_hasil'],
                        'diskon_5_tipe' => $item['diskon_5_tipe'],
                        'diskon_5_nilai' => $item['diskon_5_nilai'],
                        'diskon_5_hasil' => $item['diskon_5_hasil'],
                        'diskon_total' => $item['diskon_total'],
                        'jumlah' => $item['jumlah'],
                        'promo_id' => $item['promo_id'] ?? null,
                        'hpp_at_time' => $hpp,
                        'serial_unit_ids' => $isSerial ? $serialUnits->pluck('ulid')->all() : null,
                    ]);

                    // Reduce stock (qty_base is always positive, subtract it)
                    $newStock = $currentStock - $item['qty_base'];

                    // Update running stock for next iteration of same product
                    $runningStocks[$item['product_id']] = $newStock;

                    // Produk serial: tandai unit terjual + catat movement + rekalkulasi avg (Metode A)
                    $avgAfter = $isSerial ? $oldAvg : $hpp;
                    if ($isSerial) {
                        foreach ($serialUnits as $unit) {
                            $unit->update([
                                'status' => SerialUnit::STATUS_TERJUAL,
                                'sale_id' => $sales->id,
                                'sale_detail_id' => $detail->id,
                                'sold_at' => $sales->tanggal,
                            ]);
                            SerialUnitMovement::record([
                                'serial_unit_id' => $unit->id,
                                'doc_type' => 'SALES',
                                'doc_id' => $sales->id,
                                'doc_no' => $nomorDokumen,
                                'movement_type' => 'OUT',
                                'from_warehouse_id' => $warehouseId,
                                'to_warehouse_id' => null,
                                'from_status' => SerialUnit::STATUS_TERSEDIA,
                                'to_status' => SerialUnit::STATUS_TERJUAL,
                                'tanggal' => $sales->tanggal,
                                'notes' => null,
                            ]);
                        }

                        // avg_cost agregat = rata cost_per_unit unit tersisa (0 bila habis)
                        $tersedia = SerialUnit::byProduct((int) $item['product_id'])->tersedia()->get(['cost_per_unit']);
                        $sisa = $tersedia->count();
                        $avgAfter = $sisa > 0 ? round((float) $tersedia->sum(fn ($u) => (float) $u->cost_per_unit) / $sisa, 4) : 0.0;
                        $product->avg_cost = $avgAfter;
                        $product->save();
                        $product->syncAvgCostToInventoryStocks();
                    }

                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'qty' => $newStock,
                            'avg_cost' => $avgAfter,
                        ]
                    );

                    // Record stock card — SALES (retail: HPP tetap; serial: avg bergeser via Metode A)
                    StockCard::record([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'transaction_type' => 'SALES',
                        'transaction_id' => $sales->id,
                        'transaction_no' => $nomorDokumen,
                        'tanggal' => $sales->tanggal,
                        'qty_in' => 0,
                        'qty_out' => $item['qty_base'],
                        'cost_per_unit' => $hpp,
                        'avg_cost_before' => $oldAvg,
                        'avg_cost_after' => $avgAfter,
                        'notes' => null,
                    ]);

                    // Retail: cek reset HPP bila stok global habis. Serial sudah ditangani Metode A.
                    if (!$isSerial) {
                        $product->checkAndResetHppIfStockEmpty(
                            $warehouseId,
                            $sales->id,
                            $nomorDokumen,
                            $sales->tanggal
                        );
                    }
                }
            } finally {
                StockCard::$skipObserver = false;
            }

            // Create payments
            foreach ($payments as $payment) {
                DocSalesPayment::create([
                    'sales_id' => $sales->id,
                    'metode_pembayaran_id' => $payment['metode_pembayaran_id'],
                    'nominal' => $payment['nominal'],
                    'biaya_tambahan' => $payment['biaya_tambahan'] ?? 0,
                    'reference' => $payment['reference'] ?? null,
                ]);
            }

            // Load relations for response
            $sales->load([
                'details.product:id,ulid,kode_produk,nama_produk',
                'payments.metodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran',
                'customer:id,ulid,kode_customer,nama',
                'terminal:id,ulid,kode_terminal,nama_terminal',
            ]);

            return $sales;
        });
    }

    /**
     * Build nota discounts array with customer auto-discount.
     *
     * Level 0 (disc nota 1): auto from customer tipe — ALWAYS overridden from DB (fraud-safe)
     * Level 1 (disc nota 2): auto from customer kategori — ALWAYS overridden from DB (fraud-safe)
     * Level 2 (disc nota 3): manual from kasir — validated against promo settings
     */

    /**
     * Validate stock availability untuk semua items.
     * Throw ValidationException kalau ada item yang stoknya kurang (dan negative stock tidak allow).
     *
     * @param array $items Item rows dari request
     * @param \Illuminate\Support\Collection $stocks InventoryStock keyed by product_id
     * @param \Illuminate\Support\Collection $products MasterProduk keyed by id
     */
    private function validateStockAvailability(array $items, $stocks, $products): void
    {
        $negativeStockAllowed = SettingService::isNegativeStockAllowed();
        $errors = [];

        foreach ($items as $item) {
            $currentStock = $stocks[$item['product_id']]->qty ?? 0;
            $qtyBase = $item['qty_base'];

            if ($currentStock < $qtyBase && !$negativeStockAllowed) {
                $product = $products[$item['product_id']];
                $errors[] = "Stok {$product->nama_produk} tidak mencukupi. Tersedia: {$currentStock}, Dibutuhkan: {$qtyBase}";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages(['stock' => $errors]);
        }
    }

    /**
     * Anti-fraud: rebuild diskon_1..DB_DISCOUNT_SLOTS dari DB promo, JANGAN trust FE.
     * Slot MANUAL_DISCOUNT_SLOT (5) TETAP dari FE (manual kasir, divalidasi di buildNotaDiscounts).
     *
     * Mutate $items by reference.
     *
     * @param array $items Items array (mutated: diskon_1..4 di-override, promo_id di-set)
     * @param \Illuminate\Support\Collection $products MasterProduk keyed by id
     * @param int|null $customerId
     * @param int $terminalId
     * @param string $discountMode 'recursive' atau 'sum'
     */
    private function applyPromosToItems(array &$items, $products, ?int $customerId, int $terminalId, string $discountMode): void
    {
        $customer = $customerId
            ? \App\Models\MasterCustomer::select('id', 'tipe_customer_id', 'kategori_customer_id')->find($customerId)
            : null;
        $customerTypeId = $customer?->tipe_customer_id;
        $customerCategoryId = $customer?->kategori_customer_id;
        $activePromos = PromoService::getActivePromos($terminalId, $customerTypeId, customerCategoryId: $customerCategoryId);

        foreach ($items as &$item) {
            // Respect explicit kasir override — skip auto-derive, keep frontend slots as-sent.
            // Kasir UI sets this flag when they click "Hapus Semua Diskon Item".
            if (!empty($item['override_promo'])) {
                // Still clear promo_id so reports don't mis-attribute the override
                $item['promo_id'] = null;
                continue;
            }

            $product = $products[$item['product_id']] ?? null;
            $promoResult = null;

            if ($product && $activePromos->isNotEmpty()) {
                $promoResult = PromoService::findBestPromo(
                    (int) $product->id,
                    $product->grup_id ? (int) $product->grup_id : null,
                    $product->kategori_id ? (int) $product->kategori_id : null,
                    (float) $item['qty'],
                    (float) $item['harga_satuan'],
                    $activePromos,
                    $discountMode
                );
            }

            // Override diskon_1..DB_DISCOUNT_SLOTS dari promo.
            for ($i = 1; $i <= PromoConstants::DB_DISCOUNT_SLOTS; $i++) {
                $item["diskon_{$i}_tipe"] = $promoResult["diskon_{$i}_tipe"] ?? 'none';
                $item["diskon_{$i}_nilai"] = $promoResult["diskon_{$i}_nilai"] ?? 0;
            }
            $item['promo_id'] = $promoResult['promo_id'] ?? null;
        }
        unset($item);
    }

    private function buildNotaDiscounts(int $customerId, array $frontendDiscounts, array $overrides = []): array
    {
        $none = ['tipe' => 'none', 'nilai' => 0];
        $discounts = [$none, $none, $none];
        $labels = [null, null, null];

        // Normalize overrides to 3-bool array
        $overrides = array_pad(array_map('boolval', $overrides), 3, false);

        // Load customer with relations (fresh from DB, not from frontend)
        $customer = \App\Models\MasterCustomer::with(['tipeCustomer', 'kategoriCustomer'])
            ->find($customerId);

        if ($customer) {
            // Level 0: tipe customer discount.
            // Default: anti-fraud override (force customer's tipe discount).
            // If overrides[0] === true, kasir explicitly cleared this slot — respect
            // frontend value (expected to be none/0) and keep slot empty.
            $tipe = $customer->tipeCustomer;
            if (!$overrides[0] && $tipe && $tipe->diskon_tipe !== 'none' && (float) $tipe->diskon_nilai > 0) {
                $discounts[0] = ['tipe' => $tipe->diskon_tipe, 'nilai' => (float) $tipe->diskon_nilai];
                $labels[0] = $tipe->diskon_tipe === 'percent'
                    ? "{$tipe->kode_tipe} {$tipe->diskon_nilai}%"
                    : "{$tipe->kode_tipe} Rp " . number_format((float) $tipe->diskon_nilai, 0, ',', '.');
            }

            // Level 1: kategori customer discount — same override rule.
            $kat = $customer->kategoriCustomer;
            if (!$overrides[1] && $kat && $kat->diskon_tipe !== 'none' && (float) $kat->diskon_nilai > 0) {
                $discounts[1] = ['tipe' => $kat->diskon_tipe, 'nilai' => (float) $kat->diskon_nilai];
                $labels[1] = $kat->diskon_tipe === 'percent'
                    ? "{$kat->kode_kategori} {$kat->diskon_nilai}%"
                    : "{$kat->kode_kategori} Rp " . number_format((float) $kat->diskon_nilai, 0, ',', '.');
            }
        }

        // Level 2: manual kasir — validate against promo settings
        $manualDisc = $frontendDiscounts[2] ?? $none;
        $manualTipe = $manualDisc['tipe'] ?? 'none';
        $manualNilai = (float) ($manualDisc['nilai'] ?? 0);

        if ($manualTipe !== 'none' && $manualNilai > 0) {
            $promoSettings = SettingService::getPromoSettings();

            // Check promo.enabled — if disabled, reject manual discount
            if (!$promoSettings['enabled']) {
                $manualTipe = 'none';
                $manualNilai = 0;
            }

            // Check allow_manual_discount
            if (!$promoSettings['allow_manual_discount']) {
                $manualTipe = 'none';
                $manualNilai = 0;
            }

            // Validate max_manual_discount_percent
            if ($manualTipe === 'percent' && $promoSettings['max_manual_discount_percent']) {
                $manualNilai = min($manualNilai, (float) $promoSettings['max_manual_discount_percent']);
            }

            // Validate max_manual_discount_nominal
            if ($manualTipe === 'nominal' && $promoSettings['max_manual_discount_nominal']) {
                $manualNilai = min($manualNilai, (float) $promoSettings['max_manual_discount_nominal']);
            }
        }

        $discounts[2] = ['tipe' => $manualTipe, 'nilai' => $manualNilai];
        if ($manualTipe !== 'none' && $manualNilai > 0) {
            $labels[2] = 'Disc Manual';
        }

        return ['discounts' => $discounts, 'labels' => $labels];
    }
}
