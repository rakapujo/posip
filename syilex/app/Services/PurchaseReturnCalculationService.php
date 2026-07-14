<?php

namespace App\Services;

/**
 * Service for handling all PurchaseReturn calculations.
 *
 * Reuses discount calculation methods from PurchaseOrderCalculationService.
 * Simplified version without biaya kirim and biaya lain.
 */
class PurchaseReturnCalculationService
{
    /**
     * Calculate item discounts (5 lines, supports recursive and sum modes).
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateItemDiscounts(float $hargaBruto, array $discounts): array
    {
        return PurchaseOrderCalculationService::calculateItemDiscounts($hargaBruto, $discounts);
    }

    /**
     * Calculate header discounts (3 lines, supports recursive and sum modes).
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateHeaderDiscounts(float $subtotal, array $discounts): array
    {
        return PurchaseOrderCalculationService::calculateHeaderDiscounts($subtotal, $discounts);
    }

    /**
     * Calculate tax based on settings.
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateTax(float $dpp): array
    {
        return PurchaseOrderCalculationService::calculateTax($dpp);
    }

    /**
     * Apply rounding to total based on purchase settings.
     */
    public static function applyRounding(float $amount): float
    {
        return SettingService::applyRounding($amount, 'purchase');
    }

    /**
     * Calculate complete Purchase Return totals.
     *
     * @param array $data - Full return data including details and header discounts
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
                'po_detail_id' => $detail['po_detail_id'] ?? null,
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

        // DPP = total after header discounts (no biaya tambahan for returns)
        $dpp = $headerDiscountResult['total_setelah_diskon'];

        // Calculate tax
        $taxResult = self::calculateTax($dpp);

        // Calculate nilai_kalkulasi (grand total) before rounding
        $nilaiSebelumPembulatan = $dpp + $taxResult['nominal'];

        // Apply rounding
        $nilaiKalkulasi = self::applyRounding($nilaiSebelumPembulatan);
        $pembulatan = $nilaiKalkulasi - $nilaiSebelumPembulatan;

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
            'dpp' => $dpp,
            'pajak_nama' => $taxResult['name'],
            'pajak_persen' => $taxResult['percent'],
            'pajak_nominal' => $taxResult['nominal'],
            'total_sebelum_pembulatan' => $nilaiSebelumPembulatan,
            'pembulatan' => $pembulatan,
            'nilai_kalkulasi' => $nilaiKalkulasi,
        ];
    }
}
