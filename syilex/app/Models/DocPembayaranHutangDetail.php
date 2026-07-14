<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPembayaranHutangDetail extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_pembayaran_hutang_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'pembayaran_id',
        'hutang_id',
        'nominal_dibayar',
        'sumber',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'pembayaran_id',
        'hutang_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'nominal_dibayar' => 'decimal:2',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent pembayaran.
     */
    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(DocPembayaranHutang::class, 'pembayaran_id');
    }

    /**
     * Get the related hutang.
     */
    public function hutang(): BelongsTo
    {
        return $this->belongsTo(SupplierHutang::class, 'hutang_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for filtering by sumber.
     */
    public function scopeBySumber($query, string $sumber)
    {
        return $query->where('sumber', $sumber);
    }

    /**
     * Scope for cash payments.
     */
    public function scopeCash($query)
    {
        return $query->where('sumber', 'cash');
    }

    /**
     * Scope for deposit payments.
     */
    public function scopeDeposit($query)
    {
        return $query->where('sumber', 'deposit');
    }

    // ==================== HELPERS ====================

    /**
     * Check if this detail is from cash.
     */
    public function isCash(): bool
    {
        return $this->sumber === 'cash';
    }

    /**
     * Check if this detail is from deposit.
     */
    public function isDeposit(): bool
    {
        return $this->sumber === 'deposit';
    }
}
