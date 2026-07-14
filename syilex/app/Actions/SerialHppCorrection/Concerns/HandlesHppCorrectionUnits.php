<?php

namespace App\Actions\SerialHppCorrection\Concerns;

use App\Models\DocSerialHppCorrection;
use App\Models\DocSerialHppCorrectionDetail;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validasi & pembuatan detail Koreksi HPP Serial — dipakai Create & Update (DRY).
 * Hanya unit TERSEDIA yang boleh dikoreksi biaya pokoknya (harga_modal & cost_per_unit).
 */
trait HandlesHppCorrectionUnits
{
    /**
     * @return array{0: MasterProduk, 1: Collection}  [product, serialUnits(keyBy ulid)]
     * @throws ValidationException
     */
    protected function validateHppPayload(int $productId, array $units): array
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

        $ulids = array_map(fn ($u) => (string) ($u['serial_unit_id'] ?? ''), $units);
        $serialUnits = SerialUnit::whereIn('ulid', $ulids)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('ulid');

        foreach ($units as $u) {
            $su = $serialUnits->get((string) ($u['serial_unit_id'] ?? ''));
            if (!$su) {
                throw ValidationException::withMessages(['units' => ['Ada unit yang tidak valid / bukan milik produk ini.']]);
            }
            if ($su->status !== SerialUnit::STATUS_TERSEDIA) {
                throw ValidationException::withMessages([
                    'units' => ["Unit {$su->kode_internal} (SN {$su->serial_number}) tidak bisa dikoreksi (status: {$su->status})."],
                ]);
            }
        }

        return [$product, $serialUnits];
    }

    /**
     * Buat baris detail: komponen biaya BARU + landed (cost_per_unit) terhitung + snapshot lama.
     * Landed = Modal + Biaya Kirim + Biaya Lain + Pajak; Pajak otomatis dari setting pajak
     * pembelian (0 bila tax_purchase_included_in_hpp off).
     */
    protected function createCorrectionDetails(DocSerialHppCorrection $correction, array $units, Collection $serialUnits): void
    {
        $tax = SettingService::getPurchaseTaxSettings(); // ['percent','included_in_hpp',...]
        $percent = (float) ($tax['percent'] ?? 0);
        $included = (bool) ($tax['included_in_hpp'] ?? false);

        foreach ($units as $u) {
            $su = $serialUnits->get((string) $u['serial_unit_id']);

            $modal = (float) $u['harga_modal_baru'];
            $kirim = (float) ($u['biaya_kirim_baru'] ?? 0);
            $lain = (float) ($u['biaya_lain_baru'] ?? 0);
            $dpp = $modal + $kirim + $lain;
            $pajak = $included ? round($dpp * $percent / 100, 2) : 0.0;
            $landed = $dpp + $pajak;

            DocSerialHppCorrectionDetail::create([
                'correction_id' => $correction->id,
                'serial_unit_id' => $su->id,
                'harga_modal_baru' => $modal,
                'biaya_kirim_baru' => $kirim,
                'biaya_lain_baru' => $lain,
                'pajak_baru' => $pajak,
                'cost_per_unit_baru' => $landed,
                'before' => [
                    'harga_modal' => $su->harga_modal,
                    'cost_per_unit' => $su->cost_per_unit,
                ],
            ]);
        }
    }
}
