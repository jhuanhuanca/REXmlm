<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ConnectionStatus: string
{
    case Idle = 'idle';
    case Connected = 'connected';
    case Error = 'error';
    case Disabled = 'disabled';
}
