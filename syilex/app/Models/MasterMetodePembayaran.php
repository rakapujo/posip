<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterMetodePembayaran extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_metode_pembayaran';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_pembayaran',
        'nama_pembayaran',
        'metode',
        'jenis',
        'nama_akun',
        'nomor_akun',
        'logo',
        'qr_code',
        'biaya_tambahan_tipe',
        'biaya_tambahan_nilai',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'biaya_tambahan_nilai' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['logo_url', 'qr_code_url'];

    /**
     * Get the logo URL.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        // If already full URL, return as-is
        if (filter_var($this->logo, FILTER_VALIDATE_URL)) {
            return $this->logo;
        }

        return asset('storage/' . $this->logo);
    }

    /**
     * Get the QR code URL.
     */
    public function getQrCodeUrlAttribute(): ?string
    {
        if (!$this->qr_code) {
            return null;
        }

        // If already full URL, return as-is
        if (filter_var($this->qr_code, FILTER_VALIDATE_URL)) {
            return $this->qr_code;
        }

        return asset('storage/' . $this->qr_code);
    }

    /**
     * Check if payment method is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if payment method is cash (tunai).
     */
    public function isTunai(): bool
    {
        return $this->metode === 'tunai';
    }

    /**
     * Check if payment method is non-cash (non_tunai).
     */
    public function isNonTunai(): bool
    {
        return $this->metode === 'non_tunai';
    }

    /**
     * Scope for active payment methods.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive payment methods.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for cash payment methods.
     */
    public function scopeTunai($query)
    {
        return $query->where('metode', 'tunai');
    }

    /**
     * Scope for non-cash payment methods.
     */
    public function scopeNonTunai($query)
    {
        return $query->where('metode', 'non_tunai');
    }

    /**
     * Get POS terminals using this as default payment method.
     */
    public function posTerminalsAsDefault()
    {
        return $this->hasMany(MasterPosTerminal::class, 'default_metode_pembayaran_id');
    }

    /**
     * Get POS terminals that allow this payment method.
     */
    public function posTerminals()
    {
        return $this->belongsToMany(MasterPosTerminal::class, 'pos_terminal_payment_methods', 'metode_pembayaran_id', 'terminal_id');
    }

    /**
     * Get sales payments using this payment method.
     */
    public function salesPayments()
    {
        return $this->hasMany(\App\Models\DocSalesPayment::class, 'metode_pembayaran_id');
    }
}
