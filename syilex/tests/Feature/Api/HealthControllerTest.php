<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_health_response_structure(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $response->assertJsonStructure([
            'status',
            'app',
            'env',
            'timestamp',
            'checks' => [
                'db' => ['ok'],
                'storage' => ['ok'],
                'cache' => ['ok'],
            ],
        ]);
    }

    public function test_db_check_reports_latency(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $data = $response->json();
        $this->assertTrue($data['checks']['db']['ok']);
        $this->assertIsNumeric($data['checks']['db']['latency_ms']);
    }

    public function test_storage_check_probe_file_is_cleaned(): void
    {
        $before = glob(storage_path('app/private/health-probe/*')) ?: [];

        $this->getJson('/api/v1/health')->assertOk();

        $after = glob(storage_path('app/private/health-probe/*')) ?: [];
        $this->assertEquals(count($before), count($after), 'Health probe harus dihapus setelah cek');
    }

    public function test_cache_check_reports_driver(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $driver = $response->json('checks.cache.driver');
        $this->assertNotEmpty($driver);
    }

    // ====================================================================
    // EDGE CASE: status & exact field values saat semua sehat (200 OK)
    // ====================================================================

    /** Saat semua cek sehat: status 'ok', kode 200, dan SETIAP cek ok===true (boolean, bukan truthy). */
    public function test_all_checks_healthy_returns_status_ok_with_200(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $this->assertSame('ok', $response->json('status'));
        $this->assertSame(true, $response->json('checks.db.ok'));
        $this->assertSame(true, $response->json('checks.storage.ok'));
        $this->assertSame(true, $response->json('checks.cache.ok'));
        // storage check mengembalikan writable=true saat sukses
        $this->assertSame(true, $response->json('checks.storage.writable'));
    }

    /** Field app/env mengikuti config (bukan hardcode). */
    public function test_app_and_env_reflect_config(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame(config('app.name'), $response->json('app'));
        $this->assertSame(config('app.env'), $response->json('env'));
        $this->assertSame(config('cache.default'), $response->json('checks.cache.driver'));
    }

    /** Timestamp valid ISO-8601 (bukan sekadar tidak kosong). */
    public function test_timestamp_is_valid_iso8601(): void
    {
        $ts = $this->getJson('/api/v1/health')->assertOk()->json('timestamp');

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $ts);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed, 'timestamp harus ISO-8601 (ATOM)');
    }

    // ====================================================================
    // EDGE CASE: degraded → 503 saat salah satu cek gagal
    // ====================================================================

    /**
     * Cache read mismatch → cek cache ok=false → status keseluruhan 'degraded' + HTTP 503.
     * Membuktikan logika collect()->every() benar: SATU gagal = degraded.
     */
    public function test_cache_failure_makes_status_degraded_with_503(): void
    {
        // Paksa cek cache gagal: nilai yang dibaca berbeda dari yang ditulis.
        // driver() dibiarkan passthrough agar RateLimiter (middleware) tetap berfungsi.
        $mock = \Mockery::mock(app('cache'))->makePartial();
        $mock->shouldReceive('put')->andReturn(true);
        $mock->shouldReceive('get')->andReturn('NILAI-BERBEDA-SENGAJA');
        $mock->shouldReceive('forget')->andReturn(true);
        Cache::swap($mock);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);
        $this->assertSame('degraded', $response->json('status'));
        $this->assertSame(false, $response->json('checks.cache.ok'));
        // Cek lain tetap sehat — degraded hanya karena cache.
        $this->assertSame(true, $response->json('checks.db.ok'));
        $this->assertSame(true, $response->json('checks.storage.ok'));
    }

    /**
     * Exception di cache (driver lempar) → ok=false + ada key 'error', tetap 503 (tidak crash).
     */
    public function test_cache_exception_is_caught_and_reported_as_degraded(): void
    {
        $mock = \Mockery::mock(app('cache'))->makePartial();
        $mock->shouldReceive('put')->andThrow(new \RuntimeException('cache down'));
        Cache::swap($mock);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);
        $this->assertSame('degraded', $response->json('status'));
        $this->assertSame(false, $response->json('checks.cache.ok'));
        $this->assertSame('cache down', $response->json('checks.cache.error'));
    }
}
