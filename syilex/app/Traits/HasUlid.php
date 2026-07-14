<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUlid
{
    /**
     * Boot the HasUlid trait.
     * Automatically generates ULID when creating a new model.
     */
    protected static function bootHasUlid(): void
    {
        static::creating(function ($model) {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * Initialize the HasUlid trait.
     * Ensures ulid is not mass assignable by default but is always filled.
     */
    public function initializeHasUlid(): void
    {
        $this->mergeFillable(['ulid']);
    }

    /**
     * Get the route key name for Laravel route model binding.
     * Uses 'ulid' instead of 'id' for public URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Scope to find by ULID.
     */
    public function scopeByUlid($query, string $ulid)
    {
        return $query->where('ulid', $ulid);
    }
}
