<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom cast for DATE fields to prevent timezone issues in JSON serialization.
 *
 * Problem: Laravel's default 'date' cast serializes as "2026-01-25T00:00:00.000000Z"
 * which causes frontend to interpret it in UTC and display wrong date in local timezone.
 *
 * Solution: This cast serializes as "2026-01-25" (date only, no time/timezone).
 */
class DateOnly implements CastsAttributes
{
    /**
     * Cast the given value (from database to PHP).
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
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
            return $value->format('Y-m-d');
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    /**
     * Serialize the value for JSON (this is the key fix).
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        return Carbon::parse($value)->format('Y-m-d');
    }
}
