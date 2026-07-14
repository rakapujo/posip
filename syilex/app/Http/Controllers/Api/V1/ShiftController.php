<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\PosTerminalShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends BaseApiController
{
    /**
     * Display a listing of shifts (read-only).
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('terminal.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = PosTerminalShift::with([
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'user:id,ulid,name',
            'forcedByUser:id,ulid,name',
        ]);

        // Search by terminal kode/nama or user name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('terminal', function ($tq) use ($search) {
                    $tq->where('kode_terminal', 'like', "%{$search}%")
                       ->orWhere('nama_terminal', 'like', "%{$search}%");
                })->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%");
                });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'active':
                    $query->whereNull('ended_at');
                    break;
                case 'ended':
                    $query->whereNotNull('ended_at')->where('ended_by_force', false);
                    break;
                case 'forced':
                    $query->whereNotNull('ended_at')->where('ended_by_force', true);
                    break;
            }
        }

        // Date range filter on started_at
        if ($request->filled('start_date')) {
            $query->where('started_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('started_at', '<=', $request->end_date . ' 23:59:59');
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['started_at', 'ended_at', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'started_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('started_at', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $shifts = $query->paginate($perPage);

        return $this->success([
            'shifts' => $shifts->items(),
            'pagination' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    /**
     * Daily summary: agregasi shift per tanggal + terminal.
     * Metric: jumlah shift, total omzet (completed sales), total selisih.
     *
     * Permission: terminal.view.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('terminal.view')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'terminal_id' => 'nullable|integer',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());

        // Aggregate shift per (tanggal, terminal)
        $shiftsQ = DB::table('pos_terminal_shifts as sh')
            ->leftJoin('master_pos_terminal as t', 't.id', '=', 'sh.terminal_id')
            ->leftJoin('users as u', 'u.id', '=', 'sh.user_id')
            ->whereBetween(DB::raw('DATE(sh.started_at)'), [$from, $to])
            ->when($request->filled('terminal_id'),
                fn ($q) => $q->where('sh.terminal_id', $request->terminal_id))
            ->select(
                DB::raw('DATE(sh.started_at) as tanggal'),
                't.id as terminal_id',
                't.kode_terminal',
                't.nama_terminal',
                DB::raw('COUNT(*) as shift_count'),
                DB::raw('SUM(CASE WHEN sh.ended_by_force = 1 THEN 1 ELSE 0 END) as shift_paksa_count'),
                DB::raw('COALESCE(SUM(sh.selisih), 0) as total_selisih')
            )
            ->groupBy(DB::raw('DATE(sh.started_at)'), 't.id', 't.kode_terminal', 't.nama_terminal')
            ->orderBy('tanggal', 'desc')
            ->orderBy('t.kode_terminal')
            ->get();

        // Omzet per (tanggal, terminal) — completed sales dari shift di tanggal itu
        $omzetRows = DB::table('doc_sales as s')
            ->join('pos_terminal_shifts as sh', 'sh.id', '=', 's.shift_id')
            ->where('s.status', 'completed')
            ->whereBetween(DB::raw('DATE(sh.started_at)'), [$from, $to])
            ->when($request->filled('terminal_id'),
                fn ($q) => $q->where('sh.terminal_id', $request->terminal_id))
            ->select(
                DB::raw('DATE(sh.started_at) as tanggal'),
                'sh.terminal_id',
                DB::raw('COALESCE(SUM(s.grand_total), 0) as omzet')
            )
            ->groupBy(DB::raw('DATE(sh.started_at)'), 'sh.terminal_id')
            ->get()
            ->keyBy(fn ($r) => $r->tanggal . '_' . $r->terminal_id);

        $items = $shiftsQ->map(function ($s) use ($omzetRows) {
            $key = $s->tanggal . '_' . $s->terminal_id;
            $omzet = (float) ($omzetRows->get($key)->omzet ?? 0);
            $shiftCount = (int) $s->shift_count;
            return [
                'tanggal' => $s->tanggal,
                'terminal_id' => $s->terminal_id,
                'kode_terminal' => $s->kode_terminal,
                'nama_terminal' => $s->nama_terminal,
                'shift_count' => $shiftCount,
                'shift_paksa_count' => (int) $s->shift_paksa_count,
                'omzet_total' => $omzet,
                'omzet_per_shift' => $shiftCount > 0 ? round($omzet / $shiftCount, 2) : 0,
                'total_selisih' => (float) $s->total_selisih,
            ];
        });

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'items' => $items->values(),
        ]);
    }
}
