<?php

namespace Tests\Unit\Services;

use App\Models\DocSerialIntake;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\User;
use App\Services\CustomerRules;
use App\Services\MetodePembayaranRules;
use App\Services\ProdukRules;
use App\Services\PurchaseMasterRules;
use App\Services\SupplierRules;
use App\Services\WarehouseRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_supplier_purchase_block_when_inactive(): void
    {
        $supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-IN',
            'nama_supplier' => 'Inactive Supplier',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('Supplier tidak aktif.', SupplierRules::purchaseBlockMessage($supplier));
    }

    public function test_supplier_deactivation_blocked_when_outstanding_hutang(): void
    {
        $supplier = $this->createSupplier();

        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'tanggal' => now(),
            'nominal_awal' => 100000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 100000,
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        $message = SupplierRules::deactivationBlockMessage($supplier);
        $this->assertNotNull($message);
        $this->assertStringContainsString('hutang belum lunas', $message);
    }

    public function test_supplier_deactivation_blocked_when_deposit_balance_remains(): void
    {
        $supplier = $this->createSupplier();
        $this->createSupplierDeposit($supplier, 50000);

        $message = SupplierRules::deactivationBlockMessage($supplier);
        $this->assertNotNull($message);
        $this->assertStringContainsString('sisa deposit', $message);
    }

    public function test_supplier_deletion_includes_serial_intake_guard(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $product = MasterProduk::factory()->create(['status' => 'active', 'is_serial' => true]);

        DocSerialIntake::create([
            'nomor_dokumen' => 'SI-001',
            'tanggal' => now(),
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        $message = SupplierRules::deletionBlockMessage($supplier);
        $this->assertNotNull($message);
        $this->assertStringContainsString('Pembelian Serial', $message);
    }

    public function test_customer_walk_in_cannot_be_deactivated(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'WALKIN',
            'nama' => 'Walk In',
            'telepon' => '08000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->assertSame(
            'Customer Walk-in tidak dapat dinonaktifkan',
            CustomerRules::deactivationBlockMessage($customer)
        );
    }

    public function test_customer_walk_in_cannot_be_deleted(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'WALKIN',
            'nama' => 'Walk In',
            'telepon' => '08000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->assertSame(
            'Customer Walk-in tidak dapat dihapus',
            CustomerRules::deletionBlockMessage($customer)
        );
    }

    public function test_metode_pembayaran_normalize_clears_non_tunai_fields_for_tunai(): void
    {
        $normalized = MetodePembayaranRules::normalize([
            'metode' => 'tunai',
            'jenis' => 'bank',
            'nama_akun' => 'BCA',
            'biaya_tambahan_tipe' => 'percent',
            'biaya_tambahan_nilai' => 2,
        ]);

        $this->assertNull($normalized['jenis']);
        $this->assertSame('none', $normalized['biaya_tambahan_tipe']);
        $this->assertSame(0, $normalized['biaya_tambahan_nilai']);
    }

    public function test_metode_pembayaran_store_rules_reject_percent_biaya_over_100(): void
    {
        $request = Request::create('/api/v1/metode-pembayarans', 'POST', [
            'kode_pembayaran' => 'QRIS',
            'nama_pembayaran' => 'QRIS Shop',
            'metode' => 'non_tunai',
            'jenis' => 'qris',
            'biaya_tambahan_tipe' => 'percent',
            'biaya_tambahan_nilai' => 150,
            'status' => 'active',
        ]);

        $validator = validator($request->all(), MetodePembayaranRules::storeRules($request));
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('biaya_tambahan_nilai', $validator->errors()->toArray());
    }

    public function test_metode_pembayaran_deletion_blocked_when_used_by_sales_payment(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-RU',
            'nama_pembayaran' => 'Tunai RU',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WH-PAY',
            'nama_warehouse' => 'Gudang Pay',
            'is_saleable' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $terminalDefaultMetodeId = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-TRM-RU',
            'nama_pembayaran' => 'Tunai Terminal RU',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ])->id;

        $terminalId = DB::table('master_pos_terminal')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-RU',
            'nama_terminal' => 'Kasir RU',
            'warehouse_id' => $warehouseId,
            'default_metode_pembayaran_id' => $terminalDefaultMetodeId,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shiftId = DB::table('pos_terminal_shifts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminalId,
            'user_id' => $this->user->id,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'C-RU',
            'nama' => 'Customer RU',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'SL-001',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'subtotal' => 10000,
            'total_setelah_diskon' => 10000,
            'grand_total' => 10000,
            'total_bayar' => 10000,
            'kembalian' => 0,
            'status' => 'completed',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_sales_payments')->insert([
            'sales_id' => $saleId,
            'metode_pembayaran_id' => $metode->id,
            'nominal' => 10000,
            'biaya_tambahan' => 0,
        ]);

        $message = MetodePembayaranRules::deletionBlockMessage($metode);
        $this->assertNotNull($message);
        $this->assertStringContainsString('pembayaran transaksi', $message);
    }

    public function test_purchase_master_rules_reject_inactive_supplier_and_warehouse(): void
    {
        $supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-PMR',
            'nama_supplier' => 'Inactive PMR',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $errors = PurchaseMasterRules::supplierAndWarehouseErrors($supplier->id, $warehouse->id);
        $this->assertNotNull($errors);
        $this->assertArrayHasKey('supplier_id', $errors);
        $this->assertArrayHasKey('warehouse_id', $errors);
    }

    public function test_warehouse_rules_block_deactivation_when_stock_remains(): void
    {
        $warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $product = MasterProduk::factory()->create(['status' => 'active']);

        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 3, 'avg_cost' => 1000]
        );

        $message = WarehouseRules::deactivationBlockMessage($warehouse);
        $this->assertNotNull($message);
        $this->assertStringContainsString('stok', strtolower($message));
    }

    public function test_produk_rules_reject_inactive_kategori_and_grup(): void
    {
        $tipe = \App\Models\MasterTipe::create([
            'kode_tipe' => 'TP-RU',
            'nama_tipe' => 'Tipe RU',
            'status' => 'active',
        ]);

        $kategori = \App\Models\MasterKategori::create([
            'tipe_id' => $tipe->id,
            'kode_kategori' => 'KT-RU',
            'nama_kategori' => 'Inactive',
            'status' => 'inactive',
        ]);

        $activeKategori = \App\Models\MasterKategori::create([
            'tipe_id' => $tipe->id,
            'kode_kategori' => 'KT-RU-ACT',
            'nama_kategori' => 'Active',
            'status' => 'active',
        ]);

        $grup = \App\Models\MasterGrup::create([
            'kategori_id' => $activeKategori->id,
            'kode_grup' => 'GR-RU',
            'nama_grup' => 'Inactive Grup',
            'status' => 'inactive',
        ]);

        $errors = ProdukRules::masterReferenceErrors($kategori->id, $grup->id);
        $this->assertNotNull($errors);
        $this->assertArrayHasKey('kategori_id', $errors);
        $this->assertArrayHasKey('grup_id', $errors);
    }

    private function createSupplier(): MasterSupplier
    {
        return MasterSupplier::create([
            'kode_supplier' => 'SUP-'.Str::upper(Str::random(4)),
            'nama_supplier' => 'Supplier Rules Test',
            'nama_pic' => 'PIC',
            'telepon' => '08123456789',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    private function createSupplierDeposit(MasterSupplier $supplier, float $sisaDeposit): void
    {
        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WH-DEP-'.Str::upper(Str::random(3)),
            'nama_warehouse' => 'Gudang Deposit Test',
            'is_saleable' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-DEP-'.Str::upper(Str::random(4)),
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouseId,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-DEP-'.Str::upper(Str::random(4)),
            'tanggal' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouseId,
            'po_id' => $poId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_deposit')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'retur_id' => $returId,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $sisaDeposit,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $sisaDeposit,
            'status' => 'available',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
