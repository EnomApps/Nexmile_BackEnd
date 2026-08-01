<?php

namespace App\Enums;

enum RiderStatus: string
{
    case Offline = 'offline';
    case Available = 'available';
    case OnOrder = 'on_order';
    case OnBreak = 'on_break';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
