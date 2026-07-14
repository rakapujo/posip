<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCashTransaction extends Model
{
    use HasFactory, HasUlid;

    /**
     * The table associated with the model.
     */
    protected $table = 'pos_cash_transactions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ulid',
        'terminal_id',
        'shift_id',
        'tipe',
        'nominal',
        'keterangan',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'terminal_id',
        'shift_id',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(MasterPosTerminal::class, 'terminal_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosTerminalShift::class, 'shift_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    public function scopeByShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeSetorAwal($query)
    {
        return $query->where('tipe', 'setor_awal');
    }

    public function scopeKasMasuk($query)
    {
        return $query->where('tipe', 'kas_masuk');
    }

    public function scopeKasKeluar($query)
    {
        return $query->where('tipe', 'kas_keluar');
    }
}
