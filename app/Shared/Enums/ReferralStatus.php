<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ReferralStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Independent = 'independent';
    case Inactive = 'inactive';
}
