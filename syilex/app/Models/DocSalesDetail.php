<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocSalesDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_sales_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'sales_id',
        'product_id',
        'unit',
        'konversi',
        'qty',
        'qty_base',
        'harga_satuan',
        'diskon_1_tipe', 'diskon_1_nilai', 'diskon_1_hasil',
        'diskon_2_tipe', 'diskon_2_nilai', 'diskon_2_hasil',
        'diskon_3_tipe', 'diskon_3_nilai', 'diskon_3_hasil',
        'diskon_4_tipe', 'diskon_4_nilai', 'diskon_4_hasil',
        'diskon_5_tipe', 'diskon_5_nilai', 'diskon_5_hasil',
        'diskon_total',
        'jumlah',
        'promo_id',
        'hpp_at_time',
        'serial_unit_ids',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'sales_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'konversi' => 'integer',
            'qty' => 'decimal:2',
            'qty_base' => 'decimal:2',
            'harga_satuan' => 'decimal:2',
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
            'diskon_total' => 'decimal:2',
            'jumlah' => 'decimal:2',
            'hpp_at_time' => 'decimal:4',
            'serial_unit_ids' => 'array',
        ];
    }

    // ==================== RELATIONS ====================

    public function sales(): BelongsTo
    {
        return $this->belongsTo(DocSales::class, 'sales_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(DocPromo::class, 'promo_id');
    }

    public function returnDetails(): HasMany
    {
        return $this->hasMany(DocSalesReturnDetail::class, 'sales_detail_id');
    }

    // ==================== HELPERS ====================

    /**
     * Get total qty already returned in base unit for this detail.
     */
    public function getTotalReturnedBaseAttribute(): float
    {
        return (float) $this->returnDetails()->sum('qty_base');
    }

    /**
     * Get remaining returnable qty in base unit.
     */
    public function getReturnableBaseAttribute(): float
    {
        return (float) $this->qty_base - $this->total_returned_base;
    }
}
