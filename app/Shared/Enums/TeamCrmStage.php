<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TeamCrmStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Active = 'active';
    case FollowUp = 'follow_up';
    case NeedsSupport = 'needs_support';
    case Independent = 'independent';
}
