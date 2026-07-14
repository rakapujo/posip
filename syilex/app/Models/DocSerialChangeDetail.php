<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detail Perubahan Data Serial: nilai BARU per unit + snapshot lama (before) untuk audit.
 */
class DocSerialChangeDetail extends Model
{
    protected $table = 'doc_serial_change_detail';

    protected $fillable = [
        'change_id',
        'serial_unit_id',
        'serial_number',
        'harga_jual',
        'grade',
        'battery_condition',
        'battery_health',
        'account_status',
        'catatan',
        'before',
    ];

    protected $hidden = [
        'id',
        'change_id',
        'serial_unit_id',
    ];

    protected function casts(): array
    {
        return [
            'harga_jual' => 'decimal:2',
            'battery_health' => 'decimal:2',
            'before' => 'array',
        ];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(DocSerialChange::class, 'change_id');
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class, 'serial_unit_id');
    }
}
