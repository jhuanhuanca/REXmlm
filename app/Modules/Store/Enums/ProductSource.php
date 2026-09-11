<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

enum ProductSource: string
{
    case Personal = 'personal';
    case Company = 'company';
    case Incentive = 'incentive';
}
