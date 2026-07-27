<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\LocalDateTime;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierHutang extends Model
{
    use HasUlid, HasDateRangeScope;

    /**
     * The table associated with the model.
     */
    protected $table = 'supplier_hutang';

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
        'po_id',
        'serial_intake_id',
        'tanggal',
        'tanggal_jatuh_tempo',
        'nominal_awal',
        'nominal_terbayar',
        'nominal_retur',
        'sisa_hutang',
        'status',
        'created_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'supplier_id',
        'po_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'tanggal_jatuh_tempo' => DateOnly::class, // Keep as DATE (only need date)
            'nominal_awal' => 'decimal:2',
            'nominal_terbayar' => 'decimal:2',
            'nominal_retur' => 'decimal:2',
            'sisa_hutang' => 'decimal:2',
            'created_at' => LocalDateTime::class,
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
     * Get the purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DocPurchaseOrder::class, 'po_id');
    }

    /**
     * Get the serial intake (sumber hutang alternatif selain PO).
     */
    public function serialIntake(): BelongsTo
    {
        return $this->belongsTo(DocSerialIntake::class, 'serial_intake_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope for unpaid status.
     */
    public function scopeUnpaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    /**
     * Scope for partial status.
     */
    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    /**
     * Scope for paid status.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope for outstanding (unpaid + partial).
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    /**
     * Scope for filtering by supplier.
     */
    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for overdue hutang.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotNull('tanggal_jatuh_tempo')
                     ->where('tanggal_jatuh_tempo', '<', now()->toDateString())
                     ->whereIn('status', ['unpaid', 'partial']);
    }

    /**
     * Scope for NOT overdue hutang (due date >= today or no due date).
     */
    public function scopeNotOverdue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tanggal_jatuh_tempo')
              ->orWhere('tanggal_jatuh_tempo', '>=', now()->toDateString());
        })->whereIn('status', ['unpaid', 'partial']);
    }

    /**
     * Scope for hutang due within X days (not yet overdue).
     * Shows hutang with due date between today and today + X days.
     */
    public function scopeDueWithinDays($query, int $days)
    {
        $today = now()->toDateString();
        $targetDate = now()->addDays($days)->toDateString();

        return $query->whereNotNull('tanggal_jatuh_tempo')
                     ->where('tanggal_jatuh_tempo', '>=', $today)
                     ->where('tanggal_jatuh_tempo', '<=', $targetDate)
                     ->whereIn('status', ['unpaid', 'partial']);
    }

    /**
     * Scope for hutang overdue within X days.
     * Shows hutang with due date between today - X days and today (already overdue).
     */
    public function scopeOverdueWithinDays($query, int $days)
    {
        $today = now()->toDateString();
        $startDate = now()->subDays($days)->toDateString();

        return $query->whereNotNull('tanggal_jatuh_tempo')
                     ->where('tanggal_jatuh_tempo', '<', $today)
                     ->where('tanggal_jatuh_tempo', '>=', $startDate)
                     ->whereIn('status', ['unpaid', 'partial']);
    }

    /**
     * Exclusive aging bucket — ranges match SupplierHutangController::agingSummary().
     */
    public function scopeAgingBucket($query, string $bucket)
    {
        $today = now()->toDateString();

        $query->where('sisa_hutang', '>', 0)
            ->whereIn('status', ['unpaid', 'partial']);

        return match ($bucket) {
            'belum_tempo' => $query->where(function ($q) use ($today) {
                $q->whereNull('tanggal_jatuh_tempo')
                    ->orWhere('tanggal_jatuh_tempo', '>=', $today);
            }),
            'b1_30' => $query->whereNotNull('tanggal_jatuh_tempo')
                ->where('tanggal_jatuh_tempo', '<', $today)
                ->where('tanggal_jatuh_tempo', '>=', now()->subDays(30)->toDateString()),
            'b31_60' => $query->whereNotNull('tanggal_jatuh_tempo')
                ->where('tanggal_jatuh_tempo', '<', now()->subDays(30)->toDateString())
                ->where('tanggal_jatuh_tempo', '>=', now()->subDays(60)->toDateString()),
            'b61_90' => $query->whereNotNull('tanggal_jatuh_tempo')
                ->where('tanggal_jatuh_tempo', '<', now()->subDays(60)->toDateString())
                ->where('tanggal_jatuh_tempo', '>=', now()->subDays(90)->toDateString()),
            'above_90' => $query->whereNotNull('tanggal_jatuh_tempo')
                ->where('tanggal_jatuh_tempo', '<', now()->subDays(90)->toDateString()),
            default => $query,
        };
    }

    /**
     * Scope for searching by PO number or supplier.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereHas('purchaseOrder', function ($poq) use ($search) {
                $poq->where('nomor_dokumen', 'like', "%{$search}%");
            })->orWhereHas('serialIntake', function ($siq) use ($search) {
                $siq->where('nomor_dokumen', 'like', "%{$search}%");
            })->orWhereHas('supplier', function ($sq) use ($search) {
                $sq->where('nama_supplier', 'like', "%{$search}%")
                   ->orWhere('kode_supplier', 'like', "%{$search}%");
            });
        });
    }

    // ==================== HELPERS ====================

    /**
     * Check if hutang is unpaid.
     */
    public function isUnpaid(): bool
    {
        return $this->status === 'unpaid';
    }

    /**
     * Check if hutang is partial.
     */
    public function isPartial(): bool
    {
        return $this->status === 'partial';
    }

    /**
     * Check if hutang is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Check if hutang is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->tanggal_jatuh_tempo
            && $this->tanggal_jatuh_tempo < now()->toDateString()
            && !$this->isPaid();
    }

    /**
     * Get days until due date (negative if overdue).
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->tanggal_jatuh_tempo) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->tanggal_jatuh_tempo, false);
    }

    /**
     * Record a payment and update status.
     */
    public function recordPayment(float $amount): void
    {
        $this->nominal_terbayar += $amount;
        $this->sisa_hutang = max(0, (float) $this->nominal_awal - (float) $this->nominal_terbayar - (float) $this->nominal_retur);

        if ($this->sisa_hutang <= 0) {
            $this->sisa_hutang = 0;
            $this->status = 'paid';
        } elseif ($this->nominal_terbayar > 0 || (float) $this->nominal_retur > 0) {
            $this->status = 'partial';
        }

        $this->save();
    }

    /**
     * Credit return amount against outstanding hutang. Returns amount actually credited.
     */
    public function recordReturnCredit(float $amount): float
    {
        $credited = min(max(0, $amount), (float) $this->sisa_hutang);
        if ($credited <= 0) {
            return 0;
        }

        $this->nominal_retur += $credited;
        $this->sisa_hutang = max(0, (float) $this->nominal_awal - (float) $this->nominal_terbayar - (float) $this->nominal_retur);
        $this->status = $this->sisa_hutang <= 0 ? 'paid' : 'partial';
        $this->save();

        return $credited;
    }
}
