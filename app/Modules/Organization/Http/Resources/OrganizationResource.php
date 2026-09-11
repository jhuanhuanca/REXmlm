<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Metrics\OrganizationMetricsProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'catalog_company_id' => $this->catalog_company_id,
            'default_timezone' => $this->default_timezone,
            'default_currency' => $this->default_currency,
            'status' => $this->status,
            'metrics_profile' => OrganizationMetricsProfile::from($this->metrics_profile)->toArray(),
            'members_count' => $this->whenCounted('users'),
            'connections' => OrganizationConnectionResource::collection($this->whenLoaded('connections')),
            'connector' => $this->when(
                $this->relationLoaded('connections'),
                function () {
                    $connections = $this->connections;
                    $connected = $connections->contains(
                        fn ($item) => ($item->status?->value ?? $item->status) === 'connected',
                    );
                    $last = $connections
                        ->sortByDesc(fn ($item) => $item->last_synced_at?->timestamp ?? 0)
                        ->first();

                    return [
                        'connected' => $connected,
                        'last_synced_at' => $last?->last_synced_at?->toIso8601String(),
                        'drivers' => $connections
                            ->pluck('driver')
                            ->map(fn ($driver) => is_object($driver) ? $driver->value : $driver)
                            ->values(),
                    ];
                },
            ),
        ];
    }
}
