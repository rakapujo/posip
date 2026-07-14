<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditLog;

class MasterCustomer extends Model
{
    // SoftDeletes untuk jaga integritas relasi ke Doc Sales historis.
    use HasFactory, HasUlid, HasCreatedUpdatedBy, SoftDeletes, HasAuditLog;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_customer';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_customer',
        'nama',
        'telepon',
        'email',
        'alamat',
        'nik',
        'npwp',
        'tipe_customer_id',
        'kategori_customer_id',
        'jenis',
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
        'tipe_customer_id',
        'kategori_customer_id',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if customer is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if customer is walk-in type.
     */
    public function isWalkIn(): bool
    {
        return $this->jenis === 'walk_in';
    }

    /**
     * Scope for active customers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive customers.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for walk-in customers.
     */
    public function scopeWalkIn($query)
    {
        return $query->where('jenis', 'walk_in');
    }

    /**
     * Scope for specific customers.
     */
    public function scopeSpesifik($query)
    {
        return $query->where('jenis', 'spesifik');
    }

    /**
     * Get the tipe customer.
     */
    public function tipeCustomer()
    {
        return $this->belongsTo(MasterTipeCustomer::class, 'tipe_customer_id');
    }

    /**
     * Get the kategori customer.
     */
    public function kategoriCustomer()
    {
        return $this->belongsTo(MasterKategoriCustomer::class, 'kategori_customer_id');
    }

    /**
     * Get POS terminals using this customer as default.
     */
    public function posTerminals()
    {
        return $this->hasMany(MasterPosTerminal::class, 'default_customer_id');
    }

    /**
     * Get sales transactions for this customer.
     */
    public function sales()
    {
        return $this->hasMany(\App\Models\DocSales::class, 'customer_id');
    }
}
