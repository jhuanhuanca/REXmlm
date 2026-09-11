<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CommissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';
}
