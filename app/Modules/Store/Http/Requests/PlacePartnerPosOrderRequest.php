<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Services\StoreSellerGrantService;

class PlacePartnerPosOrderRequest extends PlacePosOrderRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(StoreSellerGrantService::class)->storeFor($user) !== null;
    }
}
