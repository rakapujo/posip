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
        $dateToEnd = $request->input('date_to').' 23:59:59';

        return [$dateFrom, $dateToEnd];
    }

    /**
     * Apply sort_field + sort_order ke query, dengan whitelist safety.
     *
     * @param  mixed  $query  Query builder (Eloquent atau DB facade)
     * @param  array  $sortableFields  Whitelist kolom yang boleh di-sort
     * @param  string  $defaultField  Field default kalau sort_field invalid
     * @param  string  $defaultOrder  'asc' | 'desc' default order
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
     * @param  array  $summary  Aggregate totals
     * @param  array  $extras  Tambahan key di response (misal 'can_view_harga')
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

    /** @return string 'bruto' | 'net' */
    public static function resolveMode(Request $request): string
    {
        return $request->input('mode') === 'net' ? 'net' : 'bruto';
    }

    /** Merge into $request->validate([...]) for Bruto|Net reports. */
    public static function modeRules(): array
    {
        return ['mode' => 'nullable|in:bruto,net'];
    }

    /**
     * ACC-3: base filtered query for retur beli by header (dpr), reused by
     * sumPurchaseReturnMoney (blanket total) and purchaseReturnMoneyBySupplierSubquery (grouped, list/export).
     *
     * @param  array{supplier_id?: int|string|null, warehouse_id?: int|string|null}  $filters
     */
    private static function purchaseReturnMoneyQuery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('doc_purchase_return as dpr')
            ->whereIn('dpr.status', ['lock', 'approved'])
            ->where('dpr.tanggal', '>=', $dateFrom)
            ->where('dpr.tanggal', '<=', $dateToEnd);

        if (! empty($filters['supplier_id'])) {
            $q->where('dpr.supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $q->where('dpr.warehouse_id', $filters['warehouse_id']);
        }

        return $q;
    }

    /**
     * ACC-3: SUM money retur beli (lock|approved) by return.tanggal.
     * money = COALESCE(nilai_diakui, nilai_kalkulasi).
     *
     * @param  array{supplier_id?: int|string|null, warehouse_id?: int|string|null}  $filters
     */
    public static function sumPurchaseReturnMoney(string $dateFrom, string $dateToEnd, array $filters = []): float
    {
        return (float) self::purchaseReturnMoneyQuery($dateFrom, $dateToEnd, $filters)
            ->selectRaw('COALESCE(SUM(COALESCE(dpr.nilai_diakui, dpr.nilai_kalkulasi)), 0) as t')
            ->value('t');
    }

    /**
     * ACC-3 Wave B: retur beli grouped by po_id — for LEFT JOIN onto the per-dokumen
     * list/export query so mode=net nets each row (not just the summary).
     * Only rows with po_id set (pembelian serial excluded, matches existing source!=='serial' guard).
     */
    public static function purchaseReturnByPoSubquery(string $dateFrom, string $dateToEnd): \Illuminate\Database\Query\Builder
    {
        return DB::table('doc_purchase_return')
            ->whereIn('status', ['lock', 'approved'])
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd)
            ->whereNotNull('po_id')
            ->groupBy('po_id')
            ->selectRaw('po_id, COALESCE(SUM(COALESCE(nilai_diakui, nilai_kalkulasi)), 0) as ret_money, COALESCE(SUM(total_diskon_header), 0) as ret_disc');
    }

    /**
     * ACC-3 Wave B: retur beli grouped by supplier_id — for LEFT JOIN onto Per Supplier
     * list/export (aggregated rows), so mode=net nets each supplier row directly.
     *
     * @param  array{warehouse_id?: int|string|null}  $filters
     */
    public static function purchaseReturnMoneyBySupplierSubquery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        return self::purchaseReturnMoneyQuery($dateFrom, $dateToEnd, $filters)
            ->groupBy('dpr.supplier_id')
            ->selectRaw('dpr.supplier_id, COALESCE(SUM(COALESCE(dpr.nilai_diakui, dpr.nilai_kalkulasi)), 0) as ret_money, COALESCE(SUM(dpr.total_diskon_header), 0) as ret_disc');
    }

    /**
     * ACC-3 Per Barang: base filtered query for retur beli lines, reused by
     * sumPurchaseReturnLines (blanket total) and purchaseReturnLinesByProductSubquery (grouped, list/export).
     *
     * @param  array{supplier_id?: mixed, warehouse_id?: mixed, brand_id?: mixed, kategori_id?: mixed, search?: mixed}  $filters
     */
    private static function purchaseReturnLinesQuery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('doc_purchase_return_detail as dprd')
            ->join('doc_purchase_return as dpr', 'dpr.id', '=', 'dprd.retur_id')
            ->join('master_produk as mp', 'mp.id', '=', 'dprd.product_id')
            ->whereIn('dpr.status', ['lock', 'approved'])
            ->where('dpr.tanggal', '>=', $dateFrom)
            ->where('dpr.tanggal', '<=', $dateToEnd);

        if (! empty($filters['supplier_id'])) {
            $q->where('dpr.supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $q->where('dpr.warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['brand_id'])) {
            $q->where('mp.brand_id', $filters['brand_id']);
        }
        if (! empty($filters['kategori_id'])) {
            $q->where('mp.kategori_id', $filters['kategori_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $q->where(function ($w) use ($search) {
                $w->where('mp.kode_produk', 'like', "%{$search}%")
                    ->orWhere('mp.nama_produk', 'like', "%{$search}%");
            });
        }

        return $q;
    }

    /**
     * ACC-3 Per Barang: agregat baris retur beli (harga) matching product filters.
     *
     * @param  array{supplier_id?: mixed, warehouse_id?: mixed, brand_id?: mixed, kategori_id?: mixed, search?: mixed}  $filters
     * @return object{total_bruto: float, total_diskon: float, total_subtotal: float, total_qty: float}
     */
    public static function sumPurchaseReturnLines(string $dateFrom, string $dateToEnd, array $filters = []): object
    {
        $row = self::purchaseReturnLinesQuery($dateFrom, $dateToEnd, $filters)->selectRaw(
            'COALESCE(SUM(dprd.harga_bruto), 0) as total_bruto,
             COALESCE(SUM(dprd.total_diskon_item), 0) as total_diskon,
             COALESCE(SUM(dprd.subtotal), 0) as total_subtotal,
             COALESCE(SUM(dprd.qty_in_base), 0) as total_qty'
        )->first();

        return (object) [
            'total_bruto' => (float) ($row->total_bruto ?? 0),
            'total_diskon' => (float) ($row->total_diskon ?? 0),
            'total_subtotal' => (float) ($row->total_subtotal ?? 0),
            'total_qty' => (float) ($row->total_qty ?? 0),
        ];
    }

    /**
     * ACC-3 Wave B: retur beli lines grouped by product_id — for LEFT JOIN onto Per Barang
     * list/export (aggregated rows), so mode=net nets each product row directly.
     *
     * @param  array{supplier_id?: mixed, warehouse_id?: mixed, brand_id?: mixed, kategori_id?: mixed, search?: mixed}  $filters
     */
    public static function purchaseReturnLinesByProductSubquery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        return self::purchaseReturnLinesQuery($dateFrom, $dateToEnd, $filters)
            ->groupBy('dprd.product_id')
            ->selectRaw(
                'dprd.product_id,
                 COALESCE(SUM(dprd.harga_bruto), 0) as ret_bruto,
                 COALESCE(SUM(dprd.total_diskon_item), 0) as ret_diskon,
                 COALESCE(SUM(dprd.subtotal), 0) as ret_subtotal,
                 COALESCE(SUM(dprd.qty_in_base), 0) as ret_qty'
            );
    }

    /** ACC-3 Diskon: SUM header disc on purchase returns in range. */
    public static function sumPurchaseReturnDiskonHeader(string $dateFrom, string $dateToEnd, array $filters = []): float
    {
        $q = DB::table('doc_purchase_return as dpr')
            ->whereIn('dpr.status', ['lock', 'approved'])
            ->where('dpr.tanggal', '>=', $dateFrom)
            ->where('dpr.tanggal', '<=', $dateToEnd);

        if (! empty($filters['supplier_id'])) {
            $q->where('dpr.supplier_id', $filters['supplier_id']);
        }

        return (float) $q->selectRaw('COALESCE(SUM(dpr.total_diskon_header), 0) as t')->value('t');
    }

    /**
     * ACC-4: apply terminal_id/source/search filters to a retur penjualan (dsr) query.
     * Shared by disc-line/disc-nota/biaya reduction queries (SalesFinancialReportController + exports).
     *
     * @param  array{terminal_id?: mixed, source?: mixed, search?: mixed}  $filters
     */
    private static function applySalesReturnFilters(\Illuminate\Database\Query\Builder $query, array $filters): void
    {
        if (! empty($filters['terminal_id'])) {
            $query->where('dsr.terminal_id', $filters['terminal_id']);
        }
        if (in_array($filters['source'] ?? null, ['pos', 'manual'], true)) {
            $query->where('dsr.source', $filters['source']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($w) use ($search) {
                $w->where('ds.nomor_dokumen', 'like', "%{$search}%")
                    ->orWhere('dsr.nomor_dokumen', 'like', "%{$search}%");
            });
        }
    }

    /** ACC-4: base filtered query for disc-line retur (proportional line disc on returned qty). */
    public static function salesDiscLineReturnQuery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('doc_sales_return_detail as dsrd')
            ->join('doc_sales_returns as dsr', 'dsr.id', '=', 'dsrd.return_id')
            ->join('doc_sales_detail as dsd', 'dsd.id', '=', 'dsrd.sales_detail_id')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsr.sales_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where('ds.status', 'completed')
            ->where('dsr.tanggal', '>=', $dateFrom)
            ->where('dsr.tanggal', '<=', $dateToEnd);

        self::applySalesReturnFilters($q, $filters);

        return $q;
    }

    /** ACC-4: blanket SUM disc-line retur — used by discLine summary. */
    public static function salesDiscLineReturnTotal(string $dateFrom, string $dateToEnd, array $filters = []): float
    {
        return (float) self::salesDiscLineReturnQuery($dateFrom, $dateToEnd, $filters)->selectRaw(
            'COALESCE(SUM(CASE WHEN dsd.qty > 0 THEN (dsrd.qty / dsd.qty) * dsd.diskon_total ELSE 0 END), 0) as t'
        )->value('t');
    }

    /** ACC-4 Wave B: disc-line retur grouped by nota (dsr.sales_id) — LEFT JOIN onto discLine list/export. */
    public static function salesDiscLineReturnByNotaSubquery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        return self::salesDiscLineReturnQuery($dateFrom, $dateToEnd, $filters)
            ->groupBy('dsr.sales_id')
            ->selectRaw(
                'dsr.sales_id,
                 COALESCE(SUM(CASE WHEN dsd.qty > 0 THEN (dsrd.qty / dsd.qty) * dsd.diskon_total ELSE 0 END), 0) as ret_disc'
            );
    }

    /** ACC-4: base filtered query for disc-nota / biaya retur (per return header). */
    public static function salesNotaReturnQuery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('doc_sales_returns as dsr')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsr.sales_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where('ds.status', 'completed')
            ->where('dsr.tanggal', '>=', $dateFrom)
            ->where('dsr.tanggal', '<=', $dateToEnd);

        self::applySalesReturnFilters($q, $filters);

        return $q;
    }

    /** ACC-4: blanket SUM disc-nota retur (linked only) — used by discNota summary. */
    public static function salesDiscNotaReturnTotal(string $dateFrom, string $dateToEnd, array $filters = []): float
    {
        return (float) self::salesNotaReturnQuery($dateFrom, $dateToEnd, $filters)->selectRaw(
            'COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (ds.total_diskon / ds.grand_total) ELSE 0 END), 0) as t'
        )->value('t');
    }

    /** ACC-4 Wave B: disc-nota retur grouped by nota (dsr.sales_id) — LEFT JOIN onto discNota list/export. */
    public static function salesDiscNotaReturnByNotaSubquery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        return self::salesNotaReturnQuery($dateFrom, $dateToEnd, $filters)
            ->groupBy('dsr.sales_id')
            ->selectRaw(
                'dsr.sales_id,
                 COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (ds.total_diskon / ds.grand_total) ELSE 0 END), 0) as ret_disc'
            );
    }

    /**
     * ACC-4: blanket SUM biaya kirim/lain retur (linked only) — used by biaya summary.
     *
     * @return array{0: float, 1: float} [kirim, lain]
     */
    public static function salesBiayaReturnTotals(string $dateFrom, string $dateToEnd, array $filters = []): array
    {
        $row = self::salesNotaReturnQuery($dateFrom, $dateToEnd, $filters)->selectRaw(
            'COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (COALESCE(ds.biaya_kirim_hasil, 0) / ds.grand_total) ELSE 0 END), 0) as kirim,
             COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (COALESCE(ds.biaya_lain_hasil, 0) / ds.grand_total) ELSE 0 END), 0) as lain'
        )->first();

        return [(float) ($row->kirim ?? 0), (float) ($row->lain ?? 0)];
    }

    /** ACC-4 Wave B: biaya retur grouped by nota (dsr.sales_id) — LEFT JOIN onto biaya list/export. */
    public static function salesBiayaReturnByNotaSubquery(string $dateFrom, string $dateToEnd, array $filters = []): \Illuminate\Database\Query\Builder
    {
        return self::salesNotaReturnQuery($dateFrom, $dateToEnd, $filters)
            ->groupBy('dsr.sales_id')
            ->selectRaw(
                'dsr.sales_id,
                 COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (COALESCE(ds.biaya_kirim_hasil, 0) / ds.grand_total) ELSE 0 END), 0) as ret_kirim,
                 COALESCE(SUM(CASE WHEN ds.grand_total > 0 THEN dsr.grand_total * (COALESCE(ds.biaya_lain_hasil, 0) / ds.grand_total) ELSE 0 END), 0) as ret_lain'
            );
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
        return "(SELECT COALESCE(SUM(rd.qty_base), 0) FROM doc_sales_return_detail rd INNER JOIN doc_sales_returns r ON r.id = rd.return_id WHERE r.sales_id = {$salesIdExpr} AND r.status IN ('lock', 'approved'))";
    }

    /** Select expressions for receipt status (bought + returned qty). */
    public static function salesReceiptQtySelects(string $salesIdExpr): array
    {
        return [
            DB::raw(self::sqlSalesBoughtBase($salesIdExpr).' as total_bought_base'),
            DB::raw(self::sqlSalesReturnedBase($salesIdExpr).' as total_returned_base'),
        ];
    }

    /**
     * Line revenue after nota-level discount allocation (excludes biaya/PPN/pembulatan).
     * Shared by Per Barang, Gross Profit (S1), Promo Usage.
     */
    public static function salesLineNettExpr(string $detailAlias = 'd', string $salesAlias = 's'): string
    {
        return "{$detailAlias}.jumlah * CASE WHEN {$salesAlias}.subtotal > 0 THEN {$salesAlias}.total_setelah_diskon / {$salesAlias}.subtotal ELSE 1 END";
    }

    /** HPP line = qty_base × hpp snapshot (base UOM). */
    public static function salesLineHppExpr(string $detailAlias = 'd'): string
    {
        return "{$detailAlias}.qty_base * {$detailAlias}.hpp_at_time";
    }

    /** Return HPP — prefer qty_base when populated. */
    public static function returnLineHppExpr(string $detailAlias = 'rd'): string
    {
        return "COALESCE({$detailAlias}.qty_base, {$detailAlias}.qty) * {$detailAlias}.hpp_at_time";
    }

    /** Return line revenue (retail, no nota-proration) — mirrors GrossProfit (S1) return revenue. */
    public static function returnLineRevenueExpr(string $detailAlias = 'rd'): string
    {
        return "{$detailAlias}.harga_satuan * {$detailAlias}.qty";
    }

    /**
     * Line diskon returned, prorated by qty vs the original sales_detail line qty.
     * Requires $returnAlias joined to $salesDetailAlias via sales_detail_id.
     */
    public static function returnLineDiskonProrataExpr(string $returnAlias = 'rd', string $salesDetailAlias = 'sd'): string
    {
        return "CASE WHEN {$salesDetailAlias}.qty > 0 THEN ({$returnAlias}.qty / {$salesDetailAlias}.qty) * {$salesDetailAlias}.diskon_total ELSE 0 END";
    }

    /**
     * Wave B B1.3: retur jual (lock|approved) grouped by kasir (created_by) — for net omzet
     * in Kasir Performance report + export. Default source=pos (backward compat, kasir =
     * operator POS); B3.5 mengizinkan override ('manual'|'all') dari controller.
     */
    public static function salesReturnMoneyByKasirSubquery(string $dateFrom, string $dateToEnd, ?int $terminalId = null, string $source = 'pos'): \Illuminate\Database\Query\Builder
    {
        return DB::table('doc_sales_returns')
            ->when($source !== 'all', fn ($q) => $q->where('source', $source))
            ->whereIn('status', ['lock', 'approved'])
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd)
            ->when($terminalId, fn ($q) => $q->where('terminal_id', $terminalId))
            ->groupBy('created_by')
            ->selectRaw('created_by as user_id, COALESCE(SUM(grand_total), 0) as ret_money');
    }

    /**
     * Wave B B1.3: retur jual (lock|approved) grouped by customer_id — for net omzet
     * in Top Customer report + export.
     */
    public static function salesReturnMoneyByCustomerSubquery(string $dateFrom, string $dateToEnd): \Illuminate\Database\Query\Builder
    {
        return DB::table('doc_sales_returns')
            ->whereIn('status', ['lock', 'approved'])
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(grand_total), 0) as ret_money');
    }
}
