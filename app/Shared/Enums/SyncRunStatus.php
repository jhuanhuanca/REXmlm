<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
