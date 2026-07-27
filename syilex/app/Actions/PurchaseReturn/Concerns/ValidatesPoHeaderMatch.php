<?php

namespace App\Actions\PurchaseReturn\Concerns;

use App\Models\DocPurchaseOrder;
use App\Models\DocPurchaseOrderDetail;
use App\Models\MasterProduk;
use Illuminate\Validation\ValidationException;

trait ValidatesPoHeaderMatch
{
    protected function assertPoMatchesHeader(?int $poId, int $supplierId, int $warehouseId): void
    {
        if (! $poId) {
            return;
        }

        $po = DocPurchaseOrder::query()->find($poId);
        if (! $po) {
            throw ValidationException::withMessages(['po_id' => ['Purchase order tidak ditemukan.']]);
        }
        if ($po->status !== 'approved') {
            throw ValidationException::withMessages(['po_id' => ['Hanya PO approved yang dapat diretur.']]);
        }
        if ((int) $po->supplier_id !== $supplierId) {
            throw ValidationException::withMessages(['po_id' => ['PO tidak cocok dengan supplier yang dipilih.']]);
        }
        if ((int) $po->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(['po_id' => ['PO tidak cocok dengan gudang yang dipilih.']]);
        }
    }

    protected function assertPoDetailOwnership(?int $poId, array $details): void
    {
        if (! $poId) {
            return;
        }

        $serialProductIds = MasterProduk::whereIn('id', collect($details)->pluck('product_id'))
            ->where('is_serial', true)
            ->pluck('id')
            ->flip();

        foreach ($details as $i => $detail) {
            $poDetailId = $detail['po_detail_id'] ?? null;
            $isSerial = $serialProductIds->has($detail['product_id']) || ! empty($detail['serial_unit_ids']);

            if ($poDetailId) {
                $owned = DocPurchaseOrderDetail::where('id', $poDetailId)->where('po_id', $poId)->exists();
                if (! $owned) {
                    throw ValidationException::withMessages([
                        "details.{$i}.po_detail_id" => ['Baris PO tidak cocok dengan PO yang dipilih.'],
                    ]);
                }
            } elseif (! $isSerial) {
                throw ValidationException::withMessages([
                    "details.{$i}.po_detail_id" => ['po_detail_id wajib bila ada PO.'],
                ]);
            }
        }
    }
}
