<?php

namespace App\Enums;

/**
 * What became of one ringing order card.
 *
 * The four endings are kept apart rather than collapsed into "not accepted"
 * because they mean different things about the rider. Declining is a choice
 * and a reasonable one; letting it ring out is the thing that slows every
 * other rider down, and only one of the two should count against anybody.
 */
enum OfferStatus: string
{
    /** Still ringing, as far as the server knows. */
    case Pending = 'pending';

    case Accepted = 'accepted';

    /** The rider tapped no. */
    case Rejected = 'rejected';

    /** The window closed with no answer. */
    case Expired = 'expired';

    /**
     * Withdrawn by us: another rider was faster, or the order was cancelled
     * while the phone was still ringing.
     */
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Over, whatever the ending. */
    public function isFinished(): bool
    {
        return $this !== self::Pending;
    }
}
