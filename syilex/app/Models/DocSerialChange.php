<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasCreatedUpdatedBy;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header Perubahan Data Serial (modul serial A+).
 * Koreksi data unit tersedia produk serial (harga jual + SN + atribut); alur draft → approved.
 */
class DocSerialChange extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    protected $dateRangeColumn = 'tanggal';

    protected $table = 'doc_serial_change';

    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'product_id',
        'total_unit',
        'status',
        'approved_at',
        'approved_by',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'id',
        'product_id',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'total_unit' => 'integer',
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'product_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DocSerialChangeDetail::class, 'change_id');
    }

    // ==================== SCOPES ====================

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
              ->orWhereHas('product', function ($pq) use ($search) {
                  $pq->where('nama_produk', 'like', "%{$search}%")
                     ->orWhere('kode_produk', 'like', "%{$search}%");
              });
        });
    }

    // ==================== HELPERS ====================

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    public function canApprove(): bool
    {
        return $this->isDraft() && $this->details()->count() > 0;
    }
}
