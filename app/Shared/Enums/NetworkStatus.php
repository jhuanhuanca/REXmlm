<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum NetworkStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
