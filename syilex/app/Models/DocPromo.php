<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Traits\HasAuditLog;

class DocPromo extends Model
{
    use HasUlid, HasCreatedUpdatedBy, HasAuditLog;

    protected $table = 'doc_promo';

    protected $fillable = [
        'ulid',
        'kode_promo',
        'nama_promo',
        'deskripsi',
        'customer_type_id',
        'customer_category_id',
        'terminal_id',
        'tanggal_mulai',
        'tanggal_selesai',
        'jam_mulai',
        'jam_selesai',
        'status',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'id',
        'customer_type_id',
        'customer_category_id',
        'terminal_id',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function details(): HasMany
    {
        return $this->hasMany(DocPromoDetail::class, 'promo_id');
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(MasterTipeCustomer::class, 'customer_type_id');
    }

    public function customerCategory(): BelongsTo
    {
        return $this->belongsTo(MasterKategoriCustomer::class, 'customer_category_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(MasterPosTerminal::class, 'terminal_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for draft status.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope for approved status.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope for inactive status.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for promo yang berlaku SEKARANG (computed dari tanggal + jam).
     * Dipakai oleh POS + CheckoutAction untuk fetch active promos.
     */
    public function scopeEffective(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? now();
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        return $query->where('status', 'approved')
            ->where('tanggal_mulai', '<=', $today)
            ->where(fn ($q) => $q->whereNull('tanggal_selesai')
                                 ->orWhere('tanggal_selesai', '>=', $today))
            ->where(fn ($q) => $q->whereNull('jam_mulai')
                                 ->orWhere(fn ($q2) => $q2->where('jam_mulai', '<=', $time)
                                                           ->where('jam_selesai', '>=', $time)));
    }

    /**
     * Scope untuk filter by computed display status.
     * Dipakai oleh list page di admin.
     */
    public function scopeByDisplayStatus(Builder $query, string $status): Builder
    {
        $now = now();
        $today = $now->toDateString();

        return match ($status) {
            'draft' => $query->where('status', 'draft'),
            'inactive' => $query->where('status', 'inactive'),
            'active' => $query->effective(),
            'upcoming' => $query->where('status', 'approved')->where('tanggal_mulai', '>', $today),
            'expired' => $query->where('status', 'approved')
                ->whereNotNull('tanggal_selesai')
                ->where('tanggal_selesai', '<', $today),
            default => $query,
        };
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

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /**
     * Computed status untuk display (active / upcoming / expired).
     * Hanya relevan jika status DB = 'approved'.
     */
    public function getDisplayStatus(?Carbon $now = null): string
    {
        if ($this->status !== 'approved') {
            return $this->status; // draft / inactive
        }

        $now = $now ?? now();
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        if ($this->tanggal_mulai && $this->tanggal_mulai->toDateString() > $today) {
            return 'upcoming';
        }
        if ($this->tanggal_selesai && $this->tanggal_selesai->toDateString() < $today) {
            return 'expired';
        }
        // Date OK, cek jam
        if ($this->jam_mulai && $this->jam_selesai) {
            if ($time < $this->jam_mulai || $time > $this->jam_selesai) {
                return 'upcoming'; // belum masuk jam aktif (atau sudah lewat jam hari ini)
            }
        }
        return 'active';
    }
}
