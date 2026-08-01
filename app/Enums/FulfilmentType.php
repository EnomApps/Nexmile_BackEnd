<?php

namespace App\Enums;

enum FulfilmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
