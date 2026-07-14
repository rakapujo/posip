<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocSalesPayment extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_sales_payments';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'sales_id',
        'metode_pembayaran_id',
        'nominal',
        'biaya_tambahan',
        'reference',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'sales_id',
        'metode_pembayaran_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'biaya_tambahan' => 'decimal:2',
        ];
    }

    // ==================== RELATIONS ====================

    public function sales(): BelongsTo
    {
        return $this->belongsTo(DocSales::class, 'sales_id');
    }

    public function metodePembayaran(): BelongsTo
    {
        return $this->belongsTo(MasterMetodePembayaran::class, 'metode_pembayaran_id');
    }
}
