<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPembayaranPiutangDetail extends Model
{
    use HasUlid;

    protected $table = 'doc_pembayaran_piutang_detail';

    public $timestamps = false;

    protected $fillable = [
        'ulid',
        'pembayaran_id',
        'piutang_id',
        'nominal_dibayar',
        'sumber',
    ];

    protected $hidden = ['id', 'pembayaran_id', 'piutang_id'];

    protected function casts(): array
    {
        return ['nominal_dibayar' => 'decimal:2'];
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(DocPembayaranPiutang::class, 'pembayaran_id');
    }

    public function piutang(): BelongsTo
    {
        return $this->belongsTo(CustomerPiutang::class, 'piutang_id');
    }
}
