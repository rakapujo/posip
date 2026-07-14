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

class DocPembayaranHutang extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_pembayaran_hutang';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'supplier_id',
        'total_bayar_cash',
        'total_bayar_deposit',
        'total_pembayaran',
        'metode_pembayaran',
        'no_referensi',
        'bank_nama',
        'bank_rekening',
        'notes',
        'status',
        'completed_at',
        'completed_by',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'supplier_id',
        'completed_by',
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
            'total_bayar_cash' => 'decimal:2',
            'total_bayar_deposit' => 'decimal:2',
            'total_pembayaran' => 'decimal:2',
            'completed_at' => LocalDateTime::class,
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
     * Get the user who completed this document.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the payment details (per hutang).
     */
    public function details(): HasMany
    {
        return $this->hasMany(DocPembayaranHutangDetail::class, 'pembayaran_id');
    }

    /**
     * Get the deposit usage details.
     */
    public function depositUsages(): HasMany
    {
        return $this->hasMany(DocPembayaranHutangDeposit::class, 'pembayaran_id');
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
     * Scope for completed status.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for searching by nomor_dokumen, no_referensi, or supplier.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhere('no_referensi', 'like', "%{$search}%")
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

    // ==================== HELPERS ====================

    /**
     * Check if pembayaran is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if pembayaran is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if pembayaran can be edited.
     */
    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    /**
     * Check if pembayaran can be completed.
     */
    public function canComplete(): bool
    {
        return $this->isDraft() && $this->details()->count() > 0;
    }

    /**
     * Get total items count.
     */
    public function getTotalItemsAttribute(): int
    {
        return $this->details()->count();
    }

    /**
     * Get total hutangs paid.
     */
    public function getTotalHutangsPaidAttribute(): int
    {
        return $this->details()->distinct('hutang_id')->count('hutang_id');
    }
}
