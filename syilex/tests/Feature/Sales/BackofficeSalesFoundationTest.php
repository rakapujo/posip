<?php

namespace Tests\Feature\Sales;

use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutang;
use App\Models\DocSales;
use App\Models\MasterCustomer;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeSalesFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_finance_models_share_the_sales_foundation(): void
    {
        $user = User::factory()->create();
        $customer = MasterCustomer::create([
            'kode_customer' => 'BO-CUST',
            'nama' => 'Backoffice Customer',
            'telepon' => '0800',
            'tempo_default' => 30,
            'jenis' => 'spesifik',
            'status' => 'active',
        ]);
        $warehouse = MasterWarehouse::factory()->create();

        $sale = DocSales::create([
            'nomor_dokumen' => 'SOM-2607-0001',
            'source' => 'manual',
            'tanggal' => now(),
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'tempo_hari' => 30,
            'tanggal_jatuh_tempo' => today()->addDays(30),
            'grand_total' => 100000,
            'status' => 'completed',
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $piutang = CustomerPiutang::create([
            'customer_id' => $customer->id,
            'sales_id' => $sale->id,
            'tanggal' => now(),
            'tanggal_jatuh_tempo' => today()->addDays(30),
            'nominal_awal' => 100000,
            'sisa_piutang' => 100000,
        ]);
        $deposit = CustomerDeposit::create([
            'customer_id' => $customer->id,
            'tanggal' => today(),
            'nominal_awal' => 25000,
            'sisa_deposit' => 25000,
            'status' => 'available',
            'created_by' => $user->id,
        ]);
        $payment = DocPembayaranPiutang::create([
            'nomor_dokumen' => 'PPI-2607-0001',
            'tanggal' => today(),
            'customer_id' => $customer->id,
            'total_bayar_deposit' => 25000,
            'total_pembayaran' => 25000,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $payment->details()->create([
            'piutang_id' => $piutang->id,
            'nominal_dibayar' => 25000,
            'sumber' => 'deposit',
        ]);
        $payment->depositUsages()->create([
            'deposit_id' => $deposit->id,
            'nominal_digunakan' => 25000,
        ]);

        $this->assertTrue($sale->isCompleted());
        $this->assertTrue($sale->piutang->is($piutang));
        $this->assertSame(30, $customer->tempo_default);
        $this->assertTrue($payment->customer->is($customer));
        $this->assertTrue($payment->details->first()->piutang->is($piutang));
        $this->assertTrue($payment->depositUsages->first()->deposit->is($deposit));
    }
}
