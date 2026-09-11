<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? 'info',
            'title' => $data['title'] ?? 'Aviso',
            'body' => $data['body'] ?? '',
            'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
            'product_name' => $data['product_name'] ?? null,
            'stock' => isset($data['stock']) ? (int) $data['stock'] : null,
            'expires_at' => $data['expires_at'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
