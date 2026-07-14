<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocHppCorrectionDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_hpp_correction_detail';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'correction_id',
        'product_id',
        'hpp_lama',
        'hpp_baru',
        'alasan',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'correction_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'hpp_lama' => 'decimal:4',
            'hpp_baru' => 'decimal:4',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent correction.
     */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(DocHppCorrection::class, 'correction_id');
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
     * Get the HPP difference (hpp_baru - hpp_lama).
     */
    public function getHppDifferenceAttribute(): float
    {
        return (float) $this->hpp_baru - (float) $this->hpp_lama;
    }

    /**
     * Get alasan label.
     */
    public function getAlasanLabelAttribute(): string
    {
        return DocHppCorrection::getAlasanLabel($this->alasan);
    }
}
