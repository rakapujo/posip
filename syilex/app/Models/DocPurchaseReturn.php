<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\HasAuditLog;

class DocPurchaseReturn extends Model
{
    use HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_purchase_return';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'supplier_id',
        'warehouse_id',
        'po_id',
        'subtotal',
        'diskon_1_tipe',
        'diskon_1_nilai',
        'diskon_1_hasil',
        'diskon_2_tipe',
        'diskon_2_nilai',
        'diskon_2_hasil',
        'diskon_3_tipe',
        'diskon_3_nilai',
        'diskon_3_hasil',
        'total_diskon_header',
        'dpp',
        'pajak_nama',
        'pajak_persen',
        'pajak_nominal',
        'pembulatan',
        'nilai_kalkulasi',
        'nilai_diakui',
        'selisih',
        'catatan_approval',
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
        'supplier_id',
        'warehouse_id',
        'po_id',
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
            'diskon_1_nilai' => 'decimal:2',
            'diskon_1_hasil' => 'decimal:2',
            'diskon_2_nilai' => 'decimal:2',
            'diskon_2_hasil' => 'decimal:2',
            'diskon_3_nilai' => 'decimal:2',
            'diskon_3_hasil' => 'decimal:2',
            'total_diskon_header' => 'decimal:2',
            'dpp' => 'decimal:2',
            'pajak_persen' => 'decimal:2',
            'pajak_nominal' => 'decimal:2',
            'nilai_kalkulasi' => 'decimal:2',
            'nilai_diakui' => 'decimal:2',
            'selisih' => 'decimal:2',
            'locked_at' => LocalDateTime::class,
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MasterSupplier::class, 'supplier_id');
    }

    /**
     * Get the warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    /**
     * Get the related purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseOrder::class, 'po_id');
    }

    /**
     * Get the user who locked this document.
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
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
        return $this->hasMany(DocPurchaseReturnDetail::class, 'retur_id');
    }

    /**
     * Get the deposit created on approval.
     */
    public function deposit(): HasOne
    {
        return $this->hasOne(SupplierDeposit::class, 'retur_id');
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
     * Scope for lock status.
     */
    public function scopeLock($query)
    {
        return $query->where('status', 'lock');
    }

    /**
     * Scope for approved status.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope for searching.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhere('notes', 'like', "%{$search}%")
              ->orWhereHas('supplier', function ($sq) use ($search) {
                  $sq->where('nama_supplier', 'like', "%{$search}%")
                     ->orWhere('kode_supplier', 'like', "%{$search}%");
              });
        });
    }

    /**
     * Scope for filtering by supplier.
     */
    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
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
     * Check if retur is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if retur is locked.
     */
    public function isLock(): bool
    {
        return $this->status === 'lock';
    }

    /**
     * Check if retur is approved.
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
     * Get total qty (sum of all detail qty_in_base).
     */
    public function getTotalQtyAttribute(): float
    {
        return (float) $this->details()->sum('qty_in_base');
    }

    /**
     * Check if retur can be edited.
     */
    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    /**
     * Check if retur can be locked.
     */
    public function canLock(): bool
    {
        return $this->isDraft() && $this->details()->count() > 0;
    }

    /**
     * Check if retur can be approved.
     */
    public function canApprove(): bool
    {
        return $this->isLock();
    }
}
