<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreSellerGrant;
use App\Modules\Subscription\Services\PlanEntitlements;
use Illuminate\Validation\ValidationException;

class StoreSellerGrantService
{
    public function __construct(
        private readonly StoreTeamMembership $team,
    ) {}

    public function grant(Store $store, int $partnerUserId, ?User $grantedBy = null): StoreSellerGrant
    {
        $owner = $grantedBy ?? $store->user;
        if ($owner && ! PlanEntitlements::allows($owner, PlanEntitlements::PARTNER_SELL)) {
            throw ValidationException::withMessages([
                'can_sell_inventory' => ['Tu plan no permite que socios vendan tu inventario. Pasa a Intermedio o Premium.'],
            ]);
        }

        $partnerId = $this->team->partnerIdOnTeam($store, $partnerUserId);

        if ($partnerId === null) {
            throw ValidationException::withMessages([
                'can_sell_inventory' => ['Solo puedes autorizar a alguien de tu equipo con cuenta en la plataforma.'],
            ]);
        }

        return StoreSellerGrant::query()->updateOrCreate(
            ['partner_user_id' => $partnerId],
            [
                'store_id' => $store->id,
                'granted_by' => $grantedBy?->id ?? $store->user_id,
            ],
        );
    }

    public function revoke(Store $store, int $partnerUserId): void
    {
        StoreSellerGrant::query()
            ->where('store_id', $store->id)
            ->where('partner_user_id', $partnerUserId)
            ->delete();
    }

    public function storeFor(User $partner): ?Store
    {
        $grant = StoreSellerGrant::query()
            ->with(['store.user'])
            ->where('partner_user_id', $partner->id)
            ->first();

        $store = $grant?->store;

        return $store instanceof Store ? $store : null;
    }

    public function allows(User $partner, Store $store): bool
    {
        return StoreSellerGrant::query()
            ->where('store_id', $store->id)
            ->where('partner_user_id', $partner->id)
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function partnerIdsForStore(Store $store): array
    {
        return StoreSellerGrant::query()
            ->where('store_id', $store->id)
            ->pluck('partner_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
