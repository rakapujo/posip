<?php

namespace App\Actions\PurchaseReturn;

use App\Actions\PurchaseReturn\Concerns\PreparesSerialReturnDetails;
use App\Models\DocPurchaseReturn;
use App\Models\DocPurchaseReturnDetail;
use App\Services\PurchaseReturnCalculationService;
use Illuminate\Support\Facades\DB;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class UpdatePurchaseReturnAction
{
    use RequiresAuthenticatedUser;
    use PreparesSerialReturnDetails;

    /**
     * Update an existing purchase return with details.
     */
    public function execute(DocPurchaseReturn $retur, array $data): DocPurchaseReturn
    {
        $this->ensureAuthenticated();

        if (!$retur->isDraft()) {
            throw new \Exception('Hanya retur dengan status draft yang dapat diedit');
        }

        return DB::transaction(function () use ($retur, $data) {
            // Produk serial: turunkan qty & harga (rata-rata modal) dari unit terpilih
            $data['details'] = $this->prepareSerialReturnDetails($data['details']);

            // Calculate totals
            $calculated = PurchaseReturnCalculationService::calculateTotals($data);

            // Update header
            $retur->update([
                'tanggal' => $data['tanggal'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'po_id' => $data['po_id'] ?? null,
                'subtotal' => $calculated['subtotal'],
                'diskon_1_tipe' => $calculated['diskon_1_tipe'],
                'diskon_1_nilai' => $calculated['diskon_1_nilai'],
                'diskon_1_hasil' => $calculated['diskon_1_hasil'],
                'diskon_2_tipe' => $calculated['diskon_2_tipe'],
                'diskon_2_nilai' => $calculated['diskon_2_nilai'],
                'diskon_2_hasil' => $calculated['diskon_2_hasil'],
                'diskon_3_tipe' => $calculated['diskon_3_tipe'],
                'diskon_3_nilai' => $calculated['diskon_3_nilai'],
                'diskon_3_hasil' => $calculated['diskon_3_hasil'],
                'total_diskon_header' => $calculated['total_diskon_header'],
                'dpp' => $calculated['dpp'],
                'pajak_nama' => $calculated['pajak_nama'],
                'pajak_persen' => $calculated['pajak_persen'],
                'pajak_nominal' => $calculated['pajak_nominal'],
                'pembulatan' => $calculated['pembulatan'],
                'nilai_kalkulasi' => $calculated['nilai_kalkulasi'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Delete existing details
            $retur->details()->delete();

            // Create new details (serial_unit_ids dilampirkan per indeks)
            foreach ($calculated['details'] as $i => $detail) {
                DocPurchaseReturnDetail::create([
                    'retur_id' => $retur->id,
                    'product_id' => $detail['product_id'],
                    'serial_unit_ids' => $data['details'][$i]['serial_unit_ids'] ?? null,
                    'po_detail_id' => $detail['po_detail_id'] ?? null,
                    'unit_used' => $detail['unit_used'],
                    'unit_konversi' => $detail['unit_konversi'],
                    'qty_in_unit' => $detail['qty_in_unit'],
                    'qty_in_base' => $detail['qty_in_base'],
                    'harga_per_unit' => $detail['harga_per_unit'],
                    'harga_per_base' => $detail['harga_per_base'],
                    'harga_bruto' => $detail['harga_bruto'],
                    'diskon_1_tipe' => $detail['diskon_1_tipe'],
                    'diskon_1_nilai' => $detail['diskon_1_nilai'],
                    'diskon_1_hasil' => $detail['diskon_1_hasil'],
                    'diskon_2_tipe' => $detail['diskon_2_tipe'],
                    'diskon_2_nilai' => $detail['diskon_2_nilai'],
                    'diskon_2_hasil' => $detail['diskon_2_hasil'],
                    'diskon_3_tipe' => $detail['diskon_3_tipe'],
                    'diskon_3_nilai' => $detail['diskon_3_nilai'],
                    'diskon_3_hasil' => $detail['diskon_3_hasil'],
                    'diskon_4_tipe' => $detail['diskon_4_tipe'],
                    'diskon_4_nilai' => $detail['diskon_4_nilai'],
                    'diskon_4_hasil' => $detail['diskon_4_hasil'],
                    'diskon_5_tipe' => $detail['diskon_5_tipe'],
                    'diskon_5_nilai' => $detail['diskon_5_nilai'],
                    'diskon_5_hasil' => $detail['diskon_5_hasil'],
                    'total_diskon_item' => $detail['total_diskon_item'],
                    'subtotal' => $detail['subtotal'],
                ]);
            }

            return $retur->fresh(['details.product', 'supplier', 'warehouse']);
        });
    }
}
