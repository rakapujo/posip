<?php

namespace App\Services;

/**
 * Service for handling all PurchaseOrder calculations.
 *
 * Implements:
 * - Discount calculation based on global settings (recursive or sum mode)
 *   - 5 lines for detail items
 *   - 3 lines for header
 * - Additional costs calculation (biaya kirim, biaya lain) - supports % and nominal
 * - Tax calculation from settings
 * - Cost allocation by_value mode
 * - Rounding based on settings
 */
class PurchaseOrderCalculationService
{
    /**
     * Calculate item discounts (5 lines, supports recursive and sum modes).
     *
     * @param float $hargaBruto - Base price before discounts
     * @param array $discounts - Array of discount definitions [{tipe, nilai}, ...]
     * @return array - Contains individual discount results and total
     */
    public static function calculateItemDiscounts(float $hargaBruto, array $discounts): array
    {
        $result = [
            'harga_bruto' => $hargaBruto,
            'discounts' => [],
            'total_diskon' => 0,
            'subtotal' => $hargaBruto,
        ];

        $mode = SettingService::getDiscountMode();

        if ($mode === 'sum') {
            // Sum mode: calculate all discounts based on original amount, then apply once
            $totalDiscountPercent = 0;
            $totalDiscountNominal = 0;

            // First pass: collect all discounts
            for ($i = 0; $i < 5; $i++) {
                $discount = $discounts[$i] ?? ['tipe' => 'none', 'nilai' => 0];
                $tipe = $discount['tipe'] ?? 'none';
                $nilai = (float) ($discount['nilai'] ?? 0);

                $hasil = DocumentCalculation::applyDiscountLine($tipe, $nilai, $hargaBruto, capNominalToBase: false);
                if ($tipe === 'percent' && $nilai > 0) {
                    $totalDiscountPercent += $nilai;
                } elseif ($tipe === 'nominal' && $nilai > 0) {
                    $totalDiscountNominal += $nilai;
                }
                $result['discounts'][] = [
                    'tipe' => $tipe,
                    'nilai' => $nilai,
                    'hasil' => $hasil,
                ];
                $result['total_diskon'] += $hasil;
            }

            // Apply total discount (cannot exceed bruto)
            $result['total_diskon'] = min($result['total_diskon'], $hargaBruto);
            $result['subtotal'] = max(0, round($hargaBruto - $result['total_diskon'], 2));
        } else {
            // Recursive mode (default): apply each discount to remaining amount
            $currentAmount = $hargaBruto;

            for ($i = 0; $i < 5; $i++) {
                $discount = $discounts[$i] ?? ['tipe' => 'none', 'nilai' => 0];
                $tipe = $discount['tipe'] ?? 'none';
                $nilai = (float) ($discount['nilai'] ?? 0);

                $hasil = DocumentCalculation::applyDiscountLine($tipe, $nilai, $currentAmount);
                $result['discounts'][] = [
                    'tipe' => $tipe,
                    'nilai' => $nilai,
                    'hasil' => $hasil,
                ];
                $result['total_diskon'] += $hasil;
                $currentAmount -= $hasil;
            }

            $result['subtotal'] = max(0, round($currentAmount, 2));
        }

        return $result;
    }

    /**
     * Calculate header discounts (3 lines, supports recursive and sum modes).
     *
     * @param float $subtotal - Sum of all item subtotals
     * @param array $discounts - Array of discount definitions [{tipe, nilai}, ...]
     * @return array - Contains individual discount results and total
     */
    public static function calculateHeaderDiscounts(float $subtotal, array $discounts): array
    {
        $result = [
            'subtotal' => $subtotal,
            'discounts' => [],
            'total_diskon' => 0,
            'total_setelah_diskon' => $subtotal,
        ];

        $mode = SettingService::getDiscountMode();

        if ($mode === 'sum') {
            // Sum mode: calculate all discounts based on original subtotal, then apply once
            for ($i = 0; $i < 3; $i++) {
                $discount = $discounts[$i] ?? ['tipe' => 'none', 'nilai' => 0];
                $tipe = $discount['tipe'] ?? 'none';
                $nilai = (float) ($discount['nilai'] ?? 0);

                $hasil = DocumentCalculation::applyDiscountLine($tipe, $nilai, $subtotal, capNominalToBase: false);
                $result['discounts'][] = [
                    'tipe' => $tipe,
                    'nilai' => $nilai,
                    'hasil' => $hasil,
                ];
                $result['total_diskon'] += $hasil;
            }

            // Apply total discount (cannot exceed subtotal)
            $result['total_diskon'] = min($result['total_diskon'], $subtotal);
            $result['total_setelah_diskon'] = max(0, round($subtotal - $result['total_diskon'], 2));
        } else {
            // Recursive mode (default): apply each discount to remaining amount
            $currentAmount = $subtotal;

            for ($i = 0; $i < 3; $i++) {
                $discount = $discounts[$i] ?? ['tipe' => 'none', 'nilai' => 0];
                $tipe = $discount['tipe'] ?? 'none';
                $nilai = (float) ($discount['nilai'] ?? 0);

                $hasil = DocumentCalculation::applyDiscountLine($tipe, $nilai, $currentAmount);
                $result['discounts'][] = [
                    'tipe' => $tipe,
                    'nilai' => $nilai,
                    'hasil' => $hasil,
                ];
                $result['total_diskon'] += $hasil;
                $currentAmount -= $hasil;
            }

            $result['total_setelah_diskon'] = max(0, round($currentAmount, 2));
        }

        return $result;
    }

    /**
     * Calculate additional cost (biaya kirim or biaya lain).
     *
     * @param float $baseAmount - Amount to calculate percentage from
     * @param string $tipe - 'percent', 'nominal', or 'none'
     * @param float $nilai - The value (percentage or nominal amount)
     * @return float - Calculated cost
     */
    public static function calculateAdditionalCost(float $baseAmount, string $tipe, float $nilai): float
    {
        return DocumentCalculation::applyFeeLine($tipe, $nilai, $baseAmount);
    }

    /**
     * Calculate tax based on settings.
     *
     * @param float $dpp - Dasar Pengenaan Pajak (tax base)
     * @return array - Contains name, percent, nominal
     */
    public static function calculateTax(float $dpp): array
    {
        $taxSettings = SettingService::getPurchaseTaxSettings();

        $nominal = round($dpp * ($taxSettings['percent'] / 100), 2);

        return [
            'name' => $taxSettings['name'],
            'percent' => $taxSettings['percent'],
            'nominal' => $nominal,
            'included_in_hpp' => $taxSettings['included_in_hpp'],
        ];
    }

    /**
     * Calculate cost allocation per item (by_value mode).
     * Distributes additional costs proportionally based on each item's subtotal.
     *
     * @param array $details - Array of detail items with 'subtotal'
     * @param float $totalBiaya - Total additional costs to allocate
     * @param float $pajakNominal - Tax amount to allocate (if included in HPP)
     * @param bool $includeTaxInHpp - Whether to include tax in HPP calculation
     * @param float $pembulatan - Rounding amount to allocate to HPP
     * @return array - Array with allocated cost per item
     */
    public static function allocateCosts(
        array $details,
        float $totalBiaya,
        float $pajakNominal,
        bool $includeTaxInHpp,
        float $pembulatan = 0
    ): array {
        $totalSubtotal = array_sum(array_column($details, 'subtotal'));

        if ($totalSubtotal <= 0) {
            return array_map(fn($detail) => array_merge($detail, ['allocated_cost' => 0]), $details);
        }

        // Calculate total cost to allocate (biaya + pajak if included + pembulatan)
        $totalCostToAllocate = $totalBiaya + $pembulatan;
        if ($includeTaxInHpp) {
            $totalCostToAllocate += $pajakNominal;
        }

        // Allocate by value proportion
        return array_map(function ($detail) use ($totalSubtotal, $totalCostToAllocate) {
            $proportion = (float) $detail['subtotal'] / $totalSubtotal;
            $allocatedCost = $totalCostToAllocate * $proportion;

            return array_merge($detail, [
                'allocated_cost' => round($allocatedCost, 4),
            ]);
        }, $details);
    }

    /**
     * Calculate cost per unit (base unit) for a detail item.
     *
     * @param float $subtotal - Item subtotal after discounts
     * @param float $allocatedCost - Allocated additional costs
     * @param float $qtyInBase - Quantity in base unit
     * @return float - Cost per base unit
     */
    public static function calculateCostPerUnit(float $subtotal, float $allocatedCost, float $qtyInBase): float
    {
        if ($qtyInBase <= 0) {
            return 0;
        }

        $totalCost = $subtotal + $allocatedCost;
        return round($totalCost / $qtyInBase, 4);
    }

    /**
     * Apply rounding to grand total based on purchase settings.
     *
     * @param float $amount - Amount to round
     * @return float - Rounded amount
     */
    public static function applyRounding(float $amount): float
    {
        return SettingService::applyRounding($amount, 'purchase');
    }

    /**
     * Calculate complete PO totals.
     *
     * @param array $data - Full PO data including details and header discounts
     * @return array - Calculated totals
     */
    public static function calculateTotals(array $data): array
    {
        $details = $data['details'] ?? [];

        // Calculate each detail's discounts and subtotals
        $calculatedDetails = [];
        $totalSubtotal = 0;

        foreach ($details as $detail) {
            // Calculate qty_in_base
            $qtyInUnit = (float) ($detail['qty_in_unit'] ?? 0);
            $unitKonversi = (int) ($detail['unit_konversi'] ?? 1);
            $qtyInBase = $qtyInUnit * $unitKonversi;

            // Calculate harga_bruto
            $hargaPerUnit = (float) ($detail['harga_per_unit'] ?? 0);
            $hargaBruto = $qtyInUnit * $hargaPerUnit;

            // Calculate harga_per_base
            $hargaPerBase = $unitKonversi > 0 ? $hargaPerUnit / $unitKonversi : 0;

            // Calculate item discounts
            $discounts = [];
            for ($i = 1; $i <= 5; $i++) {
                $discounts[] = [
                    'tipe' => $detail["diskon_{$i}_tipe"] ?? 'none',
                    'nilai' => $detail["diskon_{$i}_nilai"] ?? 0,
                ];
            }
            $discountResult = self::calculateItemDiscounts($hargaBruto, $discounts);

            $calculatedDetail = [
                'product_id' => $detail['product_id'],
                'unit_used' => $detail['unit_used'],
                'unit_konversi' => $unitKonversi,
                'qty_in_unit' => $qtyInUnit,
                'qty_in_base' => $qtyInBase,
                'harga_per_unit' => $hargaPerUnit,
                'harga_per_base' => round($hargaPerBase, 4),
                'harga_bruto' => $discountResult['harga_bruto'],
            ];

            // Add discount fields
            for ($i = 0; $i < 5; $i++) {
                $calculatedDetail["diskon_" . ($i + 1) . "_tipe"] = $discountResult['discounts'][$i]['tipe'];
                $calculatedDetail["diskon_" . ($i + 1) . "_nilai"] = $discountResult['discounts'][$i]['nilai'];
                $calculatedDetail["diskon_" . ($i + 1) . "_hasil"] = $discountResult['discounts'][$i]['hasil'];
            }

            $calculatedDetail['total_diskon_item'] = $discountResult['total_diskon'];
            $calculatedDetail['subtotal'] = $discountResult['subtotal'];

            $calculatedDetails[] = $calculatedDetail;
            $totalSubtotal += $discountResult['subtotal'];
        }

        // Calculate header discounts
        $headerDiscounts = [];
        for ($i = 1; $i <= 3; $i++) {
            $headerDiscounts[] = [
                'tipe' => $data["diskon_{$i}_tipe"] ?? 'none',
                'nilai' => $data["diskon_{$i}_nilai"] ?? 0,
            ];
        }
        $headerDiscountResult = self::calculateHeaderDiscounts($totalSubtotal, $headerDiscounts);

        // Calculate additional costs
        $biayaKirimTipe = $data['biaya_kirim_tipe'] ?? 'none';
        $biayaKirimNilai = (float) ($data['biaya_kirim_nilai'] ?? 0);
        $biayaKirimHasil = self::calculateAdditionalCost(
            $headerDiscountResult['total_setelah_diskon'],
            $biayaKirimTipe,
            $biayaKirimNilai
        );

        $biayaLainTipe = $data['biaya_lain_tipe'] ?? 'none';
        $biayaLainNilai = (float) ($data['biaya_lain_nilai'] ?? 0);
        $biayaLainHasil = self::calculateAdditionalCost(
            $headerDiscountResult['total_setelah_diskon'],
            $biayaLainTipe,
            $biayaLainNilai
        );

        $totalBiayaTambahan = $biayaKirimHasil + $biayaLainHasil;

        // Calculate DPP (base for tax calculation)
        $dpp = $headerDiscountResult['total_setelah_diskon'] + $totalBiayaTambahan;

        // Calculate tax
        $taxResult = self::calculateTax($dpp);

        // Calculate grand total before rounding
        $grandTotalSebelumPembulatan = $dpp + $taxResult['nominal'];

        // Apply rounding
        $grandTotal = self::applyRounding($grandTotalSebelumPembulatan);
        $pembulatan = $grandTotal - $grandTotalSebelumPembulatan;

        // Allocate costs to details for HPP calculation (including pembulatan)
        $calculatedDetails = self::allocateCosts(
            $calculatedDetails,
            $totalBiayaTambahan,
            $taxResult['nominal'],
            $taxResult['included_in_hpp'],
            $pembulatan
        );

        // Calculate cost_per_unit for each detail
        foreach ($calculatedDetails as &$detail) {
            $detail['cost_per_unit'] = self::calculateCostPerUnit(
                $detail['subtotal'],
                $detail['allocated_cost'],
                $detail['qty_in_base']
            );
        }

        return [
            'details' => $calculatedDetails,
            'subtotal' => $totalSubtotal,
            'diskon_1_tipe' => $headerDiscountResult['discounts'][0]['tipe'],
            'diskon_1_nilai' => $headerDiscountResult['discounts'][0]['nilai'],
            'diskon_1_hasil' => $headerDiscountResult['discounts'][0]['hasil'],
            'diskon_2_tipe' => $headerDiscountResult['discounts'][1]['tipe'],
            'diskon_2_nilai' => $headerDiscountResult['discounts'][1]['nilai'],
            'diskon_2_hasil' => $headerDiscountResult['discounts'][1]['hasil'],
            'diskon_3_tipe' => $headerDiscountResult['discounts'][2]['tipe'],
            'diskon_3_nilai' => $headerDiscountResult['discounts'][2]['nilai'],
            'diskon_3_hasil' => $headerDiscountResult['discounts'][2]['hasil'],
            'total_diskon_header' => $headerDiscountResult['total_diskon'],
            'total_setelah_diskon' => $headerDiscountResult['total_setelah_diskon'],
            'biaya_kirim_tipe' => $biayaKirimTipe,
            'biaya_kirim_nilai' => $biayaKirimNilai,
            'biaya_kirim_hasil' => $biayaKirimHasil,
            'biaya_lain_nama' => $data['biaya_lain_nama'] ?? null,
            'biaya_lain_tipe' => $biayaLainTipe,
            'biaya_lain_nilai' => $biayaLainNilai,
            'biaya_lain_hasil' => $biayaLainHasil,
            'total_biaya_tambahan' => $totalBiayaTambahan,
            'dpp' => $dpp,
            'pajak_nama' => $taxResult['name'],
            'pajak_persen' => $taxResult['percent'],
            'pajak_nominal' => $taxResult['nominal'],
            'total_sebelum_pembulatan' => $grandTotalSebelumPembulatan,
            'pembulatan' => $pembulatan,
            'grand_total' => $grandTotal,
        ];
    }
}
