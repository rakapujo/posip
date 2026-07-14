<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocAdjustment;
use App\Models\DocHppCorrection;
use App\Models\DocPurchaseOrder;
use App\Models\DocRepack;
use App\Models\DocSales;
use App\Models\DocSerialChange;
use App\Models\DocSerialHppCorrection;
use App\Models\DocSerialIntake;
use App\Models\DocStockOpname;
use App\Models\DocTransfer;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Services\SettingService;
use App\Models\SupplierHutang;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseApiController
{
    /**
     * Get dashboard data based on user permissions.
     * Each section is only included if the user has the required permission.
     */
    public function index()
    {
        $user = auth()->user();
        $data = [];
        $today = Carbon::today();

        // Sales Today — laporan.view (single query for count + sum)
        if ($user->can('laporan.view')) {
            $salesToday = DocSales::whereDate('tanggal', $today)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as cnt, SUM(grand_total) as total')
                ->first();

            $salesData = ['count' => (int) $salesToday->cnt];

            // Omzet only if user can view HPP (financial data)
            if ($user->can('stok.view_hpp')) {
                $salesData['omzet'] = (float) ($salesToday->total ?? 0);
            }

            $data['sales_today'] = $salesData;
        }

        // Products — produk.view
        if ($user->can('produk.view')) {
            $data['products'] = [
                'total_active' => MasterProduk::where('status', 'active')->count(),
            ];
        }

        // Stock — stok.view
        if ($user->can('stok.view')) {
            $data['stock'] = [
                'low_stock_count' => InventoryStock::whereHas('product', function ($q) {
                    $q->where('status', 'active');
                })->whereHas('warehouse', function ($q) {
                    $q->where('status', 'active');
                })->whereRaw('inventory_stock.qty < (SELECT minimum_stok FROM master_produk WHERE master_produk.id = inventory_stock.product_id)')
                ->where('qty', '>', 0)
                ->count(),
            ];
        }

        // Hutang — hutang.view_nominal
        if ($user->can('hutang.view_nominal')) {
            $data['hutang'] = [
                'total' => (float) SupplierHutang::where('status', '!=', 'paid')->sum('sisa_hutang'),
            ];
        }

        // PO Pending — po.view
        if ($user->can('po.view')) {
            $data['po_pending'] = DocPurchaseOrder::where('status', 'draft')->count();
        }

        // Pending Approval — check each *.approve permission
        $approvalModules = [
            'adjustment' => ['model' => DocAdjustment::class, 'permission' => 'adjustment.approve'],
            'transfer' => ['model' => DocTransfer::class, 'permission' => 'transfer.approve'],
            'opname' => ['model' => DocStockOpname::class, 'permission' => 'opname.approve'],
            'repack' => ['model' => DocRepack::class, 'permission' => 'repack.approve'],
            'hpp' => ['model' => DocHppCorrection::class, 'permission' => 'hpp.approve'],
            'po' => ['model' => DocPurchaseOrder::class, 'permission' => 'po.approve'],
            'serial_intake' => ['model' => DocSerialIntake::class, 'permission' => 'serial-intake.approve'],
            'serial_change' => ['model' => DocSerialChange::class, 'permission' => 'serial-change.approve'],
            'serial_hpp' => ['model' => DocSerialHppCorrection::class, 'permission' => 'serial-hpp.approve'],
        ];

        // Modul Elektronik nonaktif → jangan tampilkan kartu/approval serial di dashboard.
        if (!SettingService::isElektronikEnabled()) {
            unset($approvalModules['serial_intake'], $approvalModules['serial_change'], $approvalModules['serial_hpp']);
        }

        $pendingApproval = [];
        foreach ($approvalModules as $key => $config) {
            if ($user->can($config['permission'])) {
                $count = $config['model']::where('status', 'draft')->count();
                if ($count > 0) {
                    $pendingApproval[$key] = $count;
                }
            }
        }
        if (!empty($pendingApproval)) {
            $data['pending_approval'] = $pendingApproval;
        }

        // Sales Chart (7 days) — laporan.view (single GROUP BY query)
        if ($user->can('laporan.view')) {
            $canViewFinancial = $user->can('stok.view_hpp');
            $startDate = $today->copy()->subDays(6);

            // Single query: count + sum grouped by date
            $chartRows = DocSales::where('status', 'completed')
                ->whereDate('tanggal', '>=', $startDate)
                ->whereDate('tanggal', '<=', $today)
                ->selectRaw('DATE(tanggal) as sale_date, COUNT(*) as cnt, SUM(grand_total) as total')
                ->groupByRaw('DATE(tanggal)')
                ->get()
                ->keyBy('sale_date');

            $salesChart = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i);
                $dateKey = $date->format('Y-m-d');
                $row = $chartRows[$dateKey] ?? null;

                $entry = [
                    'date' => $dateKey,
                    'label' => $date->format('d/m'),
                    'count' => $row ? (int) $row->cnt : 0,
                ];

                if ($canViewFinancial) {
                    $entry['total'] = $row ? (float) $row->total : 0;
                }

                $salesChart[] = $entry;
            }

            $data['sales_chart'] = $salesChart;
        }

        // Payment Methods Chart — laporan.view + stok.view_hpp
        if ($user->can('laporan.view') && $user->can('stok.view_hpp')) {
            $data['payment_methods'] = DB::table('doc_sales_payments')
                ->join('doc_sales', 'doc_sales.id', '=', 'doc_sales_payments.sales_id')
                ->join('master_metode_pembayaran', 'master_metode_pembayaran.id', '=', 'doc_sales_payments.metode_pembayaran_id')
                ->where('doc_sales.status', 'completed')
                ->whereDate('doc_sales.tanggal', '>=', $today->copy()->subDays(6))
                ->groupBy('doc_sales_payments.metode_pembayaran_id', 'master_metode_pembayaran.nama_pembayaran')
                ->select(
                    'master_metode_pembayaran.nama_pembayaran as label',
                    DB::raw('SUM(doc_sales_payments.nominal) as total')
                )
                ->orderByDesc('total')
                ->get()
                ->map(fn ($item) => [
                    'label' => $item->label,
                    'total' => (float) $item->total,
                ])
                ->toArray();
        }

        // Top 5 Products (7 days) — laporan.view
        if ($user->can('laporan.view')) {
            $data['top_products'] = DB::table('doc_sales_detail')
                ->join('doc_sales', 'doc_sales.id', '=', 'doc_sales_detail.sales_id')
                ->join('master_produk', 'master_produk.id', '=', 'doc_sales_detail.product_id')
                ->where('doc_sales.status', 'completed')
                ->whereDate('doc_sales.tanggal', '>=', $today->copy()->subDays(6))
                ->groupBy('doc_sales_detail.product_id', 'master_produk.kode_produk', 'master_produk.nama_produk')
                ->select(
                    'master_produk.kode_produk as kode',
                    'master_produk.nama_produk as nama',
                    DB::raw('SUM(doc_sales_detail.qty_base) as total_qty')
                )
                ->orderByDesc('total_qty')
                ->limit(5)
                ->get()
                ->map(fn ($item) => [
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'total_qty' => (float) $item->total_qty,
                ])
                ->toArray();
        }

        // Recent Sales (5 latest) — laporan.view
        if ($user->can('laporan.view')) {
            $recentQuery = DocSales::with('customer:id,nama')
                ->orderByDesc('tanggal')
                ->orderByDesc('created_at')
                ->limit(5);

            $recentSales = $recentQuery->get()->map(function ($sale) use ($user) {
                $item = [
                    'nomor' => $sale->nomor_dokumen,
                    'tanggal' => $sale->tanggal->format('Y-m-d H:i'),
                    'customer' => $sale->customer?->nama ?? '-',
                    'status' => $sale->status,
                ];

                if ($user->can('stok.view_hpp')) {
                    $item['grand_total'] = (float) $sale->grand_total;
                }

                return $item;
            })->toArray();

            $data['recent_sales'] = $recentSales;
        }

        // Low Stock Items (top 10) — stok.view
        if ($user->can('stok.view')) {
            $data['low_stock_items'] = DB::table('inventory_stock')
                ->join('master_produk', 'master_produk.id', '=', 'inventory_stock.product_id')
                ->join('master_warehouse', 'master_warehouse.id', '=', 'inventory_stock.warehouse_id')
                ->where('master_produk.status', 'active')
                ->where('master_warehouse.status', 'active')
                ->whereRaw('inventory_stock.qty < master_produk.minimum_stok')
                ->where('inventory_stock.qty', '>', 0)
                ->select(
                    'master_produk.kode_produk as kode',
                    'master_produk.nama_produk as nama',
                    'master_warehouse.nama_warehouse as warehouse',
                    DB::raw('CAST(inventory_stock.qty AS DECIMAL(15,2)) as qty'),
                    'master_produk.minimum_stok as minimum'
                )
                ->orderByRaw('(inventory_stock.qty / master_produk.minimum_stok) ASC')
                ->limit(10)
                ->get()
                ->map(fn ($item) => [
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'warehouse' => $item->warehouse,
                    'qty' => (float) $item->qty,
                    'minimum' => (int) $item->minimum,
                ])
                ->toArray();
        }

        // Pending Approval Items (top 10) — *.approve
        if (!empty($pendingApproval)) {
            $pendingItems = [];

            $moduleModels = [
                'adjustment' => ['model' => DocAdjustment::class, 'label' => 'Adjustment'],
                'transfer' => ['model' => DocTransfer::class, 'label' => 'Transfer'],
                'opname' => ['model' => DocStockOpname::class, 'label' => 'Stock Opname'],
                'repack' => ['model' => DocRepack::class, 'label' => 'Repack'],
                'hpp' => ['model' => DocHppCorrection::class, 'label' => 'HPP Correction'],
                'po' => ['model' => DocPurchaseOrder::class, 'label' => 'Purchase Order'],
            ];

            foreach ($moduleModels as $key => $config) {
                if (!isset($pendingApproval[$key])) continue;

                $items = $config['model']::where('status', 'draft')
                    ->orderByDesc('created_at')
                    ->limit(3)
                    ->get(['nomor_dokumen', 'created_at'])
                    ->map(fn ($item) => [
                        'module' => $key,
                        'label' => $config['label'],
                        'nomor' => $item->nomor_dokumen,
                        'tanggal' => $item->created_at->format('Y-m-d'),
                    ])
                    ->toArray();

                $pendingItems = array_merge($pendingItems, $items);
            }

            // Sort by date desc and take top 10
            usort($pendingItems, fn ($a, $b) => strcmp($b['tanggal'], $a['tanggal']));
            $data['pending_items'] = array_slice($pendingItems, 0, 10);
        }

        return $this->success($data);
    }
}
