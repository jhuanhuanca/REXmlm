<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ConnectionDriver: string
{
    case Api = 'api';
    case Excel = 'excel';
    case Catalog = 'catalog';
    case Other = 'other';
}
