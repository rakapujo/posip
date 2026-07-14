<?php

namespace App\Actions\SerialIntake;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialIntake\Concerns\HandlesSerialUnits;
use App\Models\DocSerialIntake;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Buat Pembelian Serial sebagai DRAFT (Fase 2 modul serial A+, alur draft → approved).
 *
 * Create hanya mencatat header (draft) + unit (status 'pending') — BELUM menyentuh stok/HPP.
 * Komit stok + HPP weighted-average + stock_card dilakukan saat approve (ApproveSerialIntakeAction),
 * konsisten dgn PurchaseOrder (gudang buat draft, admin approve).
 */
class CreateSerialIntakeAction
{
    use RequiresAuthenticatedUser, HandlesSerialUnits;

    public function execute(array $data): DocSerialIntake
    {
        $this->ensureAuthenticated();

        [$product, $serials, $kodes] = $this->validateSerialPayload((int) $data['product_id'], $data['units']);

        $units = $data['units'];
        $qty = count($units);
        $calc = $this->calculateFinance($units, $data);
        $tanggal = $data['tanggal'] ?? now();
        $tempoHari = (int) ($data['tempo_hari'] ?? 0);
        $jatuhTempo = $tempoHari > 0 ? Carbon::parse($tanggal)->addDays($tempoHari)->toDateString() : null;

        return DB::transaction(function () use ($product, $data, $units, $serials, $kodes, $qty, $calc, $tanggal, $tempoHari, $jatuhTempo) {
            $nomor = SettingService::generateDocumentNumber('serial_intake', 'doc_serial_intake');

            $intake = DocSerialIntake::create(array_merge([
                'nomor_dokumen' => $nomor,
                'tanggal' => $tanggal,
                'product_id' => $product->id,
                'warehouse_id' => (int) $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'no_doc_referensi' => $data['no_doc_referensi'] ?? null,
                'total_unit' => $qty,
                'tempo_hari' => $tempoHari,
                'tanggal_jatuh_tempo' => $jatuhTempo,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'cash_payment' => (bool) ($data['cash_payment'] ?? false),
                'cash_metode' => $data['cash_metode'] ?? null,
                'cash_no_referensi' => $data['cash_no_referensi'] ?? null,
                'cash_bank_nama' => $data['cash_bank_nama'] ?? null,
                'cash_bank_rekening' => $data['cash_bank_rekening'] ?? null,
            ], $this->financialColumns($calc)));

            $this->createSerialUnits($intake, $units, $serials, $calc, $kodes);

            return $intake->load(['product', 'warehouse', 'supplier', 'units']);
        });
    }
}
