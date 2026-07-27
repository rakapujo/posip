<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocPembayaranPiutangDeposit extends Model
{
    protected $table = 'doc_pembayaran_piutang_deposit';

    public $timestamps = false;

    protected $fillable = ['pembayaran_id', 'deposit_id', 'nominal_digunakan'];

    protected $hidden = ['id', 'pembayaran_id', 'deposit_id'];

    protected function casts(): array
    {
        return ['nominal_digunakan' => 'decimal:2'];
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(DocPembayaranPiutang::class, 'pembayaran_id');
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(CustomerDeposit::class, 'deposit_id');
    }
}
