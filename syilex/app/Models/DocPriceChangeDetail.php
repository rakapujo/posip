<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPriceChangeDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_price_change_detail';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'price_change_id',
        'product_id',
        'harga_1_lama',
        'harga_2_lama',
        'harga_3_lama',
        'harga_4_lama',
        'harga_1_baru',
        'harga_2_baru',
        'harga_3_baru',
        'harga_4_baru',
        'alasan',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'price_change_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'harga_1_lama' => 'decimal:2',
            'harga_2_lama' => 'decimal:2',
            'harga_3_lama' => 'decimal:2',
            'harga_4_lama' => 'decimal:2',
            'harga_1_baru' => 'decimal:2',
            'harga_2_baru' => 'decimal:2',
            'harga_3_baru' => 'decimal:2',
            'harga_4_baru' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent price change.
     */
    public function priceChange(): BelongsTo
    {
        return $this->belongsTo(DocPriceChange::class, 'price_change_id');
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
     * Get the price difference for harga_1.
     */
    public function getHarga1DifferenceAttribute(): float
    {
        return (float) $this->harga_1_baru - (float) $this->harga_1_lama;
    }

    /**
     * Get the price difference for harga_2.
     */
    public function getHarga2DifferenceAttribute(): float
    {
        return (float) $this->harga_2_baru - (float) $this->harga_2_lama;
    }

    /**
     * Get the price difference for harga_3.
     */
    public function getHarga3DifferenceAttribute(): float
    {
        return (float) $this->harga_3_baru - (float) $this->harga_3_lama;
    }

    /**
     * Get the price difference for harga_4.
     */
    public function getHarga4DifferenceAttribute(): float
    {
        return (float) $this->harga_4_baru - (float) $this->harga_4_lama;
    }

    /**
     * Get alasan label.
     */
    public function getAlasanLabelAttribute(): string
    {
        return DocPriceChange::getAlasanLabel($this->alasan);
    }
}
