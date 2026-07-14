<?php

namespace App\Actions\SerialIntake\Concerns;

use App\Models\DocSerialIntake;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Services\PurchaseOrderCalculationService;
use Illuminate\Validation\ValidationException;

/**
 * Validasi & pembuatan unit serial — dipakai Create & Update intake (DRY).
 */
trait HandlesSerialUnits
{
    /**
     * Validasi produk serial + unit.
     *
     * Nomor seri (SN) TIDAK wajib unik — boleh kembar, bahkan dalam 1 produk (SN ponsel
     * sering kembar/typo dari supplier). Identitas unik unit = `kode_internal` (UNIQUE global):
     * opsional di input (kosong = auto KI-{id} oleh model); yang DIISI wajib unik (cek withTrashed
     * agar sejajar dgn UNIQUE index DB yg memuat baris soft-deleted).
     *
     * @param  int|null  $excludeIntakeId  Intake yang unitnya dikecualikan dari cek unik kode_internal (untuk edit).
     * @return array{0: MasterProduk, 1: array, 2: array}  [product, normalizedSerials, normalizedKodes]
     */
    protected function validateSerialPayload(int $productId, array $units, ?int $excludeIntakeId = null): array
    {
        $product = MasterProduk::find($productId);
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Produk tidak ditemukan.']]);
        }
        if (!$product->is_serial) {
            throw ValidationException::withMessages(['product_id' => ['Produk ini bukan produk serial.']]);
        }
        if (count($units) === 0) {
            throw ValidationException::withMessages(['units' => ['Minimal 1 unit (nomor seri) wajib diisi.']]);
        }

        $serials = array_map(fn ($u) => trim((string) ($u['serial_number'] ?? '')), $units);
        if (in_array('', $serials, true)) {
            throw ValidationException::withMessages(['units' => ['Ada nomor seri kosong.']]);
        }

        // Kode internal: opsional (kosong = auto). Yang diisi wajib unik antar-input & global.
        $kodes = array_map(fn ($u) => trim((string) ($u['kode_internal'] ?? '')), $units);
        $provided = array_values(array_filter($kodes, fn ($k) => $k !== ''));
        if (count($provided) > 0) {
            $dupInPayload = array_diff_assoc($provided, array_unique($provided));
            if (count($dupInPayload) > 0) {
                throw ValidationException::withMessages([
                    'units' => ['Kode internal duplikat dalam input: ' . implode(', ', array_unique($dupInPayload))],
                ]);
            }

            // Format "KI-<angka>" dicadangkan untuk auto-generate (KI-{id}). Override yang menabrak
            // pola ini bisa bentrok dgn kode auto unit lain di masa depan → tolak (pakai kode lain).
            $reserved = array_values(array_filter($provided, fn ($k) => preg_match('/^KI-\d+$/i', $k)));
            if (count($reserved) > 0) {
                throw ValidationException::withMessages([
                    'units' => ['Kode internal "' . implode(', ', $reserved) . '" memakai format KI-angka yang dicadangkan sistem. Pakai kode lain.'],
                ]);
            }

            $query = SerialUnit::withTrashed()->whereIn('kode_internal', $provided);
            if ($excludeIntakeId !== null) {
                $query->where('intake_id', '!=', $excludeIntakeId);
            }
            $existing = $query->pluck('kode_internal')->all();
            if (count($existing) > 0) {
                throw ValidationException::withMessages([
                    'units' => ['Kode internal sudah dipakai unit lain: ' . implode(', ', $existing)],
                ]);
            }
        }

        return [$product, $serials, $kodes];
    }

    /**
     * Buat baris serial_units status 'pending' (belum commit stok — menunggu approve).
     * cost_per_unit = landed cost dari hasil PurchaseOrderCalculationService.
     */
    protected function createSerialUnits(DocSerialIntake $intake, array $units, array $serials, array $calc, array $kodes = []): void
    {
        $calcDetails = $calc['details'] ?? [];
        foreach ($units as $i => $u) {
            SerialUnit::create([
                'product_id' => $intake->product_id,
                'warehouse_id' => $intake->warehouse_id,
                'intake_id' => $intake->id,
                'serial_number' => $serials[$i],
                // Kosong → model hook auto-isi KI-{id}; diisi → override (sudah divalidasi unik).
                'kode_internal' => ($kodes[$i] ?? '') !== '' ? $kodes[$i] : null,
                'harga_modal' => (float) ($u['harga_modal'] ?? 0),
                'cost_per_unit' => (float) ($calcDetails[$i]['cost_per_unit'] ?? ($u['harga_modal'] ?? 0)),
                'harga_jual' => isset($u['harga_jual']) && $u['harga_jual'] !== '' ? (float) $u['harga_jual'] : null,
                'grade' => $u['grade'] ?? null,
                'battery_condition' => $u['battery_condition'] ?? null,
                'battery_health' => isset($u['battery_health']) && $u['battery_health'] !== '' ? (float) $u['battery_health'] : null,
                'account_status' => $u['account_status'] ?? null,
                'status' => 'pending',
                'catatan' => $u['catatan'] ?? null,
            ]);
        }
    }

    /**
     * Hitung finansial (diskon header + biaya + pajak + grand total + alokasi cost_per_unit)
     * via PurchaseOrderCalculationService — tiap unit = 1 detail (qty 1, harga = modal).
     */
    protected function calculateFinance(array $units, array $headerData): array
    {
        $details = array_map(fn ($u) => [
            'product_id' => 0,
            'unit_used' => 'UNIT',
            'unit_konversi' => 1,
            'qty_in_unit' => 1,
            'harga_per_unit' => (float) ($u['harga_modal'] ?? 0),
        ], $units);

        return PurchaseOrderCalculationService::calculateTotals([
            'details' => $details,
            'diskon_1_tipe' => $headerData['diskon_1_tipe'] ?? 'none',
            'diskon_1_nilai' => $headerData['diskon_1_nilai'] ?? 0,
            'diskon_2_tipe' => $headerData['diskon_2_tipe'] ?? 'none',
            'diskon_2_nilai' => $headerData['diskon_2_nilai'] ?? 0,
            'diskon_3_tipe' => $headerData['diskon_3_tipe'] ?? 'none',
            'diskon_3_nilai' => $headerData['diskon_3_nilai'] ?? 0,
            'biaya_kirim_tipe' => $headerData['biaya_kirim_tipe'] ?? 'none',
            'biaya_kirim_nilai' => $headerData['biaya_kirim_nilai'] ?? 0,
            'biaya_lain_nama' => $headerData['biaya_lain_nama'] ?? null,
            'biaya_lain_tipe' => $headerData['biaya_lain_tipe'] ?? 'none',
            'biaya_lain_nilai' => $headerData['biaya_lain_nilai'] ?? 0,
        ]);
    }

    /**
     * Map hasil calculateTotals → kolom header doc_serial_intake.
     */
    protected function financialColumns(array $calc): array
    {
        return [
            'subtotal' => $calc['subtotal'],
            'total_modal' => $calc['subtotal'],
            'diskon_1_tipe' => $calc['diskon_1_tipe'], 'diskon_1_nilai' => $calc['diskon_1_nilai'], 'diskon_1_hasil' => $calc['diskon_1_hasil'],
            'diskon_2_tipe' => $calc['diskon_2_tipe'], 'diskon_2_nilai' => $calc['diskon_2_nilai'], 'diskon_2_hasil' => $calc['diskon_2_hasil'],
            'diskon_3_tipe' => $calc['diskon_3_tipe'], 'diskon_3_nilai' => $calc['diskon_3_nilai'], 'diskon_3_hasil' => $calc['diskon_3_hasil'],
            'total_diskon_header' => $calc['total_diskon_header'],
            'total_setelah_diskon' => $calc['total_setelah_diskon'],
            'biaya_kirim_tipe' => $calc['biaya_kirim_tipe'], 'biaya_kirim_nilai' => $calc['biaya_kirim_nilai'], 'biaya_kirim_hasil' => $calc['biaya_kirim_hasil'],
            'biaya_lain_nama' => $calc['biaya_lain_nama'], 'biaya_lain_tipe' => $calc['biaya_lain_tipe'], 'biaya_lain_nilai' => $calc['biaya_lain_nilai'], 'biaya_lain_hasil' => $calc['biaya_lain_hasil'],
            'total_biaya_tambahan' => $calc['total_biaya_tambahan'],
            'dpp' => $calc['dpp'],
            'pajak_nama' => $calc['pajak_nama'], 'pajak_persen' => $calc['pajak_persen'], 'pajak_nominal' => $calc['pajak_nominal'],
            'pembulatan' => $calc['pembulatan'],
            'grand_total' => $calc['grand_total'],
        ];
    }
}
