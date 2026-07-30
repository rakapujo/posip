<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomerPiutangExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\CustomerPiutang;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CustomerPiutangController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('piutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat piutang.');
        }

        $query = CustomerPiutang::with([
            'customer:id,ulid,kode_customer,nama',
            'sales:id,ulid,nomor_dokumen,tanggal',
        ]);
        $this->applyFilters($query, $request);

        $sort = in_array($request->input('sort_field'), [
            'tanggal', 'tanggal_jatuh_tempo', 'nominal_awal', 'sisa_piutang', 'created_at',
        ], true) ? $request->input('sort_field') : 'tanggal';
        $order = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $items = $query->orderBy($sort, $order)->paginate($this->getPerPage($request, 15));
        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $rows = collect($items->items());
        if (! $canViewNominal) {
            $rows->each->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
        }

        return $this->success([
            'items' => $rows,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('piutang.view')) {
            return $this->forbidden();
        }

        $piutang = CustomerPiutang::with([
            'customer:id,ulid,kode_customer,nama,telepon,alamat',
            'sales:id,ulid,nomor_dokumen,tanggal,grand_total,notes',
            'sales.details:id,sales_id,product_id,unit,qty,jumlah',
            'sales.details.product:id,ulid,kode_produk,nama_produk',
            'paymentDetails.pembayaran:id,ulid,nomor_dokumen,tanggal,status',
        ])->where('ulid', $ulid)->first();
        if (! $piutang) {
            return $this->notFound('Piutang tidak ditemukan.');
        }
        if (! auth()->user()->can('piutang.view_nominal')) {
            $piutang->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
            $piutang->sales?->makeHidden(['grand_total']);
            $piutang->sales?->details->each->makeHidden(['jumlah']);
            $piutang->paymentDetails->each->makeHidden(['nominal_dibayar']);
        }

        return $this->success(['piutang' => $piutang]);
    }

    public function summary(Request $request): JsonResponse
    {
        if (! auth()->user()->can('piutang.view')) {
            return $this->forbidden();
        }

        $query = CustomerPiutang::query();
        $this->applyFilters($query, $request);
        $nominal = auth()->user()->can('piutang.view_nominal');

        return $this->success(['summary' => [
            'total_piutang' => $nominal ? (float) (clone $query)->sum('sisa_piutang') : null,
            'total_unpaid' => (clone $query)->unpaid()->count(),
            'total_partial' => (clone $query)->partial()->count(),
            'total_paid' => (clone $query)->paid()->count(),
            'total_overdue' => (clone $query)->overdue()->count(),
            'total_overdue_amount' => $nominal ? (float) (clone $query)->overdue()->sum('sisa_piutang') : null,
        ]]);
    }

    public function agingSummary(Request $request): JsonResponse
    {
        if (! auth()->user()->can('piutang.view') || ! auth()->user()->can('piutang.view_nominal')) {
            return $this->forbidden('Akses aging membutuhkan permission piutang.view_nominal.');
        }

        $query = CustomerPiutang::query()->select('sisa_piutang', 'tanggal_jatuh_tempo')
            ->outstanding()->where('sisa_piutang', '>', 0);
        $this->applyFilters($query, $request, includeAgingBucket: false);
        $rows = $query->get();
        $buckets = collect(['belum_tempo', 'b1_30', 'b31_60', 'b61_90', 'above_90'])
            ->mapWithKeys(fn ($key) => [$key => ['count' => 0, 'nominal' => 0.0]])->all();

        foreach ($rows as $row) {
            $days = $row->tanggal_jatuh_tempo && Carbon::parse($row->tanggal_jatuh_tempo)->isBefore(now()->startOfDay())
                ? Carbon::parse($row->tanggal_jatuh_tempo)->startOfDay()->diffInDays(now()->startOfDay())
                : 0;
            $key = $days === 0 ? 'belum_tempo'
                : ($days <= 30 ? 'b1_30' : ($days <= 60 ? 'b31_60' : ($days <= 90 ? 'b61_90' : 'above_90')));
            $buckets[$key]['count']++;
            $buckets[$key]['nominal'] += (float) $row->sisa_piutang;
        }
        $total = collect($buckets)->sum('nominal');

        return $this->success([
            'today' => now()->toDateString(),
            'total_piutang_outstanding' => round($total, 2),
            'total_count' => collect($buckets)->sum('count'),
            'buckets' => collect($buckets)->map(fn ($bucket) => [
                'count' => $bucket['count'],
                'nominal' => round($bucket['nominal'], 2),
                'percent' => $total > 0 ? round($bucket['nominal'] / $total * 100, 2) : 0,
            ])->all(),
        ]);
    }

    public function export(Request $request)
    {
        if (! auth()->user()->can('piutang.view') || ! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export piutang customer.');
        }

        return Excel::download(
            new CustomerPiutangExport(auth()->user()->can('piutang.view_nominal'), $request->all()),
            'piutang_customer_'.date('Y-m-d_His').'.xlsx',
        );
    }

    private function applyFilters($query, Request $request, bool $includeAgingBucket = true): void
    {
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('customer_id')) {
            $query->byCustomer((int) $request->customer_id);
        }
        if ($request->filled('status')) {
            $request->status === 'outstanding' ? $query->outstanding() : $query->where('status', $request->status);
        }
        if ($request->filled('due_within_days')) {
            $request->due_within_days === 'all' ? $query->notOverdue() : $query->dueWithinDays((int) $request->due_within_days);
        }
        if ($request->filled('overdue_within_days')) {
            $request->overdue_within_days === 'all' ? $query->overdue() : $query->overdueWithinDays((int) $request->overdue_within_days);
        }
        if ($includeAgingBucket && $request->filled('aging_bucket')) {
            $allowed = ['belum_tempo', 'b1_30', 'b31_60', 'b61_90', 'above_90'];
            if (in_array($request->aging_bucket, $allowed, true)) {
                $query->agingBucket($request->aging_bucket);
            }
        }
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }
    }
}
