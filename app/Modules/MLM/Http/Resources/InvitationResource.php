<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'leader' => $this->whenLoaded('leader', fn () => [
                'id' => $this->leader->id,
                'name' => $this->leader->name,
            ]),
        ];
    }
}
