<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\HasAuditLog;

class DocPurchaseOrder extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, HasAuditLog, HasDateRangeScope;

    /** Kolom DATETIME untuk filter date-range (lihat HasDateRangeScope). */
    protected $dateRangeColumn = 'tanggal_po';

    /**
     * The table associated with the model.
     */
    protected $table = 'doc_purchase_order';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'nomor_dokumen',
        'tanggal_po',
        'supplier_id',
        'warehouse_id',
        'no_doc_referensi',
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
        'total_setelah_diskon',
        'biaya_kirim_tipe',
        'biaya_kirim_nilai',
        'biaya_kirim_hasil',
        'biaya_lain_nama',
        'biaya_lain_tipe',
        'biaya_lain_nilai',
        'biaya_lain_hasil',
        'total_biaya_tambahan',
        'dpp',
        'pajak_nama',
        'pajak_persen',
        'pajak_nominal',
        'pembulatan',
        'grand_total',
        'tempo_hari',
        'tanggal_jatuh_tempo',
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

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'supplier_id',
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
            'tanggal_po' => LocalDateTime::class,
            'tanggal_jatuh_tempo' => DateOnly::class, // Keep as DATE (only need date)
            'cash_payment' => 'boolean',
            'subtotal' => 'decimal:2',
            'diskon_1_nilai' => 'decimal:2',
            'diskon_1_hasil' => 'decimal:2',
            'diskon_2_nilai' => 'decimal:2',
            'diskon_2_hasil' => 'decimal:2',
            'diskon_3_nilai' => 'decimal:2',
            'diskon_3_hasil' => 'decimal:2',
            'total_diskon_header' => 'decimal:2',
            'total_setelah_diskon' => 'decimal:2',
            'biaya_kirim_nilai' => 'decimal:2',
            'biaya_kirim_hasil' => 'decimal:2',
            'biaya_lain_nilai' => 'decimal:2',
            'biaya_lain_hasil' => 'decimal:2',
            'total_biaya_tambahan' => 'decimal:2',
            'dpp' => 'decimal:2',
            'pajak_persen' => 'decimal:2',
            'pajak_nominal' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'tempo_hari' => 'integer',
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
        return $this->hasMany(DocPurchaseOrderDetail::class, 'po_id');
    }

    /**
     * Get the hutang record created on approval.
     */
    public function hutang(): HasOne
    {
        return $this->hasOne(SupplierHutang::class, 'po_id');
    }

    /**
     * Get price history records created on approval.
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(HistoryHargaBeli::class, 'po_id');
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
              ->orWhere('no_doc_referensi', 'like', "%{$search}%")
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
     * Check if PO is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if PO is approved.
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
     * Check if PO can be edited.
     */
    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    /**
     * Check if PO can be approved.
     */
    public function canApprove(): bool
    {
        return $this->isDraft() && $this->details()->count() > 0;
    }
}
