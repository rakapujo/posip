<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosTerminalShift extends Model
{
    use HasFactory, HasUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pos_terminal_shifts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'terminal_id',
        'user_id',
        'started_at',
        'ended_at',
        'ended_by_force',
        'forced_by',
        'is_locked',
        'locked_at',
        'saldo_fisik',
        'saldo_system',
        'selisih',
        'closing_notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ended_by_force' => 'boolean',
            'is_locked' => 'boolean',
            'saldo_fisik' => 'decimal:2',
            'saldo_system' => 'decimal:2',
            'selisih' => 'decimal:2',
            'started_at' => LocalDateTime::class,
            'ended_at' => LocalDateTime::class,
            'locked_at' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if shift is locked.
     */
    public function isLocked(): bool
    {
        return $this->is_locked === true;
    }

    /**
     * Lock the shift.
     */
    public function lock(): void
    {
        $this->update([
            'is_locked' => true,
            'locked_at' => now(),
        ]);
    }

    /**
     * Unlock the shift.
     */
    public function unlock(): void
    {
        $this->update([
            'is_locked' => false,
            'locked_at' => null,
        ]);
    }

    /**
     * Check if shift is still active.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Scope for active shifts (not ended).
     */
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Get the terminal for this shift.
     */
    public function terminal()
    {
        return $this->belongsTo(MasterPosTerminal::class, 'terminal_id');
    }

    /**
     * Get the user who started this shift.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who forced this shift to end.
     */
    public function forcedByUser()
    {
        return $this->belongsTo(User::class, 'forced_by');
    }

    /**
     * Get sales for this shift.
     */
    public function sales()
    {
        return $this->hasMany(DocSales::class, 'shift_id');
    }

    /**
     * Get returns processed during this shift.
     */
    public function salesReturns()
    {
        return $this->hasMany(DocSalesReturn::class, 'shift_id');
    }

    /**
     * Get cash transactions for this shift.
     */
    public function cashTransactions()
    {
        return $this->hasMany(PosCashTransaction::class, 'shift_id');
    }
}
