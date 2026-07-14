<?php

namespace App\Http\Controllers\Concerns;

use App\Models\SerialUnit;

/**
 * Tempelkan rincian unit serial (SN/IMEI, grade, baterai, status akun, catatan) ke tiap
 * baris detail penjualan/retur yang punya serial_unit_ids — supaya muncul di SEMUA nota
 * (struk POS, struk online, nota back-end, laporan closing, export).
 *
 * Sumber = serial_unit_ids (apa yang terjual saat itu) → tetap akurat walau unit kelak
 * diretur/void (sale_detail_id unit bisa hilang, tapi serial_unit_ids JSON tetap). 1 query
 * batch (whereIn ulid) untuk seluruh detail dalam transaksi.
 */
trait AttachesSerialUnits
{
    /** Field unit yang ditampilkan di nota. */
    private array $serialReceiptFields = [
        'kode_internal', 'serial_number', 'grade', 'battery_condition', 'battery_health', 'account_status', 'catatan',
    ];

    /**
     * Tempelkan `serial_units` ke detail $sale (termasuk detail tiap retur, bila dimuat).
     */
    protected function attachSerialUnitsToSale($sale): void
    {
        if (!$sale) {
            return;
        }

        $detailCollections = [$sale->details ?? collect()];
        foreach ($sale->returns ?? collect() as $ret) {
            $detailCollections[] = $ret->details ?? collect();
        }

        $this->attachSerialUnitsToDetails(
            collect($detailCollections)->flatMap(fn ($c) => $c)
        );
    }

    /**
     * Tempelkan `serial_units` ke kumpulan baris detail (DocSalesDetail / DocSalesReturnDetail).
     *
     * @param  \Illuminate\Support\Collection  $details
     */
    protected function attachSerialUnitsToDetails($details): void
    {
        $ulids = $details
            ->flatMap(fn ($d) => $d->serial_unit_ids ?? [])
            ->filter()
            ->unique()
            ->values();

        $units = $ulids->isEmpty()
            ? collect()
            : SerialUnit::whereIn('ulid', $ulids)
                ->get(array_merge(['ulid'], $this->serialReceiptFields))
                ->keyBy('ulid');

        foreach ($details as $d) {
            $d->serial_units = collect($d->serial_unit_ids ?? [])
                ->map(fn ($u) => optional($units->get($u))->only($this->serialReceiptFields))
                ->filter()
                ->values()
                ->all();
        }
    }
}
