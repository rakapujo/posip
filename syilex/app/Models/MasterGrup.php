<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterGrup extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_grup';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kategori_id',
        'kode_grup',
        'nama_grup',
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
        'kategori_id',
        'created_by',
        'updated_by',
    ];

    /**
     * The accessors to append to the model's array form.
     * Note: kategori_nama, tipe_nama tidak di-append untuk menghindari N+1 query.
     *
     * @var list<string>
     */
    protected $appends = [];

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
     * Check if grup is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active grups.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive grups.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Get kategori yang memiliki grup ini (parent).
     */
    public function kategori()
    {
        return $this->belongsTo(MasterKategori::class, 'kategori_id');
    }

    /**
     * Get kategori name accessor.
     */
    public function getKategoriNamaAttribute(): ?string
    {
        return $this->kategori?->nama_kategori;
    }

    /**
     * Get tipe name accessor (through kategori).
     */
    public function getTipeNamaAttribute(): ?string
    {
        return $this->kategori?->tipe?->nama_tipe;
    }

    /**
     * Get produk yang menggunakan grup ini.
     */
    public function products()
    {
        return $this->hasMany(MasterProduk::class, 'grup_id');
    }
}
