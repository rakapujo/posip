<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasCreatedUpdatedBy;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocSales extends Model
{
    use HasAuditLog, HasCreatedUpdatedBy, HasDateRangeScope, HasFactory, HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_sales';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'source',
        'tanggal',
        'terminal_id',
        'shift_id',
        'warehouse_id',
        'customer_id',
        'subtotal',
        'diskon_nota_1_tipe',
        'diskon_nota_1_nilai',
        'diskon_nota_1_hasil',
        'diskon_nota_1_label',
        'diskon_nota_2_tipe',
        'diskon_nota_2_nilai',
        'diskon_nota_2_hasil',
        'diskon_nota_2_label',
        'diskon_nota_3_tipe',
        'diskon_nota_3_nilai',
        'diskon_nota_3_hasil',
        'diskon_nota_3_label',
        'total_diskon',
        'total_setelah_diskon',
        'biaya_kirim_tipe',
        'biaya_kirim_nilai',
        'biaya_kirim_hasil',
        'biaya_lain_tipe',
        'biaya_lain_nilai',
        'biaya_lain_hasil',
        'biaya_lain_nama',
        'dpp',
        'pajak_nama',
        'pajak_persen',
        'pajak_nominal',
        'pembulatan',
        'grand_total',
        'tempo_hari',
        'tanggal_jatuh_tempo',
        'cash_payment',
        'cash_metode',
        'cash_no_referensi',
        'cash_bank_nama',
        'cash_bank_rekening',
        'total_bayar',
        'kembalian',
        'total_biaya_pembayaran',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
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
        'terminal_id',
        'shift_id',
        'warehouse_id',
        'customer_id',
        'voided_by',
        'approved_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'tanggal_jatuh_tempo' => DateOnly::class,
            'tempo_hari' => 'integer',
            'cash_payment' => 'boolean',
            'subtotal' => 'decimal:2',
            'diskon_nota_1_nilai' => 'decimal:2',
            'diskon_nota_1_hasil' => 'decimal:2',
            'diskon_nota_2_nilai' => 'decimal:2',
            'diskon_nota_2_hasil' => 'decimal:2',
            'diskon_nota_3_nilai' => 'decimal:2',
            'diskon_nota_3_hasil' => 'decimal:2',
            'total_diskon' => 'decimal:2',
            'total_setelah_diskon' => 'decimal:2',
            'biaya_kirim_nilai' => 'decimal:2',
            'biaya_kirim_hasil' => 'decimal:2',
            'biaya_lain_nilai' => 'decimal:2',
            'biaya_lain_hasil' => 'decimal:2',
            'dpp' => 'decimal:2',
            'pajak_persen' => 'decimal:2',
            'pajak_nominal' => 'decimal:2',
            'pembulatan' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_bayar' => 'decimal:2',
            'kembalian' => 'decimal:2',
            'total_biaya_pembayaran' => 'decimal:2',
            'voided_at' => LocalDateTime::class,
            'approved_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(MasterPosTerminal::class, 'terminal_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosTerminalShift::class, 'shift_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MasterCustomer::class, 'customer_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DocSalesDetail::class, 'sales_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DocSalesPayment::class, 'sales_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(DocSalesReturn::class, 'sales_id');
    }

    public function piutang(): HasOne
    {
        return $this->hasOne(CustomerPiutang::class, 'sales_id');
    }

    // ==================== SCOPES ====================

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeVoided($query)
    {
        return $query->where('status', 'voided');
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }

    public function scopePos($query)
    {
        return $query->where('source', 'pos');
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nomor_dokumen', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%");
        });
    }

    public function scopeByShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeByTerminal($query, int $terminalId)
    {
        return $query->where('terminal_id', $terminalId);
    }

    // ==================== HELPERS ====================

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function canVoid(): bool
    {
        return $this->isCompleted()
            && ! $this->returns()->whereIn('status', ['lock', 'approved'])->exists();
    }
}
