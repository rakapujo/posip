<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesPerNotaExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnits;
use App\Models\DocSales;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\User;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SalesReportController extends BaseApiController
{
    use AttachesSerialUnits;

    /**
     * List all sales (paginated, with filters).
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan penjualan.');
        }

        $query = DocSales::with([
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'createdBy:id,name',
            'customer:id,ulid,nama',
            'payments.metodePembayaran:id,ulid,nama_pembayaran',
        ])
        ->addSelect(['doc_sales.*'])
        ->addSelect(ReportHelperService::salesReceiptQtySelects('doc_sales.id'));

        // Search by nomor_dokumen or customer nama
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nomor_dokumen', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('nama', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'voided') {
                $query->where('status', 'voided');
            } elseif ($status === 'completed') {
                // Only truly completed (no returns)
                $query->where('status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('doc_sales.id') . ' = 0');
            }
            // retur_partial and retur_full are handled below
        }

        // Filter by terminal
        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->terminal_id);
        }

        // Filter by kasir (created_by)
        if ($request->filled('user_id')) {
            $query->where('created_by', $request->user_id);
        }

        // Filter by metode pembayaran
        if ($request->filled('metode_bayar_id')) {
            $query->whereHas('payments', function ($pq) use ($request) {
                $pq->where('metode_pembayaran_id', $request->metode_bayar_id);
            });
        }

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['nomor_dokumen', 'tanggal', 'grand_total', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        // Secondary sort for consistent ordering
        if ($sortField !== 'created_at') {
            $query->orderBy('created_at', 'desc');
        }

        // Filter by receipt_status (computed field)
        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'retur_partial') {
                $query->where('status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('doc_sales.id') . ' > 0')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('doc_sales.id') . ' < ' . ReportHelperService::sqlSalesBoughtBase('doc_sales.id'));
            } elseif ($status === 'retur_full') {
                $query->where('status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('doc_sales.id') . ' >= ' . ReportHelperService::sqlSalesBoughtBase('doc_sales.id'))
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('doc_sales.id') . ' > 0');
            }
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $sales = $query->paginate($perPage);

        // Compute receipt_status for each item
        $sales->getCollection()->transform(function ($item) {
            if ($item->status === 'voided') {
                $item->receipt_status = 'voided';
            } elseif ($item->total_returned_base > 0 && $item->total_returned_base >= $item->total_bought_base) {
                $item->receipt_status = 'retur_full';
            } elseif ($item->total_returned_base > 0) {
                $item->receipt_status = 'retur_partial';
            } else {
                $item->receipt_status = 'completed';
            }
            // Remove helper fields from response
            unset($item->total_bought_base, $item->total_returned_base);
            return $item;
        });

        return $this->success([
            'items' => $sales->items(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
            ],
        ]);
    }

    /**
     * Show sales detail for dialog.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan penjualan.');
        }

        $sales = DocSales::with([
            'details.product:id,ulid,kode_produk,nama_produk',
            'payments.metodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran',
            'customer:id,ulid,kode_customer,nama,telepon',
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'createdBy:id,name',
            'voidedBy:id,name',
            'returns.details.product:id,ulid,kode_produk,nama_produk',
            'returns.createdBy:id,name',
            'returns.terminal:id,ulid,kode_terminal,nama_terminal',
            'returns.shift:id,ulid,started_at',
        ])->where('ulid', $ulid)->first();

        if (!$sales) {
            return $this->notFound('Transaksi tidak ditemukan.');
        }

        $this->attachSerialUnitsToSale($sales);

        // Compute receipt_status
        $totalBuyBase = $sales->details->sum('qty_base');
        $totalReturnBase = $sales->returns->flatMap->details->sum('qty_base');

        if ($sales->status === 'voided') {
            $sales->receipt_status = 'voided';
        } elseif ($totalReturnBase > 0 && $totalReturnBase >= $totalBuyBase) {
            $sales->receipt_status = 'retur_full';
        } elseif ($totalReturnBase > 0) {
            $sales->receipt_status = 'retur_partial';
        } else {
            $sales->receipt_status = 'completed';
        }

        return $this->success([
            'sales' => $sales,
        ]);
    }

    /**
     * Export sales per nota to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        // Default to current month if no dates provided
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo = $request->date_to ?? now()->toDateString();

        $filename = 'laporan_penjualan_per_nota_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new SalesPerNotaExport(
            $dateFrom,
            $dateTo,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->filled('user_id') ? (int) $request->user_id : null,
            $request->filled('metode_bayar_id') ? (int) $request->metode_bayar_id : null,
            $request->input('status'),
            $request->input('search'),
        ), $filename);
    }

    /**
     * Get dropdown data for filters.
     */
    public function dropdowns(): JsonResponse
    {
        if (!auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        // Terminals that have sales
        $terminals = MasterPosTerminal::select('id', 'kode_terminal', 'nama_terminal')
            ->whereHas('shifts.sales')
            ->orderBy('kode_terminal')
            ->get()
            ->makeVisible('id');

        // Users who have created sales
        $userIds = DocSales::distinct()->pluck('created_by');
        $users = User::select('id', 'name')
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get()
            ->makeVisible('id');

        // Active payment methods
        $metodeBayar = MasterMetodePembayaran::select('id', 'nama_pembayaran')
            ->active()
            ->orderBy('nama_pembayaran')
            ->get()
            ->makeVisible('id');

        return $this->success([
            'terminals' => $terminals,
            'users' => $users,
            'metode_bayar' => $metodeBayar,
        ]);
    }
}
