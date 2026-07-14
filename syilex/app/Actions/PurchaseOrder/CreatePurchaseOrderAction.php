<?php

namespace App\Actions\PurchaseOrder;

use App\Models\DocPurchaseOrder;
use App\Models\DocPurchaseOrderDetail;
use App\Models\MasterSupplier;
use App\Services\PurchaseOrderCalculationService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreatePurchaseOrderAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocPurchaseOrder
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'purchase_order',
                'doc_purchase_order',
                'nomor_dokumen'
            );

            // Get supplier for tempo default
            $supplier = MasterSupplier::find($data['supplier_id']);
            $tempoHari = $data['tempo_hari'] ?? $supplier->tempo_default ?? 0;

            // Calculate tanggal_jatuh_tempo
            $tanggalPo = Carbon::parse($data['tanggal_po']);
            $tanggalJatuhTempo = $tempoHari > 0
                ? $tanggalPo->copy()->addDays($tempoHari)
                : null;

            // Calculate all totals
            $calculated = PurchaseOrderCalculationService::calculateTotals($data);

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Format no_doc_referensi if provided
            $noDocReferensi = isset($data['no_doc_referensi'])
                ? SettingService::formatName($data['no_doc_referensi'])
                : null;

            // Create header
            $po = DocPurchaseOrder::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal_po' => $data['tanggal_po'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'no_doc_referensi' => $noDocReferensi,
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
                'total_setelah_diskon' => $calculated['total_setelah_diskon'],
                'biaya_kirim_tipe' => $calculated['biaya_kirim_tipe'],
                'biaya_kirim_nilai' => $calculated['biaya_kirim_nilai'],
                'biaya_kirim_hasil' => $calculated['biaya_kirim_hasil'],
                'biaya_lain_nama' => $calculated['biaya_lain_nama'],
                'biaya_lain_tipe' => $calculated['biaya_lain_tipe'],
                'biaya_lain_nilai' => $calculated['biaya_lain_nilai'],
                'biaya_lain_hasil' => $calculated['biaya_lain_hasil'],
                'total_biaya_tambahan' => $calculated['total_biaya_tambahan'],
                'dpp' => $calculated['dpp'],
                'pajak_nama' => $calculated['pajak_nama'],
                'pajak_persen' => $calculated['pajak_persen'],
                'pajak_nominal' => $calculated['pajak_nominal'],
                'pembulatan' => $calculated['pembulatan'],
                'grand_total' => $calculated['grand_total'],
                'tempo_hari' => $tempoHari,
                'tanggal_jatuh_tempo' => $tanggalJatuhTempo,
                'notes' => $notes,
                'status' => 'draft',
                'cash_payment' => (bool) ($data['cash_payment'] ?? false),
                'cash_metode' => $data['cash_metode'] ?? null,
                'cash_no_referensi' => $data['cash_no_referensi'] ?? null,
                'cash_bank_nama' => $data['cash_bank_nama'] ?? null,
                'cash_bank_rekening' => $data['cash_bank_rekening'] ?? null,
            ]);

            // Create details
            foreach ($calculated['details'] as $detail) {
                DocPurchaseOrderDetail::create([
                    'po_id' => $po->id,
                    'product_id' => $detail['product_id'],
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
                    'cost_per_unit' => $detail['cost_per_unit'],
                ]);
            }

            // Load relations for response
            $po->load(['supplier', 'warehouse', 'details.product', 'createdBy']);

            return $po;
        });
    }
}
