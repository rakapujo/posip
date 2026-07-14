<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPromoDetail extends Model
{
    protected $table = 'doc_promo_details';

    protected $fillable = [
        'promo_id',
        'target_type',
        'target_id',
        'min_qty',
        'diskon_1_tipe',
        'diskon_1_nilai',
        'diskon_2_tipe',
        'diskon_2_nilai',
        'diskon_3_tipe',
        'diskon_3_nilai',
        'diskon_4_tipe',
        'diskon_4_nilai',
        'keterangan',
    ];

    protected $hidden = [
        'id',
        'promo_id',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',   // ensures === comparison works on both MySQL and SQLite
            'min_qty' => 'integer',
            'diskon_1_nilai' => 'decimal:2',
            'diskon_2_nilai' => 'decimal:2',
            'diskon_3_nilai' => 'decimal:2',
            'diskon_4_nilai' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function promo(): BelongsTo
    {
        return $this->belongsTo(DocPromo::class, 'promo_id');
    }

    // Polymorphic-like target resolver (target_id points to different tables)
    // Dipakai di PromoService untuk matching produk
    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduk::class, 'target_id');
    }

    public function targetGrup(): BelongsTo
    {
        return $this->belongsTo(MasterGrup::class, 'target_id');
    }

    public function targetKategori(): BelongsTo
    {
        return $this->belongsTo(MasterKategori::class, 'target_id');
    }

    // ==================== HELPERS ====================

    /**
     * Cek apakah detail row ini match dengan produk tertentu.
     *
     * @param int $productId
     * @param int|null $grupId
     * @param int|null $kategoriId
     * @return bool
     */
    public function matchesProduct(int $productId, ?int $grupId, ?int $kategoriId): bool
    {
        return match ($this->target_type) {
            'semua' => true,
            'produk' => $this->target_id === $productId,
            'grup' => $this->target_id === $grupId,
            'kategori' => $this->target_id === $kategoriId,
            default => false,
        };
    }

    /**
     * Cek apakah qty memenuhi min_qty.
     */
    public function matchesQty(float $qty): bool
    {
        return $qty >= $this->min_qty;
    }

    /**
     * Cek apakah detail ini qualify (target + qty match).
     */
    public function qualifies(int $productId, ?int $grupId, ?int $kategoriId, float $qty): bool
    {
        return $this->matchesProduct($productId, $grupId, $kategoriId)
            && $this->matchesQty($qty);
    }
}
