<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SalesBiayaExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->whereRaw('(COALESCE(ds.biaya_kirim_hasil, 0) + COALESCE(ds.biaya_lain_hasil, 0)) > 0')
            ->select(
                'ds.tanggal',
                'ds.nomor_dokumen',
                'pt.nama_terminal',
                'ds.total_setelah_diskon',
                'ds.biaya_kirim_tipe',
                'ds.biaya_kirim_nilai',
                'ds.biaya_kirim_hasil',
                'ds.biaya_lain_tipe',
                'ds.biaya_lain_nilai',
                'ds.biaya_lain_hasil',
                DB::raw('(COALESCE(ds.biaya_kirim_hasil, 0) + COALESCE(ds.biaya_lain_hasil, 0)) as total_biaya'),
                'ds.dpp'
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
        return [
            'No', 'Tanggal', 'No. Invoice', 'Terminal', 'Stlh Diskon',
            'B.Kirim Tipe', 'B.Kirim Nilai', 'B.Kirim Hasil',
            'B.Lain Tipe', 'B.Lain Nilai', 'B.Lain Hasil',
            'Total Biaya', 'DPP',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $fmtDisc = fn ($tipe) => match ($tipe) {
            'percent' => 'Persen',
            'nominal' => 'Nominal',
            default => '-',
        };

        return [
            $this->rowNumber,
            $row->tanggal,
            $row->nomor_dokumen,
            $row->nama_terminal,
            $row->total_setelah_diskon,
            $fmtDisc($row->biaya_kirim_tipe), $row->biaya_kirim_nilai, $row->biaya_kirim_hasil,
            $fmtDisc($row->biaya_lain_tipe), $row->biaya_lain_nilai, $row->biaya_lain_hasil,
            $row->total_biaya,
            $row->dpp,
        ];
    }

}
