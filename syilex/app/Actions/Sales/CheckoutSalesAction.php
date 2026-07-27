<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Sales\Concerns\PostsSalesInventory;
use App\Constants\PromoConstants;
use App\Models\DocSales;
use App\Models\DocSalesPayment;
use App\Models\MasterProduk;
use App\Models\PosTerminalShift;
use App\Services\PosCheckoutRules;
use App\Services\PromoService;
use App\Services\SalesCalculationService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutSalesAction
{
    use PostsSalesInventory;
    use RequiresAuthenticatedUser;

    /**
     * Execute the checkout.
     *
     * @param  array  $data  Validated checkout data
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
            $payments = SalesCalculationService::applyMasterPaymentFees($data['payments']);

            // Re-lock shift row untuk prevent race: admin force-release antara controller
            // cek isActive() dan commit di sini. Tanpa lock, sale bisa ter-commit ke shift
            // yang sudah ditutup (silent data drift di laporan shift).
            $shift = PosTerminalShift::where('id', $shiftId)->lockForUpdate()->first();
            if (! $shift || $shift->ended_at !== null) {
                throw ValidationException::withMessages([
                    'shift' => ['Shift sudah ditutup. Silakan refresh halaman dan mulai shift baru.'],
                ]);
            }

            // Collect product IDs
            $productIds = array_column($items, 'product_id');

            // Lock products
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Anti-fraud: rebuild konversi / qty_base / harga from master (+ serial harga_jual)
            PosCheckoutRules::rebuildTrustedLineFields($items, $products);

            // Re-assert shift not locked after lockForUpdate (race with layar kunci)
            if ($shift->isLocked()) {
                throw ValidationException::withMessages([
                    'shift' => ['Shift sedang dikunci. Buka kunci sebelum checkout.'],
                ]);
            }

            // Auto-apply promo (anti-fraud: rebuild diskon_1..4 dari DB, override FE)
            $discountMode = SettingService::getDiscountMode();
            $canPosDiscount = Auth::user()?->can('pos.discount') ?? false;
            $this->applyPromosToItems($items, $products, $customerId, $terminalId, $discountMode, $canPosDiscount);

            // Pre-calculate line discounts to get accurate subtotal
            $processedItems = array_map(
                fn ($item) => SalesCalculationService::applyLineDiscounts($item, $discountMode),
                $items,
            );

            $subtotal = array_sum(array_column($processedItems, 'jumlah'));

            // Build nota discounts with auto-customer discount. L1+L2 overridden from
            // DB UNLESS kasir explicitly set override flag (nota_discount_overrides[i]=true).
            $notaResult = $this->buildNotaDiscounts(
                $customerId,
                $data['discounts'] ?? [],
                $data['nota_discount_overrides'] ?? [],
                $canPosDiscount,
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
                'source' => 'pos',
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

            $this->postSalesInventory($sales, $processedItems);

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
     * Anti-fraud: rebuild diskon_1..DB_DISCOUNT_SLOTS dari DB promo, JANGAN trust FE.
     * Slot MANUAL_DISCOUNT_SLOT (5) dari FE hanya jika user punya pos.discount.
     *
     * @param  array  $items  Items array (mutated: diskon_1..4 di-override, promo_id di-set)
     * @param  \Illuminate\Support\Collection  $products  MasterProduk keyed by id
     * @param  string  $discountMode  'recursive' atau 'sum'
     */
    private function applyPromosToItems(array &$items, $products, ?int $customerId, int $terminalId, string $discountMode, bool $canPosDiscount): void
    {
        $customer = $customerId
            ? \App\Models\MasterCustomer::select('id', 'tipe_customer_id', 'kategori_customer_id')->find($customerId)
            : null;
        $customerTypeId = $customer?->tipe_customer_id;
        $customerCategoryId = $customer?->kategori_customer_id;
        $activePromos = PromoService::getActivePromos(
            $terminalId,
            $customerTypeId,
            customerCategoryId: $customerCategoryId,
            channel: 'pos',
        );

        $slot = PromoConstants::MANUAL_DISCOUNT_SLOT;

        foreach ($items as &$item) {
            // Respect explicit kasir override — skip auto-derive, zero promo slots (anti-fraud).
            // Kasir UI sets this flag when they click "Hapus Semua Diskon Item".
            if (! empty($item['override_promo'])) {
                $item['promo_id'] = null;
                for ($i = 1; $i <= PromoConstants::DB_DISCOUNT_SLOTS; $i++) {
                    $item["diskon_{$i}_tipe"] = 'none';
                    $item["diskon_{$i}_nilai"] = 0;
                }
            } else {
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

            // Manual line discount (slot 5) — require pos.discount
            if (! $canPosDiscount) {
                $item["diskon_{$slot}_tipe"] = 'none';
                $item["diskon_{$slot}_nilai"] = 0;
            }
        }
        unset($item);
    }

    private function buildNotaDiscounts(int $customerId, array $frontendDiscounts, array $overrides = [], bool $canPosDiscount = false): array
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
            if (! $overrides[0] && $tipe && $tipe->isActive() && $tipe->diskon_tipe !== 'none' && (float) $tipe->diskon_nilai > 0) {
                $discounts[0] = ['tipe' => $tipe->diskon_tipe, 'nilai' => (float) $tipe->diskon_nilai];
                $labels[0] = $tipe->diskon_tipe === 'percent'
                    ? "{$tipe->kode_tipe} {$tipe->diskon_nilai}%"
                    : "{$tipe->kode_tipe} Rp ".number_format((float) $tipe->diskon_nilai, 0, ',', '.');
            }

            // Level 1: kategori customer discount — same override rule.
            $kat = $customer->kategoriCustomer;
            if (! $overrides[1] && $kat && $kat->isActive() && $kat->diskon_tipe !== 'none' && (float) $kat->diskon_nilai > 0) {
                $discounts[1] = ['tipe' => $kat->diskon_tipe, 'nilai' => (float) $kat->diskon_nilai];
                $labels[1] = $kat->diskon_tipe === 'percent'
                    ? "{$kat->kode_kategori} {$kat->diskon_nilai}%"
                    : "{$kat->kode_kategori} Rp ".number_format((float) $kat->diskon_nilai, 0, ',', '.');
            }
        }

        // Level 2: manual kasir — require pos.discount + promo settings
        $manualDisc = $frontendDiscounts[2] ?? $none;
        $manualTipe = $manualDisc['tipe'] ?? 'none';
        $manualNilai = (float) ($manualDisc['nilai'] ?? 0);

        if (! $canPosDiscount) {
            $manualTipe = 'none';
            $manualNilai = 0;
        } elseif ($manualTipe !== 'none' && $manualNilai > 0) {
            $promoSettings = SettingService::getPromoSettings();

            // Check promo.enabled — if disabled, reject manual discount
            if (! $promoSettings['enabled']) {
                $manualTipe = 'none';
                $manualNilai = 0;
            }

            // Check allow_manual_discount
            if (! $promoSettings['allow_manual_discount']) {
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
