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

class DocRepack extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_repack';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'warehouse_id',
        'tipe',
        'tanggal',
        'biaya_repack',
        'total_cost_input',
        'total_cost_output',
        'status',
        'notes',
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
            'biaya_repack' => 'decimal:2',
            'total_cost_input' => 'decimal:2',
            'total_cost_output' => 'decimal:2',
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
     * Get the input items (bahan).
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(DocRepackInput::class, 'repack_id');
    }

    /**
     * Get the output items (hasil).
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(DocRepackOutput::class, 'repack_id');
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

    /**
     * Scope for filtering by tipe.
     */
    public function scopeByTipe($query, string $tipe)
    {
        return $query->where('tipe', $tipe);
    }

    // ==================== HELPERS ====================

    /**
     * Check if repack is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if repack is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if tipe is pecah.
     */
    public function isPecah(): bool
    {
        return $this->tipe === 'pecah';
    }

    /**
     * Check if tipe is gabung.
     */
    public function isGabung(): bool
    {
        return $this->tipe === 'gabung';
    }

    /**
     * Get tipe label.
     */
    public function getTipeLabelAttribute(): string
    {
        return $this->tipe === 'pecah' ? 'Pecah' : 'Gabung';
    }

    /**
     * Get total input items count.
     */
    public function getTotalInputItemsAttribute(): int
    {
        return $this->inputs()->count();
    }

    /**
     * Get total output items count.
     */
    public function getTotalOutputItemsAttribute(): int
    {
        return $this->outputs()->count();
    }
}
