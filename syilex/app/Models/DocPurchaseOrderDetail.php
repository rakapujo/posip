<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPurchaseOrderDetail extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_purchase_order_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'po_id',
        'product_id',
        'unit_used',
        'unit_konversi',
        'qty_in_unit',
        'qty_in_base',
        'harga_per_unit',
        'harga_per_base',
        'harga_bruto',
        'diskon_1_tipe',
        'diskon_1_nilai',
        'diskon_1_hasil',
        'diskon_2_tipe',
        'diskon_2_nilai',
        'diskon_2_hasil',
        'diskon_3_tipe',
        'diskon_3_nilai',
        'diskon_3_hasil',
        'diskon_4_tipe',
        'diskon_4_nilai',
        'diskon_4_hasil',
        'diskon_5_tipe',
        'diskon_5_nilai',
        'diskon_5_hasil',
        'total_diskon_item',
        'subtotal',
        'cost_per_unit',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'po_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'unit_konversi' => 'integer',
            'qty_in_unit' => 'decimal:4',
            'qty_in_base' => 'decimal:4',
            'harga_per_unit' => 'decimal:2',
            'harga_per_base' => 'decimal:4',
            'harga_bruto' => 'decimal:2',
            'diskon_1_nilai' => 'decimal:2',
            'diskon_1_hasil' => 'decimal:2',
            'diskon_2_nilai' => 'decimal:2',
            'diskon_2_hasil' => 'decimal:2',
            'diskon_3_nilai' => 'decimal:2',
            'diskon_3_hasil' => 'decimal:2',
            'diskon_4_nilai' => 'decimal:2',
            'diskon_4_hasil' => 'decimal:2',
            'diskon_5_nilai' => 'decimal:2',
            'diskon_5_hasil' => 'decimal:2',
            'total_diskon_item' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseOrder::class, 'po_id');
    }

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    // ==================== HELPERS ====================

    /**
     * Get discount summary (all 5 lines combined).
     */
    public function getDiskonInfoAttribute(): array
    {
        $discounts = [];
        for ($i = 1; $i <= 5; $i++) {
            $tipe = $this->{"diskon_{$i}_tipe"};
            if ($tipe !== 'none') {
                $discounts[] = [
                    'line' => $i,
                    'tipe' => $tipe,
                    'nilai' => $this->{"diskon_{$i}_nilai"},
                    'hasil' => $this->{"diskon_{$i}_hasil"},
                ];
            }
        }
        return $discounts;
    }

    /**
     * Check if this item has any discount.
     */
    public function hasDiscount(): bool
    {
        return (float) $this->total_diskon_item > 0;
    }

    /**
     * Get the unit info from product.
     */
    public function getUnitInfoAttribute(): ?array
    {
        $product = $this->product;
        if (!$product) {
            return null;
        }

        // Find matching unit from product
        for ($i = 1; $i <= 4; $i++) {
            if ($product->{"unit_{$i}"} === $this->unit_used) {
                return [
                    'unit' => $product->{"unit_{$i}"},
                    'konversi' => $product->{"konversi_{$i}"},
                    'harga_jual' => $product->{"harga_{$i}"},
                ];
            }
        }

        return null;
    }
}
