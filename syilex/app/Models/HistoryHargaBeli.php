<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoryHargaBeli extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'history_harga_beli';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'product_id',
        'supplier_id',
        'po_id',
        'po_detail_id',
        'tanggal',
        'unit_used',
        'qty_in_unit',
        'qty_in_base',
        'harga_per_unit',
        'harga_per_base',
        'created_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'product_id',
        'supplier_id',
        'po_id',
        'po_detail_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'qty_in_unit' => 'decimal:4',
            'qty_in_base' => 'decimal:4',
            'harga_per_unit' => 'decimal:2',
            'harga_per_base' => 'decimal:4',
            'created_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MasterSupplier::class, 'supplier_id');
    }

    /**
     * Get the purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseOrder::class, 'po_id');
    }

    /**
     * Get the purchase order detail.
     */
    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseOrderDetail::class, 'po_detail_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for filtering by product.
     */
    public function scopeByProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope for filtering by supplier.
     */
    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for filtering by unit.
     */
    public function scopeByUnit($query, string $unit)
    {
        return $query->where('unit_used', $unit);
    }

    /**
     * Scope for getting last price.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get last purchase price for a product.
     *
     * @param int $productId
     * @param int|null $supplierId - Optional: filter by supplier
     * @param string|null $unit - Optional: filter by unit
     * @return self|null
     */
    public static function getLastPrice(int $productId, ?int $supplierId = null, ?string $unit = null): ?self
    {
        $query = static::where('product_id', $productId);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        if ($unit) {
            $query->where('unit_used', $unit);
        }

        return $query->orderBy('tanggal', 'desc')
                     ->orderBy('id', 'desc')
                     ->first();
    }

    /**
     * Get price history for a product.
     *
     * @param int $productId
     * @param int|null $supplierId
     * @param string|null $unit
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPriceHistory(int $productId, ?int $supplierId = null, ?string $unit = null, int $limit = 10)
    {
        $query = static::with(['supplier:id,kode_supplier,nama_supplier', 'purchaseOrder:id,ulid,nomor_dokumen'])
            ->where('product_id', $productId);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        if ($unit) {
            $query->where('unit_used', $unit);
        }

        return $query->orderBy('tanggal', 'desc')
                     ->orderBy('id', 'desc')
                     ->limit($limit)
                     ->get();
    }
}
