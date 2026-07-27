<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class SalesPembulatanExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected string $dateFrom;

    protected string $dateTo;

    protected ?int $terminalId;

    protected ?string $tipe;

    protected ?string $search;

    protected ?string $source;

    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $terminalId = null,
        ?string $tipe = null,
        ?string $search = null,
        ?string $source = null,
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo.' 23:59:59';
        $this->terminalId = $terminalId;
        $this->tipe = $tipe;
        $this->search = $search;
        $this->source = in_array($source, ['pos', 'manual'], true) ? $source : null;
    }

    public function query()
    {
        $salesQuery = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->select(
                'ds.tanggal',
                'ds.nomor_dokumen',
                DB::raw("'Penjualan' as tipe"),
                'pt.nama_terminal',
                'ds.grand_total',
                'ds.pembulatan'
            );

        $returQuery = DB::table('doc_sales_returns as dsr')
            ->leftJoin('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->leftJoin('master_pos_terminal as pt2', 'pt2.id', '=', 'dsr.terminal_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where(function ($q) {
                $q->whereNull('dsr.sales_id')
                    ->orWhere('ds2.status', 'completed');
            })
            ->where('dsr.tanggal', '>=', $this->dateFrom)
            ->where('dsr.tanggal', '<=', $this->dateTo)
            ->select(
                'dsr.tanggal',
                'dsr.nomor_dokumen',
                DB::raw("'Retur' as tipe"),
                'pt2.nama_terminal',
                'dsr.grand_total',
                'dsr.pembulatan'
            );

        if ($this->terminalId) {
            $salesQuery->where('ds.terminal_id', $this->terminalId);
            $returQuery->where('dsr.terminal_id', $this->terminalId);
        }
        if ($this->source) {
            $salesQuery->where('ds.source', $this->source);
            $returQuery->where('dsr.source', $this->source);
        }
        if ($this->search) {
            $salesQuery->where('ds.nomor_dokumen', 'like', "%{$this->search}%");
            $returQuery->where('dsr.nomor_dokumen', 'like', "%{$this->search}%");
        }

        if ($this->tipe === 'Penjualan') {
            return $salesQuery->orderBy('tanggal', 'desc');
        } elseif ($this->tipe === 'Retur') {
            return $returQuery->orderBy('tanggal', 'desc');
        }

        return DB::query()->fromSub(
            $salesQuery->unionAll($returQuery),
            'combined'
        )->orderBy('tanggal', 'desc');
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'No. Dokumen', 'Tipe', 'Terminal', 'Grand Total', 'Pembulatan'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->tanggal,
            $row->nomor_dokumen,
            $row->tipe,
            $row->nama_terminal ?? 'Backoffice',
            $row->grand_total,
            $row->pembulatan,
        ];
    }
}
