<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verify critical data invariants. Intended to be run periodically (daily cron
 * or via middleware trigger) to catch drift between derived aggregates and
 * stored snapshots.
 */
class VerifyDataInvariants extends Command
{
    protected $signature = 'data:verify
                            {--json : Output hasil sebagai JSON}
                            {--fail-on-mismatch : Exit code 1 jika ada mismatch}';

    protected $description = 'Verify data integrity invariants (stock_card vs inventory_stock, payment totals, hutang ledger)';

    public function handle(): int
    {
        $report = [
            'stock_consistency' => $this->checkStockConsistency(),
            'sales_payment_totals' => $this->checkSalesPaymentTotals(),
            'hutang_ledger' => $this->checkHutangLedger(),
            'serial_stock_consistency' => $this->checkSerialStockConsistency(),
            'serial_sold_integrity' => $this->checkSerialSoldIntegrity(),
        ];

        $hasIssue = collect($report)->contains(fn ($r) => $r['mismatches'] > 0);

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $hasIssue ? 'mismatch' : 'ok',
                'timestamp' => now()->toIso8601String(),
                'report' => $report,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->renderTable($report);
        }

        if ($hasIssue) {
            Log::warning('Data invariant check found mismatches', ['report' => $report]);
            return $this->option('fail-on-mismatch') ? 1 : 0;
        }

        return 0;
    }

    /**
     * Invariant: untuk tiap (product_id, warehouse_id),
     *   SUM(stock_card.qty_in) - SUM(stock_card.qty_out) === inventory_stock.qty
     */
    protected function checkStockConsistency(): array
    {
        $rows = DB::table('inventory_stock as inv')
            ->leftJoin(DB::raw('(
                SELECT product_id, warehouse_id,
                       COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) AS computed_qty
                FROM stock_card
                GROUP BY product_id, warehouse_id
            ) as sc'), function ($join) {
                $join->on('sc.product_id', '=', 'inv.product_id')
                     ->on('sc.warehouse_id', '=', 'inv.warehouse_id');
            })
            ->select('inv.product_id', 'inv.warehouse_id', 'inv.qty as stored_qty',
                     DB::raw('COALESCE(sc.computed_qty, 0) as computed_qty'))
            ->get();

        $mismatches = $rows->filter(fn ($r) => (int) $r->stored_qty !== (int) $r->computed_qty);

        return [
            'name' => 'Stock Consistency',
            'description' => 'SUM(stock_card.qty_in - qty_out) === inventory_stock.qty per (product, warehouse)',
            'checked' => $rows->count(),
            'mismatches' => $mismatches->count(),
            'samples' => $mismatches->take(5)->map(fn ($r) => [
                'product_id' => $r->product_id,
                'warehouse_id' => $r->warehouse_id,
                'stored' => (int) $r->stored_qty,
                'computed' => (int) $r->computed_qty,
                'diff' => (int) $r->stored_qty - (int) $r->computed_qty,
            ])->values(),
        ];
    }

    /**
     * Invariant: untuk tiap completed sale,
     *   SUM(doc_sales_payments.nominal) >= doc_sales.grand_total
     * (over-payment OK karena kembalian; under-payment = bug)
     */
    protected function checkSalesPaymentTotals(): array
    {
        $rows = DB::table('doc_sales as s')
            ->where('s.status', 'completed')
            ->leftJoin(DB::raw('(
                SELECT sales_id, COALESCE(SUM(nominal), 0) AS paid_total
                FROM doc_sales_payments
                GROUP BY sales_id
            ) as p'), 'p.sales_id', '=', 's.id')
            ->select('s.id', 's.nomor_dokumen', 's.grand_total', 's.total_biaya_pembayaran',
                     DB::raw('COALESCE(p.paid_total, 0) as paid_total'))
            ->get();

        $mismatches = $rows->filter(function ($r) {
            $expected = (float) $r->grand_total + (float) $r->total_biaya_pembayaran;
            return (float) $r->paid_total < $expected;
        });

        return [
            'name' => 'Sales Payment Totals',
            'description' => 'SUM(payments) >= grand_total + total_biaya_pembayaran untuk completed sales',
            'checked' => $rows->count(),
            'mismatches' => $mismatches->count(),
            'samples' => $mismatches->take(5)->map(fn ($r) => [
                'sales_id' => $r->id,
                'nomor_dokumen' => $r->nomor_dokumen,
                'grand_total' => (float) $r->grand_total,
                'biaya_pembayaran' => (float) $r->total_biaya_pembayaran,
                'paid_total' => (float) $r->paid_total,
                'shortfall' => (float) $r->grand_total + (float) $r->total_biaya_pembayaran - (float) $r->paid_total,
            ])->values(),
        ];
    }

    /**
     * Invariant: supplier_hutang.sisa_hutang === total_hutang - sum(pembayaran).
     */
    protected function checkHutangLedger(): array
    {
        if (!\Schema::hasTable('supplier_hutang')) {
            return [
                'name' => 'Hutang Ledger',
                'description' => 'skipped (table not present)',
                'checked' => 0,
                'mismatches' => 0,
                'samples' => [],
            ];
        }

        $rows = DB::table('supplier_hutang as h')
            ->leftJoin(DB::raw('(
                SELECT pd.hutang_id, COALESCE(SUM(pd.nominal_dibayar), 0) AS paid
                FROM doc_pembayaran_hutang_detail pd
                INNER JOIN doc_pembayaran_hutang p ON p.id = pd.pembayaran_id
                WHERE p.status = \'completed\'
                GROUP BY pd.hutang_id
            ) as pay'), 'pay.hutang_id', '=', 'h.id')
            ->select(
                'h.id',
                'h.nominal_awal as total_hutang',
                'h.sisa_hutang',
                DB::raw('COALESCE(pay.paid, 0) as paid'),
                DB::raw('(h.nominal_awal - COALESCE(pay.paid, 0)) as expected_sisa')
            )
            ->get();

        $mismatches = $rows->filter(function ($r) {
            return abs((float) $r->sisa_hutang - (float) $r->expected_sisa) > 0.01;
        });

        return [
            'name' => 'Hutang Ledger',
            'description' => 'sisa_hutang === nominal_awal - SUM(pembayaran.nominal_dibayar)',
            'checked' => $rows->count(),
            'mismatches' => $mismatches->count(),
            'samples' => $mismatches->take(5)->map(fn ($r) => [
                'hutang_id' => $r->id,
                'total' => (float) $r->total_hutang,
                'paid' => (float) $r->paid,
                'stored_sisa' => (float) $r->sisa_hutang,
                'expected_sisa' => (float) $r->expected_sisa,
                'diff' => (float) $r->sisa_hutang - (float) $r->expected_sisa,
            ])->values(),
        ];
    }

    /**
     * Invariant serial (modul serial A+): untuk tiap produk is_serial + gudang,
     *   COUNT(serial_units.status='tersedia') === inventory_stock.qty
     */
    protected function checkSerialStockConsistency(): array
    {
        if (!\Schema::hasTable('serial_units')) {
            return [
                'name' => 'Serial Stock Consistency',
                'description' => 'skipped (table not present)',
                'checked' => 0, 'mismatches' => 0, 'samples' => [],
            ];
        }

        $invRows = DB::table('inventory_stock as inv')
            ->join('master_produk as p', 'p.id', '=', 'inv.product_id')
            ->where('p.is_serial', true)
            ->select('inv.product_id', 'inv.warehouse_id', 'inv.qty')
            ->get();

        $unitRows = DB::table('serial_units')
            ->whereNull('deleted_at')
            ->where('status', 'tersedia')
            ->select('product_id', 'warehouse_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('product_id', 'warehouse_id')
            ->get();

        $invMap = [];
        foreach ($invRows as $r) {
            $invMap["{$r->product_id}-{$r->warehouse_id}"] = (int) $r->qty;
        }
        $unitMap = [];
        foreach ($unitRows as $r) {
            $unitMap["{$r->product_id}-{$r->warehouse_id}"] = (int) $r->cnt;
        }

        $keys = array_unique(array_merge(array_keys($invMap), array_keys($unitMap)));
        $mismatches = [];
        foreach ($keys as $key) {
            $stored = $invMap[$key] ?? 0;
            $count = $unitMap[$key] ?? 0;
            if ($stored !== $count) {
                [$pid, $wid] = explode('-', $key);
                $mismatches[] = [
                    'product_id' => (int) $pid,
                    'warehouse_id' => (int) $wid,
                    'inventory_qty' => $stored,
                    'available_units' => $count,
                    'diff' => $stored - $count,
                ];
            }
        }

        return [
            'name' => 'Serial Stock Consistency',
            'description' => "COUNT(serial_units 'tersedia') === inventory_stock.qty per (produk serial, gudang)",
            'checked' => count($keys),
            'mismatches' => count($mismatches),
            'samples' => array_slice($mismatches, 0, 5),
        ];
    }

    /**
     * Invariant serial: unit 'terjual' WAJIB punya sale_id; unit 'tersedia' WAJIB sale_id null.
     */
    protected function checkSerialSoldIntegrity(): array
    {
        if (!\Schema::hasTable('serial_units')) {
            return [
                'name' => 'Serial Sold Integrity',
                'description' => 'skipped (table not present)',
                'checked' => 0, 'mismatches' => 0, 'samples' => [],
            ];
        }

        $bad = DB::table('serial_units')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('status', 'terjual')->whereNull('sale_id');
                })->orWhere(function ($q2) {
                    $q2->where('status', 'tersedia')->whereNotNull('sale_id');
                });
            })
            ->select('id', 'serial_number', 'status', 'sale_id')
            ->get();

        $total = DB::table('serial_units')->whereNull('deleted_at')->count();

        return [
            'name' => 'Serial Sold Integrity',
            'description' => "unit 'terjual' wajib punya sale_id; unit 'tersedia' wajib sale_id null",
            'checked' => $total,
            'mismatches' => $bad->count(),
            'samples' => $bad->take(5)->map(fn ($r) => [
                'serial_unit_id' => $r->id,
                'serial_number' => $r->serial_number,
                'status' => $r->status,
                'sale_id' => $r->sale_id,
            ])->values(),
        ];
    }

    private function renderTable(array $report): void
    {
        $this->info('Data Invariant Verification Report');
        $this->line(str_repeat('=', 60));

        foreach ($report as $check) {
            $status = $check['mismatches'] === 0 ? '<fg=green>✓ OK</>' : '<fg=red>✗ FAIL</>';
            $this->line("\n{$status} {$check['name']}");
            $this->line("  " . $check['description']);
            $this->line("  Checked: {$check['checked']}, Mismatches: {$check['mismatches']}");

            if ($check['mismatches'] > 0 && !empty($check['samples'])) {
                $this->line('  Samples (first 5):');
                foreach ($check['samples'] as $sample) {
                    $this->line('    ' . json_encode($sample));
                }
            }
        }

        $this->line(str_repeat('=', 60));
    }
}
