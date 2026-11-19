<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'role',
        'status',
        'preferred_locale',
        'veg_mode',
        'promotional_notifications',
    ];

    /**
     * Defaults for a model that has not been read back from the database.
     *
     * The column defaults alone are not enough: a User built in memory and
     * used before it is reloaded has these as null, and `(bool) null` is
     * false — which would have read as "this customer turned offers off" for
     * every account in its first moments.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'veg_mode' => false,
        'promotional_notifications' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'veg_mode' => 'boolean',
            'promotional_notifications' => 'boolean',
        ];
    }

    /**
     * Customers who have not turned marketing pushes off.
     *
     * The only door marketing is allowed through — see
     * `PushService::promotionalToUsers()`. A scope rather than a check at each
     * call site, because the call site that forgets is the one that sends.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWantsPromotions(Builder $query): void
    {
        $query->where('promotional_notifications', true);
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    /** Restaurants this customer bookmarked. */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'favourites')->withTimestamps();
    }

    public function rider(): HasOne
    {
        return $this->hasOne(Rider::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
