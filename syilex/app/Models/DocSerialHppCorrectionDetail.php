<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detail Koreksi HPP Serial: nilai BARU harga_modal & cost_per_unit per unit
 * + snapshot lama (before) untuk audit.
 */
class DocSerialHppCorrectionDetail extends Model
{
    protected $table = 'doc_serial_hpp_correction_detail';

    protected $fillable = [
        'correction_id',
        'serial_unit_id',
        'harga_modal_baru',
        'biaya_kirim_baru',
        'biaya_lain_baru',
        'pajak_baru',
        'cost_per_unit_baru',
        'before',
    ];

    protected $hidden = [
        'id',
        'correction_id',
        'serial_unit_id',
    ];

    protected function casts(): array
    {
        return [
            'harga_modal_baru' => 'decimal:2',
            'biaya_kirim_baru' => 'decimal:2',
            'biaya_lain_baru' => 'decimal:2',
            'pajak_baru' => 'decimal:2',
            'cost_per_unit_baru' => 'decimal:4',
            'before' => 'array',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(DocSerialHppCorrection::class, 'correction_id');
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class, 'serial_unit_id');
    }
}
