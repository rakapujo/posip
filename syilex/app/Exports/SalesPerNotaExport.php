<?php

namespace App\Exports;

use App\Models\SerialUnit;
use App\Services\ReportHelperService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SalesPerNotaExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected ?int $terminalId;
    protected ?int $userId;
    protected ?int $metodeBayarId;
    protected ?string $status;
    protected ?string $search;
    protected int $rowNumber = 0;

    /** Map: sales_id (int) => "SN1, SN2" gabungan nomor seri unit terjual pada nota itu. */
    protected ?array $serialMap = null;

    /** Map: sales_id (int) => "KI1, KI2" gabungan kode internal unit terjual pada nota itu. */
    protected ?array $kodeMap = null;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        ?int $terminalId = null,
        ?int $userId = null,
        ?int $metodeBayarId = null,
        ?string $status = null,
        ?string $search = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->terminalId = $terminalId;
        $this->userId = $userId;
        $this->metodeBayarId = $metodeBayarId;
        $this->status = $status;
        $this->search = $search;
    }

    public function query()
    {
        $query = DB::table('doc_sales as ds')
            ->join('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->leftJoin('master_customer as mc', 'mc.id', '=', 'ds.customer_id')
            ->join('users as u', 'u.id', '=', 'ds.created_by')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->select(
                'ds.id as sales_id',
                'ds.tanggal',
                'ds.nomor_dokumen',
                'mc.nama as customer_nama',
                'pt.nama_terminal',
                'u.name as kasir',
                'ds.subtotal',
                'ds.total_diskon',
                'ds.total_setelah_diskon',
                'ds.pajak_nominal',
                'ds.biaya_kirim_hasil',
                'ds.biaya_lain_hasil',
                'ds.pembulatan',
                'ds.grand_total',
                'ds.status',
                ...ReportHelperService::salesReceiptQtySelects('ds.id')
            );

        if ($this->terminalId) {
            $query->where('ds.terminal_id', $this->terminalId);
        }
        if ($this->userId) {
            $query->where('ds.created_by', $this->userId);
        }
        if ($this->metodeBayarId) {
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('doc_sales_payments')
                  ->whereColumn('doc_sales_payments.sales_id', 'ds.id')
                  ->where('doc_sales_payments.metode_pembayaran_id', $this->metodeBayarId);
            });
        }
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('ds.nomor_dokumen', 'like', "%{$search}%")
                  ->orWhere('mc.nama', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($this->status) {
            if ($this->status === 'voided') {
                $query->where('ds.status', 'voided');
            } elseif ($this->status === 'completed') {
                $query->where('ds.status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('ds.id') . ' = 0');
            } elseif ($this->status === 'retur_partial') {
                $query->where('ds.status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('ds.id') . ' > 0')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('ds.id') . ' < ' . ReportHelperService::sqlSalesBoughtBase('ds.id'));
            } elseif ($this->status === 'retur_full') {
                $query->where('ds.status', 'completed')
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('ds.id') . ' >= ' . ReportHelperService::sqlSalesBoughtBase('ds.id'))
                    ->whereRaw(ReportHelperService::sqlSalesReturnedBase('ds.id') . ' > 0');
            }
        }

        return $query->orderBy('ds.tanggal', 'desc');
    }

    public function headings(): array
    {
        return [
            'No', 'Tanggal', 'No. Invoice', 'Customer', 'Terminal', 'Kasir',
            'Subtotal', 'Diskon', 'Stlh Diskon', 'Pajak', 'Biaya Kirim', 'Biaya Lain',
            'Pembulatan', 'Grand Total', 'Status', 'Kode Internal', 'Nomor Seri',
        ];
    }

    /** Gabungan nomor seri unit terjual per nota (lihat buildSerialMaps). */
    protected function serialMapFor(int $salesId): string
    {
        $this->buildSerialMaps();

        return $this->serialMap[$salesId] ?? '';
    }

    /** Gabungan kode internal unit terjual per nota (sumber sama dgn serialMapFor). */
    protected function kodeMapFor(int $salesId): string
    {
        $this->buildSerialMaps();

        return $this->kodeMap[$salesId] ?? '';
    }

    /**
     * Bangun (sekali) map sales_id => gabungan kode internal & nomor seri.
     * Satu pass: kumpulkan semua serial_unit_ids dari detail nota dalam rentang tanggal,
     * lalu SATU query lookup SerialUnit (kode_internal + serial_number per ulid). Hindari N+1.
     */
    protected function buildSerialMaps(): void
    {
        if ($this->serialMap !== null) {
            return;
        }
        $this->serialMap = [];
        $this->kodeMap = [];

        // Ambil detail bersama serial_unit_ids untuk nota dalam rentang tanggal yang diekspor.
        $details = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->whereNotNull('dsd.serial_unit_ids')
            ->select('dsd.sales_id', 'dsd.serial_unit_ids')
            ->get();

        // Kumpulkan seluruh ulid + simpan urutan ulid per nota.
        $allUlids = [];
        $perSale = []; // sales_id => [ulid, ...]
        foreach ($details as $d) {
            $ids = json_decode($d->serial_unit_ids ?? '[]', true) ?: [];
            foreach ($ids as $ulid) {
                if ($ulid === null || $ulid === '') {
                    continue;
                }
                $allUlids[$ulid] = true;
                $perSale[$d->sales_id][] = $ulid;
            }
        }

        // SATU query lookup kode_internal + serial_number.
        $unitByUlid = empty($allUlids)
            ? collect()
            : SerialUnit::whereIn('ulid', array_keys($allUlids))
                ->get(['ulid', 'kode_internal', 'serial_number'])
                ->keyBy('ulid');

        foreach ($perSale as $sid => $ulids) {
            $sn = [];
            $ki = [];
            foreach ($ulids as $ulid) {
                $unit = $unitByUlid->get($ulid);
                if (!$unit) {
                    continue;
                }
                if ($unit->serial_number !== null && $unit->serial_number !== '') {
                    $sn[] = $unit->serial_number;
                }
                if ($unit->kode_internal !== null && $unit->kode_internal !== '') {
                    $ki[] = $unit->kode_internal;
                }
            }
            $this->serialMap[(int) $sid] = implode(', ', $sn);
            $this->kodeMap[(int) $sid] = implode(', ', $ki);
        }
    }

    public function map($row): array
    {
        $this->rowNumber++;

        // Compute receipt_status
        if ($row->status === 'voided') {
            $status = 'Void';
        } elseif ($row->total_returned_base > 0 && $row->total_returned_base >= $row->total_bought_base) {
            $status = 'Retur Penuh';
        } elseif ($row->total_returned_base > 0) {
            $status = 'Retur Sebagian';
        } else {
            $status = 'Selesai';
        }

        return [
            $this->rowNumber,
            $row->tanggal,
            $row->nomor_dokumen,
            $row->customer_nama ?? 'Walk-in',
            $row->nama_terminal,
            $row->kasir,
            $row->subtotal,
            $row->total_diskon,
            $row->total_setelah_diskon,
            $row->pajak_nominal,
            $row->biaya_kirim_hasil,
            $row->biaya_lain_hasil,
            $row->pembulatan,
            $row->grand_total,
            $status,
            $this->kodeMapFor((int) $row->sales_id),
            $this->serialMapFor((int) $row->sales_id),
        ];
    }

}
