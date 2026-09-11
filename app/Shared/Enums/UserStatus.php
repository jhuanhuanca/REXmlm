<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
