<?php

namespace App\Enums;

/**
 * Order lifecycle. Delivery and self-pickup share the same states up to
 * ReadyForPickup, then branch (EP10).
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Placed = 'placed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case RiderAssigned = 'rider_assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Rejected], true);
    }
}
