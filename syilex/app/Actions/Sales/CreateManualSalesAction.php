<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSales;
use App\Models\MasterProduk;
use App\Services\ManualSalesCalculationService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateManualSalesAction
{
    use RequiresAuthenticatedUser;

    public function execute(array $data): DocSales
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            $sales = new DocSales([
                'nomor_dokumen' => SettingService::generateDocumentNumber(
                    'manual_sales',
                    'doc_sales',
                    date: $data['tanggal'],
                ),
                'source' => 'manual',
                'status' => 'draft',
            ]);

            return $this->save($sales, $data);
        });
    }

    public function save(DocSales $sales, array $data): DocSales
    {
        if (! SettingService::isElektronikEnabled()) {
            $serialIds = MasterProduk::whereIn('id', array_column($data['details'], 'product_id'))
                ->where('is_serial', true)
                ->pluck('id');
            foreach ($data['details'] as $i => $detail) {
                if ($serialIds->contains($detail['product_id']) || ! empty($detail['serial_unit_ids'])) {
                    throw ValidationException::withMessages([
                        "details.{$i}.product_id" => ['Modul Elektronik nonaktif. Fitur serial tidak tersedia.'],
                    ]);
                }
            }
        }

        $calculated = ManualSalesCalculationService::calculate(
            ManualSalesCalculationService::prepareForPersist($data),
            true,
        );
        $totals = $calculated['totals'];
        $tanggal = SettingService::parseDate($data['tanggal']);
        $tempo = (int) ($data['tempo_hari'] ?? 0);

        $sales->fill(array_merge($totals, [
            'tanggal' => $tanggal,
            'warehouse_id' => $data['warehouse_id'],
            'customer_id' => $data['customer_id'],
            'diskon_nota_1_label' => $calculated['labels'][0],
            'diskon_nota_2_label' => $calculated['labels'][1],
            'diskon_nota_3_label' => $calculated['labels'][2],
            'tempo_hari' => $tempo,
            'tanggal_jatuh_tempo' => $tempo > 0 ? $tanggal->copy()->addDays($tempo) : null,
            'cash_payment' => (bool) ($data['cash_payment'] ?? false),
            'cash_metode' => $data['cash_metode'] ?? null,
            'cash_no_referensi' => $data['cash_no_referensi'] ?? null,
            'cash_bank_nama' => $data['cash_bank_nama'] ?? null,
            'cash_bank_rekening' => $data['cash_bank_rekening'] ?? null,
            'biaya_lain_nama' => isset($data['biaya_lain_nama']) ? SettingService::formatName($data['biaya_lain_nama']) : null,
            'total_bayar' => 0,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'notes' => $data['notes'] ?? null,
        ]));
        $sales->save();
        $sales->details()->delete();

        foreach ($calculated['details'] as $detail) {
            $values = [
                'product_id' => $detail['product_id'],
                'unit' => $detail['unit'],
                'konversi' => $detail['konversi'],
                'qty' => $detail['qty'],
                'qty_base' => $detail['qty_base'],
                'harga_satuan' => $detail['harga_satuan'],
                'diskon_total' => $detail['diskon_total'],
                'jumlah' => $detail['jumlah'],
                'promo_id' => $detail['promo_id'] ?? null,
                'serial_unit_ids' => $detail['serial_unit_ids'] ?? null,
            ];
            for ($slot = 1; $slot <= 5; $slot++) {
                foreach (['tipe', 'nilai', 'hasil'] as $field) {
                    $values["diskon_{$slot}_{$field}"] = $detail["diskon_{$slot}_{$field}"];
                }
            }
            $sales->details()->create($values);
        }

        return $sales->load(['customer', 'warehouse', 'details.product']);
    }
}
