<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\ReportHelperService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class SalesDiscLineExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected string $dateFrom;

    protected string $dateTo;

    protected ?int $terminalId;

    protected ?string $search;

    protected ?string $source;

    protected string $mode;

    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $terminalId = null,
        ?string $search = null,
        ?string $source = null,
        ?string $mode = null,
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo.' 23:59:59';
        $this->terminalId = $terminalId;
        $this->search = $search;
        $this->source = in_array($source, ['pos', 'manual'], true) ? $source : null;
        $this->mode = $mode === 'net' ? 'net' : 'bruto';
    }

    public function query()
    {
        $applyNet = $this->mode === 'net';

        $query = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->join('doc_sales_detail as dsd', 'dsd.sales_id', '=', 'ds.id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->groupBy('ds.id', 'ds.tanggal', 'ds.nomor_dokumen', 'pt.nama_terminal')
            ->havingRaw('SUM(dsd.diskon_total) > 0');

        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::salesDiscLineReturnByNotaSubquery($this->dateFrom, $this->dateTo, [
                    'terminal_id' => $this->terminalId,
                    'source' => $this->source,
                    'search' => $this->search,
                ]),
                'ret',
                fn ($join) => $join->on('ret.sales_id', '=', 'ds.id')
            );
        }

        $query->select([
            'ds.tanggal',
            'ds.nomor_dokumen',
            'pt.nama_terminal',
            DB::raw('COUNT(dsd.id) as jumlah_item'),
            DB::raw('SUM(dsd.qty * dsd.harga_satuan) as total_bruto'),
            $applyNet
                ? DB::raw('GREATEST(SUM(dsd.diskon_total) - COALESCE(MAX(ret.ret_disc), 0), 0) as total_disc_line')
                : DB::raw('SUM(dsd.diskon_total) as total_disc_line'),
            DB::raw('SUM(dsd.jumlah) as total_setelah_disc'),
        ]);

        if ($this->terminalId) {
            $query->where('ds.terminal_id', $this->terminalId);
        }
        if ($this->source) {
            $query->where('ds.source', $this->source);
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
            $row->nama_terminal ?? 'Backoffice',
            $row->jumlah_item,
            round($row->total_bruto, 2),
            round($row->total_disc_line, 2),
            round($row->total_setelah_disc, 2),
        ];
    }
}
