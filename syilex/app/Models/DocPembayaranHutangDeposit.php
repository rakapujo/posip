<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPembayaranHutangDeposit extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_pembayaran_hutang_deposit';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'pembayaran_id',
        'deposit_id',
        'nominal_digunakan',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'pembayaran_id',
        'deposit_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'nominal_digunakan' => 'decimal:2',
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
     * Get the related deposit.
     */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(SupplierDeposit::class, 'deposit_id');
    }
}
