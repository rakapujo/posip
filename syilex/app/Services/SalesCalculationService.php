<?php

namespace App\Services;

class SalesCalculationService
{
    /**
     * Calculate line item total.
     * jumlah = (qty × harga_satuan) - diskon_nominal
     */
    public static function calculateLineItem(float $qty, float $hargaSatuan, float $diskonPersen = 0): array
    {
        $bruto = $qty * $hargaSatuan;
        $diskonNominal = $diskonPersen > 0 ? round($bruto * $diskonPersen / 100, 2) : 0;
        $jumlah = $bruto - $diskonNominal;

        return [
            'diskon_nominal' => $diskonNominal,
            'jumlah' => $jumlah,
        ];
    }

    /**
     * Calculate a single discount level.
     */
    public static function calculateDiscountLevel(string $tipe, float $nilai, float $base): float
    {
        return DocumentCalculation::discountLevel($tipe, $nilai, $base);
    }

    /**
     * Calculate a single biaya/fee level.
     */
    public static function calculateBiayaLevel(string $tipe, float $nilai, float $base): float
    {
        return DocumentCalculation::feeLevel($tipe, $nilai, $base);
    }

    /**
     * Calculate sales totals with 3-level disc nota, biaya kirim, biaya lain.
     *
     * Flow: Subtotal → Disc 1,2,3 (recursive) → Total → +Biaya Kirim → +Biaya Lain → DPP → Pajak → Grand Total
     *
     * @param float $subtotal SUM of detail jumlah
     * @param array $discounts [['tipe' => ..., 'nilai' => ...], ...] up to 3 levels
     * @param array $biayaKirim ['tipe' => ..., 'nilai' => ...]
     * @param array $biayaLain ['tipe' => ..., 'nilai' => ...]
     * @param array $payments Array of payment methods with nominal and biaya_tambahan
     * @return array Calculated totals
     */
    public static function calculateTotals(
        float $subtotal,
        array $discounts = [],
        array $biayaKirim = [],
        array $biayaLain = [],
        array $payments = []
    ): array {
        // 1. Disc Nota 1,2,3 (mode from settings: recursive or sum)
        $discountMode = SettingService::getDiscountMode();
        $running = $subtotal;
        $discResults = [];
        $totalDiskon = 0;

        for ($i = 0; $i < 3; $i++) {
            $tipe = $discounts[$i]['tipe'] ?? 'none';
            $nilai = $discounts[$i]['nilai'] ?? 0;
            // 'recursive': each level uses running value; 'sum': all use original subtotal
            $base = $discountMode === 'recursive' ? $running : $subtotal;
            $hasil = self::calculateDiscountLevel($tipe, $nilai, $base);
            $running -= $hasil;
            $totalDiskon += $hasil;
            $discResults[] = [
                'tipe' => $tipe,
                'nilai' => $nilai,
                'hasil' => $hasil,
            ];
        }

        $totalSetelahDiskon = $subtotal - $totalDiskon;

        // 2. Biaya Kirim & Biaya Lain (from total after discount, before tax)
        $bkTipe = $biayaKirim['tipe'] ?? 'none';
        $bkNilai = $biayaKirim['nilai'] ?? 0;
        $bkHasil = self::calculateBiayaLevel($bkTipe, $bkNilai, $totalSetelahDiskon);

        $blTipe = $biayaLain['tipe'] ?? 'none';
        $blNilai = $biayaLain['nilai'] ?? 0;
        $blHasil = self::calculateBiayaLevel($blTipe, $blNilai, $totalSetelahDiskon);

        $beforeTax = $totalSetelahDiskon + $bkHasil + $blHasil;

        // 3. Tax from settings (exclusive by default)
        $taxSettings = SettingService::getSalesTaxSettings();
        $taxPercent = $taxSettings['percent'];
        $taxName = $taxSettings['name'];

        $taxResult = SettingService::calculateTax($beforeTax, $taxPercent, false);
        $dpp = $taxResult['base_amount'];
        $pajakNominal = $taxResult['tax_amount'];

        $beforeRounding = $dpp + $pajakNominal;

        // 4. Rounding
        $rounded = SettingService::applyRounding($beforeRounding, 'sales');
        $pembulatan = $rounded - $beforeRounding;

        $grandTotal = $rounded;

        // 5. Payment fees
        $totalBiayaPembayaran = 0;
        foreach ($payments as $payment) {
            $totalBiayaPembayaran += $payment['biaya_tambahan'] ?? 0;
        }

        return [
            'subtotal' => $subtotal,
            'diskon_nota_1_tipe' => $discResults[0]['tipe'],
            'diskon_nota_1_nilai' => $discResults[0]['nilai'],
            'diskon_nota_1_hasil' => $discResults[0]['hasil'],
            'diskon_nota_2_tipe' => $discResults[1]['tipe'],
            'diskon_nota_2_nilai' => $discResults[1]['nilai'],
            'diskon_nota_2_hasil' => $discResults[1]['hasil'],
            'diskon_nota_3_tipe' => $discResults[2]['tipe'],
            'diskon_nota_3_nilai' => $discResults[2]['nilai'],
            'diskon_nota_3_hasil' => $discResults[2]['hasil'],
            'total_diskon' => $totalDiskon,
            'total_setelah_diskon' => $totalSetelahDiskon,
            'biaya_kirim_tipe' => $bkTipe,
            'biaya_kirim_nilai' => $bkNilai,
            'biaya_kirim_hasil' => $bkHasil,
            'biaya_lain_tipe' => $blTipe,
            'biaya_lain_nilai' => $blNilai,
            'biaya_lain_hasil' => $blHasil,
            'dpp' => $dpp,
            'pajak_nama' => $taxName,
            'pajak_persen' => $taxPercent,
            'pajak_nominal' => $pajakNominal,
            'pembulatan' => $pembulatan,
            'grand_total' => $grandTotal,
            'total_biaya_pembayaran' => $totalBiayaPembayaran,
        ];
    }

    /**
     * Calculate payment fee for a specific payment method.
     */
    public static function calculatePaymentFee(float $nominal, string $tipe, float $nilai): float
    {
        return match ($tipe) {
            'percent' => round($nominal * $nilai / 100, 2),
            'nominal' => $nilai,
            default => 0,
        };
    }

    /**
     * Calculate return totals (simpler than sales - no header discount).
     */
    public static function calculateReturnTotals(float $subtotal): array
    {
        $taxSettings = SettingService::getSalesTaxSettings();
        $taxPercent = $taxSettings['percent'];
        $taxName = $taxSettings['name'];

        $taxResult = SettingService::calculateTax($subtotal, $taxPercent, false);
        $dpp = $taxResult['base_amount'];
        $pajakNominal = $taxResult['tax_amount'];

        $beforeRounding = $dpp + $pajakNominal;
        $rounded = SettingService::applyRounding($beforeRounding, 'sales');
        $pembulatan = $rounded - $beforeRounding;

        return [
            'subtotal' => $subtotal,
            'pajak_nama' => $taxName,
            'pajak_persen' => $taxPercent,
            'pajak_nominal' => $pajakNominal,
            'pembulatan' => $pembulatan,
            'grand_total' => $rounded,
        ];
    }
}
