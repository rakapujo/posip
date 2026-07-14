<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceChangeTriggerLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'price_change_trigger_log';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'triggered_at',
        'documents_processed',
        'trigger_type',
        'triggered_by',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'id',
        'triggered_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'triggered_at' => LocalDateTime::class,
            'documents_processed' => 'integer',
        ];
    }

    // ==================== RELATIONS ====================

    /**
     * Get the user who triggered this.
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    // ==================== HELPERS ====================

    /**
     * Check if this was an auto trigger.
     */
    public function isAuto(): bool
    {
        return $this->trigger_type === 'auto';
    }

    /**
     * Check if this was a manual trigger.
     */
    public function isManual(): bool
    {
        return $this->trigger_type === 'manual';
    }
}
