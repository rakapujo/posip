<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SupplierHutangExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\SupplierHutang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SupplierHutangController extends BaseApiController
{
    /**
     * Display a listing of hutang.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat hutang supplier.');
        }

        $query = SupplierHutang::with([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'purchaseOrder:id,ulid,nomor_dokumen,tanggal_po',
            'serialIntake:id,ulid,nomor_dokumen,tanggal',
        ]);

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by supplier
        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            if ($request->status === 'outstanding') {
                $query->outstanding();
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by due within X days (not yet overdue)
        if ($request->filled('due_within_days')) {
            if ($request->due_within_days === 'all') {
                // Show all outstanding that are NOT overdue (due date >= today)
                $query->notOverdue();
            } else {
                $query->dueWithinDays((int) $request->due_within_days);
            }
        }

        // Filter by overdue within X days
        if ($request->filled('overdue_within_days')) {
            if ($request->overdue_within_days === 'all') {
                // Show all overdue (due date < today)
                $query->overdue();
            } else {
                $query->overdueWithinDays((int) $request->overdue_within_days);
            }
        }

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['tanggal', 'tanggal_jatuh_tempo', 'nominal_awal', 'sisa_hutang', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $items = $query->paginate($perPage);

        // Check if user can view nominal
        $canViewNominal = auth()->user()->can('hutang.view_nominal');

        // Hide sensitive fields if not allowed
        $transformedItems = collect($items->items())->map(function ($item) use ($canViewNominal) {
            if (!$canViewNominal) {
                $item->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_hutang']);
            }
            return $item;
        });

        return $this->success([
            'items' => $transformedItems,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Display the specified hutang.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat hutang supplier.');
        }

        $hutang = SupplierHutang::with([
            'supplier:id,ulid,kode_supplier,nama_supplier,telepon,alamat',
            'purchaseOrder:id,ulid,nomor_dokumen,tanggal_po,grand_total,notes',
            'purchaseOrder.details:id,po_id,product_id,unit_used,qty_in_unit,qty_in_base,subtotal',
            'purchaseOrder.details.product:id,ulid,kode_produk,nama_produk',
            'serialIntake:id,ulid,nomor_dokumen,tanggal,grand_total,notes,product_id',
            'serialIntake.product:id,ulid,kode_produk,nama_produk',
            'serialIntake.units:id,intake_id,kode_internal,serial_number,cost_per_unit',
        ])->where('ulid', $ulid)->first();

        if (!$hutang) {
            return $this->notFound('Hutang tidak ditemukan.');
        }

        // Check if user can view nominal
        $canViewNominal = auth()->user()->can('hutang.view_nominal');

        if (!$canViewNominal) {
            $hutang->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_hutang']);
            if ($hutang->purchaseOrder) {
                $hutang->purchaseOrder->makeHidden(['grand_total']);
                foreach ($hutang->purchaseOrder->details as $detail) {
                    $detail->makeHidden(['subtotal']);
                }
            }
        }

        return $this->success([
            'hutang' => $hutang,
        ]);
    }

    /**
     * Get summary statistics.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $canViewNominal = auth()->user()->can('hutang.view_nominal');

        $query = SupplierHutang::query();

        // Filter by supplier
        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
        }

        $totalHutang = $canViewNominal ? $query->clone()->sum('sisa_hutang') : null;
        $totalUnpaid = $query->clone()->unpaid()->count();
        $totalPartial = $query->clone()->partial()->count();
        $totalPaid = $query->clone()->paid()->count();
        $totalOverdue = $query->clone()->overdue()->count();
        $totalOverdueAmount = $canViewNominal ? $query->clone()->overdue()->sum('sisa_hutang') : null;

        return $this->success([
            'summary' => [
                'total_hutang' => $totalHutang,
                'total_unpaid' => $totalUnpaid,
                'total_partial' => $totalPartial,
                'total_paid' => $totalPaid,
                'total_overdue' => $totalOverdue,
                'total_overdue_amount' => $totalOverdueAmount,
            ],
        ]);
    }

    /**
     * Aging summary — bucket hutang outstanding berdasarkan umur sejak tanggal_jatuh_tempo.
     *
     * Bucket:
     *  - belum_tempo: tanggal_jatuh_tempo > today atau null
     *  - b1_30: lewat 1-30 hari
     *  - b31_60: 31-60 hari
     *  - b61_90: 61-90 hari
     *  - above_90: >90 hari
     *
     * Hanya hutang status unpaid/partial (punya sisa_hutang > 0) yang dihitung.
     * Butuh permission hutang.view + hutang.view_nominal (karena tampilkan nominal).
     */
    public function agingSummary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        if (!auth()->user()->can('hutang.view_nominal')) {
            return $this->forbidden('Akses aging butuh permission hutang.view_nominal.');
        }

        $today = now()->toDateString();

        $q = \DB::table('supplier_hutang as h')
            ->where('h.sisa_hutang', '>', 0)
            ->when($request->filled('supplier_id'),
                fn ($qq) => $qq->where('h.supplier_id', $request->supplier_id));

        // SQLite-friendly computation — fetch rows lalu bucket di PHP
        $rows = $q->select('h.id', 'h.supplier_id', 'h.sisa_hutang', 'h.tanggal_jatuh_tempo')->get();

        $buckets = [
            'belum_tempo' => ['count' => 0, 'nominal' => 0.0],
            'b1_30' => ['count' => 0, 'nominal' => 0.0],
            'b31_60' => ['count' => 0, 'nominal' => 0.0],
            'b61_90' => ['count' => 0, 'nominal' => 0.0],
            'above_90' => ['count' => 0, 'nominal' => 0.0],
        ];

        foreach ($rows as $h) {
            $sisa = (float) $h->sisa_hutang;
            if (empty($h->tanggal_jatuh_tempo)) {
                $buckets['belum_tempo']['count']++;
                $buckets['belum_tempo']['nominal'] += $sisa;
                continue;
            }

            $daysOverdue = now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($h->tanggal_jatuh_tempo)->startOfDay(),
                absolute: false
            );
            // diffInDays returns NEGATIVE kalau target (tanggal_jatuh_tempo) di masa depan
            // (karena now() > future). Jadi:
            //   daysOverdue < 0 → belum tempo
            //   daysOverdue >= 0 → umur overdue (semakin besar semakin lama)
            $overdue = (int) (-$daysOverdue); // flip: days past due (positif = overdue)

            if ($overdue <= 0) {
                $buckets['belum_tempo']['count']++;
                $buckets['belum_tempo']['nominal'] += $sisa;
            } elseif ($overdue <= 30) {
                $buckets['b1_30']['count']++;
                $buckets['b1_30']['nominal'] += $sisa;
            } elseif ($overdue <= 60) {
                $buckets['b31_60']['count']++;
                $buckets['b31_60']['nominal'] += $sisa;
            } elseif ($overdue <= 90) {
                $buckets['b61_90']['count']++;
                $buckets['b61_90']['nominal'] += $sisa;
            } else {
                $buckets['above_90']['count']++;
                $buckets['above_90']['nominal'] += $sisa;
            }
        }

        $total = collect($buckets)->sum('nominal');
        $totalCount = collect($buckets)->sum('count');

        return $this->success([
            'today' => $today,
            'total_hutang_outstanding' => round($total, 2),
            'total_count' => $totalCount,
            'buckets' => collect($buckets)->map(fn ($b) => [
                'count' => $b['count'],
                'nominal' => round($b['nominal'], 2),
                'percent' => $total > 0 ? round(($b['nominal'] / $total) * 100, 2) : 0,
            ])->all(),
        ]);
    }

    /**
     * Export hutang to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $canViewNominal = auth()->user()->can('hutang.view_nominal');

        $filename = 'hutang_supplier_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new SupplierHutangExport(
            canViewNominal: $canViewNominal,
            supplierId: $request->filled('supplier_id') ? (int) $request->supplier_id : null,
            status: $request->input('status'),
            dueWithinDays: $request->input('due_within_days'),
            overdueWithinDays: $request->input('overdue_within_days'),
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            search: $request->input('search'),
        ), $filename);
    }

    /**
     * Get hutang for a specific supplier (for dropdown/selection).
     */
    public function bySupplier(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
        ]);

        $hutangs = SupplierHutang::with([
                'purchaseOrder:id,ulid,nomor_dokumen,tanggal_po',
            ])
            ->bySupplier($request->supplier_id)
            ->outstanding()
            ->orderBy('tanggal_jatuh_tempo')
            ->get();

        // Check if user can view nominal
        $canViewNominal = auth()->user()->can('hutang.view_nominal');

        if (!$canViewNominal) {
            $hutangs->each(function ($hutang) {
                $hutang->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_hutang']);
            });
        }

        return $this->success([
            'items' => $hutangs,
        ]);
    }
}
