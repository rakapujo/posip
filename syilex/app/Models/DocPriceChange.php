<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasAuditLog;

class DocPriceChange extends Model
{
    use HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /** Kolom DATETIME untuk filter date-range (lihat HasDateRangeScope). */
    protected $dateRangeColumn = 'tanggal_pengajuan';

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_price_change';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal_pengajuan',
        'tanggal_berlaku',
        'status',
        'notes',
        'approved_at',
        'approved_by',
        'applied_at',
        'applied_by',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'approved_by',
        'applied_by',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal_pengajuan' => LocalDateTime::class,
            'tanggal_berlaku' => LocalDateTime::class,
            'approved_at' => LocalDateTime::class,
            'applied_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Alasan labels.
     */
    public const ALASAN_LABELS = [
        'PENYESUAIAN_PASAR' => 'Penyesuaian Harga Pasar',
        'KENAIKAN_BIAYA' => 'Kenaikan Biaya Operasional',
        'PROMO' => 'Program Promo',
        'KOREKSI_DATA' => 'Koreksi Data',
        'LAINNYA' => 'Lainnya',
    ];

    // ==================== RELATIONS ====================

    /**
     * Get the details for this price change.
     */
    public function details(): HasMany
    {
        return $this->hasMany(DocPriceChangeDetail::class, 'price_change_id');
    }

    /**
     * Get the user who approved this price change.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who applied this price change.
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * Get the user who created this price change.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this price change.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for search by nomor_dokumen or notes.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhere('notes', 'like', "%{$search}%");
        });
    }

    /**
     * Scope by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope by tanggal berlaku range.
     */
    public function scopeByTanggalBerlakuRange($query, ?string $startDate, ?string $endDate)
    {
        if ($startDate) {
            $query->whereDate('tanggal_berlaku', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('tanggal_berlaku', '<=', $endDate);
        }
        return $query;
    }

    /**
     * Scope for draft status.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope for scheduled status.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope for applied status.
     */
    public function scopeApplied($query)
    {
        return $query->where('status', 'applied');
    }

    /**
     * Scope for pending (scheduled and past due).
     */
    public function scopePending($query)
    {
        return $query->where('status', 'scheduled')
                     ->where('tanggal_berlaku', '<=', now());
    }

    /**
     * Scope for not applied (draft or scheduled).
     */
    public function scopeNotApplied($query)
    {
        return $query->whereIn('status', ['draft', 'scheduled']);
    }

    // ==================== HELPERS ====================

    /**
     * Check if price change is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if price change is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if price change is applied.
     */
    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    /**
     * Check if price change is pending (scheduled and past due).
     */
    public function isPending(): bool
    {
        return $this->status === 'scheduled' && $this->tanggal_berlaku <= now();
    }

    /**
     * Get alasan label.
     */
    public static function getAlasanLabel(string $alasan): string
    {
        return self::ALASAN_LABELS[$alasan] ?? $alasan;
    }
}
