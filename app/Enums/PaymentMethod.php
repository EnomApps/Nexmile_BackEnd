<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Upi = 'upi';
    case Card = 'card';
    case NetBanking = 'netbanking';
    case Wallet = 'wallet';
    case Cod = 'cod';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
