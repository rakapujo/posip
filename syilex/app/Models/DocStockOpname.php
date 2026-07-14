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
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\HasAuditLog;

class DocStockOpname extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /** Kolom DATETIME untuk filter date-range (lihat HasDateRangeScope). */
    protected $dateRangeColumn = 'tanggal_opname';

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_stock_opname';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'warehouse_id',
        'tanggal_opname',
        'mode',
        'status',
        'approved_at',
        'approved_by',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'warehouse_id',
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
            'tanggal_opname' => LocalDateTime::class,
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
        return $this->hasMany(DocStockOpnameDetail::class, 'opname_id');
    }

    /**
     * Get the generated adjustment (if any).
     */
    public function adjustment(): HasOne
    {
        return $this->hasOne(DocAdjustment::class, 'opname_id');
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
     * Scope for searching by nomor_dokumen or notes.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhere('notes', 'like', "%{$search}%");
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
     * Check if opname is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if opname is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if opname is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if opname is full mode.
     */
    public function isFullMode(): bool
    {
        return $this->mode === 'full';
    }

    /**
     * Get total items count.
     */
    public function getTotalItemsAttribute(): int
    {
        return $this->details()->count();
    }

    /**
     * Get total items with difference.
     */
    public function getTotalDifferenceItemsAttribute(): int
    {
        return $this->details()->where('qty_difference', '!=', 0)->count();
    }
}
