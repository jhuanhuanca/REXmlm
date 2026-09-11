<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

enum ProductFulfillment: string
{
    case Stock = 'stock';
    case Dropship = 'dropship';
}
