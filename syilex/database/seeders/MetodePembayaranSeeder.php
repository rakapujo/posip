<?php

namespace Database\Seeders;

use App\Models\MasterMetodePembayaran;
use Illuminate\Database\Seeder;

class MetodePembayaranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default CASH payment method
        MasterMetodePembayaran::firstOrCreate(
            ['kode_pembayaran' => 'PYB0001'],
            [
                'nama_pembayaran' => 'CASH',
                'metode' => 'tunai',
                'jenis' => null,
                'nama_akun' => null,
                'nomor_akun' => null,
                'logo' => null,
                'qr_code' => null,
                'biaya_tambahan_tipe' => 'none',
                'biaya_tambahan_nilai' => 0,
                'status' => 'active',
            ]
        );

        $this->command->info('Metode Pembayaran seeded successfully!');
        $this->command->table(
            ['Kode', 'Nama', 'Metode', 'Status'],
            [
                ['PYB0001', 'CASH', 'tunai', 'active'],
            ]
        );
    }
}
