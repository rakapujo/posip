<?php

namespace App\Actions\SalesReturn;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Services\SalesReturnCalculationService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSalesReturnAction
{
    use RequiresAuthenticatedUser;

    public function execute(array $data): DocSalesReturn
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            $isLinked = ! empty($data['sales_id']);

            if ($isLinked) {
                $sales = DocSales::with('details.product')->findOrFail($data['sales_id']);
                if ($sales->source !== 'manual' || ! $sales->isCompleted()) {
                    throw ValidationException::withMessages([
                        'sales_id' => ['Hanya penjualan backoffice completed yang dapat diretur.'],
                    ]);
                }
                if (! empty($data['customer_id']) && (int) $data['customer_id'] !== (int) $sales->customer_id) {
                    throw ValidationException::withMessages(['customer_id' => ['Customer tidak cocok dengan nota.']]);
                }
                if (! empty($data['warehouse_id']) && (int) $data['warehouse_id'] !== (int) $sales->warehouse_id) {
                    throw ValidationException::withMessages(['warehouse_id' => ['Gudang tidak cocok dengan nota.']]);
                }

                SalesReturnCalculationService::validateReturnable($sales, $data['details']);
                $calculation = SalesReturnCalculationService::calculate($sales, $data['details']);
                $customerId = $sales->customer_id;
                $warehouseId = $sales->warehouse_id;
                $salesId = $sales->id;
                $pajakNama = $sales->pajak_nama;
            } else {
                if (! SettingService::isSalesReturnFreeAllowed()) {
                    throw ValidationException::withMessages([
                        'sales_id' => ['Mode retur bebas dinonaktifkan di pengaturan. Pilih dokumen referensi.'],
                    ]);
                }
                $customerId = (int) ($data['customer_id'] ?? 0);
                $warehouseId = (int) ($data['warehouse_id'] ?? 0);
                if ($customerId <= 0 || $warehouseId <= 0) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['Customer dan gudang wajib untuk retur bebas.'],
                    ]);
                }
                $calculation = SalesReturnCalculationService::calculateFree($data['details']);
                $salesId = null;
                $pajakNama = null;
            }

            $return = DocSalesReturn::create([
                'nomor_dokumen' => SettingService::generateDocumentNumber(
                    'sales_return',
                    'doc_sales_returns',
                    date: $data['tanggal'],
                ),
                'source' => 'manual',
                'tanggal' => $data['tanggal'],
                'sales_id' => $salesId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'subtotal' => $calculation['subtotal'],
                'pajak_nama' => $pajakNama,
                'pajak_persen' => 0,
                'pajak_nominal' => 0,
                'pembulatan' => $calculation['pembulatan'],
                'grand_total' => $calculation['grand_total'],
                'refund_method' => null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $return->details()->createMany($calculation['details']);

            return $return->fresh([
                'sales:id,ulid,nomor_dokumen',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'customer:id,ulid,kode_customer,nama',
                'details.product:id,ulid,kode_produk,nama_produk,is_serial',
            ]);
        });
    }
}
