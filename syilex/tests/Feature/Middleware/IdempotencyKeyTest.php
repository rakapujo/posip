<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests untuk IdempotencyKey middleware.
 *
 * Setup: register test route di dalam test yang apply middleware,
 * supaya bisa test behavior tanpa bergantung pada route production.
 */
class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Cache::flush();

        // Register test route yang pakai idempotency middleware
        Route::post('/test-idempotent', function () {
            // Return unique timestamp untuk membedakan run yang berbeda
            return response()->json([
                'success' => true,
                'executed_at' => microtime(true),
            ], 200);
        })->middleware(IdempotencyKey::class);

        // Route kedua (path beda) untuk uji cache scoped per-route.
        Route::post('/test-idempotent-other', function () {
            return response()->json([
                'success' => true,
                'executed_at' => microtime(true),
            ], 200);
        })->middleware(IdempotencyKey::class);

        // Route yang SELALU error 422 — untuk uji response error tidak di-cache.
        Route::post('/test-idempotent-error', function () {
            return response()->json([
                'success' => false,
                'executed_at' => microtime(true),
            ], 422);
        })->middleware(IdempotencyKey::class);

        // Route yang SELALU 500 — error server juga tidak boleh di-cache.
        Route::post('/test-idempotent-500', function () {
            return response()->json([
                'success' => false,
                'executed_at' => microtime(true),
            ], 500);
        })->middleware(IdempotencyKey::class);
    }
    #[Test]
    public function request_without_idempotency_key_passes_through_normally(): void
    {
        $response1 = $this->postJson('/test-idempotent');
        $response2 = $this->postJson('/test-idempotent');

        $response1->assertOk();
        $response2->assertOk();

        // Tanpa idempotency key, setiap request di-execute ulang — timestamp beda
        $this->assertNotEquals(
            $response1->json('executed_at'),
            $response2->json('executed_at')
        );
    }
    #[Test]
    public function duplicate_request_with_same_key_returns_cached_response(): void
    {
        $key = 'test-idempotent-key-1234567890abcdef';

        $response1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $response2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $response1->assertOk();
        $response2->assertOk();

        // Response kedua HARUS sama persis (cached), timestamp identical
        $this->assertEquals(
            $response1->json('executed_at'),
            $response2->json('executed_at')
        );

        // Header replay menandakan response dari cache
        $response2->assertHeader('Idempotent-Replayed', 'true');
    }
    #[Test]
    public function different_keys_produce_different_responses(): void
    {
        $key1 = 'test-key-first-1234567890abcdef1';
        $key2 = 'test-key-second-1234567890abcdef';

        $response1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key1]);
        $response2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key2]);

        // Key beda = request beda = execute beda
        $this->assertNotEquals(
            $response1->json('executed_at'),
            $response2->json('executed_at')
        );
    }
    #[Test]
    public function invalid_key_format_returns_400(): void
    {
        // Key kurang dari 16 karakter
        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => 'short']);

        $response->assertStatus(400);
        $response->assertJson(['success' => false]);
    }
    #[Test]
    public function key_with_special_characters_rejected(): void
    {
        // Key dengan karakter ilegal
        $response = $this->postJson('/test-idempotent', [], [
            'Idempotency-Key' => 'invalid!@#$%^key-with-specials',
        ]);

        $response->assertStatus(400);
    }
    #[Test]
    public function cache_is_scoped_per_user(): void
    {
        $key = 'shared-key-1234567890abcdef1234';

        // User A kirim request
        $response1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $user1ExecutedAt = $response1->json('executed_at');

        // Switch ke user B dengan key yang sama
        $user2 = User::factory()->create();
        $this->actingAs($user2);

        $response2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $user2ExecutedAt = $response2->json('executed_at');

        // User B dapat response baru (bukan cache user A), karena scoped per-user
        $this->assertNotEquals($user1ExecutedAt, $user2ExecutedAt);
    }

    // ============================================================
    // EDGE CASES (galak) — boundary regex, cache 2xx-only, scope
    // ============================================================
    #[Test]
    public function key_tepat_16_karakter_diterima_dan_di_replay(): void
    {
        // Batas bawah regex: {16,128}. Tepat 16 → VALID.
        $key = str_repeat('a', 16);

        $r1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertEquals($r1->json('executed_at'), $r2->json('executed_at'));
        $r2->assertHeader('Idempotent-Replayed', 'true');
    }
    #[Test]
    public function key_15_karakter_ditolak_400(): void
    {
        // Tepat di bawah batas bawah → INVALID.
        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => str_repeat('a', 15)]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false]);
    }
    #[Test]
    public function key_tepat_128_karakter_diterima(): void
    {
        // Batas atas regex: tepat 128 → VALID.
        $key = str_repeat('b', 128);

        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $response->assertOk();
    }
    #[Test]
    public function key_129_karakter_ditolak_400(): void
    {
        // Tepat di atas batas atas → INVALID.
        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => str_repeat('b', 129)]);

        $response->assertStatus(400);
    }
    #[Test]
    public function key_dengan_dash_dan_underscore_diterima(): void
    {
        // Charset regex: [A-Za-z0-9_\-]. Dash + underscore legal.
        $key = 'KEY_with-dash_AND-underscore-123';

        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $response->assertOk();
    }
    #[Test]
    public function key_dengan_spasi_ditolak_400(): void
    {
        // Spasi bukan bagian charset → INVALID.
        $response = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => 'key with spaces 12345']);

        $response->assertStatus(400);
    }
    #[Test]
    public function response_error_422_tidak_di_cache_sehingga_di_eksekusi_ulang(): void
    {
        // Kontrak: hanya 2xx yang di-cache. 4xx → user boleh retry → eksekusi baru.
        $key = 'err-422-key-1234567890abcdef';

        $r1 = $this->postJson('/test-idempotent-error', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent-error', [], ['Idempotency-Key' => $key]);

        $r1->assertStatus(422);
        $r2->assertStatus(422);
        // Tidak ada replay header & timestamp beda (re-execute, bukan cache).
        $this->assertNotEquals($r1->json('executed_at'), $r2->json('executed_at'));
        $this->assertNull($r2->headers->get('Idempotent-Replayed'));
    }
    #[Test]
    public function response_error_500_tidak_di_cache(): void
    {
        $key = 'err-500-key-1234567890abcdef';

        $r1 = $this->postJson('/test-idempotent-500', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent-500', [], ['Idempotency-Key' => $key]);

        $r1->assertStatus(500);
        $r2->assertStatus(500);
        $this->assertNotEquals($r1->json('executed_at'), $r2->json('executed_at'));
        $this->assertNull($r2->headers->get('Idempotent-Replayed'));
    }
    #[Test]
    public function cache_scoped_per_route_key_sama_path_beda_eksekusi_berbeda(): void
    {
        // buildCacheKey memasukkan path → key sama tapi route beda = TIDAK replay.
        $key = 'same-key-diff-route-1234567890ab';

        $r1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent-other', [], ['Idempotency-Key' => $key]);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertNotEquals($r1->json('executed_at'), $r2->json('executed_at'));
        $this->assertNull($r2->headers->get('Idempotent-Replayed'));
    }
    #[Test]
    public function response_pertama_mengembalikan_echo_header_idempotency_key(): void
    {
        // Eksekusi pertama (bukan replay) tetap meng-echo header Idempotency-Key,
        // tapi TANPA Idempotent-Replayed.
        $key = 'echo-key-1234567890abcdefXYZ';

        $r1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $r1->assertOk();
        $r1->assertHeader('Idempotency-Key', $key);
        $this->assertNull($r1->headers->get('Idempotent-Replayed'), 'Eksekusi pertama bukan replay');
    }
    #[Test]
    public function replay_mempertahankan_status_code_2xx_asli_dan_body_persis(): void
    {
        // Replay HARUS mengembalikan status & body sama persis dengan response pertama.
        $key = 'replay-status-1234567890abcdef';

        $r1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $this->assertEquals(200, $r1->getStatusCode());
        $this->assertEquals(200, $r2->getStatusCode());
        $r2->assertHeader('Idempotent-Replayed', 'true');
        $r2->assertHeader('Idempotency-Key', $key);
        // Body identik (success + executed_at sama).
        $this->assertSame($r1->json(), $r2->json());
    }
    #[Test]
    public function key_valid_acak_uuid_like_di_replay_konsisten(): void
    {
        // UUID v4 (36 char dengan dash) masuk charset & panjang → valid & replay.
        $key = (string) Str::uuid();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]{16,128}$/', $key);

        $r1 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);
        $r2 = $this->postJson('/test-idempotent', [], ['Idempotency-Key' => $key]);

        $r1->assertOk();
        $r2->assertHeader('Idempotent-Replayed', 'true');
        $this->assertEquals($r1->json('executed_at'), $r2->json('executed_at'));
    }
}
