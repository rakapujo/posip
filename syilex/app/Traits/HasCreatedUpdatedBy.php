<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait HasCreatedUpdatedBy
{
    /**
     * Boot the HasCreatedUpdatedBy trait.
     * Automatically sets created_by and updated_by fields.
     */
    protected static function bootHasCreatedUpdatedBy(): void
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = $model->created_by ?? Auth::id();
                $model->updated_by = $model->updated_by ?? Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    /**
     * Initialize the trait.
     */
    public function initializeHasCreatedUpdatedBy(): void
    {
        $this->mergeFillable(['created_by', 'updated_by']);
    }

    /**
     * Get the user who created this model.
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Get the user who last updated this model.
     */
    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }
}
