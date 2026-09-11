<?php

declare(strict_types=1);

namespace App\Modules\Landing\Http\Resources;

use App\Services\Catalog\CompanyBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'template' => $this->template,
            'content' => $this->content,
            'is_published' => $this->is_published,
            'whatsapp' => data_get($this->content, 'whatsapp'),
            'owner_name' => $this->when(
                $this->relationLoaded('user') && $this->user,
                fn () => $this->user->name,
                $this->title,
            ),
            'store_slug' => $this->when(
                $this->relationLoaded('user') && $this->user?->relationLoaded('store') && $this->user->store,
                fn () => $this->user->store->slug,
                $this->slug,
            ),
            'company' => $this->when(
                $this->relationLoaded('user'),
                fn () => CompanyBranding::forUser($this->user),
            ),
        ];
    }
}
