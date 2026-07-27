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

class SalesDiscNotaExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
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
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->where('ds.total_diskon', '>', 0);

        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::salesDiscNotaReturnByNotaSubquery($this->dateFrom, $this->dateTo, [
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
            'ds.subtotal',
            'ds.diskon_nota_1_tipe',
            'ds.diskon_nota_1_nilai',
            'ds.diskon_nota_1_hasil',
            'ds.diskon_nota_1_label',
            'ds.diskon_nota_2_tipe',
            'ds.diskon_nota_2_nilai',
            'ds.diskon_nota_2_hasil',
            'ds.diskon_nota_2_label',
            'ds.diskon_nota_3_tipe',
            'ds.diskon_nota_3_nilai',
            'ds.diskon_nota_3_hasil',
            'ds.diskon_nota_3_label',
            $applyNet
                ? DB::raw('GREATEST(ds.total_diskon - COALESCE(ret.ret_disc, 0), 0) as total_diskon')
                : DB::raw('ds.total_diskon as total_diskon'),
            'ds.total_setelah_diskon',
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
        return [
            'No', 'Tanggal', 'No. Invoice', 'Terminal', 'Subtotal',
            'Disc 1 Label', 'Disc 1 Tipe', 'Disc 1 Nilai', 'Disc 1 Hasil',
            'Disc 2 Label', 'Disc 2 Tipe', 'Disc 2 Nilai', 'Disc 2 Hasil',
            'Disc 3 Label', 'Disc 3 Tipe', 'Disc 3 Nilai', 'Disc 3 Hasil',
            'Total Diskon', 'Total Stlh Diskon',
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
            $row->nama_terminal ?? 'Backoffice',
            $row->subtotal,
            $row->diskon_nota_1_label ?? '-', $fmtDisc($row->diskon_nota_1_tipe), $row->diskon_nota_1_nilai, $row->diskon_nota_1_hasil,
            $row->diskon_nota_2_label ?? '-', $fmtDisc($row->diskon_nota_2_tipe), $row->diskon_nota_2_nilai, $row->diskon_nota_2_hasil,
            $row->diskon_nota_3_label ?? '-', $fmtDisc($row->diskon_nota_3_tipe), $row->diskon_nota_3_nilai, $row->diskon_nota_3_hasil,
            $row->total_diskon,
            $row->total_setelah_diskon,
        ];
    }
}
