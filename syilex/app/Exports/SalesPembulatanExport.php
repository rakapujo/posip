<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SalesPembulatanExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected ?int $terminalId;
    protected ?string $tipe;
    protected ?string $search;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $terminalId = null,
        ?string $tipe = null,
        ?string $search = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->terminalId = $terminalId;
        $this->tipe = $tipe;
        $this->search = $search;
    }

    public function query()
    {
        $salesQuery = DB::table('doc_sales as ds')
            ->join('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
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
            ->join('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->join('master_pos_terminal as pt2', 'pt2.id', '=', 'ds2.terminal_id')
            ->where('ds2.status', 'completed')
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
            $returQuery->where('ds2.terminal_id', $this->terminalId);
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
            $row->nama_terminal,
            $row->grand_total,
            $row->pembulatan,
        ];
    }

}
