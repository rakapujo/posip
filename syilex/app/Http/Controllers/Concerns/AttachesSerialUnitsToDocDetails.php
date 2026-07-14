<?php

namespace App\Http\Controllers\Concerns;

use App\Models\SerialUnit;

/**
 * Tempelkan rincian unit serial ke detail dokumen inventory (Adjustment/Transfer/Retur/Opname)
 * agar tampil di halaman DETAIL (read) + PDF — bukan hanya ulid mentah.
 *
 * Sumber ulid = kolom JSON pada detail (default `serial_unit_ids`; Opname `serial_unit_ids_present`).
 * Untuk Adjustment, `fateField` (`serial_unit_statuses`) memetakan status keluar pilihan user
 * (rusak/hilang) per unit. 1 query batch (whereIn ulid). Berbeda dari AttachesSerialUnits (nota
 * penjualan) yang sengaja minimal; di sini ikut sertakan `kode_internal`, `status`, dan `fate`.
 */
trait AttachesSerialUnitsToDocDetails
{
    /**
     * @param  \Illuminate\Support\Collection|iterable  $details
     * @param  string       $idField     Kolom JSON daftar ulid unit pada detail.
     * @param  string|null  $fateField   Kolom JSON map {ulid: status} (Adjustment) → diekspos sbg `fate`.
     */
    protected function attachDocSerialUnits($details, string $idField = 'serial_unit_ids', ?string $fateField = null): void
    {
        if (!$details) {
            return;
        }

        $ulids = collect($details)
            ->flatMap(fn ($d) => $d->{$idField} ?? [])
            ->filter()
            ->unique()
            ->values();

        $units = $ulids->isEmpty()
            ? collect()
            : SerialUnit::whereIn('ulid', $ulids)
                ->get(['ulid', 'kode_internal', 'serial_number', 'grade', 'battery_condition', 'battery_health', 'account_status', 'catatan', 'status'])
                ->keyBy('ulid');

        $fields = ['kode_internal', 'serial_number', 'grade', 'battery_condition', 'battery_health', 'account_status', 'catatan', 'status'];

        foreach ($details as $d) {
            $fateMap = $fateField ? ($d->{$fateField} ?? []) : [];

            $d->serial_units = collect($d->{$idField} ?? [])
                ->map(function ($ulid) use ($units, $fields, $fateMap) {
                    $u = $units->get($ulid);
                    if (!$u) {
                        return null;
                    }
                    $row = $u->only($fields);
                    // Status fate keluar (Adjustment): pilihan user; fallback ke status unit terkini.
                    $row['fate'] = $fateMap[$ulid] ?? null;

                    return $row;
                })
                ->filter()
                ->values()
                ->all();
        }
    }
}
