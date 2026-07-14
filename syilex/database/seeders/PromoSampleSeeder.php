<?php

namespace Database\Seeders;

use App\Models\DocPromo;
use App\Models\MasterGrup;
use App\Models\MasterKategori;
use App\Models\MasterKategoriCustomer;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterTipeCustomer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder untuk sample data promo.
 * Mencakup berbagai skenario test:
 * - Promo global (tanpa filter)
 * - Promo by kategori customer (GOLD, SILVER)
 * - Promo by tipe customer (RESELLER)
 * - Promo kombinasi (GOLD + RESELLER)
 * - Promo by terminal
 * - Target type: semua, produk, grup, kategori
 * - Discount type: percent, nominal, combined
 *
 * Usage:
 *   php artisan db:seed --class=PromoSampleSeeder
 */
class PromoSampleSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();
        if (!$user) {
            $this->command->error('No user found. Run UserSeeder first.');
            return;
        }

        $katGold   = MasterKategoriCustomer::where('kode_kategori', 'GOLD')->first();
        $katSilver = MasterKategoriCustomer::where('kode_kategori', 'SILVER')->first();
        $katPlat   = MasterKategoriCustomer::where('kode_kategori', 'PLATINUM')->first();
        $tipeResel = MasterTipeCustomer::where('kode_tipe', 'RESELLER')->first();
        $terminal  = MasterPosTerminal::first();

        $produkIndomie = MasterProduk::where('kode_produk', 'INDMIE_GRG')->first();
        $produkRoma    = MasterProduk::where('kode_produk', 'ROMA_KELAPA')->first();
        $grupMieGoreng = MasterGrup::where('kode_grup', 'MIE_GORENG')->first();
        $katSnack      = MasterKategori::where('kode_kategori', 'SNACK')->first();

        $promos = [
            // 1. Promo global — semua customer, semua produk 5%
            [
                'promo' => [
                    'kode_promo'      => 'PM-GLOBAL5',
                    'nama_promo'      => 'Diskon Umum 5%',
                    'deskripsi'       => 'Berlaku untuk semua customer & semua produk',
                    'tanggal_mulai'   => today()->subDay()->toDateString(),
                    'tanggal_selesai' => today()->addMonths(3)->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 5, 'keterangan' => 'Diskon dasar 5%'],
                ],
            ],

            // 2. Promo kategori GOLD — diskon 15%
            [
                'promo' => [
                    'kode_promo'           => 'PM-GOLD15',
                    'nama_promo'           => 'Diskon Member GOLD 15%',
                    'deskripsi'            => 'Khusus customer kategori GOLD',
                    'customer_category_id' => $katGold?->id,
                    'tanggal_mulai'        => today()->subDay()->toDateString(),
                    'tanggal_selesai'      => today()->addMonths(6)->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 15, 'keterangan' => 'GOLD member benefit'],
                ],
            ],

            // 3. Promo kategori SILVER — diskon 10%
            [
                'promo' => [
                    'kode_promo'           => 'PM-SILVER10',
                    'nama_promo'           => 'Diskon Member SILVER 10%',
                    'deskripsi'            => 'Khusus customer kategori SILVER',
                    'customer_category_id' => $katSilver?->id,
                    'tanggal_mulai'        => today()->subDay()->toDateString(),
                    'tanggal_selesai'      => today()->addMonths(6)->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10],
                ],
            ],

            // 4. Promo PLATINUM + RESELLER (kombinasi kategori + tipe)
            [
                'promo' => [
                    'kode_promo'           => 'PM-PLAT-RES',
                    'nama_promo'           => 'Diskon PLATINUM Reseller 20%',
                    'deskripsi'            => 'Kombinasi: PLATINUM + RESELLER',
                    'customer_category_id' => $katPlat?->id,
                    'customer_type_id'     => $tipeResel?->id,
                    'tanggal_mulai'        => today()->subDay()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 20],
                ],
            ],

            // 5. Promo khusus produk Indomie — min qty 3 diskon nominal 2000
            [
                'promo' => [
                    'kode_promo'      => 'PM-INDOMIE',
                    'nama_promo'      => 'Beli 3 Indomie Diskon Rp 2.000',
                    'deskripsi'       => 'Diskon nominal untuk Indomie Goreng',
                    'tanggal_mulai'   => today()->subDay()->toDateString(),
                    'tanggal_selesai' => today()->addMonth()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'produk', 'target_id' => $produkIndomie?->id, 'min_qty' => 3, 'diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 2000, 'keterangan' => 'Min beli 3 pcs'],
                ],
            ],

            // 6. Promo grup MIE_GORENG — 12% min 2 pcs
            [
                'promo' => [
                    'kode_promo'    => 'PM-MIEGORENG',
                    'nama_promo'    => 'Grup Mie Goreng 12%',
                    'deskripsi'     => 'Diskon untuk semua produk grup Mie Goreng',
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'grup', 'target_id' => $grupMieGoreng?->id, 'min_qty' => 2, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 12],
                ],
            ],

            // 7. Promo kategori SNACK untuk GOLD — bertingkat 10%+5%
            [
                'promo' => [
                    'kode_promo'           => 'PM-SNACKGOLD',
                    'nama_promo'           => 'SNACK GOLD Bertingkat 10%+5%',
                    'deskripsi'            => 'Diskon bertingkat SNACK untuk GOLD',
                    'customer_category_id' => $katGold?->id,
                    'tanggal_mulai'        => today()->subDay()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'kategori', 'target_id' => $katSnack?->id, 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10, 'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 5, 'keterangan' => 'Double discount GOLD'],
                ],
            ],

            // 8. Roma triple discount + min qty 5
            [
                'promo' => [
                    'kode_promo'    => 'PM-ROMA3X',
                    'nama_promo'    => 'Roma Kelapa Triple Discount',
                    'deskripsi'     => '3 slot diskon Roma Kelapa min 5 pcs',
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'produk', 'target_id' => $produkRoma?->id, 'min_qty' => 5, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 8, 'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 5, 'diskon_3_tipe' => 'nominal', 'diskon_3_nilai' => 500],
                ],
            ],

            // 9. Promo terminal-specific
            [
                'promo' => [
                    'kode_promo'    => 'PM-TERMINAL',
                    'nama_promo'    => 'Promo Terminal Kasir 1',
                    'deskripsi'     => 'Hanya aktif di terminal tertentu',
                    'terminal_id'   => $terminal?->id,
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 3],
                ],
            ],

            // 10. Happy hour 08:00-12:00
            [
                'promo' => [
                    'kode_promo'    => 'PM-HAPPY',
                    'nama_promo'    => 'Happy Hour Pagi 08:00-12:00',
                    'deskripsi'     => 'Diskon khusus jam pagi (setiap hari)',
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                    'jam_mulai'     => '08:00:00',
                    'jam_selesai'   => '12:00:00',
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 7],
                ],
            ],

            // 10b. Happy Hour sepanjang hari (untuk verify UI checkbox simulasi aktif terus)
            [
                'promo' => [
                    'kode_promo'    => 'PM-HAPPY-ALL',
                    'nama_promo'    => 'Happy Hour 24 Jam',
                    'deskripsi'     => 'Verify UI Happy Hour checkbox, aktif terus',
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                    'jam_mulai'     => '00:00:00',
                    'jam_selesai'   => '23:59:00',
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 4],
                ],
            ],

            // 10c. Happy Hour window yang mencakup waktu SEKARANG (untuk verify anti-fraud filter jam bekerja)
            [
                'promo' => [
                    'kode_promo'    => 'PM-HAPPY-NOW',
                    'nama_promo'    => 'Happy Hour Window Saat Ini',
                    'deskripsi'     => 'Window ±2 jam dari waktu seeding, untuk verify filter jam',
                    'tanggal_mulai' => today()->subDay()->toDateString(),
                    'jam_mulai'     => now()->subHours(2)->format('H:i:s'),
                    'jam_selesai'   => now()->addHours(2)->format('H:i:s'),
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 6],
                ],
            ],

            // 11. DRAFT — test edit/approve flow
            [
                'promo' => [
                    'kode_promo'           => 'PM-DRAFT',
                    'nama_promo'           => '[DRAFT] Promo Belum Approved',
                    'deskripsi'            => 'Gunakan untuk test edit/approve flow',
                    'customer_category_id' => $katSilver?->id,
                    'tanggal_mulai'        => today()->toDateString(),
                    '_status'              => 'draft',
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 25],
                ],
            ],

            // 12. INACTIVE — test reactivate flow
            [
                'promo' => [
                    'kode_promo'    => 'PM-INACTIVE',
                    'nama_promo'    => '[INACTIVE] Promo Dinonaktifkan',
                    'deskripsi'     => 'Gunakan untuk test reactivate flow',
                    'tanggal_mulai' => today()->subDays(10)->toDateString(),
                    '_status'       => 'inactive',
                ],
                'details' => [
                    ['target_type' => 'semua', 'min_qty' => 1, 'diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 1000],
                ],
            ],
        ];

        foreach ($promos as $data) {
            if (DocPromo::where('kode_promo', $data['promo']['kode_promo'])->exists()) {
                $this->command->warn("Skip {$data['promo']['kode_promo']} (already exists)");
                continue;
            }

            $status = $data['promo']['_status'] ?? 'approved';
            unset($data['promo']['_status']);

            $promo = DocPromo::create(array_merge([
                'ulid'        => (string) Str::ulid(),
                'status'      => $status,
                'approved_at' => $status === 'approved' ? now() : null,
                'approved_by' => $status === 'approved' ? $user->id : null,
                'created_by'  => $user->id,
            ], $data['promo']));

            foreach ($data['details'] as $detail) {
                $promo->details()->create(array_merge([
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                ], $detail));
            }

            $this->command->info("Created: {$promo->kode_promo} — {$promo->nama_promo} [{$status}]");
        }

        $this->command->info("\nTotal promo: " . DocPromo::count());
        $this->command->info("Approved:    " . DocPromo::where('status', 'approved')->count());
        $this->command->info("Draft:       " . DocPromo::where('status', 'draft')->count());
        $this->command->info("Inactive:    " . DocPromo::where('status', 'inactive')->count());
    }
}
