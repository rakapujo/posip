<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Models\StockCard;
use App\Observers\InventoryStockObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([InventoryStockObserver::class])]
class InventoryStock extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'inventory_stock';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'qty',
        'avg_cost',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'product_id',
        'warehouse_id',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'total_value',
        'is_low_stock',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'avg_cost' => 'decimal:4',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Get the product that owns the stock.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    /**
     * Get the warehouse that owns the stock.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    /**
     * Calculate total value (qty × avg_cost).
     */
    public function getTotalValueAttribute(): float
    {
        return $this->qty * $this->avg_cost;
    }

    /**
     * Check if stock is below minimum.
     */
    public function getIsLowStockAttribute(): bool
    {
        if (!$this->relationLoaded('product')) {
            return false;
        }

        $minimumStok = $this->product->minimum_stok ?? 0;
        return $this->qty < $minimumStok;
    }

    /**
     * Scope for searching by product code or name.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->whereHas('product', function ($q) use ($search) {
            $q->where('kode_produk', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%")
              ->orWhere('nama_produk', 'like', "%{$search}%");
        });
    }

    /**
     * Scope for filtering by warehouse.
     */
    public function scopeByWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * Scope for low stock items (qty < minimum_stok).
     */
    public function scopeLowStock($query)
    {
        return $query->whereHas('product', function ($q) {
            $q->whereColumn('inventory_stock.qty', '<', 'master_produk.minimum_stok');
        });
    }

    /**
     * Scope for active products only.
     */
    public function scopeActiveProduct($query)
    {
        return $query->whereHas('product', function ($q) {
            $q->where('status', 'active');
        });
    }

    /**
     * Scope for active warehouses only.
     */
    public function scopeActiveWarehouse($query)
    {
        return $query->whereHas('warehouse', function ($q) {
            $q->where('status', 'active');
        });
    }

    /**
     * Create initial stock records for a warehouse (all active products).
     */
    public static function initializeForWarehouse(int $warehouseId): int
    {
        $products = MasterProduk::active()->pluck('id');
        $count = 0;

        foreach ($products as $productId) {
            $exists = self::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->exists();

            if (!$exists) {
                self::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => 0,
                    'avg_cost' => 0,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create initial stock records for a product (all active warehouses).
     */
    public static function initializeForProduct(int $productId): int
    {
        $warehouses = MasterWarehouse::active()->pluck('id');
        $count = 0;

        foreach ($warehouses as $warehouseId) {
            $exists = self::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->exists();

            if (!$exists) {
                self::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => 0,
                    'avg_cost' => 0,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Initialize all missing stock records.
     */
    public static function initializeAll(): int
    {
        $products = MasterProduk::active()->pluck('id');
        $warehouses = MasterWarehouse::active()->pluck('id');
        $count = 0;

        foreach ($products as $productId) {
            foreach ($warehouses as $warehouseId) {
                $exists = self::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->exists();

                if (!$exists) {
                    self::create([
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'qty' => 0,
                        'avg_cost' => 0,
                    ]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Update stock quantity without triggering observer.
     * Use this when the calling module handles StockCard recording itself.
     *
     * @param int $productId
     * @param int $warehouseId
     * @param int $qtyChange - positive for increase, negative for decrease
     * @param bool $syncAvgCost - whether to sync avg_cost from product (default: true)
     * @return self
     */
    public static function adjustStock(int $productId, int $warehouseId, int $qtyChange, bool $syncAvgCost = true): self
    {
        // Skip observer
        StockCard::$skipObserver = true;

        // Get product's global avg_cost
        $product = MasterProduk::find($productId);
        $globalAvgCost = $product ? (float) $product->avg_cost : 0;

        $stock = self::firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['qty' => 0, 'avg_cost' => $globalAvgCost]
        );

        $stock->qty += $qtyChange;

        // Sync avg_cost from product (global HPP)
        if ($syncAvgCost) {
            $stock->avg_cost = $globalAvgCost;
        }

        $stock->save();

        // Reset flag
        StockCard::$skipObserver = false;

        return $stock;
    }
}
