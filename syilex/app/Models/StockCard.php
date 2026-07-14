<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class StockCard extends Model
{
    use HasUlid, HasDateRangeScope;

    /**
     * Flag to skip InventoryStock observer when recording from other modules.
     * Set to true before updating InventoryStock to prevent duplicate entries.
     */
    public static bool $skipObserver = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'stock_card';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'product_id',
        'warehouse_id',
        'transaction_type',
        'transaction_id',
        'transaction_no',
        'tanggal',
        'qty_in',
        'qty_out',
        'qty_balance',
        'cost_per_unit',
        'total_cost',
        'avg_cost_before',
        'avg_cost_after',
        'notes',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'product_id',
        'warehouse_id',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'qty_in' => 'integer',
            'qty_out' => 'integer',
            'qty_balance' => 'integer',
            'cost_per_unit' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'avg_cost_before' => 'decimal:4',
            'avg_cost_after' => 'decimal:4',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Transaction type labels.
     */
    public const TRANSACTION_TYPES = [
        'PURCHASE' => 'Pembelian',
        'SALES' => 'Penjualan',
        'PURCHASE_RETURN' => 'Retur Pembelian',
        'SALES_RETURN' => 'Retur Penjualan',
        'ADJUSTMENT_IN' => 'Adjustment Masuk',
        'ADJUSTMENT_OUT' => 'Adjustment Keluar',
        'STOCK_OPNAME' => 'Stock Opname',
        'TRANSFER_IN' => 'Transfer Masuk',
        'TRANSFER_OUT' => 'Transfer Keluar',
        'REPACK_IN' => 'Repack Masuk',
        'REPACK_OUT' => 'Repack Keluar',
        'HPP_RESET' => 'Reset HPP (Stock Kosong)',
        'HPP_CORRECTION' => 'Koreksi HPP',
    ];

    /**
     * Transaction types that don't affect stock quantity.
     */
    public const TYPES_NO_QTY = [
        'HPP_RESET',
        'HPP_CORRECTION',
    ];

    /**
     * Transaction types that increase stock.
     */
    public const TYPES_IN = [
        'PURCHASE',
        'SALES_RETURN',
        'ADJUSTMENT_IN',
        'TRANSFER_IN',
        'REPACK_IN',
    ];

    /**
     * Transaction types that decrease stock.
     */
    public const TYPES_OUT = [
        'SALES',
        'PURCHASE_RETURN',
        'ADJUSTMENT_OUT',
        'TRANSFER_OUT',
        'REPACK_OUT',
    ];

    // ==================== RELATIONS ====================

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    /**
     * Get the warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope by product.
     */
    public function scopeByProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope by warehouse.
     */
    public function scopeByWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * Scope by transaction type.
     */
    public function scopeByTransactionType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    /**
     * Scope for search by transaction_no.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('transaction_no', 'like', "%{$search}%");
    }

    // ==================== HELPERS ====================

    /**
     * Get transaction type label.
     */
    public function getTransactionTypeLabelAttribute(): string
    {
        return self::TRANSACTION_TYPES[$this->transaction_type] ?? $this->transaction_type;
    }

    /**
     * Get last balance for product in warehouse.
     */
    public static function getLastBalance(int $productId, int $warehouseId): int
    {
        $lastRecord = self::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastRecord ? $lastRecord->qty_balance : 0;
    }

    /**
     * Get balance before a specific date.
     */
    public static function getBalanceBefore(int $productId, int $warehouseId, string $date): int
    {
        $record = self::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('tanggal', '<', $date . ' 00:00:00')
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $record ? $record->qty_balance : 0;
    }

    /**
     * Record a stock card entry.
     * This is the main helper method for other modules to insert stock card records.
     *
     * @param array $data Required: product_id, transaction_type, tanggal
     *                    Optional: warehouse_id (nullable for HPP-only types), transaction_id, transaction_no, qty_in, qty_out, cost_per_unit, notes, avg_cost_before, avg_cost_after
     * @return self
     */
    public static function record(array $data): self
    {
        // Get GLOBAL avg_cost from product (not per-warehouse)
        // Allow override via avg_cost_before parameter (useful when recording after avg_cost has been recalculated)
        if (isset($data['avg_cost_before'])) {
            $avgCostBefore = (float) $data['avg_cost_before'];
        } else {
            $product = MasterProduk::find($data['product_id']);
            $avgCostBefore = $product ? (float) $product->avg_cost : 0;
        }

        $qtyIn = $data['qty_in'] ?? 0;
        $qtyOut = $data['qty_out'] ?? 0;
        $warehouseId = $data['warehouse_id'] ?? null;

        // Check if this is a HPP-only type (no stock movement, no balance calculation needed)
        $isHppOnlyType = in_array($data['transaction_type'], self::TYPES_NO_QTY);

        if ($isHppOnlyType || $warehouseId === null) {
            // HPP-only types don't need balance calculation
            $newBalance = 0;
        } else {
            // Get last balance for this warehouse and calculate new balance
            $lastBalance = self::getLastBalance($data['product_id'], $warehouseId);
            $newBalance = $lastBalance + $qtyIn - $qtyOut;
        }

        // Calculate total cost
        $costPerUnit = $data['cost_per_unit'] ?? $avgCostBefore;
        $totalCost = ($qtyIn + $qtyOut) * $costPerUnit;

        // Get avg_cost_after (may be updated by the calling module after recalculation)
        $avgCostAfter = $data['avg_cost_after'] ?? $avgCostBefore;

        return self::create([
            'product_id' => $data['product_id'],
            'warehouse_id' => $warehouseId,
            'transaction_type' => $data['transaction_type'],
            'transaction_id' => $data['transaction_id'] ?? null,
            'transaction_no' => $data['transaction_no'] ?? null,
            'tanggal' => $data['tanggal'],
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'qty_balance' => $newBalance,
            'cost_per_unit' => $costPerUnit,
            'total_cost' => $totalCost,
            'avg_cost_before' => $avgCostBefore,
            'avg_cost_after' => $avgCostAfter,
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);
    }
}
