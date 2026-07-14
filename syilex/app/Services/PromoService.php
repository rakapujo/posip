<?php

namespace App\Services;

use App\Models\DocPromo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PromoService — Anti-fraud promo application service.
 *
 * Single source of truth untuk:
 * - Fetch promo aktif (lazy eval: status=approved + tanggal + jam)
 * - Match promo ke item (target + min_qty)
 * - Simulate diskon (recursive/sum mode)
 * - Pilih promo terbaik per item (total diskon rupiah)
 *
 * Dipakai oleh:
 * - PosController::getActivePromos() (frontend preview)
 * - CheckoutSalesAction (anti-fraud: rebuild diskon_1-4 dari DB)
 */
class PromoService
{
    /**
     * Get semua doc promo yang berlaku SEKARANG untuk konteks terminal + customer.
     *
     * Eager load 'details' untuk cegah N+1 saat matching per item.
     * Sort by created_at desc untuk tiebreaker.
     *
     * @param int|null $terminalId Current terminal ID (null = walk-in scenario)
     * @param int|null $customerTypeId Current customer type ID (null = walk-in)
     * @param Carbon|null $now Optional override for testing
     * @return Collection<DocPromo>
     */
    public static function getActivePromos(
        ?int $terminalId,
        ?int $customerTypeId,
        ?Carbon $now = null,
        ?int $customerCategoryId = null,
    ): Collection {
        // Respect global setting
        $settings = SettingService::getPromoSettings();
        if (!$settings['enabled']) {
            return collect();
        }

        return DocPromo::effective($now)
            ->where(function ($q) use ($terminalId) {
                $q->whereNull('terminal_id');
                if ($terminalId) {
                    $q->orWhere('terminal_id', $terminalId);
                }
            })
            ->where(function ($q) use ($customerTypeId) {
                $q->whereNull('customer_type_id');
                if ($customerTypeId) {
                    $q->orWhere('customer_type_id', $customerTypeId);
                }
            })
            ->where(function ($q) use ($customerCategoryId) {
                $q->whereNull('customer_category_id');
                if ($customerCategoryId) {
                    $q->orWhere('customer_category_id', $customerCategoryId);
                }
            })
            ->with(['details'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Cari doc promo terbaik untuk 1 item.
     *
     * Algoritma:
     * 1. Loop semua promo aktif
     * 2. Per promo: simulate total diskon rupiah
     * 3. Ambil yang total terbesar (tiebreaker: promo terbaru)
     *
     * @param int $productId
     * @param int|null $grupId
     * @param int|null $kategoriId
     * @param float $qty
     * @param float $harga
     * @param Collection $activePromos Pre-loaded promos
     * @param string $discountMode 'recursive' or 'sum'
     * @return array|null [
     *   'promo_id', 'nama_promo', 'total_diskon',
     *   'diskon_1_tipe', 'diskon_1_nilai',
     *   'diskon_2_tipe', 'diskon_2_nilai',
     *   'diskon_3_tipe', 'diskon_3_nilai',
     *   'diskon_4_tipe', 'diskon_4_nilai',
     * ] atau null jika tidak ada yang match
     */
    public static function findBestPromo(
        int $productId,
        ?int $grupId,
        ?int $kategoriId,
        float $qty,
        float $harga,
        Collection $activePromos,
        string $discountMode = 'recursive'
    ): ?array {
        if ($activePromos->isEmpty() || $qty <= 0 || $harga <= 0) {
            return null;
        }

        $best = null;

        foreach ($activePromos as $promo) {
            $result = self::simulatePromo($promo, $productId, $grupId, $kategoriId, $qty, $harga, $discountMode);
            if ($result === null || $result['total_diskon'] <= 0) {
                continue;
            }

            if ($best === null || $result['total_diskon'] > $best['total_diskon']) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * Simulate diskon dari 1 doc promo untuk 1 item.
     *
     * Algoritma:
     * 1. Loop detail rows, filter yang match (target + min_qty)
     * 2. Per slot (Line 1-4): jika 2+ detail isi slot sama,
     *    hitung nilai rupiah masing-masing → ambil yang terbesar
     * 3. Apply recursive/sum mode untuk hitung total_diskon final
     *
     * @return array|null [
     *   'promo_id', 'nama_promo', 'total_diskon',
     *   'diskon_1_tipe', 'diskon_1_nilai', ..., 'diskon_4_nilai',
     * ] atau null jika tidak ada detail yang match
     */
    public static function simulatePromo(
        DocPromo $promo,
        int $productId,
        ?int $grupId,
        ?int $kategoriId,
        float $qty,
        float $harga,
        string $discountMode = 'recursive'
    ): ?array {
        $bruto = $qty * $harga;
        if ($bruto <= 0) {
            return null;
        }

        // Filter detail rows yang qualify (target match + qty cukup)
        $matchingDetails = $promo->details->filter(fn ($d) =>
            $d->qualifies($productId, $grupId, $kategoriId, $qty)
        );

        if ($matchingDetails->isEmpty()) {
            return null;
        }

        // Per slot: ambil nilai terbesar dari detail yang match
        // Compare pakai nilai rupiah (bukan nilai raw), agar fair antara percent vs nominal
        $bestPerSlot = [];
        for ($i = 1; $i <= 4; $i++) {
            $bestPerSlot[$i] = [
                'tipe' => 'none',
                'nilai' => 0.0,
                'rupiah' => 0.0,
            ];
        }

        foreach ($matchingDetails as $detail) {
            for ($i = 1; $i <= 4; $i++) {
                $tipe = $detail->{"diskon_{$i}_tipe"};
                $nilai = (float) $detail->{"diskon_{$i}_nilai"};

                if ($tipe === 'none' || $nilai <= 0) {
                    continue;
                }

                // Hitung nilai rupiah untuk comparison (vs bruto — fair basis)
                $rupiah = SalesCalculationService::calculateDiscountLevel($tipe, $nilai, $bruto);

                if ($rupiah > $bestPerSlot[$i]['rupiah']) {
                    $bestPerSlot[$i] = [
                        'tipe' => $tipe,
                        'nilai' => $nilai,
                        'rupiah' => $rupiah,
                    ];
                }
            }
        }

        // Hitung total_diskon dengan recursive/sum mode
        // Catatan: bestPerSlot masih nilai raw, final value akan recalculate di CheckoutSalesAction
        // Tapi kita butuh estimate total_diskon untuk bandingkan antar promo
        $running = $bruto;
        $totalDiskon = 0.0;
        for ($i = 1; $i <= 4; $i++) {
            $tipe = $bestPerSlot[$i]['tipe'];
            $nilai = $bestPerSlot[$i]['nilai'];
            if ($tipe === 'none' || $nilai <= 0) {
                continue;
            }
            $base = $discountMode === 'recursive' ? $running : $bruto;
            $hasil = SalesCalculationService::calculateDiscountLevel($tipe, $nilai, $base);
            $running -= $hasil;
            $totalDiskon += $hasil;
        }

        if ($totalDiskon <= 0) {
            return null;
        }

        return [
            'promo_id' => $promo->id,
            'nama_promo' => $promo->nama_promo,
            'total_diskon' => $totalDiskon,
            'diskon_1_tipe' => $bestPerSlot[1]['tipe'],
            'diskon_1_nilai' => $bestPerSlot[1]['nilai'],
            'diskon_2_tipe' => $bestPerSlot[2]['tipe'],
            'diskon_2_nilai' => $bestPerSlot[2]['nilai'],
            'diskon_3_tipe' => $bestPerSlot[3]['tipe'],
            'diskon_3_nilai' => $bestPerSlot[3]['nilai'],
            'diskon_4_tipe' => $bestPerSlot[4]['tipe'],
            'diskon_4_nilai' => $bestPerSlot[4]['nilai'],
        ];
    }
}
