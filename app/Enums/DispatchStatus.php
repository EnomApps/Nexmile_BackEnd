<?php

namespace App\Enums;

/**
 * How the hunt for a rider is going.
 *
 * Separate from OrderStatus because the two run at the same time. Dispatch
 * starts when the merchant accepts, so the kitchen is cooking while riders'
 * phones are ringing, and one column cannot say both. The customer app reads
 * this for "finding a delivery partner"; the kitchen screen reads `status`.
 */
enum DispatchStatus: string
{
    /** Offers are out. At least one phone is ringing. */
    case Searching = 'searching';

    /** A rider accepted. The order has an owner. */
    case Assigned = 'assigned';

    /**
     * Every wave went out and nobody took it. The only dispatch state that
     * needs a person, which is why it is a state at all rather than a log
     * line — an order nobody is looking for has to be findable.
     */
    case NoRiderFound = 'no_rider_found';

    /**
     * Not being dispatched. Self-pickup, cancelled, or a rider handed it back
     * and the next wave has not gone out yet.
     */
    case Idle = 'idle';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
