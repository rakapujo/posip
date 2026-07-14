<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\HasAuditLog;

class User extends Authenticatable
{
    // SoftDeletes untuk audit trail — user deactivate tidak broke FK historical data.
    use HasFactory, Notifiable, HasApiTokens, HasRoles, HasUlid, HasCreatedUpdatedBy, SoftDeletes, HasAuditLog;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * Default user preferences for theme settings.
     */
    public const DEFAULT_PREFERENCES = [
        'preset' => 'Lara',
        'primary' => 'blue',
        'surface' => 'slate',
        'dark_theme' => false,
        'menu_mode' => 'static',
    ];

    protected $fillable = [
        'ulid',
        'name',
        'email',
        'password',
        'pin',
        'phone',
        'avatar',
        'status',
        'is_protected',
        'preferences',
        'last_login_at',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'password',
        'pin',
        'remember_token',
        'is_protected',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => LocalDateTime::class,
            'last_login_at' => LocalDateTime::class,
            'password' => 'hashed',
            'pin' => 'hashed',
            'preferences' => 'array',
            'is_protected' => 'boolean',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Get user preferences with defaults.
     */
    public function getPreferencesWithDefaults(): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, $this->preferences ?? []);
    }

    /**
     * Update specific preference keys.
     */
    public function updatePreferences(array $newPreferences): void
    {
        $current = $this->preferences ?? [];
        $this->preferences = array_merge($current, $newPreferences);
        $this->save();
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'avatar_url',
    ];

    /**
     * Get avatar URL attribute.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return null;
        }

        // If already a full URL, return as-is
        if (filter_var($this->avatar, FILTER_VALIDATE_URL)) {
            return $this->avatar;
        }

        return asset('storage/' . $this->avatar);
    }

    /**
     * Check if user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for inactive users.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Check if the given PIN matches the user's PIN.
     */
    public function checkPin(string $pin): bool
    {
        if (!$this->pin) {
            return false;
        }

        return \Illuminate\Support\Facades\Hash::check($pin, $this->pin);
    }

    /**
     * Check if user has a PIN set.
     */
    public function hasPin(): bool
    {
        return !empty($this->pin);
    }

    /**
     * Check if user is protected (cannot be deleted/listed).
     */
    public function isProtected(): bool
    {
        return $this->is_protected ?? false;
    }

    /**
     * Scope to exclude protected users.
     */
    public function scopeVisible($query)
    {
        return $query->where(function ($q) {
            $q->where('is_protected', false)
              ->orWhereNull('is_protected');
        });
    }
}
