<?php

namespace App\Actions\Serial\Concerns;

use App\Models\SerialUnit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolusi & validasi unit serial yang dipilih user (per SN) untuk operasi
 * Transfer / Adjustment-keluar / Retur Beli. Mengunci unit (lockForUpdate) lalu
 * memastikan: ada, milik produk yang benar, di gudang sumber yang benar, dan
 * status 'tersedia'. Dipakai bersama (DRY) oleh Action terkait.
 */
trait ResolvesSelectedUnits
{
    /**
     * @param  array  $ulids          Daftar ulid unit yang dipilih (dari frontend).
     * @param  int    $productId      Produk yang diharapkan untuk semua unit.
     * @param  int    $warehouseId    Gudang sumber yang diharapkan.
     * @param  int|null  $expectedCount  Bila diisi, jumlah unit harus persis sama (= qty detail).
     * @param  string $field          Nama field untuk pesan error.
     * @return Collection<int, SerialUnit>  Unit terkunci, terurut sesuai input.
     *
     * @throws ValidationException
     */
    protected function resolveSelectedUnits(
        array $ulids,
        int $productId,
        int $warehouseId,
        ?int $expectedCount = null,
        string $field = 'serial_unit_ids'
    ): Collection {
        $ulids = array_values(array_unique(array_filter($ulids, fn ($u) => $u !== null && $u !== '')));

        if (count($ulids) === 0) {
            throw ValidationException::withMessages([
                $field => ['Belum ada unit serial yang dipilih.'],
            ]);
        }

        $units = SerialUnit::whereIn('ulid', $ulids)
            ->lockForUpdate()
            ->get()
            ->keyBy('ulid');

        $errors = [];

        // Lengkap?
        $missing = array_diff($ulids, $units->keys()->all());
        if (count($missing) > 0) {
            $errors[] = 'Sebagian unit serial tidak ditemukan.';
        }

        foreach ($units as $unit) {
            if ((int) $unit->product_id !== $productId) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) bukan milik produk ini.";
            } elseif ((int) $unit->warehouse_id !== $warehouseId) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) tidak berada di gudang sumber.";
            } elseif ($unit->status !== SerialUnit::STATUS_TERSEDIA) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) berstatus '{$unit->status}', tidak tersedia.";
            }
        }

        $selectedCount = count($ulids);
        if ($expectedCount !== null && $selectedCount !== $expectedCount) {
            $errors[] = "Jumlah unit dipilih ({$selectedCount}) tidak sama dengan qty ({$expectedCount}).";
        }

        if (count($errors) > 0) {
            throw ValidationException::withMessages([$field => array_values(array_unique($errors))]);
        }

        // Kembalikan terurut sesuai input
        return collect($ulids)->map(fn ($u) => $units->get($u))->values();
    }
}
