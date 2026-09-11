<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = $this->config ?? [];
        $hideSecrets = ! ($request->user()?->hasRole('admin') ?? false);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'network_id' => $this->network_id,
            'scope' => $this->scope?->value ?? $this->scope,
            'driver' => $this->driver?->value ?? $this->driver,
            'name' => $this->name,
            'status' => $this->status?->value ?? $this->status,
            'config' => [
                'base_url' => $config['base_url'] ?? null,
                'health_path' => $config['health_path'] ?? null,
                'url' => $hideSecrets ? null : ($config['url'] ?? null),
                'notes' => $config['notes'] ?? null,
                'file_name' => $config['file_name'] ?? null,
                'has_file' => filled($config['file_path'] ?? null),
                'period' => $config['period'] ?? null,
            ],
            'has_token' => $this->hasToken(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn ($source) => [
                'kind' => $source->kind,
                'label' => $source->label,
                'last_count' => $source->last_count,
            ])),
            'latest_sync' => $this->whenLoaded('syncs', function () {
                $sync = $this->syncs->first();
                if (! $sync) {
                    return null;
                }

                return [
                    'id' => $sync->id,
                    'status' => $sync->status?->value ?? $sync->status,
                    'records' => $sync->records,
                    'message' => $sync->message,
                    'finished_at' => $sync->finished_at?->toIso8601String(),
                ];
            }),
        ];
    }
}
