<?php

namespace App\Actions\SerialChange\Concerns;

use App\Models\DocSerialChange;
use App\Models\DocSerialChangeDetail;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validasi & pembuatan detail Perubahan Data Serial — dipakai Create & Update (DRY).
 * Hanya unit TERSEDIA yang boleh dikoreksi; SN baru wajib diisi tapi TIDAK perlu unik
 * (SN boleh kembar — identitas unik unit = kode_internal, tak diubah modul ini).
 */
trait HandlesSerialChangeUnits
{
    /**
     * @return array{0: MasterProduk, 1: Collection, 2: array}  [product, serialUnits(keyBy ulid), newSerials]
     * @throws ValidationException
     */
    protected function validateChangePayload(int $productId, array $units): array
    {
        $product = MasterProduk::find($productId);
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Produk tidak ditemukan.']]);
        }
        if (!$product->is_serial) {
            throw ValidationException::withMessages(['product_id' => ['Produk ini bukan produk serial.']]);
        }
        if (count($units) === 0) {
            throw ValidationException::withMessages(['units' => ['Minimal 1 unit untuk dikoreksi.']]);
        }

        // Resolve unit by ulid + pastikan milik produk & TERSEDIA
        $ulids = array_map(fn ($u) => (string) ($u['serial_unit_id'] ?? ''), $units);
        $serialUnits = SerialUnit::whereIn('ulid', $ulids)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('ulid');

        foreach ($units as $u) {
            $ulid = (string) ($u['serial_unit_id'] ?? '');
            $su = $serialUnits->get($ulid);
            if (!$su) {
                throw ValidationException::withMessages(['units' => ['Ada unit yang tidak valid / bukan milik produk ini.']]);
            }
            if ($su->status !== 'tersedia') {
                throw ValidationException::withMessages([
                    'units' => ["Unit {$su->kode_internal} (SN {$su->serial_number}) tidak bisa dikoreksi (status: {$su->status})."],
                ]);
            }
        }

        // SN baru wajib diisi, TAPI tidak perlu unik — SN boleh kembar (bahkan dalam 1 produk);
        // identitas unik unit = kode_internal (tak diubah oleh modul ini, tetap melekat ke unit).
        $newSerials = array_map(fn ($u) => trim((string) ($u['serial_number'] ?? '')), $units);
        if (in_array('', $newSerials, true)) {
            throw ValidationException::withMessages(['units' => ['Ada nomor seri kosong.']]);
        }

        return [$product, $serialUnits, $newSerials];
    }

    /**
     * Buat baris detail: nilai BARU + snapshot lama (before) untuk audit.
     */
    protected function createChangeDetails(DocSerialChange $change, array $units, Collection $serialUnits, array $newSerials): void
    {
        foreach ($units as $i => $u) {
            $su = $serialUnits->get((string) $u['serial_unit_id']);

            DocSerialChangeDetail::create([
                'change_id' => $change->id,
                'serial_unit_id' => $su->id,
                'serial_number' => $newSerials[$i],
                'harga_jual' => isset($u['harga_jual']) && $u['harga_jual'] !== '' ? (float) $u['harga_jual'] : null,
                'grade' => $u['grade'] ?? null,
                'battery_condition' => $u['battery_condition'] ?? null,
                'battery_health' => isset($u['battery_health']) && $u['battery_health'] !== '' ? (float) $u['battery_health'] : null,
                'account_status' => $u['account_status'] ?? null,
                'catatan' => $u['catatan'] ?? null,
                'before' => [
                    'serial_number' => $su->serial_number,
                    'harga_jual' => $su->harga_jual,
                    'grade' => $su->grade,
                    'battery_condition' => $su->battery_condition,
                    'battery_health' => $su->battery_health,
                    'account_status' => $su->account_status,
                    'catatan' => $su->catatan,
                ],
            ]);
        }
    }
}
