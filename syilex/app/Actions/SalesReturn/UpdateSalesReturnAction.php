<?php

namespace App\Actions\SalesReturn;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSalesReturn;
use App\Services\SalesReturnCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSalesReturnAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSalesReturn $return, array $data): DocSalesReturn
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($return, $data) {
            $return = DocSalesReturn::manual()->lockForUpdate()->findOrFail($return->id);
            if (! $return->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Hanya retur draft yang dapat diubah.']]);
            }

            $isLinked = (bool) $return->sales_id;

            // Mode (linked/free) & sales_id immutable via controller — retur "menjadi bebas" baru
            // hanya terjadi di CreateSalesReturnAction (yang sudah menolaknya saat setting nonaktif).
            // Draft bebas yang SUDAH ADA di sini pasti dibuat saat mode bebas masih diizinkan —
            // tetap boleh diedit walau setting kemudian dinonaktifkan (grandfathered, tidak dikunci).

            if ($isLinked) {
                $sales = $return->sales()->with('details.product')->lockForUpdate()->firstOrFail();
                SalesReturnCalculationService::validateReturnable($sales, $data['details'], $return->id);
                $calculation = SalesReturnCalculationService::calculate($sales, $data['details']);
            } else {
                $customerId = (int) ($data['customer_id'] ?? $return->customer_id);
                $warehouseId = (int) ($data['warehouse_id'] ?? $return->warehouse_id);
                if ($customerId <= 0 || $warehouseId <= 0) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['Customer dan gudang wajib untuk retur bebas.'],
                    ]);
                }
                $calculation = SalesReturnCalculationService::calculateFree($data['details']);
                $return->customer_id = $customerId;
                $return->warehouse_id = $warehouseId;
            }

            $return->update([
                'tanggal' => $data['tanggal'],
                'customer_id' => $return->customer_id,
                'warehouse_id' => $return->warehouse_id,
                'subtotal' => $calculation['subtotal'],
                'pembulatan' => $calculation['pembulatan'],
                'grand_total' => $calculation['grand_total'],
                'notes' => $data['notes'] ?? null,
            ]);
            $return->details()->delete();
            $return->details()->createMany($calculation['details']);

            return $return->fresh(['sales', 'warehouse', 'customer', 'details.product']);
        });
    }
}
