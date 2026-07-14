<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterWarehouse extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_warehouse';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_warehouse',
        'nama_warehouse',
        'alamat',
        'pic_name',
        'pic_phone',
        'is_saleable',
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
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_saleable' => 'boolean',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if warehouse is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if warehouse is saleable (can be used for POS).
     */
    public function isSaleable(): bool
    {
        return $this->is_saleable === true;
    }

    /**
     * Scope for active warehouses.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive warehouses.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope for saleable warehouses (can be used for POS).
     */
    public function scopeSaleable($query)
    {
        return $query->where('is_saleable', true);
    }

    /**
     * Scope for non-saleable warehouses (internal/BS only).
     */
    public function scopeNonSaleable($query)
    {
        return $query->where('is_saleable', false);
    }

    /**
     * Get inventory stocks for this warehouse.
     */
    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class, 'warehouse_id');
    }

    /**
     * Get stock cards for this warehouse.
     */
    public function stockCards()
    {
        return $this->hasMany(StockCard::class, 'warehouse_id');
    }

    /**
     * Get purchase orders for this warehouse.
     */
    public function purchaseOrders()
    {
        return $this->hasMany(DocPurchaseOrder::class, 'warehouse_id');
    }

    /**
     * Get pembelian serial (intake) for this warehouse.
     */
    public function serialIntakes()
    {
        return $this->hasMany(DocSerialIntake::class, 'warehouse_id');
    }

    /**
     * Get POS terminals assigned to this warehouse.
     */
    public function posTerminals()
    {
        return $this->hasMany(MasterPosTerminal::class, 'warehouse_id');
    }
}
