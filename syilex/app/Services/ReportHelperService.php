<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Helper service untuk mengurangi boilerplate di report controllers.
 *
 * Pattern yang di-extract:
 * - Date range validation + parsing (date_from..date_to 23:59:59)
 * - Sort whitelist application (cegah SQL injection via sort_field liar)
 * - Pagination response formatting (standard struktur)
 * - Sales per-nota receipt qty SQL (bought/returned) shared by API + Excel export
 *
 * Dipakai di:
 * - app/Http/Controllers/Api/V1/PurchaseReport/* (6 controllers hasil split)
 * - SalesReportController / SalesPerNotaExport (receipt qty SQL + date range)
 * - SalesFinancialReportController
 * - SalesProductReportController
 */
class ReportHelperService
{
    /**
     * Parse date_from + date_to dari request, return tuple [dateFrom, dateToEnd].
     * dateToEnd = date_to + ' 23:59:59' agar filter inclusive hingga akhir hari.
     *
     * Asumsi request sudah di-validate dengan rules:
     *   'date_from' => 'required|date',
     *   'date_to' => 'required|date|after_or_equal:date_from',
     *
     * @return array{0: string, 1: string} [$dateFrom, $dateToEnd]
     */
    public static function parseDateRange(Request $request): array
    {
        $dateFrom = $request->input('date_from');
        $dateToEnd = $request->input('date_to') . ' 23:59:59';

        return [$dateFrom, $dateToEnd];
    }

    /**
     * Apply sort_field + sort_order ke query, dengan whitelist safety.
     *
     * @param mixed $query Query builder (Eloquent atau DB facade)
     * @param Request $request
     * @param array $sortableFields Whitelist kolom yang boleh di-sort
     * @param string $defaultField Field default kalau sort_field invalid
     * @param string $defaultOrder 'asc' | 'desc' default order
     * @return mixed Query (chainable)
     */
    public static function applySortWhitelist(
        $query,
        Request $request,
        array $sortableFields,
        string $defaultField,
        string $defaultOrder = 'desc'
    ) {
        $sortField = $request->input('sort_field', $defaultField);
        $sortOrder = $request->input('sort_order', $defaultOrder);
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, $sortableFields, true)) {
            $query->orderBy($sortField, $dir);
        } else {
            $query->orderBy($defaultField, $defaultOrder);
        }

        return $query;
    }

    /**
     * Build standard paginated response structure untuk report endpoint.
     *
     * @param LengthAwarePaginator $paginator
     * @param array $summary Aggregate totals
     * @param array $extras Tambahan key di response (misal 'can_view_harga')
     * @return array Ready-to-return response body (tinggal wrap `$this->success()`)
     */
    public static function buildPaginatedResponse(
        LengthAwarePaginator $paginator,
        array $summary = [],
        array $extras = []
    ): array {
        return array_merge([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $summary,
        ], $extras);
    }

    /**
     * Standard validation rules untuk date range di report.
     * Gunakan: `$request->validate(ReportHelperService::dateRangeRules());`
     */
    public static function dateRangeRules(): array
    {
        return [
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ];
    }

    /**
     * Resolve filter "source" untuk laporan pembelian gabungan (PO + Pembelian Serial).
     *
     * @return string 'all' | 'po' | 'serial'
     */
    public static function resolveSource(Request $request): string
    {
        $source = $request->input('source');

        return in_array($source, ['po', 'serial'], true) ? $source : 'all';
    }

    /**
     * Correlated subquery: SUM qty_base sold for a sales row.
     * $salesIdExpr = trusted column ref only (e.g. "doc_sales.id" / "ds.id"), never user input.
     */
    public static function sqlSalesBoughtBase(string $salesIdExpr): string
    {
        return "(SELECT COALESCE(SUM(d.qty_base), 0) FROM doc_sales_detail d WHERE d.sales_id = {$salesIdExpr})";
    }

    /**
     * Correlated subquery: SUM qty_base returned for a sales row.
     */
    public static function sqlSalesReturnedBase(string $salesIdExpr): string
    {
        return "(SELECT COALESCE(SUM(rd.qty_base), 0) FROM doc_sales_return_detail rd INNER JOIN doc_sales_returns r ON r.id = rd.return_id WHERE r.sales_id = {$salesIdExpr})";
    }

    /** Select expressions for receipt status (bought + returned qty). */
    public static function salesReceiptQtySelects(string $salesIdExpr): array
    {
        return [
            DB::raw(self::sqlSalesBoughtBase($salesIdExpr) . ' as total_bought_base'),
            DB::raw(self::sqlSalesReturnedBase($salesIdExpr) . ' as total_returned_base'),
        ];
    }
}
