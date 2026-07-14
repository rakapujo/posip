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

/**
 * Header dokumen Input Pembelian Serial (Fase 2 modul serial A+).
 * Satu intake = satu produk serial + banyak unit (serial_units).
 */
class DocSerialIntake extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /** Kolom DATETIME untuk filter date-range (lihat HasDateRangeScope). */
    protected $dateRangeColumn = 'tanggal';

    protected $table = 'doc_serial_intake';

    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal',
        'product_id',
        'warehouse_id',
        'supplier_id',
        'no_doc_referensi',
        'total_unit',
        'total_modal',
        'subtotal',
        'diskon_1_tipe', 'diskon_1_nilai', 'diskon_1_hasil',
        'diskon_2_tipe', 'diskon_2_nilai', 'diskon_2_hasil',
        'diskon_3_tipe', 'diskon_3_nilai', 'diskon_3_hasil',
        'total_diskon_header', 'total_setelah_diskon',
        'biaya_kirim_tipe', 'biaya_kirim_nilai', 'biaya_kirim_hasil',
        'biaya_lain_nama', 'biaya_lain_tipe', 'biaya_lain_nilai', 'biaya_lain_hasil',
        'total_biaya_tambahan',
        'dpp', 'pajak_nama', 'pajak_persen', 'pajak_nominal',
        'pembulatan', 'grand_total',
        'tempo_hari', 'tanggal_jatuh_tempo',
        'notes',
        'status',
        'cash_payment',
        'cash_metode',
        'cash_no_referensi',
        'cash_bank_nama',
        'cash_bank_rekening',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'id',
        'product_id',
        'warehouse_id',
        'supplier_id',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'cash_payment' => 'boolean',
            'total_unit' => 'integer',
            'total_modal' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'diskon_1_nilai' => 'decimal:2', 'diskon_1_hasil' => 'decimal:2',
            'diskon_2_nilai' => 'decimal:2', 'diskon_2_hasil' => 'decimal:2',
            'diskon_3_nilai' => 'decimal:2', 'diskon_3_hasil' => 'decimal:2',
            'total_diskon_header' => 'decimal:2',
            'total_setelah_diskon' => 'decimal:2',
            'biaya_kirim_nilai' => 'decimal:2', 'biaya_kirim_hasil' => 'decimal:2',
            'biaya_lain_nilai' => 'decimal:2', 'biaya_lain_hasil' => 'decimal:2',
            'total_biaya_tambahan' => 'decimal:2',
            'dpp' => 'decimal:2',
            'pajak_persen' => 'decimal:2',
            'pajak_nominal' => 'decimal:2',
            'pembulatan' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'tempo_hari' => 'integer',
            'tanggal_jatuh_tempo' => DateOnly::class,
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MasterSupplier::class, 'supplier_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(SerialUnit::class, 'intake_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hutang(): HasOne
    {
        return $this->hasOne(SupplierHutang::class, 'serial_intake_id');
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
              ->orWhere('no_doc_referensi', 'like', "%{$search}%")
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
        return $this->isDraft() && $this->units()->count() > 0;
    }
}
