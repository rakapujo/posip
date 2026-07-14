<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scope filter date-range yang konsisten untuk kolom DATETIME.
 *
 * Menyediakan scopeByDateRange($from, $to) dengan batas inklusif penuh hari:
 *   from → '... 00:00:00', to → '... 23:59:59'
 * Mencegah record hari ini (jam > 0) tersaring keluar saat filter dikirim date-only
 * (MySQL meng-coerce 'YYYY-MM-DD' menjadi '... 00:00:00'). Lihat CLAUDE.md §7 Backend #8.
 *
 * Default kolom = 'tanggal'. Model dengan kolom lain override:
 *   protected $dateRangeColumn = 'tanggal_po';
 *
 * Regression test: tests/Feature/PurchaseOrder/PurchaseOrderDateFilterTest.php
 */
trait HasDateRangeScope
{
    /**
     * Kolom DATETIME yang dipakai untuk filter date-range.
     */
    protected function dateRangeColumn(): string
    {
        return property_exists($this, 'dateRangeColumn') ? $this->dateRangeColumn : 'tanggal';
    }

    /**
     * Filter rentang tanggal inklusif penuh hari (date-only aman).
     */
    public function scopeByDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        $column = $this->dateRangeColumn();

        if ($startDate) {
            $query->where($column, '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where($column, '<=', $endDate . ' 23:59:59');
        }

        return $query;
    }
}
