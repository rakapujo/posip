<?php

namespace Database\Seeders;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Contoh data produk SERIAL + unit tersedia — untuk menguji alur serial
 * (POS jual/void/retur, Transfer, Adjustment, Opname, Koreksi HPP).
 *
 * Idempotent: aman dijalankan ulang di DB yang sudah berisi data
 *   php artisan db:seed --class=SerialSampleSeeder
 * Produk yang sudah ada akan dilewati (stok & unit tak digandakan).
 */
class SerialSampleSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('email', 'admin@posip.com')->first()?->id ?? 1;

        $warehouse = MasterWarehouse::where('kode_warehouse', 'WH_PUSAT')->first()
            ?? MasterWarehouse::where('is_saleable', true)->first();

        if (!$warehouse) {
            $this->command->warn('SerialSampleSeeder dilewati: tidak ada warehouse saleable. Jalankan MasterSeeder dulu.');
            return;
        }

        $serialProducts = [
            [
                'kode_produk' => 'IP13_128',
                'nama_produk' => 'iPhone 13 128GB',
                'units' => [
                    ['sn' => 'IP13-0001', 'modal' => 9000000, 'jual' => 11000000, 'grade' => 'A', 'batt' => 92, 'akun' => 'iCloud clean'],
                    ['sn' => 'IP13-0002', 'modal' => 8800000, 'jual' => 10800000, 'grade' => 'B', 'batt' => 85, 'akun' => 'iCloud clean'],
                    ['sn' => 'IP13-0003', 'modal' => 9200000, 'jual' => 11200000, 'grade' => 'A', 'batt' => 95, 'akun' => 'iCloud clean'],
                ],
            ],
            [
                'kode_produk' => 'IP14_256',
                'nama_produk' => 'iPhone 14 256GB',
                'units' => [
                    ['sn' => 'IP14-0001', 'modal' => 13000000, 'jual' => 15500000, 'grade' => 'A', 'batt' => 98, 'akun' => 'iCloud clean'],
                    ['sn' => 'IP14-0002', 'modal' => 12800000, 'jual' => 15200000, 'grade' => 'A', 'batt' => 97, 'akun' => 'iCloud clean'],
                ],
            ],
        ];

        $created = 0;

        foreach ($serialProducts as $sp) {
            $costs = array_column($sp['units'], 'modal');
            $count = count($costs);
            $avg = array_sum($costs) / $count;

            $product = MasterProduk::firstOrCreate(
                ['kode_produk' => $sp['kode_produk']],
                [
                    'ulid' => Str::ulid(),
                    'barcode' => null,
                    'nama_produk' => $sp['nama_produk'],
                    'is_serial' => true,
                    'minimum_stok' => 0,
                    'avg_cost' => $avg,
                    'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
                    'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
                    'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
                    'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
                    'status' => 'active',
                    'created_by' => $adminId,
                    'updated_by' => $adminId,
                ]
            );

            // Sudah ada → jangan gandakan stok/unit (jaga invariant)
            if (!$product->wasRecentlyCreated) {
                continue;
            }

            // inventory_stock + stock_card PURCHASE konsisten (invariant data:verify)
            StockCard::$skipObserver = true;
            InventoryStock::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                ['qty' => $count, 'avg_cost' => $avg]
            );
            StockCard::record([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'transaction_type' => 'PURCHASE',
                'tanggal' => now(),
                'qty_in' => $count,
                'qty_out' => 0,
                'cost_per_unit' => $avg,
            ]);
            StockCard::$skipObserver = false;

            foreach ($sp['units'] as $u) {
                SerialUnit::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'serial_number' => $u['sn'],
                    'harga_modal' => $u['modal'],
                    'cost_per_unit' => $u['modal'],
                    'harga_jual' => $u['jual'],
                    'grade' => $u['grade'],
                    'battery_condition' => 'Sehat',
                    'battery_health' => $u['batt'],
                    'account_status' => $u['akun'],
                    'status' => 'tersedia',
                    'created_by' => $adminId,
                ]);
            }
            $created++;
        }

        $this->command->info("- Produk Serial contoh: {$created} produk baru (di gudang {$warehouse->nama_warehouse})");
    }
}
