<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TeamActivityType: string
{
    case Note = 'note';
    case FollowUp = 'follow_up';
    case Call = 'call';
    case Support = 'support';
}
