<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rider extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'zone_id', 'full_name', 'date_of_birth',
        'aadhaar_number', 'pan', 'driving_licence_no', 'driving_licence_expiry',
        'vehicle_number', 'vehicle_type', 'rc_number',
        'insurance_number', 'insurance_expiry',
        'kyc_status', 'kyc_rejection_reason', 'kyc_verified_at',
        'duty_status', 'last_latitude', 'last_longitude', 'last_location_at',
        'completed_deliveries', 'rating',
        'bank_account_name', 'bank_account_number', 'bank_ifsc',
    ];

    /** Identity documents are never exposed through the API. */
    protected $hidden = [
        'aadhaar_number', 'bank_account_number',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'driving_licence_expiry' => 'date',
            'insurance_expiry' => 'date',
            'kyc_verified_at' => 'datetime',
            'last_location_at' => 'datetime',
            'kyc_status' => KycStatus::class,
            'duty_status' => RiderStatus::class,
            'last_latitude' => 'decimal:7',
            'last_longitude' => 'decimal:7',
            'completed_deliveries' => 'integer',
            'rating' => 'decimal:2',
        ];
    }

    public function kycDocuments(): MorphMany
    {
        return $this->morphMany(KycDocument::class, "documentable");
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    /**
     * A rider may only be dispatched with verified KYC and documents that have
     * not lapsed — an expired licence or insurance is a legal exposure, not a
     * paperwork detail.
     */
    public function canAcceptOrders(): bool
    {
        return $this->isKycVerified()
            && $this->duty_status === RiderStatus::Available
            && ! $this->hasExpiredDocuments();
    }

    public function hasExpiredDocuments(): bool
    {
        return ($this->driving_licence_expiry?->isPast() ?? true)
            || ($this->insurance_expiry?->isPast() ?? true);
    }
}
