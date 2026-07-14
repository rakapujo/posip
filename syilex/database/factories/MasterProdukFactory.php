<?php

namespace Database\Factories;

use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MasterProduk>
 */
class MasterProdukFactory extends Factory
{
    protected $model = MasterProduk::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('PRD-?????'));

        return [
            'ulid' => (string) Str::ulid(),
            'kode_produk' => $code,
            'barcode' => fake()->unique()->ean13(),
            'nama_produk' => fake()->words(3, true),
            'brand_id' => null,
            'tipe_id' => null,
            'kategori_id' => null,
            'grup_id' => null,
            'gambar' => null,
            'minimum_stok' => 0,
            'avg_cost' => fake()->randomFloat(4, 1000, 100000),
            'unit_1' => 'KARTON',
            'konversi_1' => 12,
            'harga_1' => fake()->randomFloat(2, 50000, 500000),
            'unit_2' => 'LUSIN',
            'konversi_2' => 12,
            'harga_2' => fake()->randomFloat(2, 40000, 400000),
            'unit_3' => 'BOX',
            'konversi_3' => 6,
            'harga_3' => fake()->randomFloat(2, 20000, 200000),
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => fake()->randomFloat(2, 5000, 50000),
            'status' => 'active',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Set specific HPP/avg_cost.
     */
    public function withAvgCost(float $avgCost): static
    {
        return $this->state(fn (array $attributes) => [
            'avg_cost' => $avgCost,
        ]);
    }

    /**
     * Set zero HPP.
     */
    public function withZeroHpp(): static
    {
        return $this->state(fn (array $attributes) => [
            'avg_cost' => 0,
        ]);
    }
}
