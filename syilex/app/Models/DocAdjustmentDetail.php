<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocAdjustmentDetail extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_adjustment_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'adjustment_id',
        'product_id',
        'jenis',
        'stok_sistem',
        'qty',
        'stok_akhir',
        'notes',
        'serial_unit_ids',
        'serial_unit_statuses',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'adjustment_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'stok_sistem' => 'integer',
            'qty' => 'integer',
            'stok_akhir' => 'integer',
            'serial_unit_ids' => 'array',
            'serial_unit_statuses' => 'array',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent adjustment.
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(DocAdjustment::class, 'adjustment_id');
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
     * Check if this is a debit (stock in).
     */
    public function isDebit(): bool
    {
        return $this->jenis === 'debit';
    }

    /**
     * Check if this is a kredit (stock out).
     */
    public function isKredit(): bool
    {
        return $this->jenis === 'kredit';
    }

    /**
     * Get the qty change (positive for debit, negative for kredit).
     */
    public function getQtyChangeAttribute(): int
    {
        return $this->isDebit() ? $this->qty : -$this->qty;
    }
}
