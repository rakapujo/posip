<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SalesDiscLineExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected ?int $terminalId;
    protected ?string $search;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $terminalId = null,
        ?string $search = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->terminalId = $terminalId;
        $this->search = $search;
    }

    public function query()
    {
        $query = DB::table('doc_sales as ds')
            ->join('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->join('doc_sales_detail as dsd', 'dsd.sales_id', '=', 'ds.id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->groupBy('ds.id', 'ds.tanggal', 'ds.nomor_dokumen', 'pt.nama_terminal')
            ->havingRaw('SUM(dsd.diskon_total) > 0')
            ->select(
                'ds.tanggal',
                'ds.nomor_dokumen',
                'pt.nama_terminal',
                DB::raw('COUNT(dsd.id) as jumlah_item'),
                DB::raw('SUM(dsd.qty * dsd.harga_satuan) as total_bruto'),
                DB::raw('SUM(dsd.diskon_total) as total_disc_line'),
                DB::raw('SUM(dsd.jumlah) as total_setelah_disc')
            );

        if ($this->terminalId) {
            $query->where('ds.terminal_id', $this->terminalId);
        }
        if ($this->search) {
            $query->where('ds.nomor_dokumen', 'like', "%{$this->search}%");
        }

        return $query->orderBy('ds.tanggal', 'desc');
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'No. Invoice', 'Terminal', 'Jumlah Item', 'Total Bruto', 'Total Disc Line', 'Total Stlh Disc'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->tanggal,
            $row->nomor_dokumen,
            $row->nama_terminal,
            $row->jumlah_item,
            round($row->total_bruto, 2),
            round($row->total_disc_line, 2),
            round($row->total_setelah_disc, 2),
        ];
    }

}
