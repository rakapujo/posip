<?php

namespace App\Actions\StockOpname\Concerns;

use App\Models\SerialUnit;
use Illuminate\Validation\ValidationException;

/** Validasi checklist SN hadir pada create/update stock opname. */
trait ResolvesOpnamePresentUnits
{
    /**
     * Key wajib (boleh []), unique, unit harus milik produk+WH & tersedia.
     *
     * @return list<string>
     */
    protected function resolvePresentUlids(array $detail, int $index, int $productId, int $warehouseId): array
    {
        if (! array_key_exists('serial_unit_ids_present', $detail) || ! is_array($detail['serial_unit_ids_present'])) {
            throw ValidationException::withMessages([
                "details.{$index}.serial_unit_ids_present" => ['Checklist unit serial wajib dikirim (boleh array kosong).'],
            ]);
        }

        $presentIds = array_values(array_unique(array_filter(
            $detail['serial_unit_ids_present'],
            fn ($u) => $u !== null && $u !== ''
        )));

        if (count($presentIds) === 0) {
            return [];
        }

        $found = SerialUnit::whereIn('ulid', $presentIds)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerialUnit::STATUS_TERSEDIA)
            ->pluck('ulid')
            ->all();

        if (count($found) !== count($presentIds)) {
            throw ValidationException::withMessages([
                "details.{$index}.serial_unit_ids_present" => ['Ada unit serial yang tidak valid / tidak tersedia di gudang ini.'],
            ]);
        }

        return $presentIds;
    }
}
