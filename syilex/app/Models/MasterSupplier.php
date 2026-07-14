<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditLog;

class MasterSupplier extends Model
{
    // SoftDeletes untuk jaga integritas relasi ke Doc Purchase historis.
    use HasFactory, HasUlid, HasCreatedUpdatedBy, SoftDeletes, HasAuditLog;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_supplier';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_supplier',
        'nama_supplier',
        'nama_pic',
        'telepon',
        'email',
        'alamat',
        'npwp',
        'bank_nama',
        'bank_rekening',
        'bank_atas_nama',
        'tempo_default',
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
            'tempo_default' => 'integer',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if supplier is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active suppliers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive suppliers.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for search by kode_supplier, nama_supplier, nama_pic.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('kode_supplier', 'like', "%{$search}%")
              ->orWhere('nama_supplier', 'like', "%{$search}%")
              ->orWhere('nama_pic', 'like', "%{$search}%");
        });
    }

    /**
     * Get purchase orders for this supplier.
     */
    public function purchaseOrders()
    {
        return $this->hasMany(DocPurchaseOrder::class, 'supplier_id');
    }

    /**
     * Get pembelian serial (intake) for this supplier.
     */
    public function serialIntakes()
    {
        return $this->hasMany(DocSerialIntake::class, 'supplier_id');
    }

    /**
     * Get outstanding hutang for this supplier.
     */
    public function hutangs()
    {
        return $this->hasMany(SupplierHutang::class, 'supplier_id');
    }

    /**
     * Get deposits for this supplier.
     */
    public function deposits()
    {
        return $this->hasMany(SupplierDeposit::class, 'supplier_id');
    }

    /**
     * Get purchase returns for this supplier.
     */
    public function purchaseReturns()
    {
        return $this->hasMany(DocPurchaseReturn::class, 'supplier_id');
    }
}
