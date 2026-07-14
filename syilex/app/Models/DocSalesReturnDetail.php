<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocSalesReturnDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'doc_sales_return_detail';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'return_id',
        'sales_detail_id',
        'product_id',
        'unit',
        'konversi',
        'qty',
        'qty_base',
        'harga_satuan',
        'jumlah',
        'hpp_at_time',
        'serial_unit_ids',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'return_id',
        'sales_detail_id',
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
            'jumlah' => 'decimal:2',
            'hpp_at_time' => 'decimal:4',
            'serial_unit_ids' => 'array',
        ];
    }

    // ==================== RELATIONS ====================

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(DocSalesReturn::class, 'return_id');
    }

    public function salesDetail(): BelongsTo
    {
        return $this->belongsTo(DocSalesDetail::class, 'sales_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }
}
