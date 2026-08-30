<?php

namespace App\Models;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfilment_type' => FulfilmentType::class,
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'distance_metres' => 'integer',
            'items_total' => 'decimal:2',
            'packaging_fee' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'merchant_payout' => 'decimal:2',
            'rider_payout' => 'decimal:2',
            'rider_payout_breakdown' => 'array',
            'accepted_latitude' => 'decimal:7',
            'accepted_longitude' => 'decimal:7',
            'cancellation_fee' => 'decimal:2',
            'estimated_prep_minutes' => 'integer',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'arrived_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** The single rating left against this order (EP12). */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Delivered->value,
            OrderStatus::Cancelled->value,
            OrderStatus::Rejected->value,
        ]);
    }

    public function isDelivery(): bool
    {
        return $this->fulfilment_type === FulfilmentType::Delivery;
    }

    /** Total actually captured, ignoring failed and pending attempts. */
    public function amountPaid(): float
    {
        return (float) $this->payments
            ->where('status', PaymentStatus::Paid)
            ->sum('amount');
    }

    /**
     * What the rider collects at the door.
     *
     * Anything already captured online is deducted, so this is the whole total
     * for a cash order and zero for a prepaid one.
     *
     * Queried rather than read off a loaded relation: getting this wrong costs
     * the rider money out of their own pocket, so it must not depend on what a
     * caller remembered to eager load.
     */
    public function cashToCollect(): float
    {
        $captured = (float) $this->payments()
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        return round(max(0, (float) $this->grand_total - $captured), 2);
    }
}
