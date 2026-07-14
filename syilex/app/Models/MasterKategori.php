<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterKategori extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_kategori';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'tipe_id',
        'kode_kategori',
        'nama_kategori',
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
        'tipe_id',
        'created_by',
        'updated_by',
    ];

    /**
     * The accessors to append to the model's array form.
     * Note: tipe_nama tidak di-append untuk menghindari N+1 query.
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
     * Check if kategori is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active kategoris.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive kategoris.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Get tipe yang memiliki kategori ini (parent).
     */
    public function tipe()
    {
        return $this->belongsTo(MasterTipe::class, 'tipe_id');
    }

    /**
     * Get grup yang memiliki kategori ini (children).
     */
    public function grups()
    {
        return $this->hasMany(MasterGrup::class, 'kategori_id');
    }

    /**
     * Get tipe name accessor.
     */
    public function getTipeNamaAttribute(): ?string
    {
        return $this->tipe?->nama_tipe;
    }
}
