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

class DocHppCorrection extends Model
{
    use HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /** Kolom DATETIME untuk filter date-range (lihat HasDateRangeScope). */
    protected $dateRangeColumn = 'tanggal_koreksi';

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_hpp_correction';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal_koreksi',
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
            'tanggal_koreksi' => LocalDateTime::class,
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Alasan labels.
     */
    public const ALASAN_LABELS = [
        'KOREKSI_HARGA_BELI' => 'Koreksi Harga Beli',
        'KOREKSI_DATA' => 'Koreksi Data',
        'MIGRASI_SISTEM' => 'Migrasi Sistem',
        'LAINNYA' => 'Lainnya',
    ];

    // ==================== RELATIONS ====================

    /**
     * Get the details for this correction.
     */
    public function details(): HasMany
    {
        return $this->hasMany(DocHppCorrectionDetail::class, 'correction_id');
    }

    /**
     * Get the user who approved this correction.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created this correction.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this correction.
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

    // ==================== HELPERS ====================

    /**
     * Check if correction is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if correction is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Get alasan label.
     */
    public static function getAlasanLabel(string $alasan): string
    {
        return self::ALASAN_LABELS[$alasan] ?? $alasan;
    }
}
