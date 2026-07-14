<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Exports\CashFlowDailyExport;
use App\Exports\CustomerPromoByCustomerExport;
use App\Exports\CustomerPromoByKategoriExport;
use App\Exports\CustomerPromoByTipeExport;
use App\Exports\CustomerPromoSummaryExport;
use App\Exports\DeadStockExport;
use App\Exports\GrossProfitByKategoriExport;
use App\Exports\GrossProfitDailyExport;
use App\Exports\GrossProfitTopProductsExport;
use App\Exports\KasirPerformanceExport;
use App\Exports\MarginPerBarangExport;
use App\Exports\PaymentMethodBreakdownExport;
use App\Exports\ProductPromoByProductExport;
use App\Exports\ProductPromoByPromoExport;
use App\Exports\PromoUsageExport;
use App\Exports\ReturPatternExport;
use App\Exports\TopCustomerExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnalyticReportExportController extends BaseApiController
{
    public function grossProfitDaily(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.keuangan') || ! auth()->user()->can('stok.view_hpp')) {
            return $this->forbidden('Export Gross Profit butuh laporan.keuangan + stok.view_hpp.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_gross_profit_harian_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new GrossProfitDailyExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function grossProfitByKategori(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.keuangan') || ! auth()->user()->can('stok.view_hpp')) {
            return $this->forbidden('Export Gross Profit butuh laporan.keuangan + stok.view_hpp.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_gross_profit_kategori_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new GrossProfitByKategoriExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function grossProfitTopProducts(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.keuangan') || ! auth()->user()->can('stok.view_hpp')) {
            return $this->forbidden('Export Gross Profit butuh laporan.keuangan + stok.view_hpp.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), [
            'limit' => 'nullable|integer|min:1|max:100',
        ]));

        $filename = 'laporan_gross_profit_top_produk_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new GrossProfitTopProductsExport(
            $request->date_from,
            $request->date_to,
            (int) $request->input('limit', 10),
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function marginPerBarang(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.keuangan') || ! auth()->user()->can('stok.view_hpp')) {
            return $this->forbidden('Export margin butuh laporan.keuangan + stok.view_hpp.');
        }

        $request->validate([
            'price_field' => 'nullable|in:harga_1,harga_2,harga_3,harga_4',
        ]);

        $filename = 'laporan_margin_per_barang_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new MarginPerBarangExport(
            $request->input('price_field', 'harga_4'),
        ), $filename);
    }

    public function kasirPerformance(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Export performance kasir butuh laporan.performa.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_kasir_performance_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new KasirPerformanceExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function cashFlowDaily(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.keuangan')) {
            return $this->forbidden('Export arus kas butuh laporan.keuangan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_arus_kas_harian_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CashFlowDailyExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function deadStock(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.inventory')) {
            return $this->forbidden('Export dead stock butuh laporan.inventory.');
        }

        $request->validate([
            'min_days_idle' => 'nullable|integer|min:1|max:3650',
            'include_never_sold' => 'nullable|boolean',
            'kategori_id' => 'nullable|integer',
            'grup_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'min_stock' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:days_desc,value_desc,qty_desc',
        ]);

        $canViewHpp = auth()->user()->can('stok.view_hpp');
        $filename = 'laporan_dead_stock_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new DeadStockExport([
            'min_days_idle' => $request->input('min_days_idle', 60),
            'include_never_sold' => $request->boolean('include_never_sold', true),
            'kategori_id' => $request->input('kategori_id'),
            'grup_id' => $request->input('grup_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'status' => $request->input('status'),
            'min_stock' => $request->input('min_stock', 0.01),
            'sort' => $request->input('sort', 'days_desc'),
        ], $canViewHpp), $filename);
    }

    public function promoUsage(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export promo usage butuh laporan.promo.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), [
            'sort' => 'nullable|in:diskon_desc,diskon_asc,trx_desc,revenue_desc',
            'include_unused' => 'nullable|boolean',
        ]));

        $filename = 'laporan_promo_usage_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new PromoUsageExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->boolean('include_unused'),
            $request->input('sort', 'diskon_desc'),
        ), $filename);
    }

    public function topCustomer(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Export top customer butuh laporan.performa.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), [
            'limit' => 'nullable|integer|min:1|max:200',
            'sort' => 'nullable|in:omzet_desc,trx_desc,avg_desc,last_desc',
        ]));

        $filename = 'laporan_top_customer_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new TopCustomerExport(
            $request->date_from,
            $request->date_to,
            (int) $request->input('limit', 50),
            $request->input('sort', 'omzet_desc'),
        ), $filename);
    }

    public function paymentMethodBreakdown(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Export metode pembayaran butuh laporan.performa.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_metode_pembayaran_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new PaymentMethodBreakdownExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
        ), $filename);
    }

    public function returPattern(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.inventory')) {
            return $this->forbidden('Export retur pattern butuh laporan.inventory.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), [
            'kategori_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:200',
            'sort' => 'nullable|in:count_desc,qty_desc,nominal_desc',
        ]));

        $filename = 'laporan_retur_pattern_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new ReturPatternExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            (int) $request->input('limit', 50),
            $request->input('sort', 'count_desc'),
        ), $filename);
    }

    public function productPromoByPromo(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export product promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all,upcoming,expired',
        ]);

        $filename = 'laporan_product_promo_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new ProductPromoByPromoExport(
            $request->input('status', 'active_now'),
        ), $filename);
    }

    public function customerPromoByCustomer(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export customer promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all',
            'tipe_id' => 'nullable|integer',
            'kategori_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'only_terjaring' => 'nullable|boolean',
        ]);

        $filename = 'laporan_customer_promo_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CustomerPromoByCustomerExport(
            $request->input('status', 'active_now'),
            $request->filled('tipe_id') ? (int) $request->tipe_id : null,
            $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            $request->input('search'),
            $request->boolean('only_terjaring'),
        ), $filename);
    }

    public function productPromoByProduct(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export product promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all,upcoming,expired',
            'only_with_promo' => 'nullable|boolean',
        ]);

        $filename = 'laporan_product_promo_per_produk_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new ProductPromoByProductExport(
            $request->input('status', 'active_now'),
            $request->boolean('only_with_promo'),
        ), $filename);
    }

    public function customerPromoByTipe(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export customer promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all',
        ]);

        $filename = 'laporan_customer_promo_per_tipe_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CustomerPromoByTipeExport(
            $request->input('status', 'active_now'),
        ), $filename);
    }

    public function customerPromoByKategori(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export customer promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all',
        ]);

        $filename = 'laporan_customer_promo_per_kategori_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CustomerPromoByKategoriExport(
            $request->input('status', 'active_now'),
        ), $filename);
    }

    public function customerPromoSummary(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        if (! auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Export customer promo butuh laporan.promo.');
        }

        $request->validate([
            'status' => 'nullable|in:active_now,approved_all',
        ]);

        $filename = 'laporan_customer_promo_summary_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CustomerPromoSummaryExport(
            $request->input('status', 'active_now'),
        ), $filename);
    }
}
