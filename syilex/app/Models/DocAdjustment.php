<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasAuditLog;

class DocAdjustment extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_adjustment';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'warehouse_id',
        'tanggal',
        'keterangan',
        'status',
        'source',
        'opname_id',
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
        'warehouse_id',
        'opname_id',
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
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    /**
     * Get the user who approved this document.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the details.
     */
    public function details(): HasMany
    {
        return $this->hasMany(DocAdjustmentDetail::class, 'adjustment_id');
    }

    /**
     * Get the source opname (if this adjustment was generated from stock opname).
     */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(DocStockOpname::class, 'opname_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for draft status.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope for approved status.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope for searching by nomor_dokumen or keterangan.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhere('keterangan', 'like', "%{$search}%");
        });
    }

    /**
     * Scope for filtering by warehouse.
     */
    public function scopeByWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // ==================== HELPERS ====================

    /**
     * Check if adjustment is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if adjustment is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Get total items count.
     */
    public function getTotalItemsAttribute(): int
    {
        return $this->details()->count();
    }

    /**
     * Check if this adjustment is from manual entry.
     */
    public function isManualSource(): bool
    {
        return $this->source === 'manual' || $this->source === null;
    }

    /**
     * Check if this adjustment is from stock opname.
     */
    public function isOpnameSource(): bool
    {
        return $this->source === 'opname';
    }
}
