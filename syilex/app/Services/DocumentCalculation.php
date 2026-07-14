<?php

namespace App\Services;

/**
 * Shared percent/nominal level calculations for sales and purchase documents.
 */
class DocumentCalculation
{
    /**
     * Calculate a single discount level (sales header / line discount helper).
     */
    public static function discountLevel(string $tipe, float $nilai, float $base): float
    {
        return match ($tipe) {
            'percent' => round($base * $nilai / 100, 2),
            'nominal' => min($nilai, $base),
            default => 0,
        };
    }

    /**
     * Calculate a single fee/biaya level (no nominal cap).
     */
    public static function feeLevel(string $tipe, float $nilai, float $base): float
    {
        return match ($tipe) {
            'percent' => round($base * $nilai / 100, 2),
            'nominal' => $nilai,
            default => 0,
        };
    }

    /**
     * Apply one discount line when nilai must be positive (PO item/header loops).
     */
    public static function applyDiscountLine(string $tipe, float $nilai, float $base, bool $capNominalToBase = true): float
    {
        if ($nilai <= 0 || $tipe === 'none') {
            return 0;
        }

        if ($tipe === 'percent') {
            return round($base * ($nilai / 100), 2);
        }

        if ($tipe === 'nominal') {
            $amount = round($nilai, 2);

            return $capNominalToBase ? min($amount, $base) : $amount;
        }

        return 0;
    }

    /**
     * Apply one fee/biaya line when nilai must be positive (PO additional costs).
     */
    public static function applyFeeLine(string $tipe, float $nilai, float $base): float
    {
        if ($nilai <= 0 || $tipe === 'none') {
            return 0;
        }

        return match ($tipe) {
            'percent' => round($base * ($nilai / 100), 2),
            'nominal' => round($nilai, 2),
            default => 0,
        };
    }
}
