<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Traits\HasAuditLog;

class MasterProduk extends Model
{
    // SoftDeletes: produk yang dihapus tetap ada di DB (deleted_at ter-set),
    // supaya relasi historis (sales, purchase, dll) tidak broken.
    // Query normal auto-exclude. Use `withTrashed()` untuk include deleted.
    use HasFactory, HasUlid, HasCreatedUpdatedBy, SoftDeletes, HasAuditLog;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_produk';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_produk',
        'barcode',
        'is_serial',
        'nama_produk',
        'brand_id',
        'tipe_id',
        'kategori_id',
        'grup_id',
        'gambar',
        'minimum_stok',
        'avg_cost',
        'unit_1',
        'konversi_1',
        'harga_1',
        'unit_2',
        'konversi_2',
        'harga_2',
        'unit_3',
        'konversi_3',
        'harga_3',
        'unit_4',
        'konversi_4',
        'harga_4',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'brand_id',
        'tipe_id',
        'kategori_id',
        'grup_id',
        'created_by',
        'updated_by',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'gambar_url',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_serial' => 'boolean',
            'minimum_stok' => 'integer',
            'avg_cost' => 'decimal:4',
            'konversi_1' => 'integer',
            'konversi_2' => 'integer',
            'konversi_3' => 'integer',
            'konversi_4' => 'integer',
            'harga_1' => 'decimal:2',
            'harga_2' => 'decimal:2',
            'harga_3' => 'decimal:2',
            'harga_4' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if produk is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active produks.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive produks.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for search by kode_produk, barcode, nama_produk.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('kode_produk', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%")
              ->orWhere('nama_produk', 'like', "%{$search}%");
        });
    }

    /**
     * Get brand yang memiliki produk ini.
     */
    public function brand()
    {
        return $this->belongsTo(MasterBrand::class, 'brand_id');
    }

    /**
     * Get tipe yang memiliki produk ini.
     */
    public function tipe()
    {
        return $this->belongsTo(MasterTipe::class, 'tipe_id');
    }

    /**
     * Get kategori yang memiliki produk ini.
     */
    public function kategori()
    {
        return $this->belongsTo(MasterKategori::class, 'kategori_id');
    }

    /**
     * Get grup yang memiliki produk ini.
     */
    public function grup()
    {
        return $this->belongsTo(MasterGrup::class, 'grup_id');
    }

    /**
     * Get inventory stocks for this product (all warehouses).
     */
    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class, 'product_id');
    }

    /**
     * Get stock cards for this product.
     */
    public function stockCards()
    {
        return $this->hasMany(StockCard::class, 'product_id');
    }

    /**
     * Get serial units for this product (modul serial A+).
     */
    public function serialUnits()
    {
        return $this->hasMany(SerialUnit::class, 'product_id');
    }

    /**
     * Get purchase price history for this product.
     */
    public function priceHistories()
    {
        return $this->hasMany(HistoryHargaBeli::class, 'product_id');
    }

    /**
     * Get gambar URL accessor.
     */
    public function getGambarUrlAttribute(): ?string
    {
        return $this->gambar ? Storage::disk('public')->url($this->gambar) : null;
    }

    /**
     * Get total stock quantity across all warehouses.
     */
    public function getTotalStockAttribute(): int
    {
        return (int) $this->inventoryStocks()->sum('qty');
    }

    /**
     * Recalculate global avg_cost using weighted average formula.
     * Call this after PURCHASE or ADJUSTMENT_IN transactions.
     *
     * @param int $newQty - Quantity being added
     * @param float $newCost - Cost per unit of new stock
     * @return float - New avg_cost
     */
    public function recalculateAvgCost(int $newQty, float $newCost): float
    {
        $currentQty = $this->total_stock;
        $currentAvgCost = (float) $this->avg_cost;

        // Weighted average formula
        // New Avg = (Current Qty × Current Avg) + (New Qty × New Cost)
        //           ─────────────────────────────────────────────────────
        //                      Current Qty + New Qty

        $totalQty = $currentQty + $newQty;

        if ($totalQty <= 0) {
            return $currentAvgCost; // Keep current avg if no stock
        }

        $newAvgCost = (($currentQty * $currentAvgCost) + ($newQty * $newCost)) / $totalQty;

        $this->avg_cost = $newAvgCost;
        $this->save();

        return $newAvgCost;
    }

    /**
     * Sync avg_cost to all inventory_stock records for this product.
     * Call this after avg_cost is updated to keep backward compatibility.
     */
    public function syncAvgCostToInventoryStocks(): void
    {
        $this->inventoryStocks()->update(['avg_cost' => $this->avg_cost]);
    }

    /**
     * Check if total stock is empty and reset HPP to 0.
     * Creates a HPP_RESET stock card entry for audit trail.
     *
     * @param int $warehouseId - Warehouse that triggered the reset (for stock card)
     * @param int|null $transactionId - Optional transaction ID that caused this
     * @param string|null $transactionNo - Optional transaction number that caused this
     * @param \DateTimeInterface|string|null $tanggal - Transaction date (defaults to now)
     * @return bool - True if HPP was reset, false otherwise
     */
    public function checkAndResetHppIfStockEmpty(
        int $warehouseId,
        ?int $transactionId = null,
        ?string $transactionNo = null,
        $tanggal = null
    ): bool {
        // Get total stock across ALL warehouses
        $totalGlobalStock = InventoryStock::where('product_id', $this->id)->sum('qty');

        // Only reset if stock is 0 or less AND current HPP > 0
        if ($totalGlobalStock <= 0 && (float) $this->avg_cost > 0) {
            $avgCostBefore = (float) $this->avg_cost;

            // Reset HPP to 0 in master_produk
            $this->avg_cost = 0;
            $this->save();

            // Sync to all inventory_stocks
            $this->syncAvgCostToInventoryStocks();

            // Create HPP_RESET stock card entry
            StockCard::record([
                'product_id' => $this->id,
                'warehouse_id' => $warehouseId,
                'transaction_type' => 'HPP_RESET',
                'transaction_id' => $transactionId,
                'transaction_no' => $transactionNo,
                'tanggal' => $tanggal ?? now(),
                'qty_in' => 0,
                'qty_out' => 0,
                'cost_per_unit' => 0,
                'avg_cost_before' => $avgCostBefore,
                'avg_cost_after' => 0,
                'notes' => 'Auto Reset HPP (Stock Kosong)',
            ]);

            return true;
        }

        return false;
    }
}
