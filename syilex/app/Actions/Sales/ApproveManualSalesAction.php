<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Concerns\SettlesCustomerPiutang;
use App\Actions\Sales\Concerns\PostsSalesInventory;
use App\Models\CustomerPiutang;
use App\Models\DocSales;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Services\ManualSalesCalculationService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveManualSalesAction
{
    use PostsSalesInventory;
    use RequiresAuthenticatedUser;
    use SettlesCustomerPiutang;

    public function execute(DocSales $sales): DocSales
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($sales) {
            $sales = DocSales::manual()->whereKey($sales->id)->lockForUpdate()->firstOrFail();
            if (! $sales->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Penjualan sudah diproses.']]);
            }

            $customer = MasterCustomer::whereKey($sales->customer_id)->lockForUpdate()->first();
            $warehouse = MasterWarehouse::whereKey($sales->warehouse_id)->lockForUpdate()->first();
            if (! $customer?->isActive()) {
                throw ValidationException::withMessages(['customer_id' => ['Customer harus aktif.']]);
            }
            if (! $warehouse?->isActive() || ! $warehouse->isSaleable()) {
                throw ValidationException::withMessages(['warehouse_id' => ['Warehouse harus aktif dan saleable.']]);
            }

            $sales->load('details');
            if ($sales->details->isEmpty()) {
                throw ValidationException::withMessages(['details' => ['Penjualan harus memiliki detail.']]);
            }
            $products = MasterProduk::whereIn('id', $sales->details->pluck('product_id'))->get()->keyBy('id');
            if ($products->count() !== $sales->details->pluck('product_id')->unique()->count()
                || $products->contains(fn ($product) => ! $product->isActive())) {
                throw ValidationException::withMessages(['details' => ['Semua produk harus aktif.']]);
            }

            if (! SettingService::isElektronikEnabled()) {
                $serialIds = $products->where('is_serial', true)->pluck('id');
                foreach ($sales->details as $i => $detail) {
                    if ($serialIds->contains($detail->product_id) || ! empty($detail->serial_unit_ids)) {
                        throw ValidationException::withMessages([
                            "details.{$i}.product_id" => ['Modul Elektronik nonaktif. Fitur serial tidak tersedia.'],
                        ]);
                    }
                }
            }

            $data = [
                'customer_id' => $sales->customer_id,
                'details' => $sales->details->map(fn ($detail) => [
                    'detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'unit' => $detail->unit,
                    'konversi' => $detail->konversi,
                    'qty' => (float) $detail->qty,
                    'harga_satuan' => (float) $detail->harga_satuan,
                    'diskon_5_tipe' => $detail->diskon_5_tipe,
                    'diskon_5_nilai' => (float) $detail->diskon_5_nilai,
                    'serial_unit_ids' => $detail->serial_unit_ids ?? [],
                ])->all(),
                'discounts' => [
                    ['tipe' => $sales->diskon_nota_1_tipe, 'nilai' => $sales->diskon_nota_1_nilai],
                    ['tipe' => $sales->diskon_nota_2_tipe, 'nilai' => $sales->diskon_nota_2_nilai],
                    ['tipe' => $sales->diskon_nota_3_tipe, 'nilai' => $sales->diskon_nota_3_nilai],
                ],
                'biaya_kirim' => ['tipe' => $sales->biaya_kirim_tipe, 'nilai' => $sales->biaya_kirim_nilai],
                'biaya_lain' => ['tipe' => $sales->biaya_lain_tipe, 'nilai' => $sales->biaya_lain_nilai],
            ];
            $calculated = ManualSalesCalculationService::calculate($data, true);

            $sales->fill(array_merge($calculated['totals'], [
                'diskon_nota_1_label' => $calculated['labels'][0],
                'diskon_nota_2_label' => $calculated['labels'][1],
                'diskon_nota_3_label' => $calculated['labels'][2],
            ]));
            $sales->save();
            $this->postSalesInventory($sales, $calculated['details']);

            $piutang = CustomerPiutang::create([
                'customer_id' => $sales->customer_id,
                'sales_id' => $sales->id,
                'tanggal' => $sales->tanggal,
                'tanggal_jatuh_tempo' => $sales->tanggal_jatuh_tempo,
                'nominal_awal' => $sales->grand_total,
                'nominal_terbayar' => 0,
                'sisa_piutang' => $sales->grand_total,
                'status' => 'unpaid',
            ]);
            if ($sales->cash_payment && (float) $piutang->sisa_piutang > 0) {
                $this->settleCustomerPiutang($piutang, $sales->tanggal, [
                    'metode_pembayaran' => $sales->cash_metode ?: 'cash',
                    'no_referensi' => $sales->cash_no_referensi,
                    'bank_nama' => $sales->cash_bank_nama,
                    'bank_rekening' => $sales->cash_bank_rekening,
                ]);
            }

            $sales->update([
                'status' => 'completed',
                'approved_at' => SettingService::now(),
                'approved_by' => Auth::id(),
            ]);

            return $sales->load([
                'customer', 'warehouse', 'details.product', 'piutang', 'approvedBy',
            ]);
        });
    }
}
