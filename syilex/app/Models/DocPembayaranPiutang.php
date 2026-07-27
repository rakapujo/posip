<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasCreatedUpdatedBy;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocPembayaranPiutang extends Model
{
    use HasAuditLog, HasCreatedUpdatedBy, HasDateRangeScope, HasUlid;

    protected $table = 'doc_pembayaran_piutang';

    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'customer_id',
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

    protected $hidden = ['id', 'customer_id', 'completed_by', 'created_by', 'updated_by'];

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MasterCustomer::class, 'customer_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DocPembayaranPiutangDetail::class, 'pembayaran_id');
    }

    public function depositUsages(): HasMany
    {
        return $this->hasMany(DocPembayaranPiutangDeposit::class, 'pembayaran_id');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
                ->orWhere('no_referensi', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('nama', 'like', "%{$search}%")
                    ->orWhere('kode_customer', 'like', "%{$search}%"));
        });
    }

    public function scopeByCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function canEdit(): bool
    {
        return $this->isDraft();
    }
}
