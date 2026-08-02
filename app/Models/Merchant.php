<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_name',
        'owner_name',
        'business_phone',
        'business_email',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'pincode',
        'latitude',
        'longitude',
        'fssai_license_no',
        'fssai_expiry_date',
        'gstin',
        'pan',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'kyc_status',
        'kyc_rejection_reason',
        'kyc_verified_at',
        'is_accepting_orders',
    ];

    protected $hidden = [
        'bank_account_number',
    ];

    protected function casts(): array
    {
        return [
            'fssai_expiry_date' => 'date',
            'kyc_verified_at' => 'datetime',
            'kyc_status' => KycStatus::class,
            'is_accepting_orders' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(MerchantOperatingHour::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * A merchant may only receive orders once KYC is verified, the FSSAI
     * licence is current, and they have switched themselves on.
     */
    public function canReceiveOrders(): bool
    {
        return $this->isKycVerified()
            && $this->hasValidFssai()
            && $this->is_accepting_orders;
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    /**
     * An FSSAI licence that has lapsed blocks the merchant from going live,
     * even once KYC has been approved.
     */
    public function hasValidFssai(): bool
    {
        return $this->fssai_license_no !== null
            && $this->fssai_expiry_date !== null
            && $this->fssai_expiry_date->isFuture();
    }
}
