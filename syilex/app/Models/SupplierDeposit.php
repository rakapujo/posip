<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDeposit extends Model
{
    use HasUlid, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'supplier_deposit';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'supplier_id',
        'retur_id',
        'no_referensi',
        'keterangan',
        'tanggal',
        'nominal_awal',
        'nominal_terpakai',
        'sisa_deposit',
        'status',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'supplier_id',
        'retur_id',
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
            'nominal_awal' => 'decimal:2',
            'nominal_terpakai' => 'decimal:2',
            'sisa_deposit' => 'decimal:2',
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
     * Get the related purchase return.
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseReturn::class, 'retur_id');
    }

    /**
     * Get the user who created this deposit.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this deposit.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for available deposits.
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    /**
     * Scope for deposits with remaining balance.
     */
    public function scopeHasBalance($query)
    {
        return $query->where('sisa_deposit', '>', 0);
    }

    /**
     * Scope for filtering by supplier.
     */
    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for searching.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereHas('supplier', function ($sq) use ($search) {
                $sq->where('nama_supplier', 'like', "%{$search}%")
                   ->orWhere('kode_supplier', 'like', "%{$search}%");
            })
            ->orWhereHas('purchaseReturn', function ($sq) use ($search) {
                $sq->where('nomor_dokumen', 'like', "%{$search}%");
            })
            ->orWhere('no_referensi', 'like', "%{$search}%")
            ->orWhere('keterangan', 'like', "%{$search}%");
        });
    }

    /**
     * Scope for manual deposits (not from retur).
     */
    public function scopeManual($query)
    {
        return $query->whereNull('retur_id');
    }

    /**
     * Scope for deposits from retur.
     */
    public function scopeFromRetur($query)
    {
        return $query->whereNotNull('retur_id');
    }

    // ==================== HELPERS ====================

    /**
     * Check if deposit is available.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'available' && $this->sisa_deposit > 0;
    }

    /**
     * Check if deposit is fully used.
     */
    public function isFullyUsed(): bool
    {
        return $this->status === 'used_all' || $this->sisa_deposit <= 0;
    }

    /**
     * Check if deposit is manual (not from retur).
     */
    public function isManual(): bool
    {
        return is_null($this->retur_id);
    }

    /**
     * Check if deposit can be edited (only manual deposits).
     */
    public function canBeEdited(): bool
    {
        return $this->isManual();
    }

    /**
     * Check if deposit can be deleted (only manual deposits with no usage).
     */
    public function canBeDeleted(): bool
    {
        return $this->isManual() && $this->nominal_terpakai == 0;
    }

    /**
     * Use deposit amount.
     *
     * @param float $amount Amount to use
     * @return float Actual amount used
     */
    public function use(float $amount): float
    {
        $actualUsed = min($amount, $this->sisa_deposit);

        $this->nominal_terpakai += $actualUsed;
        $this->sisa_deposit -= $actualUsed;

        // Update status
        if ($this->sisa_deposit <= 0) {
            $this->status = 'used_all';
        } elseif ($this->nominal_terpakai > 0) {
            $this->status = 'used_partial';
        }

        $this->save();

        return $actualUsed;
    }

    /**
     * Get total available deposits for a supplier.
     */
    public static function getTotalAvailableBySupplier(int $supplierId): float
    {
        return static::where('supplier_id', $supplierId)
            ->where('sisa_deposit', '>', 0)
            ->sum('sisa_deposit');
    }
}
