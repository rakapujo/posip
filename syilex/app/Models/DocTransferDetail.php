<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocTransferDetail extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_transfer_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'transfer_id',
        'product_id',
        'qty',
        'serial_unit_ids',
        'biaya_dialokasikan',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'transfer_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'serial_unit_ids' => 'array',
            'biaya_dialokasikan' => 'decimal:4',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent transfer.
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(DocTransfer::class, 'transfer_id');
    }

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }
}
