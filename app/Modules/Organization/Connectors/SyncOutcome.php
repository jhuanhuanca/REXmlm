<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

final class SyncOutcome
{
    /**
     * @param  array<string, int>  $records
     * @param  list<array{level: string, message: string, context?: array<string, mixed>}>  $logs
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly array $records,
        public readonly array $logs,
        public readonly ?string $message = null,
        public readonly ?array $payload = null,
    ) {}
}
