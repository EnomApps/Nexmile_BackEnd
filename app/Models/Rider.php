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
        return $this->morphMany(KycDocument::class, 'documentable');
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
     * May this rider go on duty at all?
     *
     * Paperwork only, deliberately not duty_status. This is the question the
     * app asks before showing the Go online button, and folding duty_status in
     * would make it a catch-22: false until they are online, and they cannot
     * get online while it is false.
     */
    public function canGoOnline(): bool
    {
        return $this->isKycVerified() && ! $this->hasExpiredDocuments();
    }

    /**
     * Why not — in the same words the API refuses with, so the app can show the
     * real reason instead of guessing at one. Null when nothing is blocking.
     */
    public function offlineReason(): ?string
    {
        return match (true) {
            ! $this->isKycVerified() => 'Your documents are still being verified.',
            $this->hasExpiredDocuments() => 'Your licence or insurance has expired. Upload current documents to go online.',
            default => null,
        };
    }

    /**
     * Dispatchable right now: eligible, and actually on duty. This is what
     * dispatch asks, and it is a different question from canGoOnline().
     *
     * An expired licence or insurance is a legal exposure, not a paperwork
     * detail, so both gates apply.
     */
    public function canAcceptOrders(): bool
    {
        return $this->canGoOnline()
            && $this->duty_status === RiderStatus::Available;
    }

    /**
     * A missing expiry counts as expired. The safe reading — an unrecorded
     * licence is not evidence of a current one — but it does mean a rider an
     * admin has just approved can still be blocked by a blank date, so
     * `nexmile:why-offline` names that case explicitly.
     */
    public function hasExpiredDocuments(): bool
    {
        return ($this->driving_licence_expiry?->isPast() ?? true)
            || ($this->insurance_expiry?->isPast() ?? true);
    }
}
