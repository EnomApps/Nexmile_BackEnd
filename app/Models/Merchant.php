<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

        /*
         * Storefront settings. The profile endpoint has always validated
         * these; without them here every update was accepted and silently
         * discarded.
         *
         * commission_rate is deliberately absent — it is a contract term, not
         * a merchant preference, and must never be settable by the account it
         * charges.
         */
        'service_category',
        'description',
        'avg_prep_time_minutes',
        'packaging_fee',
        'min_order_value',
        'supports_pickup',

        /*
         * Card and filter attributes. rating and rating_count are deliberately
         * absent: they are earned from reviews, and a restaurant that could
         * set its own score would make the badge worthless.
         */
        'is_pure_veg',
        'cost_for_two',
        'cuisines',
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
            'supports_pickup' => 'boolean',
            'avg_prep_time_minutes' => 'integer',
            'packaging_fee' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'rating' => 'float',
            'rating_count' => 'integer',
            'is_pure_veg' => 'boolean',
            'cost_for_two' => 'integer',
            'cuisines' => 'array',
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

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** The storefront carousel, in the order the merchant arranged it. */
    public function photos(): HasMany
    {
        return $this->hasMany(MerchantPhoto::class)->orderBy('position')->orderBy('id');
    }

    /** Ratings left against this restaurant (EP12). */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
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
     * Whether a customer can place an order right now.
     *
     * Distinct from `canReceiveOrders()`, which asks whether the merchant is
     * *allowed* to trade at all. This also asks whether the kitchen is open at
     * this minute.
     */
    public function isOpenNow(?CarbonInterface $at = null): bool
    {
        return $this->canReceiveOrders() && $this->isWithinOperatingHours($at);
    }

    /**
     * A merchant with no hours configured is treated as always open. The
     * alternative — defaulting to closed — would silently hide every merchant
     * who has not filled in a schedule yet, which looks like the platform
     * being broken rather than a missing setting.
     */
    public function isWithinOperatingHours(?CarbonInterface $at = null): bool
    {
        $at = $at ? $at->copy() : now();

        $hours = $this->relationLoaded('operatingHours')
            ? $this->operatingHours
            : $this->operatingHours()->get();

        if ($hours->isEmpty()) {
            return true;
        }

        $today = $hours->firstWhere('day_of_week', (int) $at->dayOfWeek);

        if ($today === null || $today->is_closed) {
            return false;
        }

        $opens = $at->copy()->setTimeFromTimeString($today->opens_at);
        $closes = $at->copy()->setTimeFromTimeString($today->closes_at);

        /*
         * A kitchen that closes at 01:00 closes *after* midnight, so the
         * window runs into the next day. Comparing naively would call it shut
         * all evening — the busiest hours for exactly the kind of late-night
         * place this affects.
         */
        if ($closes->lessThanOrEqualTo($opens)) {
            return $at->greaterThanOrEqualTo($opens) || $at->lessThan($closes);
        }

        return $at->betweenIncluded($opens, $closes);
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

    /**
     * Why the licence is blocking them, in terms of who can do something
     * about it.
     *
     * Uploading the certificate and having the licence recorded are two
     * different things: the merchant does the first, an admin reads the second
     * off it. A merchant looking at an approved document and being told to
     * "update your licence" has nowhere to go, and telling someone to fix
     * something they cannot reach is worse than saying nothing.
     *
     * @return 'no_document'|'awaiting_review'|'rejected'|'awaiting_details'|'expired'|null
     */
    public function fssaiBlocker(): ?string
    {
        if ($this->hasValidFssai()) {
            return null;
        }

        // A recorded but lapsed licence is the merchant's problem to solve —
        // they have to renew it and send the new certificate.
        if ($this->fssai_license_no !== null && $this->fssai_expiry_date !== null) {
            return 'expired';
        }

        $document = $this->kycDocuments()
            ->where('type', DocumentType::FssaiCertificate->value)
            ->latest('id')
            ->first();

        return match (true) {
            $document === null => 'no_document',
            $document->status === DocumentStatus::Rejected => 'rejected',
            $document->status === DocumentStatus::Approved => 'awaiting_details',
            default => 'awaiting_review',
        };
    }

    /**
     * Whether a delivery from here can ever be free.
     *
     * Config-wide today rather than per restaurant, so this is true for
     * everyone. Kept as a method so that when the threshold becomes a merchant
     * or zone setting, the card and the filter follow without another change.
     */
    public function hasFreeDelivery(): bool
    {
        return config('checkout.free_delivery_above') !== null;
    }

    /**
     * Promotions to show on the card, newest-first by importance.
     *
     * Derived from what the system will actually do at checkout rather than
     * stored as free text. A merchant who could type their own offer label
     * could promise a discount the pricing code has never heard of, and the
     * customer would find out at the payment screen.
     *
     * @return list<array{label: string, type: string}>
     */
    public function offers(): array
    {
        $offers = [];

        // surplusActive + available is exactly what the deals endpoint
        // offers, so a badge here and an orderable deal there agree.
        $liveDeals = $this->menuItems()->surplusActive()->available()->count();

        if ($liveDeals > 0) {
            $offers[] = [
                'label' => __('portal.offers.food_rescue'),
                'type' => 'food_rescue',
            ];
        }

        $threshold = config('checkout.free_delivery_above');

        if ($threshold !== null) {
            $offers[] = [
                'label' => __('portal.offers.free_delivery', ['amount' => (int) $threshold]),
                'type' => 'free_delivery',
            ];
        }

        return $offers;
    }
}
