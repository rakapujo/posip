<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocSalesReturn extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_sales_returns';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'sales_id',
        'terminal_id',
        'shift_id',
        'warehouse_id',
        'customer_id',
        'subtotal',
        'pajak_nama',
        'pajak_persen',
        'pajak_nominal',
        'pembulatan',
        'grand_total',
        'refund_method',
        'notes',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'sales_id',
        'terminal_id',
        'shift_id',
        'warehouse_id',
        'customer_id',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'subtotal' => 'decimal:2',
            'pajak_persen' => 'decimal:2',
            'pajak_nominal' => 'decimal:2',
            'pembulatan' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function sales(): BelongsTo
    {
        return $this->belongsTo(DocSales::class, 'sales_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(MasterPosTerminal::class, 'terminal_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosTerminalShift::class, 'shift_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MasterCustomer::class, 'customer_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DocSalesReturnDetail::class, 'return_id');
    }

    // ==================== SCOPES ====================

    public function scopeByShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeByTerminal($query, int $terminalId)
    {
        return $query->where('terminal_id', $terminalId);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%");
        });
    }

    // ==================== HELPERS ====================

    public function isCashRefund(): bool
    {
        return $this->refund_method === 'cash';
    }

}
