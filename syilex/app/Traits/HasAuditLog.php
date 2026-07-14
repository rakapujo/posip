<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Trait untuk enable audit logging otomatis di model.
 *
 * Wraps Spatie\Activitylog\Traits\LogsActivity dengan default config:
 * - Log semua perubahan $fillable fields (dirty only)
 * - Skip empty logs (tidak log kalau tidak ada perubahan)
 * - Log name = class basename (Promo, Sales, dll)
 *
 * Usage:
 *   class DocPromo extends Model {
 *       use HasAuditLog;
 *   }
 *
 * Override optional:
 *   protected array $auditLogFields = ['field_tertentu']; // log hanya field ini
 *   protected array $auditLogIgnore = ['updated_at'];     // skip log kalau field ini saja yang berubah
 *
 * Query audit:
 *   $model->activities;                  // semua log untuk model ini
 *   \Spatie\Activitylog\Models\Activity::where('causer_id', $user->id)->get();
 */
trait HasAuditLog
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $logName = class_basename(static::class);

        $options = LogOptions::defaults()
            ->useLogName($logName)
            ->logOnlyDirty() // hanya log field yang benar-benar berubah
            ->dontSubmitEmptyLogs(); // skip log kalau dirty check return kosong

        // Pakai $auditLogFields kalau di-define, fallback ke $fillable
        if (property_exists($this, 'auditLogFields') && is_array($this->auditLogFields)) {
            $options->logOnly($this->auditLogFields);
        } else {
            $options->logFillable();
        }

        return $options;
    }
}
