<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Rider = 'rider';
    case Merchant = 'merchant';
    case Admin = 'admin';
}
