<?php

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryStock>
 */
class InventoryStockFactory extends Factory
{
    protected $model = InventoryStock::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => MasterProduk::factory(),
            'warehouse_id' => MasterWarehouse::factory(),
            'qty' => fake()->numberBetween(0, 100),
            'avg_cost' => fake()->randomFloat(4, 1000, 100000),
        ];
    }

    /**
     * Set specific product.
     */
    public function forProduct(MasterProduk $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'avg_cost' => $product->avg_cost,
        ]);
    }

    /**
     * Set specific warehouse.
     */
    public function forWarehouse(MasterWarehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $warehouse->id,
        ]);
    }

    /**
     * Set specific quantity.
     */
    public function withQty(int $qty): static
    {
        return $this->state(fn (array $attributes) => [
            'qty' => $qty,
        ]);
    }

    /**
     * Set zero stock.
     */
    public function withZeroStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'qty' => 0,
        ]);
    }

    /**
     * Set specific avg_cost.
     */
    public function withAvgCost(float $avgCost): static
    {
        return $this->state(fn (array $attributes) => [
            'avg_cost' => $avgCost,
        ]);
    }
}
