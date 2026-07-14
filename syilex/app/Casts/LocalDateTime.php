<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom cast for DATETIME fields using timezone from global settings.
 *
 * App timezone is set to regional.timezone in AppServiceProvider.
 * Database stores in app timezone (same as display timezone).
 * This cast ensures consistent serialization with timezone offset for JS parsing.
 */
class LocalDateTime implements CastsAttributes
{
    /**
     * Cast the given value (from database to PHP).
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * Prepare the given value for storage (from PHP to database).
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * Serialize the value for JSON.
     * Output format: ISO 8601 with offset (e.g., "2026-01-25T10:30:00+07:00")
     * This ensures JavaScript's new Date() correctly interprets the timezone.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
