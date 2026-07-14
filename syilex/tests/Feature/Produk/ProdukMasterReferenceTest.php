<?php

namespace Tests\Feature\Produk;

use App\Models\MasterGrup;
use App\Models\MasterKategori;
use App\Models\MasterProduk;
use App\Models\MasterTipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProdukMasterReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterTipe $tipe;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['produk.create', 'produk.update', 'produk.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['produk.create', 'produk.update', 'produk.view']);

        $this->tipe = MasterTipe::create([
            'kode_tipe' => 'TP-PRD',
            'nama_tipe' => 'Retail',
            'status' => 'active',
        ]);
    }

    public function test_store_rejects_inactive_kategori(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-IN',
            'nama_kategori' => 'Inactive Kategori',
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/produks', $this->retailPayload([
                'kode_produk' => 'PRD_KT_IN',
                'kategori_id' => $kategori->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori_id']);
    }

    public function test_store_rejects_inactive_grup(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-GR',
            'nama_kategori' => 'Active Kategori',
            'status' => 'active',
        ]);

        $grup = MasterGrup::create([
            'kategori_id' => $kategori->id,
            'kode_grup' => 'GR-IN',
            'nama_grup' => 'Inactive Grup',
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/produks', $this->retailPayload([
                'kode_produk' => 'PRD_GR_IN',
                'kategori_id' => $kategori->id,
                'grup_id' => $grup->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grup_id']);
    }

    public function test_update_rejects_inactive_kategori_assignment(): void
    {
        $activeKategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-ACT',
            'nama_kategori' => 'Active',
            'status' => 'active',
        ]);

        $inactiveKategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-OLD',
            'nama_kategori' => 'Was Active',
            'status' => 'inactive',
        ]);

        $produk = MasterProduk::factory()->create([
            'kode_produk' => 'PRD_UPD',
            'kategori_id' => $activeKategori->id,
            'status' => 'active',
            ...$this->validUnitPriceAttributes(),
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/produks/{$produk->ulid}", array_merge(
                $this->retailPayload([
                    'nama_produk' => $produk->nama_produk,
                    'kategori_id' => $inactiveKategori->id,
                ]),
                ['status' => 'active'],
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori_id']);
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
