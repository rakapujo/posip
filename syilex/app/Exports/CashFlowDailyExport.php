<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class CashFlowDailyExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
        protected ?int $warehouseId = null,
    ) {
    }

    public function collection(): Collection
    {
        $from = $this->dateFrom;
        $to = $this->dateTo;

        $cashTxQuery = DB::table('pos_cash_transactions as c')
            ->whereBetween('c.created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($this->terminalId) {
            $cashTxQuery->where('c.terminal_id', $this->terminalId);
        }
        if ($this->warehouseId) {
            $cashTxQuery->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('master_pos_terminal as mpt')
                    ->whereColumn('mpt.id', 'c.terminal_id')
                    ->where('mpt.warehouse_id', $this->warehouseId);
            });
        }

        $cashRows = (clone $cashTxQuery)
            ->select(
                DB::raw('DATE(c.created_at) as tanggal'),
                DB::raw("SUM(CASE WHEN c.tipe = 'setor_awal' THEN c.nominal ELSE 0 END) as setor_awal"),
                DB::raw("SUM(CASE WHEN c.tipe = 'kas_masuk' THEN c.nominal ELSE 0 END) as kas_masuk"),
                DB::raw("SUM(CASE WHEN c.tipe = 'kas_keluar' THEN c.nominal ELSE 0 END) as kas_keluar_manual"),
                DB::raw("SUM(CASE WHEN c.tipe = 'refund_retur' THEN c.nominal ELSE 0 END) as refund_tunai")
            )
            ->groupBy(DB::raw('DATE(c.created_at)'))
            ->get()
            ->keyBy('tanggal');

        $salesQuery = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($this->terminalId) {
            $salesQuery->where('s.terminal_id', $this->terminalId);
        }
        if ($this->warehouseId) {
            $salesQuery->where('s.warehouse_id', $this->warehouseId);
        }

        $salesRows = (clone $salesQuery)
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(pay.nominal) as cash_received')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        $kembalianRows = DB::table('doc_sales as s')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($this->terminalId, fn ($q) => $q->where('s.terminal_id', $this->terminalId))
            ->when($this->warehouseId, fn ($q) => $q->where('s.warehouse_id', $this->warehouseId))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('doc_sales_payments as pay')
                    ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
                    ->whereColumn('pay.sales_id', 's.id')
                    ->where('m.metode', 'tunai');
            })
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(s.kembalian) as kembalian_total')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        $piutangRows = collect();
        if (! $this->terminalId && ! $this->warehouseId) {
            $piutangRows = DB::table('doc_pembayaran_piutang')
                ->where('status', 'completed')
                ->where('total_bayar_cash', '>', 0)
                ->whereBetween('tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
                ->select(
                    DB::raw('DATE(tanggal) as tanggal'),
                    DB::raw('COALESCE(SUM(total_bayar_cash), 0) as bayar_piutang_cash')
                )
                ->groupBy(DB::raw('DATE(tanggal)'))
                ->get()
                ->keyBy('tanggal');
        }

        // Penjualan non-tunai per tanggal — B3.2: info only, TIDAK masuk net_cash_flow.
        $nonTunaiQuery = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'non_tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($this->terminalId) {
            $nonTunaiQuery->where('s.terminal_id', $this->terminalId);
        }
        if ($this->warehouseId) {
            $nonTunaiQuery->where('s.warehouse_id', $this->warehouseId);
        }
        $nonTunaiRows = $nonTunaiQuery
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(pay.nominal) as nominal')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        $allDates = collect($cashRows->keys())
            ->merge($salesRows->keys())
            ->merge($piutangRows->keys())
            ->merge($nonTunaiRows->keys())
            ->unique()
            ->sort();

        return $allDates->map(function ($tanggal) use ($cashRows, $salesRows, $kembalianRows, $piutangRows, $nonTunaiRows) {
            $c = $cashRows->get($tanggal);
            $sv = $salesRows->get($tanggal);
            $kv = $kembalianRows->get($tanggal);
            $pv = $piutangRows->get($tanggal);
            $nt = $nonTunaiRows->get($tanggal);

            $setorAwal = (float) ($c->setor_awal ?? 0);
            $kasMasuk = (float) ($c->kas_masuk ?? 0);
            $kasKeluar = (float) ($c->kas_keluar_manual ?? 0);
            $refund = (float) ($c->refund_tunai ?? 0);
            $penjualanNet = (float) ($sv->cash_received ?? 0) - (float) ($kv->kembalian_total ?? 0);
            $bayarPiutang = (float) ($pv->bayar_piutang_cash ?? 0);
            $penjualanNonTunai = (float) ($nt->nominal ?? 0);
            $net = $setorAwal + $kasMasuk + $penjualanNet + $bayarPiutang - $kasKeluar - $refund;

            return (object) [
                'tanggal' => $tanggal,
                'setor_awal' => round($setorAwal, 2),
                'kas_masuk' => round($kasMasuk, 2),
                'penjualan_tunai_net' => round($penjualanNet, 2),
                'penjualan_non_tunai' => round($penjualanNonTunai, 2),
                'bayar_piutang_cash' => round($bayarPiutang, 2),
                'kas_keluar_manual' => round($kasKeluar, 2),
                'refund_tunai' => round($refund, 2),
                'net_cash_flow' => round($net, 2),
            ];
        })->values();
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'Setor Awal', 'Kas Masuk', 'Jual Tunai (Net)', 'Jual Non-Tunai (info)', 'Bayar Piutang', 'Kas Keluar', 'Refund', 'Net Cash Flow'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->tanggal,
            $row->setor_awal,
            $row->kas_masuk,
            $row->penjualan_tunai_net,
            $row->penjualan_non_tunai,
            $row->bayar_piutang_cash,
            $row->kas_keluar_manual,
            $row->refund_tunai,
            $row->net_cash_flow,
        ];
    }

}
