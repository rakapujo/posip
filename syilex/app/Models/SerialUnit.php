<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasCreatedUpdatedBy;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Register satu unit fisik per nomor seri (Fase 2 modul serial A+).
 *
 * HPP (harga_modal) & harga_jual disimpan PER-UNIT — basis laporan laba akurat (Fase 5).
 * Produk tetap normal: stok agregat di inventory_stock, HPP produk tetap weighted-avg.
 * Tidak pakai HasAuditLog (hindari log spam saat intake massal); jejak via header intake.
 */
class SerialUnit extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy, SoftDeletes;

    protected $table = 'serial_units';

    /** Status unit (kolom string, bukan enum — CLAUDE.md §2F). */
    public const STATUS_PENDING = 'pending';   // intake draft, belum commit stok
    public const STATUS_TERSEDIA = 'tersedia';  // siap (di gudang)
    public const STATUS_TERJUAL = 'terjual';   // terjual (Fase penjualan)
    public const STATUS_RUSAK = 'rusak';     // write-off via adjustment-keluar
    public const STATUS_HILANG = 'hilang';    // selisih kurang stock opname
    public const STATUS_RETUR = 'retur';     // dikembalikan ke supplier

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TERSEDIA,
        self::STATUS_TERJUAL,
        self::STATUS_RUSAK,
        self::STATUS_HILANG,
        self::STATUS_RETUR,
    ];

    /** Prefix kode internal auto-generate (KI-0000123). Identitas UNIK unit (beda dari SN). */
    public const KODE_INTERNAL_PREFIX = 'KI-';

    /**
     * Auto-isi kode_internal = KI-{id} bila kosong (PK baru ada setelah insert).
     * Override manual (diisi sebelum create) dibiarkan; keunikan dijamin UNIQUE index DB.
     */
    protected static function booted(): void
    {
        static::created(function (self $unit): void {
            if (blank($unit->kode_internal)) {
                $code = self::KODE_INTERNAL_PREFIX . str_pad((string) $unit->getKey(), 7, '0', STR_PAD_LEFT);
                $unit->newQuery()->whereKey($unit->getKey())->update(['kode_internal' => $code]);
                $unit->setAttribute('kode_internal', $code);
                $unit->syncOriginalAttribute('kode_internal');
            }
        });
    }

    protected $fillable = [
        'ulid',
        'product_id',
        'warehouse_id',
        'intake_id',
        'serial_number',
        'kode_internal',
        'harga_modal',
        'cost_per_unit',
        'harga_jual',
        'grade',
        'battery_condition',
        'battery_health',
        'account_status',
        'status',
        'sale_id',
        'sale_detail_id',
        'sold_at',
        'catatan',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'id',
        'product_id',
        'warehouse_id',
        'intake_id',
        'sale_id',
        'sale_detail_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'harga_modal' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
            'harga_jual' => 'decimal:2',
            'battery_health' => 'decimal:2',
            'sold_at' => LocalDateTime::class,
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

    public function intake(): BelongsTo
    {
        return $this->belongsTo(DocSerialIntake::class, 'intake_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(DocSales::class, 'sale_id');
    }

    public function movements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SerialUnitMovement::class, 'serial_unit_id');
    }

    // ==================== SCOPES ====================

    public function scopeTersedia($query)
    {
        return $query->where('status', 'tersedia');
    }

    public function scopeTerjual($query)
    {
        return $query->where('status', 'terjual');
    }

    public function scopeByProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeHilang($query)
    {
        return $query->where('status', self::STATUS_HILANG);
    }

    public function scopeRetur($query)
    {
        return $query->where('status', self::STATUS_RETUR);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('serial_number', 'like', "%{$search}%")
                ->orWhere('kode_internal', 'like', "%{$search}%");
        });
    }

    // ==================== HELPERS ====================

    public function isTersedia(): bool
    {
        return $this->status === 'tersedia';
    }
}
