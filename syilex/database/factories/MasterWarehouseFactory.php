<?php

namespace Database\Factories;

use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MasterWarehouse>
 */
class MasterWarehouseFactory extends Factory
{
    protected $model = MasterWarehouse::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('WH-???'));

        return [
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => $code,
            'nama_warehouse' => fake()->company() . ' Warehouse',
            'alamat' => fake()->address(),
            'status' => 'active',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the warehouse is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
