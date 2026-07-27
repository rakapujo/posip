<?php

namespace App\Actions\PurchaseReturn\Concerns;

use App\Models\DocSerialIntake;
use Illuminate\Validation\ValidationException;

trait ValidatesSerialIntakeHeaderMatch
{
    protected function assertXorPoAndSerialIntake(?int $poId, ?int $serialIntakeId): void
    {
        if ($poId && $serialIntakeId) {
            throw ValidationException::withMessages([
                'serial_intake_id' => ['Tidak boleh mengisi referensi PO dan PBS sekaligus.'],
            ]);
        }
    }

    protected function assertSerialIntakeMatchesHeader(?int $serialIntakeId, int $supplierId, int $warehouseId): void
    {
        if (! $serialIntakeId) {
            return;
        }

        $intake = DocSerialIntake::query()->find($serialIntakeId);
        if (! $intake) {
            throw ValidationException::withMessages(['serial_intake_id' => ['Pembelian serial tidak ditemukan.']]);
        }
        if ($intake->status !== 'approved') {
            throw ValidationException::withMessages(['serial_intake_id' => ['Hanya PBS approved yang dapat diretur.']]);
        }
        if ((int) $intake->supplier_id !== $supplierId) {
            throw ValidationException::withMessages(['serial_intake_id' => ['PBS tidak cocok dengan supplier yang dipilih.']]);
        }
        if ((int) $intake->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(['serial_intake_id' => ['PBS tidak cocok dengan gudang yang dipilih.']]);
        }
    }
}
