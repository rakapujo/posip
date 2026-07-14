<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocStockOpnameDetail extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_stock_opname_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'opname_id',
        'product_id',
        'qty_system',
        'qty_physical',
        'qty_difference',
        'notes',
        'serial_unit_ids_present',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'opname_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'qty_system' => 'integer',
            'qty_physical' => 'integer',
            'qty_difference' => 'integer',
            'serial_unit_ids_present' => 'array',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent opname.
     */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(DocStockOpname::class, 'opname_id');
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
     * Check if there is a difference.
     */
    public function hasDifference(): bool
    {
        return $this->qty_difference !== 0;
    }

    /**
     * Check if physical > system (surplus).
     */
    public function isSurplus(): bool
    {
        return $this->qty_difference > 0;
    }

    /**
     * Check if physical < system (shortage).
     */
    public function isShortage(): bool
    {
        return $this->qty_difference < 0;
    }
}
