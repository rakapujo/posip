<?php

namespace Tests\Feature\Console;

use App\Models\DocPriceChange;
use App\Models\DocPriceChangeDetail;
use App\Models\MasterProduk;
use App\Models\PriceChangeTriggerLog;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApplyScheduledPriceChangesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('scheduler.price_change_enabled', true, 'boolean');
        SettingService::set('scheduler.price_change_cooldown', 5, 'integer');
        SettingService::set('scheduler.price_change_max_batch', 50, 'integer');

        $this->user = User::factory()->create();
        $this->product = MasterProduk::factory()->create([
            'harga_1' => 100000,
            'harga_2' => 50000,
            'harga_3' => 20000,
            'harga_4' => 10000,
            'status' => 'active',
        ]);
    }

    private function createScheduledPriceChange(string $tanggalBerlaku, float $harga1Baru = 144000): DocPriceChange
    {
        $priceChange = DocPriceChange::create([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PCH-CMD-' . uniqid(),
            'tanggal_pengajuan' => '2026-04-10 00:00:00',
            'tanggal_berlaku' => $tanggalBerlaku,
            'status' => 'scheduled',
            'created_by' => $this->user->id,
        ]);

        DocPriceChangeDetail::create([
            'price_change_id' => $priceChange->id,
            'product_id' => $this->product->id,
            'harga_1_lama' => 100000,
            'harga_2_lama' => 50000,
            'harga_3_lama' => 20000,
            'harga_4_lama' => 10000,
            'harga_1_baru' => $harga1Baru,
            'harga_2_baru' => $harga1Baru / 2,
            'harga_3_baru' => $harga1Baru / 5,
            'harga_4_baru' => $harga1Baru / 10,
            'alasan' => 'PENYESUAIAN_PASAR',
        ]);

        return $priceChange;
    }
    #[Test]
    public function command_applies_due_scheduled_price_changes(): void
    {
        $this->actingAs($this->user);
        $this->createScheduledPriceChange(now()->subMinute()->toDateTimeString());

        $this->artisan('price-change:apply')
            ->assertSuccessful();

        $this->product->refresh();
        $this->assertSame(144000.0, (float) $this->product->harga_1);
        $this->assertDatabaseHas('price_change_trigger_log', [
            'trigger_type' => 'cron',
            'documents_processed' => 1,
        ]);
    }
    #[Test]
    public function command_skips_when_scheduler_disabled(): void
    {
        SettingService::set('scheduler.price_change_enabled', false, 'boolean');
        $this->createScheduledPriceChange(now()->subMinute()->toDateTimeString());

        $this->artisan('price-change:apply')
            ->assertSuccessful();

        $this->product->refresh();
        $this->assertSame(100000.0, (float) $this->product->harga_1);
        $this->assertSame(0, PriceChangeTriggerLog::count());
    }
    #[Test]
    public function command_force_applies_when_scheduler_disabled(): void
    {
        $this->actingAs($this->user);
        SettingService::set('scheduler.price_change_enabled', false, 'boolean');
        $this->createScheduledPriceChange(now()->subMinute()->toDateTimeString());

        $this->artisan('price-change:apply', ['--force' => true])
            ->assertSuccessful();

        $this->product->refresh();
        $this->assertSame(144000.0, (float) $this->product->harga_1);
    }
    #[Test]
    public function schedule_registers_price_change_command(): void
    {
        $this->assertTrue(
            collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
                ->contains(fn ($event) => str_contains($event->command ?? '', 'price-change:apply'))
        );
    }
}
