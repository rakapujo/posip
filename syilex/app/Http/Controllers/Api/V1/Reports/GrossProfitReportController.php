<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Reports\GrossProfitReportResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gross Profit = Revenue - HPP (S1): revenue = NETT_EXPR − retur; HPP = qty_base × hpp_at_time − retur HPP.
 *
 * Semua endpoint butuh permission `laporan.keuangan` + `stok.view_hpp`.
 * Query logic (single path) tinggal di GrossProfitReportResolver — dipakai juga oleh export.
 */
class GrossProfitReportController extends BaseApiController
{
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $row = GrossProfitReportResolver::summaryRow($from, $to, $this->parseFilters($request));

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'revenue_gross' => $row->revenue_gross,
            'revenue_return' => $row->revenue_return,
            'revenue_net' => $row->revenue_net,
            'hpp_gross' => $row->hpp_gross,
            'hpp_return' => $row->hpp_return,
            'hpp_net' => $row->hpp_net,
            'gross_profit' => $row->gross_profit,
            'margin_percent' => $row->margin_percent,
            'trx_count' => $row->trx_count,
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $rows = GrossProfitReportResolver::dailyRows($from, $to, $this->parseFilters($request));

        return $this->success(['items' => $rows->values()]);
    }

    public function byKategori(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $rows = GrossProfitReportResolver::byKategoriRows($from, $to, $this->parseFilters($request));

        return $this->success(['items' => $rows->values()]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $limit = max(1, min(100, (int) $request->input('limit', 10)));
        $rows = GrossProfitReportResolver::topProductRows($from, $to, $limit, $this->parseFilters($request));

        return $this->success(['items' => $rows, 'limit' => $limit]);
    }

    private function authorizeView(): ?JsonResponse
    {
        $user = auth()->user();
        if (! $user->can('laporan.keuangan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        if (! $user->can('stok.view_hpp')) {
            return $this->forbidden('Laporan Gross Profit butuh permission stok.view_hpp.');
        }

        return null;
    }

    private function parsePeriod(Request $request): array
    {
        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());

        return [$from, $to];
    }

    private function parseFilters(Request $request): array
    {
        return [
            'terminal_id' => $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            'kategori_id' => $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
        ];
    }
}
