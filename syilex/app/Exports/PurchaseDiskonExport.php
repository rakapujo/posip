<?php

namespace App\Exports;

use App\Services\ReportHelperService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class PurchaseDiskonExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected ?int $supplierId;
    protected ?string $search;
    protected string $source;
    protected string $mode;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $supplierId = null,
        ?string $search = null,
        ?string $source = null,
        ?string $mode = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->supplierId = $supplierId;
        $this->search = $search;
        $this->source = $source ?? 'all';
        $this->mode = $mode === 'net' ? 'net' : 'bruto';
    }

    public function query()
    {
        $applyNet = $this->mode === 'net' && $this->source !== 'serial';

        $query = DB::query()
            ->fromSub(\App\Services\PurchaseReportSource::documents($this->dateFrom, $this->dateTo, $this->source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id')
            ->where('dpo.total_diskon_header', '>', 0);

        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::purchaseReturnByPoSubquery($this->dateFrom, $this->dateTo),
                'pret',
                fn ($join) => $join->on('pret.po_id', '=', 'dpo.id')->whereRaw("dpo.sumber = 'po'")
            );
        }

        $query->select([
            'dpo.sumber',
            'dpo.tanggal_po', 'dpo.nomor_dokumen', 'ms.nama_supplier',
            $applyNet ? DB::raw('GREATEST(dpo.subtotal - COALESCE(pret.ret_money, 0), 0) as subtotal') : 'dpo.subtotal',
            'dpo.diskon_1_tipe', 'dpo.diskon_1_nilai', 'dpo.diskon_1_hasil',
            'dpo.diskon_2_tipe', 'dpo.diskon_2_nilai', 'dpo.diskon_2_hasil',
            'dpo.diskon_3_tipe', 'dpo.diskon_3_nilai', 'dpo.diskon_3_hasil',
            $applyNet ? DB::raw('GREATEST(dpo.total_diskon_header - COALESCE(pret.ret_disc, 0), 0) as total_diskon_header') : 'dpo.total_diskon_header',
            $applyNet ? DB::raw('GREATEST(dpo.total_setelah_diskon - COALESCE(pret.ret_money, 0), 0) as total_setelah_diskon') : 'dpo.total_setelah_diskon',
        ]);

        if ($this->supplierId) {
            $query->where('dpo.supplier_id', $this->supplierId);
        }
        if ($this->search) {
            $query->where('dpo.nomor_dokumen', 'like', "%{$this->search}%");
        }

        return $query->orderBy('dpo.tanggal_po', 'desc');
    }

    public function headings(): array
    {
        return [
            'No', 'Sumber', 'Tanggal', 'No. Dokumen', 'Supplier', 'Subtotal',
            'Disc 1 Tipe', 'Disc 1 Nilai', 'Disc 1 Hasil',
            'Disc 2 Tipe', 'Disc 2 Nilai', 'Disc 2 Hasil',
            'Disc 3 Tipe', 'Disc 3 Nilai', 'Disc 3 Hasil',
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
            $row->sumber === 'serial' ? 'Serial' : 'PO',
            $row->tanggal_po,
            $row->nomor_dokumen,
            $row->nama_supplier,
            $row->subtotal,
            $fmtDisc($row->diskon_1_tipe), $row->diskon_1_nilai, $row->diskon_1_hasil,
            $fmtDisc($row->diskon_2_tipe), $row->diskon_2_nilai, $row->diskon_2_hasil,
            $fmtDisc($row->diskon_3_tipe), $row->diskon_3_nilai, $row->diskon_3_hasil,
            $row->total_diskon_header,
            $row->total_setelah_diskon,
        ];
    }

}
