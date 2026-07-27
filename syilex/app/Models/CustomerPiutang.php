<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPiutang extends Model
{
    use HasAuditLog, HasDateRangeScope, HasUlid;

    protected $table = 'customer_piutang';

    public $timestamps = false;

    protected $fillable = [
        'ulid',
        'customer_id',
        'sales_id',
        'tanggal',
        'tanggal_jatuh_tempo',
        'nominal_awal',
        'nominal_terbayar',
        'nominal_retur',
        'sisa_piutang',
        'status',
        'created_at',
    ];

    protected $hidden = ['id', 'customer_id', 'sales_id'];

    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'tanggal_jatuh_tempo' => DateOnly::class,
            'nominal_awal' => 'decimal:2',
            'nominal_terbayar' => 'decimal:2',
            'nominal_retur' => 'decimal:2',
            'sisa_piutang' => 'decimal:2',
            'created_at' => LocalDateTime::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MasterCustomer::class, 'customer_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(DocSales::class, 'sales_id');
    }

    public function paymentDetails(): HasMany
    {
        return $this->hasMany(DocPembayaranPiutangDetail::class, 'piutang_id');
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeByCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<', now()->toDateString())
            ->outstanding();
    }

    public function scopeNotOverdue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tanggal_jatuh_tempo')
                ->orWhere('tanggal_jatuh_tempo', '>=', now()->toDateString());
        })->outstanding();
    }

    public function scopeDueWithinDays($query, int $days)
    {
        return $query->whereBetween('tanggal_jatuh_tempo', [
            now()->toDateString(),
            now()->addDays($days)->toDateString(),
        ])->outstanding();
    }

    public function scopeOverdueWithinDays($query, int $days)
    {
        return $query->whereBetween('tanggal_jatuh_tempo', [
            now()->subDays($days)->toDateString(),
            now()->subDay()->toDateString(),
        ])->outstanding();
    }

    /**
     * Exclusive aging bucket — ranges match CustomerPiutangController::agingSummary().
     */
    public function scopeAgingBucket($query, string $bucket)
    {
        $today = now()->toDateString();

        $query->where('sisa_piutang', '>', 0)
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

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereHas('sales', fn ($sales) => $sales->where('nomor_dokumen', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('nama', 'like', "%{$search}%")
                    ->orWhere('kode_customer', 'like', "%{$search}%"));
        });
    }

    public function recordPayment(float $amount): void
    {
        $this->nominal_terbayar += $amount;
        $this->sisa_piutang = max(0, $this->nominal_awal - $this->nominal_terbayar - $this->nominal_retur);
        $this->status = $this->sisa_piutang <= 0 ? 'paid' : 'partial';
        $this->save();
    }

    public function recordReturnCredit(float $amount): float
    {
        $credited = min(max(0, $amount), (float) $this->sisa_piutang);
        if ($credited <= 0) {
            return 0;
        }

        $this->nominal_retur += $credited;
        $this->sisa_piutang -= $credited;
        $this->status = $this->sisa_piutang <= 0 ? 'paid' : 'partial';
        $this->save();

        return $credited;
    }
}
