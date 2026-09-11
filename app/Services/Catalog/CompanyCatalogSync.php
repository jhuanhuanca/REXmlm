<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Shared\Support\Currencies;

class CompanyCatalogSync
{
    public function __construct(
        private readonly CatalogClient $catalog,
    ) {}

    /**
     * Trae el catálogo de la empresa a la tienda del líder.
     * Los ítems nuevos quedan sin publicar: el líder elige cuáles mostrar.
     * No toca inventario personal.
     */
    public function syncStoreIfStale(Store $store, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? (int) config('services.catalog.sync_ttl', 900);
        $syncedAt = $store->catalog_synced_at;

        if ($syncedAt !== null && $ttl > 0 && $syncedAt->gt(now()->subSeconds($ttl))) {
            return;
        }

        $this->syncStore($store);
    }

    public function syncStore(Store $store): void
    {
        $store->loadMissing(['user:id,catalog_company_id,country', 'user.companyMemberships']);
        $user = $store->user;

        if ($user === null) {
            return;
        }

        $companyIds = $user->companyMemberships
            ->pluck('catalog_company_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($companyIds->isEmpty() && $user->catalog_company_id) {
            $companyIds = collect([(int) $user->catalog_company_id]);
        }

        $leaderCountry = $user->country ? strtoupper(trim((string) $user->country)) : null;

        foreach ($companyIds as $companyId) {
            foreach ($this->catalog->productsForCompany($companyId) as $item) {
                $this->ensureStoreProduct($store, $companyId, $item, $leaderCountry);
            }
        }

        $store->forceFill(['catalog_synced_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ensureStoreProduct(Store $store, int $companyId, array $item, ?string $leaderCountry): void
    {
        $catalogProductId = isset($item['id']) ? (int) $item['id'] : 0;
        $name = trim((string) ($item['name'] ?? ''));

        if ($name === '' || $catalogProductId <= 0) {
            return;
        }

        $available = $this->catalogItemAvailableInCountry($item, $leaderCountry);

        $existing = Product::withTrashed()
            ->where('store_id', $store->id)
            ->where('catalog_product_id', $catalogProductId)
            ->first();

        if (! $available) {
            if ($existing !== null && ! $existing->trashed() && $existing->is_published) {
                $existing->forceFill(['is_published' => false])->save();
            }

            return;
        }

        if ($existing !== null) {
            if ($existing->trashed()) {
                return;
            }

            $existing->fill([
                'name' => $name,
                'description' => $item['description'] ?? $existing->description,
                'technical_sheet' => $item['technical_sheet'] ?? $existing->technical_sheet,
                'image' => $item['image'] ?? $existing->image,
                'catalog_company_id' => $companyId,
                'catalog_product_id' => $catalogProductId,
                'source' => ProductSource::Company,
            ])->save();

            return;
        }

        $store->products()->create([
            'name' => $name,
            'description' => $item['description'] ?? null,
            'technical_sheet' => $item['technical_sheet'] ?? null,
            'price' => $item['price'] ?? 0,
            'currency' => Currencies::normalize($item['currency'] ?? $store->currency(), $store->currency()),
            'stock' => (int) ($item['stock'] ?? 0),
            'image' => $item['image'] ?? null,
            'is_active' => (bool) ($item['is_active'] ?? true),
            'is_published' => false,
            'source' => ProductSource::Company,
            'fulfillment' => ProductFulfillment::Dropship,
            'catalog_company_id' => $companyId,
            'catalog_product_id' => $catalogProductId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function catalogItemAvailableInCountry(array $item, ?string $country): bool
    {
        $codes = $item['countries'] ?? null;

        if (! is_array($codes) || $codes === []) {
            return true;
        }

        $code = strtoupper(trim((string) $country));

        if ($code === '') {
            return true;
        }

        $normalized = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            $codes,
        );

        return in_array($code, $normalized, true);
    }
}
