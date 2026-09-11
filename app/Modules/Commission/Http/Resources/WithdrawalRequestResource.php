<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Resources;

use App\Shared\Enums\WithdrawalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status instanceof WithdrawalStatus ? $this->status->value : $this->status,
            'collect_all' => $this->collect_all,
            'whatsapp' => $this->whatsapp,
            'contact_email' => $this->contact_email,
            'notes' => $this->notes,
            'password_confirmed_at' => $this->password_confirmed_at,
            'processed_at' => $this->processed_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'processed_by' => $this->whenLoaded('processedBy', fn () => $this->processedBy ? [
                'id' => $this->processedBy->id,
                'name' => $this->processedBy->name,
            ] : null),
        ];
    }
}
