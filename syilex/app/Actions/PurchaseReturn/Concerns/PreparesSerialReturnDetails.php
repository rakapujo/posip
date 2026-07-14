<?php

namespace App\Actions\PurchaseReturn\Concerns;

use App\Models\MasterProduk;
use App\Models\SerialUnit;
use Illuminate\Validation\ValidationException;

/**
 * Pra-proses detail retur untuk produk serial (dipakai Create & Update — DRY).
 *
 * Untuk produk serial: 1 baris per produk, tapi nilai diturunkan dari unit terpilih:
 *  - qty_in_unit = jumlah unit
 *  - harga_per_unit = RATA-RATA harga_modal unit → subtotal = Σ harga_modal (kredit supplier)
 *  - unit_used = 'UNIT', unit_konversi = 1
 * serial_unit_ids dipertahankan agar bisa dilampirkan ke detail (untuk lock).
 */
trait PreparesSerialReturnDetails
{
    protected function prepareSerialReturnDetails(array $details): array
    {
        $serialProductIds = MasterProduk::whereIn('id', collect($details)->pluck('product_id'))
            ->where('is_serial', true)
            ->pluck('id');

        if ($serialProductIds->isEmpty()) {
            return $details;
        }

        // Muat semua unit terpilih sekali (untuk harga_modal)
        $allUlids = collect($details)->flatMap(fn ($d) => $d['serial_unit_ids'] ?? [])->filter()->unique()->all();
        $units = SerialUnit::whereIn('ulid', $allUlids)->get()->keyBy('ulid');

        return collect($details)->map(function ($d) use ($serialProductIds, $units) {
            if (!$serialProductIds->contains($d['product_id'])) {
                return $d;
            }

            $ulids = array_values(array_filter($d['serial_unit_ids'] ?? []));
            if (count($ulids) === 0) {
                throw ValidationException::withMessages([
                    'details' => ['Produk serial wajib memilih unit (nomor seri) yang diretur.'],
                ]);
            }

            $count = count($ulids);
            $sumModal = collect($ulids)->sum(fn ($u) => (float) ($units[$u]->harga_modal ?? 0));

            $d['unit_used'] = 'UNIT';
            $d['unit_konversi'] = 1;
            $d['qty_in_unit'] = $count;
            $d['harga_per_unit'] = round($sumModal / $count, 2);
            $d['serial_unit_ids'] = $ulids;

            return $d;
        })->all();
    }
}
