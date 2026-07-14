<?php

namespace App\Actions\SerialIntake;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialIntake\Concerns\HandlesSerialUnits;
use App\Models\DocSerialIntake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah Pembelian Serial — HANYA saat draft (konsisten dgn PO edit draft).
 * Header diperbarui + daftar unit diganti penuh (unit pending lama dihapus permanen).
 */
class UpdateSerialIntakeAction
{
    use RequiresAuthenticatedUser, HandlesSerialUnits;

    public function execute(DocSerialIntake $intake, array $data): DocSerialIntake
    {
        $this->ensureAuthenticated();

        if (!$intake->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya intake draft yang dapat diubah.']]);
        }

        // Kecualikan unit milik intake ini dari cek unik kode_internal (karena akan diganti)
        [$product, $serials, $kodes] = $this->validateSerialPayload((int) $data['product_id'], $data['units'], $intake->id);

        $units = $data['units'];
        $qty = count($units);
        $calc = $this->calculateFinance($units, $data);
        $tanggal = $data['tanggal'] ?? $intake->tanggal;
        $tempoHari = (int) ($data['tempo_hari'] ?? 0);
        $jatuhTempo = $tempoHari > 0 ? Carbon::parse($tanggal)->addDays($tempoHari)->toDateString() : null;

        return DB::transaction(function () use ($intake, $product, $data, $units, $serials, $kodes, $qty, $calc, $tanggal, $tempoHari, $jatuhTempo) {
            $intake->update(array_merge([
                'tanggal' => $tanggal,
                'product_id' => $product->id,
                'warehouse_id' => (int) $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'no_doc_referensi' => $data['no_doc_referensi'] ?? null,
                'total_unit' => $qty,
                'tempo_hari' => $tempoHari,
                'tanggal_jatuh_tempo' => $jatuhTempo,
                'notes' => $data['notes'] ?? null,
                'cash_payment' => (bool) ($data['cash_payment'] ?? false),
                'cash_metode' => $data['cash_metode'] ?? null,
                'cash_no_referensi' => $data['cash_no_referensi'] ?? null,
                'cash_bank_nama' => $data['cash_bank_nama'] ?? null,
                'cash_bank_rekening' => $data['cash_bank_rekening'] ?? null,
            ], $this->financialColumns($calc)));

            // Ganti unit: hapus permanen unit pending lama, buat baru (status pending)
            $intake->units()->forceDelete();
            $this->createSerialUnits($intake, $units, $serials, $calc, $kodes);

            return $intake->load(['product', 'warehouse', 'supplier', 'units']);
        });
    }
}
