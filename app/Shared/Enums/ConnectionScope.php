<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ConnectionScope: string
{
    case Organization = 'organization';
    case Network = 'network';
}
