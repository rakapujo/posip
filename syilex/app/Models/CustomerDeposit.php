<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasAuditLog;
use App\Traits\HasDateRangeScope;
use App\Traits\HasUlid;
use App\Models\DocPembayaranPiutangDeposit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CustomerDeposit extends Model
{
    use HasAuditLog, HasDateRangeScope, HasUlid;

    protected $table = 'customer_deposit';

    protected $fillable = [
        'ulid',
        'customer_id',
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
    ];

    protected $hidden = ['id', 'customer_id', 'retur_id', 'created_by', 'updated_by'];

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MasterCustomer::class, 'customer_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(DocSalesReturn::class, 'retur_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeHasBalance($query)
    {
        return $query->where('sisa_deposit', '>', 0);
    }

    public function scopeByCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereHas('customer', fn ($customer) => $customer
                ->where('nama', 'like', "%{$search}%")
                ->orWhere('kode_customer', 'like', "%{$search}%"))
                ->orWhereHas('salesReturn', fn ($return) => $return->where('nomor_dokumen', 'like', "%{$search}%"))
                ->orWhere('no_referensi', 'like', "%{$search}%")
                ->orWhere('keterangan', 'like', "%{$search}%");
        });
    }

    public function isManual(): bool
    {
        return $this->retur_id === null;
    }

    public function canBeEdited(): bool
    {
        return $this->isManual()
            && (float) $this->nominal_terpakai === 0.0
            && ! DocPembayaranPiutangDeposit::where('deposit_id', $this->id)->exists();
    }

    public function canBeDeleted(): bool
    {
        return $this->canBeEdited();
    }

    public function use(float $amount): float
    {
        if ($amount > (float) $this->sisa_deposit + 0.01) {
            throw ValidationException::withMessages([
                'deposit' => ['Penggunaan deposit melebihi saldo tersedia.'],
            ]);
        }

        $this->nominal_terpakai += $amount;
        $this->sisa_deposit -= $amount;
        $this->status = $this->sisa_deposit <= 0 ? 'used_all' : 'used_partial';
        $this->save();

        return $amount;
    }

    public static function getTotalAvailableByCustomer(int $customerId): float
    {
        return (float) static::where('customer_id', $customerId)->where('sisa_deposit', '>', 0)->sum('sisa_deposit');
    }
}
