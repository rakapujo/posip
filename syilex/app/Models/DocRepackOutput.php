<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocRepackOutput extends Model
{
    use HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_repack_output';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'repack_id',
        'product_id',
        'qty',
        'cost_per_unit',
        'total_cost',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'repack_id',
        'product_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'cost_per_unit' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the parent repack.
     */
    public function repack(): BelongsTo
    {
        return $this->belongsTo(DocRepack::class, 'repack_id');
    }

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }
}
