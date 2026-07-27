<?php

namespace Tests\Feature\Produk;

use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProdukGambarPathTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        foreach (['produk.create', 'produk.update', 'produk.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['produk.create', 'produk.update', 'produk.view']);
    }

    public function test_store_accepts_gambar_as_products_path_string(): void
    {
        $path = 'products/test-produk.webp';
        Storage::disk('public')->put($path, 'fake-webp');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/produks', $this->retailPayload([
                'kode_produk' => 'IMG_OK',
                'gambar' => $path,
            ]));

        $response->assertStatus(201);
        $this->assertSame($path, MasterProduk::where('kode_produk', 'IMG_OK')->value('gambar'));
    }

    public function test_store_accepts_gambar_as_full_storage_url(): void
    {
        $path = 'products/url-produk.webp';
        Storage::disk('public')->put($path, 'fake-webp');
        $url = Storage::disk('public')->url($path);

        $this->actingAs($this->user)
            ->postJson('/api/v1/produks', $this->retailPayload([
                'kode_produk' => 'IMG_URL',
                'gambar' => $url,
            ]))
            ->assertStatus(201);

        $this->assertSame($path, MasterProduk::where('kode_produk', 'IMG_URL')->value('gambar'));
    }

    public function test_store_rejects_gambar_outside_products_folder(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/produks', $this->retailPayload([
                'kode_produk' => 'IMG_BAD',
                'gambar' => 'avatars/evil.webp',
            ]))
            ->assertStatus(422);
    }

    public function test_store_rejects_multipart_gambar_file(): void
    {
        $this->actingAs($this->user)
            ->post('/api/v1/produks', array_merge($this->retailPayload([
                'kode_produk' => 'IMG_FILE',
            ]), [
                'gambar' => \Illuminate\Http\UploadedFile::fake()->image('x.jpg'),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gambar']);
    }

    public function test_update_can_clear_gambar_with_null(): void
    {
        $path = 'products/clear-me.webp';
        Storage::disk('public')->put($path, 'fake-webp');

        $produk = MasterProduk::factory()->create([
            'kode_produk' => 'CLR_IMG',
            'gambar' => $path,
            ...$this->validUnitPriceAttributes(),
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/produks/{$produk->ulid}", array_merge(
                $this->retailPayload(['nama_produk' => $produk->nama_produk]),
                ['gambar' => null, 'status' => 'active'],
            ))
            ->assertOk();

        $this->assertNull($produk->fresh()->gambar);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function retailPayload(array $overrides = []): array
    {
        return array_merge([
            'kode_produk' => 'PRD_01',
            'nama_produk' => 'Produk Retail Test',
            'status' => 'active',
            'minimum_stok' => 0,
            ...$this->validUnitPriceAttributes(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function validUnitPriceAttributes(): array
    {
        return [
            'unit_1' => 'KARTON',
            'konversi_1' => 12,
            'harga_1' => 120000,
            'unit_2' => 'BOX',
            'konversi_2' => 6,
            'harga_2' => 60000,
            'unit_3' => 'PACK',
            'konversi_3' => 2,
            'harga_3' => 20000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ];
    }
}
