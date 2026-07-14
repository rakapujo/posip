<?php

namespace Database\Seeders;

use App\Models\MasterBrand;
use App\Models\MasterTipe;
use App\Models\MasterKategori;
use App\Models\MasterGrup;
use App\Models\MasterWarehouse;
use App\Models\MasterSupplier;
use App\Models\MasterTipeCustomer;
use App\Models\MasterKategoriCustomer;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterMetodePembayaran;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MasterSeeder extends Seeder
{
    private $adminId;

    public function run(): void
    {
        $this->adminId = User::where('email', 'admin@posip.com')->first()?->id ?? 1;

        $this->seedBrands();
        $this->seedTipeKategoriGrup();
        $this->seedWarehouses();
        $this->seedSuppliers();
        $this->seedTipeKategoriCustomer();
        $this->seedCustomers();
        $this->seedMetodePembayaran();
        $this->seedProduk();

        $this->command->info('Master data seeded successfully!');
    }

    private function seedBrands(): void
    {
        $brands = [
            // Food & Beverage
            ['kode_brand' => 'INDOFOOD', 'nama_brand' => 'Indofood'],
            ['kode_brand' => 'MAYORA', 'nama_brand' => 'Mayora'],
            ['kode_brand' => 'WINGS', 'nama_brand' => 'Wings Food'],
            ['kode_brand' => 'GARUDA', 'nama_brand' => 'Garuda Food'],
            ['kode_brand' => 'NESTLE', 'nama_brand' => 'Nestle'],
            ['kode_brand' => 'DANONE', 'nama_brand' => 'Danone'],
            ['kode_brand' => 'ULTRAJAYA', 'nama_brand' => 'Ultra Jaya'],
            ['kode_brand' => 'SOSRO', 'nama_brand' => 'Sosro'],
            ['kode_brand' => 'ABC', 'nama_brand' => 'ABC'],
            // Personal Care & Household
            ['kode_brand' => 'UNILEVER', 'nama_brand' => 'Unilever'],
            ['kode_brand' => 'PNG', 'nama_brand' => 'P&G'],
            ['kode_brand' => 'KAO', 'nama_brand' => 'Kao'],
            ['kode_brand' => 'LION', 'nama_brand' => 'Lion'],
            ['kode_brand' => 'KINO', 'nama_brand' => 'Kino'],
            ['kode_brand' => 'WARDAH', 'nama_brand' => 'Wardah'],
        ];

        foreach ($brands as $brand) {
            MasterBrand::create([
                'ulid' => Str::ulid(),
                'kode_brand' => $brand['kode_brand'],
                'nama_brand' => $brand['nama_brand'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Brands: ' . count($brands) . ' records');
    }

    private function seedTipeKategoriGrup(): void
    {
        $structure = [
            'FOOD' => [
                'nama' => 'Makanan',
                'kategoris' => [
                    'MIE' => [
                        'nama' => 'Mie Instan',
                        'grups' => [
                            'MIE_GORENG' => 'Mie Goreng',
                            'MIE_KUAH' => 'Mie Kuah',
                            'MIE_CUP' => 'Mie Cup',
                        ],
                    ],
                    'SNACK' => [
                        'nama' => 'Snack & Biskuit',
                        'grups' => [
                            'BISKUIT' => 'Biskuit',
                            'WAFER' => 'Wafer',
                            'KERIPIK' => 'Keripik',
                            'COKLAT' => 'Coklat',
                        ],
                    ],
                    'BUMBU' => [
                        'nama' => 'Bumbu & Saus',
                        'grups' => [
                            'KECAP' => 'Kecap',
                            'SAUS' => 'Saus',
                            'BUMBU_MASAK' => 'Bumbu Masak',
                        ],
                    ],
                    'SUSU' => [
                        'nama' => 'Susu & Olahan',
                        'grups' => [
                            'SUSU_BUBUK' => 'Susu Bubuk',
                            'SUSU_CAIR' => 'Susu Cair',
                            'SUSU_KENTAL' => 'Susu Kental Manis',
                        ],
                    ],
                ],
            ],
            'BEVERAGE' => [
                'nama' => 'Minuman',
                'kategoris' => [
                    'TEH' => [
                        'nama' => 'Teh',
                        'grups' => [
                            'TEH_BOTOL' => 'Teh Botol',
                            'TEH_KOTAK' => 'Teh Kotak',
                            'TEH_CELUP' => 'Teh Celup',
                        ],
                    ],
                    'KOPI' => [
                        'nama' => 'Kopi',
                        'grups' => [
                            'KOPI_SACHET' => 'Kopi Sachet',
                            'KOPI_BOTOL' => 'Kopi Botol',
                        ],
                    ],
                    'AIR_MINERAL' => [
                        'nama' => 'Air Mineral',
                        'grups' => [
                            'AIR_GALON' => 'Air Galon',
                            'AIR_BOTOL' => 'Air Botol',
                            'AIR_GELAS' => 'Air Gelas',
                        ],
                    ],
                ],
            ],
            'PERSONAL_CARE' => [
                'nama' => 'Perawatan Diri',
                'kategoris' => [
                    'SABUN' => [
                        'nama' => 'Sabun',
                        'grups' => [
                            'SABUN_MANDI' => 'Sabun Mandi',
                            'SABUN_CUCI' => 'Sabun Cuci Tangan',
                        ],
                    ],
                    'SHAMPOO' => [
                        'nama' => 'Shampoo',
                        'grups' => [
                            'SHAMPOO_SACHET' => 'Shampoo Sachet',
                            'SHAMPOO_BOTOL' => 'Shampoo Botol',
                        ],
                    ],
                    'PASTA_GIGI' => [
                        'nama' => 'Pasta Gigi',
                        'grups' => [
                            'PASTA_TUBE' => 'Pasta Gigi Tube',
                        ],
                    ],
                ],
            ],
            'HOUSEHOLD' => [
                'nama' => 'Rumah Tangga',
                'kategoris' => [
                    'DETERJEN' => [
                        'nama' => 'Deterjen',
                        'grups' => [
                            'DETERJEN_BUBUK' => 'Deterjen Bubuk',
                            'DETERJEN_CAIR' => 'Deterjen Cair',
                        ],
                    ],
                    'PEMBERSIH' => [
                        'nama' => 'Pembersih',
                        'grups' => [
                            'PEMBERSIH_LANTAI' => 'Pembersih Lantai',
                            'PEMBERSIH_PIRING' => 'Sabun Cuci Piring',
                        ],
                    ],
                ],
            ],
        ];

        $tipeCount = 0;
        $kategoriCount = 0;
        $grupCount = 0;

        foreach ($structure as $tipeKode => $tipeData) {
            $tipe = MasterTipe::create([
                'ulid' => Str::ulid(),
                'kode_tipe' => $tipeKode,
                'nama_tipe' => $tipeData['nama'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
            $tipeCount++;

            foreach ($tipeData['kategoris'] as $kategoriKode => $kategoriData) {
                $kategori = MasterKategori::create([
                    'ulid' => Str::ulid(),
                    'tipe_id' => $tipe->id,
                    'kode_kategori' => $kategoriKode,
                    'nama_kategori' => $kategoriData['nama'],
                    'status' => 'active',
                    'created_by' => $this->adminId,
                    'updated_by' => $this->adminId,
                ]);
                $kategoriCount++;

                foreach ($kategoriData['grups'] as $grupKode => $grupNama) {
                    MasterGrup::create([
                        'ulid' => Str::ulid(),
                        'kategori_id' => $kategori->id,
                        'kode_grup' => $grupKode,
                        'nama_grup' => $grupNama,
                        'status' => 'active',
                        'created_by' => $this->adminId,
                        'updated_by' => $this->adminId,
                    ]);
                    $grupCount++;
                }
            }
        }

        $this->command->info("- Tipe: {$tipeCount} records");
        $this->command->info("- Kategori: {$kategoriCount} records");
        $this->command->info("- Grup: {$grupCount} records");
    }

    private function seedWarehouses(): void
    {
        $warehouses = [
            [
                'kode_warehouse' => 'WH_PUSAT',
                'nama_warehouse' => 'Gudang Pusat',
                'alamat' => 'Jl. Industri No. 1, Kawasan Industri',
                'pic_name' => 'Budi Santoso',
                'pic_phone' => '081234567890',
                'is_saleable' => true,
            ],
            [
                'kode_warehouse' => 'WH_CABANG1',
                'nama_warehouse' => 'Gudang Cabang 1',
                'alamat' => 'Jl. Raya Utama No. 10',
                'pic_name' => 'Andi Wijaya',
                'pic_phone' => '081234567891',
                'is_saleable' => true,
            ],
            [
                'kode_warehouse' => 'WH_CABANG2',
                'nama_warehouse' => 'Gudang Cabang 2',
                'alamat' => 'Jl. Merdeka No. 25',
                'pic_name' => 'Siti Rahayu',
                'pic_phone' => '081234567892',
                'is_saleable' => true,
            ],
            [
                'kode_warehouse' => 'WH_BS',
                'nama_warehouse' => 'Gudang Barang Rusak',
                'alamat' => 'Jl. Industri No. 1 Blok B',
                'pic_name' => 'Dedi Kurniawan',
                'pic_phone' => '081234567893',
                'is_saleable' => false,
            ],
        ];

        foreach ($warehouses as $wh) {
            MasterWarehouse::create([
                'ulid' => Str::ulid(),
                'kode_warehouse' => $wh['kode_warehouse'],
                'nama_warehouse' => $wh['nama_warehouse'],
                'alamat' => $wh['alamat'],
                'pic_name' => $wh['pic_name'],
                'pic_phone' => $wh['pic_phone'],
                'is_saleable' => $wh['is_saleable'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Warehouses: ' . count($warehouses) . ' records');
    }

    private function seedSuppliers(): void
    {
        $suppliers = [
            [
                'kode_supplier' => 'SUP_INDOFOOD',
                'nama_supplier' => 'PT Indofood CBP Sukses Makmur',
                'nama_pic' => 'Hendra Kusuma',
                'telepon' => '021-5795-8822',
                'email' => 'sales@indofood.co.id',
                'alamat' => 'Sudirman Plaza, Indofood Tower',
                'tempo_default' => 30,
            ],
            [
                'kode_supplier' => 'SUP_UNILEVER',
                'nama_supplier' => 'PT Unilever Indonesia',
                'nama_pic' => 'Rudi Hartono',
                'telepon' => '021-526-2112',
                'email' => 'sales@unilever.co.id',
                'alamat' => 'Grha Unilever, BSD Green Office Park',
                'tempo_default' => 30,
            ],
            [
                'kode_supplier' => 'SUP_WINGS',
                'nama_supplier' => 'PT Wings Surya',
                'nama_pic' => 'Yusuf Hidayat',
                'telepon' => '031-843-9999',
                'email' => 'sales@wingscorp.com',
                'alamat' => 'Jl. Kalisosok Kidul No. 2, Surabaya',
                'tempo_default' => 21,
            ],
            [
                'kode_supplier' => 'SUP_MAYORA',
                'nama_supplier' => 'PT Mayora Indah Tbk',
                'nama_pic' => 'Bambang Setiawan',
                'telepon' => '021-520-9777',
                'email' => 'sales@mayora.co.id',
                'alamat' => 'Mayora Building, Tomang Raya',
                'tempo_default' => 30,
            ],
            [
                'kode_supplier' => 'SUP_NESTLE',
                'nama_supplier' => 'PT Nestle Indonesia',
                'nama_pic' => 'Agus Supriadi',
                'telepon' => '021-7884-5000',
                'email' => 'sales@id.nestle.com',
                'alamat' => 'Perkantoran Hijau Arkadia Tower',
                'tempo_default' => 30,
            ],
            [
                'kode_supplier' => 'SUP_SOSRO',
                'nama_supplier' => 'PT Sinar Sosro',
                'nama_pic' => 'Tono Prasetyo',
                'telepon' => '021-840-5555',
                'email' => 'sales@sosro.com',
                'alamat' => 'Jl. Raya Sultan Agung Km. 28, Bekasi',
                'tempo_default' => 14,
            ],
        ];

        foreach ($suppliers as $sup) {
            MasterSupplier::create([
                'ulid' => Str::ulid(),
                'kode_supplier' => $sup['kode_supplier'],
                'nama_supplier' => $sup['nama_supplier'],
                'nama_pic' => $sup['nama_pic'],
                'telepon' => $sup['telepon'],
                'email' => $sup['email'],
                'alamat' => $sup['alamat'],
                'tempo_default' => $sup['tempo_default'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Suppliers: ' . count($suppliers) . ' records');
    }

    private function seedTipeKategoriCustomer(): void
    {
        $tipes = [
            ['kode_tipe' => 'RETAIL', 'nama_tipe' => 'Retail / End User', 'keterangan' => 'Pelanggan perorangan'],
            ['kode_tipe' => 'RESELLER', 'nama_tipe' => 'Reseller', 'keterangan' => 'Pelanggan yang menjual kembali'],
            ['kode_tipe' => 'GROSIR', 'nama_tipe' => 'Grosir', 'keterangan' => 'Pelanggan pembelian besar'],
            ['kode_tipe' => 'PROJECT', 'nama_tipe' => 'Project', 'keterangan' => 'Pelanggan proyek/tender'],
        ];

        foreach ($tipes as $tipe) {
            MasterTipeCustomer::create([
                'ulid' => Str::ulid(),
                'kode_tipe' => $tipe['kode_tipe'],
                'nama_tipe' => $tipe['nama_tipe'],
                'keterangan' => $tipe['keterangan'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $kategoris = [
            ['kode_kategori' => 'REGULAR', 'nama_kategori' => 'Regular', 'keterangan' => 'Pelanggan reguler tanpa diskon khusus'],
            ['kode_kategori' => 'SILVER', 'nama_kategori' => 'Silver Member', 'keterangan' => 'Diskon 3%'],
            ['kode_kategori' => 'GOLD', 'nama_kategori' => 'Gold Member', 'keterangan' => 'Diskon 5%'],
            ['kode_kategori' => 'PLATINUM', 'nama_kategori' => 'Platinum Member', 'keterangan' => 'Diskon 7%'],
        ];

        foreach ($kategoris as $kat) {
            MasterKategoriCustomer::create([
                'ulid' => Str::ulid(),
                'kode_kategori' => $kat['kode_kategori'],
                'nama_kategori' => $kat['nama_kategori'],
                'keterangan' => $kat['keterangan'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Tipe Customer: ' . count($tipes) . ' records');
        $this->command->info('- Kategori Customer: ' . count($kategoris) . ' records');
    }

    private function seedCustomers(): void
    {
        $tipeRetail = MasterTipeCustomer::where('kode_tipe', 'RETAIL')->first();
        $tipeReseller = MasterTipeCustomer::where('kode_tipe', 'RESELLER')->first();
        $tipeGrosir = MasterTipeCustomer::where('kode_tipe', 'GROSIR')->first();

        $katRegular = MasterKategoriCustomer::where('kode_kategori', 'REGULAR')->first();
        $katSilver = MasterKategoriCustomer::where('kode_kategori', 'SILVER')->first();
        $katGold = MasterKategoriCustomer::where('kode_kategori', 'GOLD')->first();

        $customers = [
            // Walk-in customer (mandatory)
            [
                'kode_customer' => 'WALKIN',
                'nama' => 'Walk-in Customer',
                'telepon' => '-',
                'email' => null,
                'alamat' => null,
                'jenis' => 'walk_in',
                'tipe_customer_id' => $tipeRetail?->id,
                'kategori_customer_id' => $katRegular?->id,
            ],
            // Specific customers
            [
                'kode_customer' => 'CUST001',
                'nama' => 'Budi Santoso',
                'telepon' => '081234567001',
                'email' => 'budi.santoso@email.com',
                'alamat' => 'Jl. Sudirman No. 10, Jakarta',
                'jenis' => 'spesifik',
                'tipe_customer_id' => $tipeRetail?->id,
                'kategori_customer_id' => $katSilver?->id,
            ],
            [
                'kode_customer' => 'CUST002',
                'nama' => 'Siti Rahayu',
                'telepon' => '081234567002',
                'email' => 'siti.rahayu@email.com',
                'alamat' => 'Jl. Thamrin No. 20, Jakarta',
                'jenis' => 'spesifik',
                'tipe_customer_id' => $tipeRetail?->id,
                'kategori_customer_id' => $katGold?->id,
            ],
            [
                'kode_customer' => 'CUST003',
                'nama' => 'Toko Elektronik Jaya',
                'telepon' => '021-5551234',
                'email' => 'tokojaya@email.com',
                'alamat' => 'Jl. Mangga Dua Raya No. 50',
                'jenis' => 'spesifik',
                'tipe_customer_id' => $tipeReseller?->id,
                'kategori_customer_id' => $katGold?->id,
            ],
            [
                'kode_customer' => 'CUST004',
                'nama' => 'CV Maju Bersama',
                'telepon' => '021-5555678',
                'email' => 'majubersama@email.com',
                'alamat' => 'Jl. Glodok No. 88',
                'jenis' => 'spesifik',
                'tipe_customer_id' => $tipeGrosir?->id,
                'kategori_customer_id' => $katGold?->id,
            ],
            [
                'kode_customer' => 'CUST005',
                'nama' => 'Ahmad Fauzi',
                'telepon' => '081234567005',
                'email' => 'ahmad.fauzi@email.com',
                'alamat' => 'Jl. Pahlawan No. 15, Surabaya',
                'jenis' => 'spesifik',
                'tipe_customer_id' => $tipeRetail?->id,
                'kategori_customer_id' => $katRegular?->id,
            ],
        ];

        foreach ($customers as $cust) {
            MasterCustomer::create([
                'ulid' => Str::ulid(),
                'kode_customer' => $cust['kode_customer'],
                'nama' => $cust['nama'],
                'telepon' => $cust['telepon'],
                'email' => $cust['email'],
                'alamat' => $cust['alamat'],
                'jenis' => $cust['jenis'],
                'tipe_customer_id' => $cust['tipe_customer_id'],
                'kategori_customer_id' => $cust['kategori_customer_id'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Customers: ' . count($customers) . ' records');
    }

    private function seedMetodePembayaran(): void
    {
        // jenis enum: bank, qris, credit_card, debit_card, e_wallet, lainnya
        $metodes = [
            ['kode_pembayaran' => 'CASH', 'nama_pembayaran' => 'Tunai', 'metode' => 'tunai', 'jenis' => null],
            ['kode_pembayaran' => 'TRANSFER_BCA', 'nama_pembayaran' => 'Transfer BCA', 'metode' => 'non_tunai', 'jenis' => 'bank', 'nama_akun' => 'BCA', 'nomor_akun' => '1234567890'],
            ['kode_pembayaran' => 'TRANSFER_MANDIRI', 'nama_pembayaran' => 'Transfer Mandiri', 'metode' => 'non_tunai', 'jenis' => 'bank', 'nama_akun' => 'Mandiri', 'nomor_akun' => '0987654321'],
            ['kode_pembayaran' => 'TRANSFER_BRI', 'nama_pembayaran' => 'Transfer BRI', 'metode' => 'non_tunai', 'jenis' => 'bank', 'nama_akun' => 'BRI', 'nomor_akun' => '1122334455'],
            ['kode_pembayaran' => 'DEBIT_BCA', 'nama_pembayaran' => 'Debit BCA', 'metode' => 'non_tunai', 'jenis' => 'debit_card'],
            ['kode_pembayaran' => 'CREDIT_CARD', 'nama_pembayaran' => 'Kartu Kredit', 'metode' => 'non_tunai', 'jenis' => 'credit_card'],
            ['kode_pembayaran' => 'QRIS', 'nama_pembayaran' => 'QRIS', 'metode' => 'non_tunai', 'jenis' => 'qris'],
            ['kode_pembayaran' => 'GOPAY', 'nama_pembayaran' => 'GoPay', 'metode' => 'non_tunai', 'jenis' => 'e_wallet'],
            ['kode_pembayaran' => 'OVO', 'nama_pembayaran' => 'OVO', 'metode' => 'non_tunai', 'jenis' => 'e_wallet'],
            ['kode_pembayaran' => 'DANA', 'nama_pembayaran' => 'DANA', 'metode' => 'non_tunai', 'jenis' => 'e_wallet'],
        ];

        foreach ($metodes as $metode) {
            MasterMetodePembayaran::create([
                'ulid' => Str::ulid(),
                'kode_pembayaran' => $metode['kode_pembayaran'],
                'nama_pembayaran' => $metode['nama_pembayaran'],
                'metode' => $metode['metode'],
                'jenis' => $metode['jenis'],
                'nama_akun' => $metode['nama_akun'] ?? null,
                'nomor_akun' => $metode['nomor_akun'] ?? null,
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Metode Pembayaran: ' . count($metodes) . ' records');
    }

    private function seedProduk(): void
    {
        // Get references - Brands
        $brandIndofood = MasterBrand::where('kode_brand', 'INDOFOOD')->first();
        $brandMayora = MasterBrand::where('kode_brand', 'MAYORA')->first();
        $brandWings = MasterBrand::where('kode_brand', 'WINGS')->first();
        $brandNestle = MasterBrand::where('kode_brand', 'NESTLE')->first();
        $brandUnilever = MasterBrand::where('kode_brand', 'UNILEVER')->first();
        $brandSosro = MasterBrand::where('kode_brand', 'SOSRO')->first();
        $brandAbc = MasterBrand::where('kode_brand', 'ABC')->first();
        $brandUltrajaya = MasterBrand::where('kode_brand', 'ULTRAJAYA')->first();

        // Get references - Tipe
        $tipeFood = MasterTipe::where('kode_tipe', 'FOOD')->first();
        $tipeBeverage = MasterTipe::where('kode_tipe', 'BEVERAGE')->first();
        $tipePersonalCare = MasterTipe::where('kode_tipe', 'PERSONAL_CARE')->first();
        $tipeHousehold = MasterTipe::where('kode_tipe', 'HOUSEHOLD')->first();

        // Get references - Kategori
        $katMie = MasterKategori::where('kode_kategori', 'MIE')->first();
        $katSnack = MasterKategori::where('kode_kategori', 'SNACK')->first();
        $katBumbu = MasterKategori::where('kode_kategori', 'BUMBU')->first();
        $katSusu = MasterKategori::where('kode_kategori', 'SUSU')->first();
        $katTeh = MasterKategori::where('kode_kategori', 'TEH')->first();
        $katKopi = MasterKategori::where('kode_kategori', 'KOPI')->first();
        $katAirMineral = MasterKategori::where('kode_kategori', 'AIR_MINERAL')->first();
        $katSabun = MasterKategori::where('kode_kategori', 'SABUN')->first();
        $katShampoo = MasterKategori::where('kode_kategori', 'SHAMPOO')->first();
        $katDeterjen = MasterKategori::where('kode_kategori', 'DETERJEN')->first();

        // Get references - Grup
        $grupMieGoreng = MasterGrup::where('kode_grup', 'MIE_GORENG')->first();
        $grupMieKuah = MasterGrup::where('kode_grup', 'MIE_KUAH')->first();
        $grupBiskuit = MasterGrup::where('kode_grup', 'BISKUIT')->first();
        $grupWafer = MasterGrup::where('kode_grup', 'WAFER')->first();
        $grupKecap = MasterGrup::where('kode_grup', 'KECAP')->first();
        $grupSusuKental = MasterGrup::where('kode_grup', 'SUSU_KENTAL')->first();
        $grupTehBottle = MasterGrup::where('kode_grup', 'TEH_BOTOL')->first();
        $grupKopiSachet = MasterGrup::where('kode_grup', 'KOPI_SACHET')->first();
        $grupAirGelas = MasterGrup::where('kode_grup', 'AIR_GELAS')->first();
        $grupSabunMandi = MasterGrup::where('kode_grup', 'SABUN_MANDI')->first();
        $grupShampooSachet = MasterGrup::where('kode_grup', 'SHAMPOO_SACHET')->first();
        $grupDeterjenBubuk = MasterGrup::where('kode_grup', 'DETERJEN_BUBUK')->first();

        // ============================================================
        // ATURAN UNIT & HARGA (sesuai price_input_mode = 'auto')
        // ============================================================
        // 1. Unit 1 = TERBESAR, Unit 4 = BASE (terkecil, selalu konversi=1)
        // 2. konversi = berapa BASE unit dalam unit tersebut
        // 3. MODE AUTO: harga_n = (harga_1 / konversi_1) × konversi_n
        // 4. AUTO-LOCK: Jika konversi=1, unit di bawahnya TERKUNCI
        // ============================================================

        $products = [
            // ============================================================
            // MIE INSTAN - 3 LEVEL (KARTON → DUS → PCS)
            // Struktur: 1 KARTON = 40 PCS, 1 DUS = 5 PCS
            // ============================================================
            // Indomie Goreng - KARTON(40) → DUS(5) → PCS
            // harga_1 = 120.000 (KARTON), harga per PCS = 3.000
            // harga_2 = (120.000 / 40) × 5 = 15.000 (DUS)
            // harga_3 = (120.000 / 40) × 1 = 3.000 (PCS)
            [
                'kode_produk' => 'INDMIE_GRG',
                'barcode' => '8886008101053',
                'nama_produk' => 'Indomie Goreng 85g',
                'brand_id' => $brandIndofood?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katMie?->id,
                'grup_id' => $grupMieGoreng?->id,
                'minimum_stok' => 100,
                'unit_1' => 'KARTON', 'konversi_1' => 40, 'harga_1' => 120000,
                'unit_2' => 'DUS', 'konversi_2' => 5, 'harga_2' => 15000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 3000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 3000,
            ],
            // Indomie Ayam Bawang - KARTON(40) → DUS(5) → PCS
            [
                'kode_produk' => 'INDMIE_AB',
                'barcode' => '8886008101060',
                'nama_produk' => 'Indomie Ayam Bawang 69g',
                'brand_id' => $brandIndofood?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katMie?->id,
                'grup_id' => $grupMieKuah?->id,
                'minimum_stok' => 100,
                'unit_1' => 'KARTON', 'konversi_1' => 40, 'harga_1' => 100000,
                'unit_2' => 'DUS', 'konversi_2' => 5, 'harga_2' => 12500,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 2500,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 2500,
            ],
            // Mie Sedaap Goreng - KARTON(40) → DUS(5) → PCS
            [
                'kode_produk' => 'SEDAAP_GRG',
                'barcode' => '8886008600012',
                'nama_produk' => 'Mie Sedaap Goreng 90g',
                'brand_id' => $brandWings?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katMie?->id,
                'grup_id' => $grupMieGoreng?->id,
                'minimum_stok' => 100,
                'unit_1' => 'KARTON', 'konversi_1' => 40, 'harga_1' => 120000,
                'unit_2' => 'DUS', 'konversi_2' => 5, 'harga_2' => 15000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 3000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 3000,
            ],

            // ============================================================
            // SNACK - 3 LEVEL (KARTON → RENCENG → PCS)
            // Struktur: 1 KARTON = 60 PCS, 1 RENCENG = 10 PCS
            // ============================================================
            // Roma Kelapa - KARTON(60) → RENCENG(10) → PCS
            // harga_1 = 120.000 (KARTON), harga per PCS = 2.000
            [
                'kode_produk' => 'ROMA_KELAPA',
                'barcode' => '8996001355053',
                'nama_produk' => 'Roma Kelapa 300g',
                'brand_id' => $brandMayora?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katSnack?->id,
                'grup_id' => $grupBiskuit?->id,
                'minimum_stok' => 50,
                'unit_1' => 'KARTON', 'konversi_1' => 60, 'harga_1' => 120000,
                'unit_2' => 'RENCENG', 'konversi_2' => 10, 'harga_2' => 20000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 2000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 2000,
            ],
            // Tango Wafer - KARTON(48) → PACK(12) → PCS
            [
                'kode_produk' => 'TANGO_COKLAT',
                'barcode' => '8996001355107',
                'nama_produk' => 'Tango Wafer Coklat 176g',
                'brand_id' => $brandMayora?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katSnack?->id,
                'grup_id' => $grupWafer?->id,
                'minimum_stok' => 30,
                'unit_1' => 'KARTON', 'konversi_1' => 48, 'harga_1' => 480000,
                'unit_2' => 'PACK', 'konversi_2' => 12, 'harga_2' => 120000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 10000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 10000,
            ],

            // ============================================================
            // BUMBU - 2 LEVEL (KARTON → PCS)
            // ============================================================
            // Kecap ABC Botol - KARTON(12) → PCS
            [
                'kode_produk' => 'ABC_KECAP_275',
                'barcode' => '8886008101275',
                'nama_produk' => 'ABC Kecap Manis 275ml',
                'brand_id' => $brandAbc?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katBumbu?->id,
                'grup_id' => $grupKecap?->id,
                'minimum_stok' => 24,
                'unit_1' => 'KARTON', 'konversi_1' => 12, 'harga_1' => 180000,
                'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 15000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 15000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 15000,
            ],

            // ============================================================
            // SUSU - 2 LEVEL (KARTON → PCS)
            // ============================================================
            // Susu Frisian Flag - KARTON(48) → PCS
            [
                'kode_produk' => 'SKM_FF_370',
                'barcode' => '8992753123456',
                'nama_produk' => 'Frisian Flag SKM Gold 370g',
                'brand_id' => $brandNestle?->id,
                'tipe_id' => $tipeFood?->id,
                'kategori_id' => $katSusu?->id,
                'grup_id' => $grupSusuKental?->id,
                'minimum_stok' => 48,
                'unit_1' => 'KARTON', 'konversi_1' => 48, 'harga_1' => 720000,
                'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 15000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 15000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 15000,
            ],

            // ============================================================
            // MINUMAN - 3 LEVEL (KRAT → PACK → BOTOL)
            // ============================================================
            // Teh Botol Sosro - KRAT(24) → PACK(6) → BOTOL
            // harga_1 = 72.000 (KRAT), harga per BOTOL = 3.000
            [
                'kode_produk' => 'TBS_BOTOL_350',
                'barcode' => '8886008101350',
                'nama_produk' => 'Teh Botol Sosro 350ml',
                'brand_id' => $brandSosro?->id,
                'tipe_id' => $tipeBeverage?->id,
                'kategori_id' => $katTeh?->id,
                'grup_id' => $grupTehBottle?->id,
                'minimum_stok' => 48,
                'unit_1' => 'KRAT', 'konversi_1' => 24, 'harga_1' => 72000,
                'unit_2' => 'PACK', 'konversi_2' => 6, 'harga_2' => 18000,
                'unit_3' => 'BOTOL', 'konversi_3' => 1, 'harga_3' => 3000,
                'unit_4' => 'BOTOL', 'konversi_4' => 1, 'harga_4' => 3000,
            ],
            // Nescafe Sachet - KARTON(120) → RENCENG(10) → PCS
            [
                'kode_produk' => 'NESCAFE_ORIG',
                'barcode' => '8850124012345',
                'nama_produk' => 'Nescafe Original 3in1 Sachet',
                'brand_id' => $brandNestle?->id,
                'tipe_id' => $tipeBeverage?->id,
                'kategori_id' => $katKopi?->id,
                'grup_id' => $grupKopiSachet?->id,
                'minimum_stok' => 100,
                'unit_1' => 'KARTON', 'konversi_1' => 120, 'harga_1' => 240000,
                'unit_2' => 'RENCENG', 'konversi_2' => 10, 'harga_2' => 20000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 2000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 2000,
            ],

            // ============================================================
            // AIR MINERAL - 3 LEVEL (KARTON → DUS → GELAS)
            // ============================================================
            // Aqua Gelas - KARTON(48) → DUS(12) → GELAS
            [
                'kode_produk' => 'AQUA_GELAS_220',
                'barcode' => '8886008101220',
                'nama_produk' => 'Aqua Gelas 220ml',
                'brand_id' => $brandAbc?->id,
                'tipe_id' => $tipeBeverage?->id,
                'kategori_id' => $katAirMineral?->id,
                'grup_id' => $grupAirGelas?->id,
                'minimum_stok' => 100,
                'unit_1' => 'KARTON', 'konversi_1' => 48, 'harga_1' => 48000,
                'unit_2' => 'DUS', 'konversi_2' => 12, 'harga_2' => 12000,
                'unit_3' => 'GELAS', 'konversi_3' => 1, 'harga_3' => 1000,
                'unit_4' => 'GELAS', 'konversi_4' => 1, 'harga_4' => 1000,
            ],

            // ============================================================
            // PERSONAL CARE - 3 LEVEL (KARTON → LUSIN → PCS)
            // ============================================================
            // Lifebuoy Sabun - KARTON(72) → LUSIN(12) → PCS
            [
                'kode_produk' => 'LIFEBUOY_80G',
                'barcode' => '8999999037581',
                'nama_produk' => 'Lifebuoy Sabun Batang 80g',
                'brand_id' => $brandUnilever?->id,
                'tipe_id' => $tipePersonalCare?->id,
                'kategori_id' => $katSabun?->id,
                'grup_id' => $grupSabunMandi?->id,
                'minimum_stok' => 72,
                'unit_1' => 'KARTON', 'konversi_1' => 72, 'harga_1' => 360000,
                'unit_2' => 'LUSIN', 'konversi_2' => 12, 'harga_2' => 60000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 5000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 5000,
            ],
            // Sunsilk Sachet - KARTON(240) → RENCENG(12) → PCS
            [
                'kode_produk' => 'SUNSILK_SCHET',
                'barcode' => '8999999038888',
                'nama_produk' => 'Sunsilk Black Shine Sachet 9ml',
                'brand_id' => $brandUnilever?->id,
                'tipe_id' => $tipePersonalCare?->id,
                'kategori_id' => $katShampoo?->id,
                'grup_id' => $grupShampooSachet?->id,
                'minimum_stok' => 200,
                'unit_1' => 'KARTON', 'konversi_1' => 240, 'harga_1' => 240000,
                'unit_2' => 'RENCENG', 'konversi_2' => 12, 'harga_2' => 12000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 1000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 1000,
            ],

            // ============================================================
            // HOUSEHOLD - 2 LEVEL (KARTON → PCS)
            // ============================================================
            // Rinso Bubuk - KARTON(24) → PCS
            [
                'kode_produk' => 'RINSO_900G',
                'barcode' => '8999999039999',
                'nama_produk' => 'Rinso Anti Noda 900g',
                'brand_id' => $brandUnilever?->id,
                'tipe_id' => $tipeHousehold?->id,
                'kategori_id' => $katDeterjen?->id,
                'grup_id' => $grupDeterjenBubuk?->id,
                'minimum_stok' => 24,
                'unit_1' => 'KARTON', 'konversi_1' => 24, 'harga_1' => 600000,
                'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 25000,
                'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 25000,
                'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 25000,
            ],
        ];

        foreach ($products as $prod) {
            MasterProduk::create([
                'ulid' => Str::ulid(),
                'kode_produk' => $prod['kode_produk'],
                'barcode' => $prod['barcode'],
                'nama_produk' => $prod['nama_produk'],
                'brand_id' => $prod['brand_id'],
                'tipe_id' => $prod['tipe_id'],
                'kategori_id' => $prod['kategori_id'],
                'grup_id' => $prod['grup_id'],
                'minimum_stok' => $prod['minimum_stok'],
                'avg_cost' => 0,
                'unit_1' => $prod['unit_1'],
                'konversi_1' => $prod['konversi_1'],
                'harga_1' => $prod['harga_1'],
                'unit_2' => $prod['unit_2'],
                'konversi_2' => $prod['konversi_2'],
                'harga_2' => $prod['harga_2'],
                'unit_3' => $prod['unit_3'],
                'konversi_3' => $prod['konversi_3'],
                'harga_3' => $prod['harga_3'],
                'unit_4' => $prod['unit_4'],
                'konversi_4' => $prod['konversi_4'],
                'harga_4' => $prod['harga_4'],
                'status' => 'active',
                'created_by' => $this->adminId,
                'updated_by' => $this->adminId,
            ]);
        }

        $this->command->info('- Produk: ' . count($products) . ' records');
    }
}
