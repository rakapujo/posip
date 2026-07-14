<?php

namespace Tests\Unit\Actions;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test untuk trait RequiresAuthenticatedUser — pastikan defensive check
 * benar-benar throw saat tidak ada user authenticated.
 */
class RequiresAuthenticatedUserTraitTest extends TestCase
{
    use RefreshDatabase;

    private object $actionStub;

    protected function setUp(): void
    {
        parent::setUp();

        // Anonymous class sebagai test subject
        $this->actionStub = new class {
            use RequiresAuthenticatedUser;

            public function run(): string
            {
                $this->ensureAuthenticated();
                return 'OK';
            }
        };
    }
    #[Test]
    public function throws_when_no_authenticated_user(): void
    {
        // Pastikan tidak ada user authenticated
        auth()->logout();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an authenticated user/');

        $this->actionStub->run();
    }
    #[Test]
    public function passes_when_user_is_authenticated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $result = $this->actionStub->run();

        $this->assertEquals('OK', $result);
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────
    #[Test]
    public function pesan_exception_menyebut_nama_class_action_konkret(): void
    {
        // static::class harus me-resolve ke class anonymous (subject), bukan trait.
        auth()->logout();

        try {
            $this->actionStub->run();
            $this->fail('Seharusnya melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $expectedClass = get_class($this->actionStub);
            $this->assertStringContainsString($expectedClass, $e->getMessage());
            $this->assertStringContainsString('requires an authenticated user', $e->getMessage());
            $this->assertStringContainsString('actingAs()', $e->getMessage());
        }
    }
    #[Test]
    public function tidak_throw_dan_mengembalikan_nilai_eksak_saat_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Identik string "OK" — pastikan ensureAuthenticated tidak mengubah alur.
        $this->assertSame('OK', $this->actionStub->run());
    }
    #[Test]
    public function throw_lagi_setelah_logout_meski_sebelumnya_sempat_login(): void
    {
        // Login → sukses, lalu logout → harus throw lagi (tidak ada state yang bocor).
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->assertSame('OK', $this->actionStub->run());

        auth()->logout();

        $this->expectException(RuntimeException::class);
        $this->actionStub->run();
    }
}
