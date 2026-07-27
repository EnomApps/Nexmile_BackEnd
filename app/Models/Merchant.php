<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
