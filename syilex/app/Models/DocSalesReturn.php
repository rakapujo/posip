<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasCreatedUpdatedBy;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocSalesReturn extends Model
{
    use HasAuditLog, HasCreatedUpdatedBy, HasFactory, HasUlid;

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
        'source',
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
        'nilai_diakui',
        'selisih',
        'catatan_approval',
        'refund_method',
        'status',
        'notes',
        'locked_at',
        'locked_by',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
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
        'locked_by',
        'approved_by',
        'created_by',
        'updated_by',
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
            'nilai_diakui' => 'decimal:2',
            'selisih' => 'decimal:2',
            'locked_at' => LocalDateTime::class,
            'approved_at' => LocalDateTime::class,
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

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(CustomerDeposit::class, 'retur_id');
    }

    // ==================== SCOPES ====================

    public function scopeByShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeLock($query)
    {
        return $query->where('status', 'lock');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCommitted($query)
    {
        return $query->whereIn('status', ['lock', 'approved']);
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
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

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isLock(): bool
    {
        return $this->status === 'lock';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function canLock(): bool
    {
        return $this->source === 'manual' && $this->isDraft() && $this->details()->exists();
    }

    public function canApprove(): bool
    {
        return $this->source === 'manual' && $this->isLock();
    }
}
