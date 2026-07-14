<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Ledger histori unit serial (paralel StockCard, tapi level unit).
 * Dicatat saat dokumen di-approve/lock untuk tiap unit yang berpindah/berubah status.
 * Append-only. Jaga invariant: tiap mutasi serial_units.warehouse_id/status WAJIB
 * punya movement padanan di transaksi yang sama (analog CLAUDE.md §2C stock invariant).
 */
class SerialUnitMovement extends Model
{
    use HasUlid;

    protected $table = 'serial_unit_movements';

    protected $fillable = [
        'ulid',
        'serial_unit_id',
        'doc_type',
        'doc_id',
        'doc_no',
        'movement_type',
        'from_warehouse_id',
        'to_warehouse_id',
        'from_status',
        'to_status',
        'tanggal',
        'notes',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'serial_unit_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => LocalDateTime::class,
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    // ==================== RELATIONS ====================

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class, 'serial_unit_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(MasterWarehouse::class, 'to_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== HELPERS ====================

    /**
     * Catat satu pergerakan unit serial. Dipanggil dari Action di dalam DB::transaction.
     *
     * @param  array  $data  Required: serial_unit_id, doc_type, doc_id, movement_type, tanggal.
     *                       Optional: doc_no, from/to_warehouse_id, from/to_status, notes.
     */
    public static function record(array $data): self
    {
        return self::create([
            'serial_unit_id' => $data['serial_unit_id'],
            'doc_type' => $data['doc_type'],
            'doc_id' => $data['doc_id'],
            'doc_no' => $data['doc_no'] ?? null,
            'movement_type' => $data['movement_type'],
            'from_warehouse_id' => $data['from_warehouse_id'] ?? null,
            'to_warehouse_id' => $data['to_warehouse_id'] ?? null,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? null,
            'tanggal' => $data['tanggal'],
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);
    }
}
